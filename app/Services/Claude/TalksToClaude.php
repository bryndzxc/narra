<?php

namespace App\Services\Claude;

use Anthropic\Client;
use App\Enums\CostCategory;
use App\Enums\CostUnit;
use App\Models\RenderJob;
use App\Support\ModelText;
use App\Support\Providers\ProviderUsage;
use App\Support\Providers\ScriptWriterException;
use App\Support\ResponseArchive;
use Illuminate\Support\Facades\Log;
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
            throw new ScriptWriterException($this->truncationMessage($operation, $config));
        }

        return [$message->text, $this->priceUsage($message, $operation, $config)];
    }

    /**
     * What to say when a call runs out of output tokens.
     *
     * -----------------------------------------------------------------------
     * THE DEFECT THIS REPLACES
     * -----------------------------------------------------------------------
     *
     * One sentence served all eight operations: *"Raise ANTHROPIC_MAX_TOKENS or
     * lower the per-act word target — a truncated act cannot be salvaged."*
     * Every clause of it was wrong on the stage that actually hit it.
     *
     *   - `generate_outline` HAS NO PER-ACT WORD TARGET. That lever belongs to
     *     the act scripts. An operator following the advice would go looking for
     *     a knob this stage does not have.
     *   - `ANTHROPIC_MAX_TOKENS` IS NOT A VARIABLE THIS APP READS. Every ceiling
     *     is suffixed — `_OUTLINE`, `_ACT_SCRIPT`, `_SCENES`. Setting the name
     *     in the message changes nothing, silently, which is the worse half:
     *     the remedy appears to be applied and the next run fails identically.
     *   - "a truncated ACT" is the wrong noun on six of the eight operations.
     *
     * **A message that names a remedy the stage does not have is worse than no
     * message.** It is confident, it is specific, and it sends the reader
     * somewhere there is nothing to find — the same family as a checklist item
     * about something that cannot exist, and as `updated_at` standing in for a
     * publication date because it was the right TYPE.
     *
     * -----------------------------------------------------------------------
     * WHY THE REMEDY LIVES IN CONFIG
     * -----------------------------------------------------------------------
     *
     * Beside the `max_tokens` it talks about, so the advice and the number
     * cannot drift apart, and so the env var it names is the one written on the
     * line above it. A `match` here would be a second copy of the roster, keyed
     * the same way, which is how `assets:generate` came to print a `--max-time`
     * that stopped being the sized one.
     *
     * An operation with no remedy configured says so plainly and stops. Inventing
     * a plausible generic one is exactly how the old message read.
     *
     * @param  array{model: string, effort: string|null, max_tokens: int, truncation_remedy?: string}  $config
     */
    private function truncationMessage(string $operation, array $config): string
    {
        $remedy = trim((string) ($config['truncation_remedy'] ?? ''));

        return sprintf(
            '%s hit its %s-token output ceiling on %s and was truncated mid-response. The response '
            .'is not salvageable and the call was billed in full, at the ceiling. %s',
            $operation,
            number_format((int) $config['max_tokens']),
            $config['model'],
            $remedy !== ''
                ? $remedy
                : sprintf(
                    'No truncation_remedy is configured for this operation, so there is no advice '
                    .'here that is known to apply to it — add one beside max_tokens in '
                    .'config/providers.php -> anthropic.operations.%s rather than assuming another '
                    ."stage's lever works on this one.",
                    $operation,
                ),
        );
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
     * means something when scanning the table, and the unit says so. The split
     * lives in `detail`. See CostUnit for the two vintages of token row.
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
            // The unit the quantity has always actually held. It said
            // `OutputTokens` for a phase while carrying the total, which no
            // reader hard-coding "tok" could notice and which
            // `ProviderUsage::summary()` printed verbatim to the operator —
            // "9581 output_tokens" for a call whose output was 3,651.
            unit: CostUnit::TotalTokens,
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
    /**
     * The one place model text enters this application.
     *
     * -----------------------------------------------------------------------
     * THE BOUNDARY, AND WHY IT IS HERE RATHER THAN AT FOUR STAGES
     * -----------------------------------------------------------------------
     *
     * A doubled escape from one act-script call walked through the outline, the
     * Gate 1 page an operator read and approved, six act calls, the scene draft,
     * 250 paid stills, a narration run and an alignment — six stages and $16.64
     * — and was stopped at the render step by `GenerateAssSubtitles::
     * assertPlain()`, a guard about ASS override markup that caught it only
     * because a backslash happens to mean something in both JSON and ASS.
     *
     * The fix for that is not a fifth guard. Four guards at four stages would
     * still leave stage five uncovered, and each one would be a second copy of
     * the same rule — the shape this codebase has paid for repeatedly. Every
     * string the model sends arrives through this method, so this is where it
     * gets cleaned, once.
     *
     * See `ModelText` for what is undone and, more importantly, what is not.
     */
    private function decodeJson(string $content, string $what): array
    {
        // Before the decode, not after: a response that fails to parse is
        // exactly the one worth keeping, and archiving on success only would
        // retain every payload except the interesting ones.
        ResponseArchive::store($what, $content);

        $decoded = json_decode($content, true);

        if (! is_array($decoded)) {
            throw new ScriptWriterException(sprintf(
                'The %s response was not the JSON its schema required: %s',
                $what,
                Str::limit($content, 300)
            ));
        }

        [$decoded, $undoubled] = ModelText::undouble($decoded);

        /*
         * Reported, never silent.
         *
         * The whole finding behind this boundary is that the defect ran for six
         * days without anything saying a word. A boundary that quietly repaired
         * it would mean nobody ever learns the model is doing this, and the next
         * variant — a doubled ampersand, a stray BOM — would arrive with the
         * pipeline looking healthy and this line reading as coverage.
         */
        if ($undoubled > 0) {
            RenderJob::noteOnCurrent(sprintf(
                'Undoubled %d escape(s) the model emitted in the %s response. '
                .'The stored text is correct; the raw payload is archived.',
                $undoubled,
                $what,
            ));

            Log::warning('Model emitted doubled escapes.', [
                'operation' => $what,
                'count' => $undoubled,
            ]);
        }

        return $decoded;
    }
}
