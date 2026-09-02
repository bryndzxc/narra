<?php

namespace App\Services\Claude;

use Anthropic\Client;
use App\Enums\CostCategory;
use App\Enums\CostUnit;
use App\Support\Providers\ProviderUsage;
use App\Support\Providers\ScriptWriterException;
use Illuminate\Support\Str;

/**
 * One streamed, schema-constrained, self-pricing call to Claude.
 *
 * Extracted when the metadata writer arrived, and extracted rather than copied
 * for one reason: `priceUsage()` is the only place in the app that turns tokens
 * into money. A second copy of it is a second answer to "what did this video
 * cost", and the first time the two disagree the ledger stops being evidence.
 *
 * Everything platform-shaped lives here too — streaming, because a call on a
 * thinking model runs long enough to outrun a non-streaming HTTP timeout and
 * `queue:work --timeout` is silently ineffective without pcntl; and the refusal
 * and max_tokens guards, because both arrive as HTTP 200 with content that is
 * unusable rather than as an error.
 *
 * The using class supplies `$this->client`.
 */
trait TalksToClaude
{
    /**
     * Run one call and price it.
     *
     * @param  array<string, mixed>  $schema  Structured-output JSON schema.
     * @param  array<string, mixed>|null  $override
     *                                               Model, effort and ceiling for a retry somewhere else, without
     *                                               giving the operation a second entry in config.
     * @return array{0: string, 1: ProviderUsage}
     */
    private function call(
        string $system,
        string $userMessage,
        string $operation,
        array $schema,
        ?array $override = null,
    ): array {
        $config = $this->operationConfig($operation, $override);

        // `effort` is omitted rather than sent as null when the model does not
        // take one. This is not tidiness: `output_config.effort` is rejected
        // outright by Haiku 4.5, so a null that survives into the request body
        // is a 400 on every call rather than a slightly worse draft.
        $outputConfig = ['format' => ['type' => 'json_schema', 'schema' => $schema]];

        if ($config['effort'] !== null && $config['effort'] !== '') {
            $outputConfig['effort'] = $config['effort'];
        }

        try {
            $stream = $this->claudeClient()->messages->createStream(
                model: $config['model'],
                maxTokens: (int) $config['max_tokens'],
                system: [
                    // The system prompt is identical across every act of a
                    // story, so it is the cache prefix. At five acts that is
                    // four cache reads at a tenth of the input rate.
                    //
                    // Caches are model-scoped, so the split below means the act
                    // calls and the scene calls no longer share one. That is
                    // priced in: they never shared a system prompt either.
                    ['type' => 'text', 'text' => $system, 'cacheControl' => ['type' => 'ephemeral']],
                ],
                messages: [
                    ['role' => 'user', 'content' => $userMessage],
                ],
                outputConfig: $outputConfig,
            );

            // Assembled by hand: the PHP SDK's stream has no finalMessage()
            // helper, unlike the Python and TypeScript ones.
            $message = StreamedMessage::consume($stream);
        } catch (\Throwable $e) {
            throw new ScriptWriterException(
                sprintf('%s failed against %s: %s', $operation, $config['model'], $e->getMessage()),
                previous: $e
            );
        }

        // Guard before reading content. A safety decline arrives as HTTP 200
        // with stop_reason 'refusal' and no usable text, and a story premise is
        // exactly the kind of input that can trip one.
        if ($message->stopReason === 'refusal') {
            throw new ScriptWriterException(sprintf(
                '%s was declined by the model (%s). Rework the premise at Gate 1.',
                $operation,
                $message->stopReasonCategory ?? 'no category given'
            ));
        }

        if ($message->stopReason === 'max_tokens') {
            throw new ScriptWriterException(sprintf(
                '%s hit the %d-token output ceiling and was truncated mid-sentence. Raise '
                .'ANTHROPIC_MAX_TOKENS or lower the per-act word target — a truncated act cannot be '
                .'salvaged and re-running it costs the same again.',
                $operation,
                (int) $config['max_tokens']
            ));
        }

        return [$message->text, $this->priceUsage($message, $operation, $config)];
    }

    /** The SDK client this implementation was constructed with. */
    private function claudeClient(): Client
    {
        return $this->client;
    }

    /**
     * Which model, effort and ceiling this operation runs at.
     *
     * One lookup, so a call site never names a model. `$override` is how the
     * scene fallback re-runs the same request somewhere else without the
     * operation having two entries in config.
     *
     * @param  array<string, mixed>|null  $override
     * @return array{model: string, effort: string|null, max_tokens: int}
     */
    private function operationConfig(string $operation, ?array $override = null): array
    {
        $operations = (array) config('providers.anthropic.operations');

        if (! isset($operations[$operation])) {
            // Loud, because the alternative is silently falling back to some
            // default model and billing a whole story against it before anyone
            // notices the operation was never assigned one.
            throw new ScriptWriterException(sprintf(
                "No model assigned for operation '%s'. Every text call names its own model in "
                .'providers.anthropic.operations; add it there rather than letting this call pick '
                .'one. Assigned: %s.',
                $operation,
                implode(', ', array_keys($operations)),
            ));
        }

        $config = $operations[$operation];

        return [
            'model' => (string) ($override['model'] ?? $config['model']),
            'effort' => $override['effort'] ?? ($config['effort'] ?? null),
            'max_tokens' => (int) ($override['max_tokens'] ?? $config['max_tokens']),
        ];
    }

    /**
     * The rate card for one model.
     *
     * @return array<string, float>
     */
    private function rateCard(string $model): array
    {
        $rates = config("providers.anthropic.pricing.{$model}");

        if (! is_array($rates)) {
            // A missing rate card must not price at zero. A story that billed
            // real tokens and recorded $0.00 breaks the one question the cost
            // table exists to answer, and it breaks it silently.
            throw new ScriptWriterException(sprintf(
                "No rate card for model '%s', so its calls cannot be priced. Add it to "
                .'providers.anthropic.pricing — recording a paid call at zero is worse than '
                .'failing here.',
                $model,
            ));
        }

        return $rates;
    }

    /**
     * Turn a response's token counts into money, from the configured rate card.
     *
     * Cache reads and writes are priced separately, and not as a rounding
     * detail: the system prompt is cached across a story's acts, so on a
     * five-act run four of the five calls read most of their input at a tenth
     * of the standard rate.
     *
     * `quantity` on the cost row is total tokens, which is the number that
     * means something when scanning the table. The split lives in `detail`.
     *
     * @param  array{model: string, effort: string|null, max_tokens: int}  $config
     */
    private function priceUsage(StreamedMessage $message, string $operation, array $config): ProviderUsage
    {
        // Keyed on the model this call actually ran against, not on a rate card
        // hanging off the provider. Since the pipeline splits across three
        // models, a single shared card would have priced Haiku scene calls at
        // Opus rates and reported a saving that never happened.
        $rates = $this->rateCard($config['model']);

        $input = $message->inputTokens;
        $output = $message->outputTokens;
        $cacheWrite = $message->cacheWriteTokens;
        $cacheRead = $message->cacheReadTokens;

        $usd = ($input * $rates['input_per_mtok']
            + $output * $rates['output_per_mtok']
            + $cacheWrite * $rates['cache_write_per_mtok']
            + $cacheRead * $rates['cache_read_per_mtok']) / 1_000_000;

        return new ProviderUsage(
            provider: 'anthropic',
            operation: $operation,
            // Text, not asset. Script generation spends at `draft` through
            // `scripted` and metadata at `rendered` — all outside the Gate 2
            // money line, because both produce text an operator reads at a
            // gate. See App\Enums\CostCategory.
            category: CostCategory::Text,
            quantity: (float) $message->totalTokens(),
            unit: CostUnit::OutputTokens,
            usdCost: round($usd, 6),
            detail: [
                'model' => $config['model'],
                'input_tokens' => $input,
                'output_tokens' => $output,
                'cache_write_tokens' => $cacheWrite,
                'cache_read_tokens' => $cacheRead,
            ],
            model: $config['model'],
        );
    }

    /**
     * @return array<mixed>
     */
    private function decodeJson(string $content, string $what): array
    {
        $decoded = json_decode($content, true);

        if (! is_array($decoded)) {
            throw new ScriptWriterException(sprintf(
                'The %s response was not the JSON its schema required: %s',
                $what,
                Str::limit($content, 300)
            ));
        }

        return $decoded;
    }
}
