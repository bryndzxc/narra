<?php

namespace App\Services\Claude;

use Anthropic\Client;
use App\Contracts\ScriptWriter;
use App\Enums\CostCategory;
use App\Enums\CostUnit;
use App\Enums\StoryFormat;
use App\Models\Story;
use App\Support\LocaleGuard;
use App\Support\Providers\ActOutline;
use App\Support\Providers\ActScriptDraft;
use App\Support\Providers\OutlineDraft;
use App\Support\Providers\ProviderUsage;
use App\Support\Providers\ScriptWriterException;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The script writer, on Claude.
 *
 * Chunked, and sequential where it has to be. A 30-40 minute video is
 * 5,500-8,000 words of narration and no single call holds that coherently, so
 * the shape is premise -> outline -> per-act, each act given the full outline
 * plus a running summary of the acts already written. `actScript()` is called
 * in order by GenerateActScripts and cannot be fanned out.
 *
 * Three things this class owns that the callers must not:
 *
 *  1. **Its own rate card.** Usage comes back on every response and is priced
 *     here, from config. A call site that priced a response itself would drift
 *     the moment a rate changed.
 *  2. **The locale instruction.** Injected into every call from the story's
 *     locale_profile, as data rather than prose baked into a prompt string.
 *     The denylist check that backs it up runs in the Action, after the call —
 *     a provider that validated its own output would have to decide what to do
 *     about a failure, and that decision is the pipeline's.
 *  3. **Structured output.** The outline comes back as JSON against a schema,
 *     not as prose to be parsed. Parsing "Act 1: ..." out of a paragraph is the
 *     kind of thing that works for a month.
 *
 * Streaming on every call. An act on a thinking model runs long enough to
 * outrun a non-streaming HTTP timeout, and on this platform there is no
 * `queue:work --timeout` to catch it — pcntl does not exist in Windows PHP.
 */
class ClaudeScriptWriter implements ScriptWriter
{
    public function __construct(
        private readonly Client $client,
        private readonly LocaleGuard $locale,
    ) {}

    public function outline(Story $story, int $actCount): OutlineDraft
    {
        if ($actCount < 3) {
            throw new RuntimeException(
                "An outline of {$actCount} acts cannot work: acts become YouTube chapters, and YouTube "
                .'ignores a chapter list shorter than three.'
            );
        }

        [$content, $usage] = $this->call(
            system: $this->outlineSystemPrompt($story),
            userMessage: $this->outlinePrompt($story, $actCount),
            operation: 'generate_outline',
            schema: $this->outlineSchema(),
        );

        $decoded = $this->decodeJson($content, 'outline');

        $acts = [];

        foreach (($decoded['acts'] ?? []) as $index => $act) {
            $acts[] = new ActOutline(
                sequence: $index + 1,
                title: trim((string) ($act['title'] ?? '')),
                summary: trim((string) ($act['summary'] ?? '')),
            );
        }

        if ($acts === []) {
            throw new ScriptWriterException('The outline call returned no acts.');
        }

        // The act count is NOT checked here, deliberately. Structured outputs
        // reject any minItems other than 0 or 1, so an exact length cannot be
        // a schema constraint and has to be verified against the response —
        // but verifying it inside the provider means throwing after the call
        // has already been billed and before its usage has been handed back,
        // which loses the cost row for a call that cost money. GenerateOutline
        // checks it, after recording the cost. See requestedActCount below.

        return new OutlineDraft(
            title: trim((string) ($decoded['title'] ?? $story->title)),
            acts: $acts,
            usage: $usage,
            requestedActCount: $actCount,
        );
    }

    public function actScript(
        Story $story,
        ActOutline $act,
        array $fullOutline,
        array $priorSummaries,
        int $targetWords,
    ): ActScriptDraft {
        [$content, $usage] = $this->call(
            system: $this->actSystemPrompt($story),
            userMessage: $this->actPrompt($story, $act, $fullOutline, $priorSummaries, $targetWords),
            operation: 'generate_act_script',
            schema: $this->actSchema(),
        );

        $decoded = $this->decodeJson($content, "act {$act->sequence}");

        $script = trim((string) ($decoded['script'] ?? ''));

        if ($script === '') {
            throw new ScriptWriterException("Act {$act->sequence} came back with an empty script.");
        }

        return new ActScriptDraft(
            sequence: $act->sequence,
            script: $script,
            summary: trim((string) ($decoded['summary'] ?? '')),
            rehookLine: trim((string) ($decoded['rehook_line'] ?? '')),
            usage: $usage,
        );
    }

    // -- The call ------------------------------------------------------------

    /**
     * One request, priced.
     *
     * @param  array<string, mixed>  $schema
     * @return array{0: string, 1: ProviderUsage}
     */
    private function call(string $system, string $userMessage, string $operation, array $schema): array
    {
        $config = config('providers.anthropic');

        try {
            $stream = $this->client->messages->createStream(
                model: $config['model'],
                maxTokens: (int) $config['max_tokens'],
                system: [
                    // The system prompt is identical across every act of a
                    // story, so it is the cache prefix. At five acts that is
                    // four cache reads at a tenth of the input rate.
                    ['type' => 'text', 'text' => $system, 'cacheControl' => ['type' => 'ephemeral']],
                ],
                messages: [
                    ['role' => 'user', 'content' => $userMessage],
                ],
                outputConfig: [
                    'effort' => $config['effort'],
                    'format' => ['type' => 'json_schema', 'schema' => $schema],
                ],
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
     */
    private function priceUsage(StreamedMessage $message, string $operation, array $config): ProviderUsage
    {
        $rates = $config['pricing'];

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
            // Text, not asset. This spend happens at `draft` through
            // `scripted`, below Gate 2, because it produces the very text the
            // operator reviews at Gate 1. See App\Enums\CostCategory.
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
        );
    }

    // -- Prompts -------------------------------------------------------------

    private function outlineSystemPrompt(Story $story): string
    {
        return implode("\n\n", [
            'You structure long-form narrated stories for a YouTube channel.',
            $this->formatGuidance($story),
            $this->locale->guidanceFor((string) $story->locale_profile),
            $this->lengthGuidance($story),
        ]);
    }

    private function actSystemPrompt(Story $story): string
    {
        return implode("\n\n", [
            <<<'TEXT'
            You write narration for long-form YouTube story videos. The text you produce is
            read aloud by a single narrator over still illustrations. Nobody reads it on a page.

            That means:
            - Write for the ear. Short sentences carry; subordinate clauses do not.
            - No headings, no scene labels, no stage directions, no bracketed notes.
            - No "Act One" or "Chapter" markers in the prose itself.
            - No dialogue attribution pile-ups. "she said" and nothing fancier.
            - Concrete physical detail over interiority. The viewer is looking at a picture.
            TEXT,
            $this->formatGuidance($story),
            $this->locale->guidanceFor((string) $story->locale_profile),
            $this->lengthGuidance($story),
        ]);
    }

    private function formatGuidance(Story $story): string
    {
        return $story->format === StoryFormat::Anthology
            ? <<<'TEXT'
                FORMAT: ANTHOLOGY. Each act is a self-contained story with its own characters,
                its own beginning and its own ending. They share a tone and a theme, nothing
                else — a character from act 2 must not appear in act 4. Each act's title works
                as a YouTube chapter title and as a hook on its own.
                TEXT
            : <<<'TEXT'
                FORMAT: SINGLE NARRATIVE. One continuous story across all acts, with the same
                characters throughout. Each act advances the same arc and ends somewhere the
                next act has to pick up from. Act titles double as YouTube chapter titles, so
                they must not spoil what the act contains.
                TEXT;
    }

    private function lengthGuidance(Story $story): string
    {
        return sprintf(
            'TARGET RUNTIME: %d-%d minutes of narration, which is roughly %s-%s words in total. '
            .'This is a watch-time format: the length is the product, not padding around it. Do not '
            .'rush to an ending, and do not stretch a thin idea to reach a count.',
            $story->target_duration_min,
            $story->target_duration_max,
            number_format($story->target_duration_min * 150),
            number_format($story->target_duration_max * 165),
        );
    }

    private function outlinePrompt(Story $story, int $actCount): string
    {
        // The act count is enumerated rather than stated. "Produce exactly 5
        // acts" was asked twice and came back with 4 both times: a bare count is
        // an instruction a model can satisfy approximately, whereas a numbered
        // list of slots is a shape it has to fill. The exact count matters
        // because each act becomes one YouTube chapter, and it cannot be a
        // schema constraint - structured outputs reject any minItems other
        // than 0 or 1.
        $slots = implode("\n", array_map(
            fn (int $n): string => "  {$n}. <act {$n}>",
            range(1, $actCount)
        ));

        return sprintf(
            "Build an act outline for this story.\n\nPREMISE:\n%s\n\n"
            ."Fill in every one of these %d slots. Return exactly %d objects, in this order:\n\n%s\n\n"
            .'Do not merge slots, do not leave one out, and do not add another. Each slot becomes '
            .'one YouTube chapter of the finished video, so the count is fixed before any of it is '
            ."written.\n\n"
            ."For each act give:\n"
            .'- title: works as a YouTube chapter title. 2-6 words. A hook, not a label. '
            ."No numbering, no colons, no 'Act One'.\n"
            .'- summary: 3-5 sentences. What actually happens, concretely — who, where, what '
            .'changes. Not a teaser and not a theme statement. The act script is written from '
            ."this and from nothing else, so anything left vague here gets invented later.\n\n"
            .'Also give a title for the whole story: under 70 characters, emotional hook on the left.',
            trim((string) $story->premise),
            $actCount,
            $actCount,
            $slots,
        );
    }

    /**
     * @param  array<int, ActOutline>  $fullOutline
     * @param  array<int, string>  $priorSummaries
     */
    private function actPrompt(
        Story $story,
        ActOutline $act,
        array $fullOutline,
        array $priorSummaries,
        int $targetWords,
    ): string {
        $outlineBlock = implode("\n", array_map(
            fn (ActOutline $entry): string => sprintf(
                '%d. %s — %s%s',
                $entry->sequence,
                $entry->title,
                $entry->summary,
                $entry->sequence === $act->sequence ? '   <-- WRITE THIS ONE' : ''
            ),
            $fullOutline
        ));

        // The running summary is what makes chunked generation coherent. Act 4
        // without it repeats act 2 and contradicts act 3.
        $priorBlock = $priorSummaries === []
            ? 'This is the first act. Nothing has been written yet.'
            : implode("\n\n", array_map(
                fn (string $summary, int $index): string => sprintf('Act %d: %s', $index + 1, $summary),
                $priorSummaries,
                array_keys($priorSummaries)
            ));

        $rehook = $act->sequence === 1
            ? 'This act opens the video. Its first two sentences are the 15-second hook — the single '
                .'highest-leverage text in the whole script. Open on a concrete image or an unanswered '
                .'question, never on scene-setting.'
            : sprintf(
                'This act opens at roughly minute %d, where viewers leave. Its first two sentences are '
                .'a re-hook: they must give someone who was about to close the tab a reason not to. Do '
                .'not open by recapping act %d.',
                (int) round(($act->sequence - 1) * $targetWords / 150),
                $act->sequence - 1,
            );

        return implode("\n\n", [
            'FULL OUTLINE (for context — write only the marked act):',
            $outlineBlock,
            'WHAT HAS ALREADY BEEN WRITTEN:',
            $priorBlock,
            sprintf('NOW WRITE ACT %d: %s', $act->sequence, $act->title),
            $act->summary,
            $rehook,
            sprintf(
                "Target %s words of narration. Return:\n"
                ."- script: the narration itself, as continuous prose.\n"
                ."- summary: 3-5 sentences on what happened in it, written for the next act's "
                .'writer — names, what changed, where things stand. This is the only thing the '
                ."next call will know about this act.\n"
                .'- rehook_line: the opening line you actually used, quoted back.',
                number_format($targetWords),
            ),
        ]);
    }

    // -- Schemas -------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function outlineSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'title' => ['type' => 'string'],
                'acts' => [
                    'type' => 'array',
                    // No minItems/maxItems: structured outputs reject any
                    // minItems other than 0 or 1, so the exact count is
                    // enforced against the decoded response instead. The
                    // prompt still asks for it explicitly.
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'title' => ['type' => 'string'],
                            'summary' => ['type' => 'string'],
                        ],
                        'required' => ['title', 'summary'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => ['title', 'acts'],
            'additionalProperties' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function actSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'script' => ['type' => 'string'],
                'summary' => ['type' => 'string'],
                'rehook_line' => ['type' => 'string'],
            ],
            'required' => ['script', 'summary', 'rehook_line'],
            'additionalProperties' => false,
        ];
    }

    /**
     * @return array<string, mixed>
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
