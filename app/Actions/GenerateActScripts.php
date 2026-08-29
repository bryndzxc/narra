<?php

namespace App\Actions;

use App\Contracts\ScriptWriter;
use App\Models\Act;
use App\Models\Story;
use App\Support\LocaleGuard;
use App\Support\Providers\ActOutline;
use App\Support\Providers\ActScriptDraft;
use Closure;
use RuntimeException;

/**
 * Writes every act, in order, each one fed what came before it.
 *
 * **This stage is sequential and must stay sequential.** It is the only one in
 * the whole pipeline that cannot fan out. Act 4 is written knowing what
 * happened in acts 1-3, via a running summary; parallelise it and the model
 * writes five acts that repeat each other, contradict each other, and resolve
 * the same thread twice. That failure is invisible in the database — every act
 * has a script, every word count is right — and only shows up when somebody
 * watches thirty-five minutes of video.
 *
 * So the loop below is a plain foreach, and it is not an oversight.
 *
 * Idempotent by act: an act that already has a script is skipped unless the
 * caller asks for it specifically. Re-running the stage after act 4 failed
 * costs one act, not five, and re-running a completed stage costs nothing —
 * which matters, because every one of these calls bills.
 */
class GenerateActScripts
{
    public function __construct(
        private readonly ScriptWriter $writer,
        private readonly LocaleGuard $locale,
        private readonly RecordProviderCost $costs,
    ) {}

    /**
     * @param  array<int, int>  $only  Act sequences to (re)write. Empty means
     *                                 every act that has no script yet.
     * @param  Closure|null  $progress  fn (Act $act, ?ActScriptDraft $draft, string $note): void
     *                                  Invoked, never Closure::call()'d — rebinding $this would
     *                                  take the callback away from whatever object owns it.
     * @return array<int, ActScriptDraft>
     */
    public function handle(Story $story, array $only = [], ?Closure $progress = null): array
    {
        $acts = $story->acts()->orderBy('sequence')->get();

        if ($acts->isEmpty()) {
            throw new RuntimeException(
                'This story has no acts. The outline is generated first and approved at Gate 1 — the '
                .'act scripts are written against it and cannot be written without it.'
            );
        }

        $outline = $acts->map(fn (Act $act): ActOutline => new ActOutline(
            sequence: $act->sequence,
            title: (string) $act->title,
            summary: (string) $act->summary,
        ))->all();

        $targetWords = $this->targetWordsPerAct($story, $acts->count());

        $priorSummaries = [];
        $drafts = [];

        foreach ($acts as $index => $act) {
            $wanted = $only === [] ? $act->script === null : in_array($act->sequence, $only, true);

            if (! $wanted) {
                // Skipped, but its summary still has to feed the acts after it.
                // Falling back to the outline summary keeps a partial re-run
                // coherent: the next act gets the best account of this one that
                // exists rather than a hole in the running context.
                $priorSummaries[] = $this->summaryFor($act);
                $progress?->__invoke($act, null, 'kept existing script');

                continue;
            }

            $draft = $this->writer->actScript(
                story: $story,
                act: $outline[$index],
                fullOutline: $outline,
                // The whole reason this loop is serial. Every act already
                // written, in order, as this call's memory.
                priorSummaries: $priorSummaries,
                targetWords: $targetWords,
            );

            // Billed before validation, because it was billed before validation.
            $this->costs->handle($story, $draft->usage);

            // Loudly, and here rather than at Gate 1. An operator reading 7,000
            // words will not reliably catch one "sari-sari store"; a US viewer
            // will catch it immediately.
            $this->locale->assert(
                $draft->proseForInspection(),
                (string) $story->locale_profile,
                "act {$act->sequence} generation"
            );

            $act->update([
                'script' => $draft->script,
                // The outline summary is replaced by what was actually written.
                // They diverge — the act is written from the outline but does
                // not always land exactly on it — and the next act needs the
                // truth, not the plan.
                'summary' => $draft->summary !== '' ? $draft->summary : $act->summary,
                'is_rehook_written' => trim($draft->rehookLine) !== '',
            ]);

            $priorSummaries[] = $this->summaryFor($act->refresh());
            $drafts[] = $draft;

            $progress?->__invoke($act, $draft, sprintf('%s words', number_format($draft->wordCount())));
        }

        return $drafts;
    }

    /**
     * Ambiguous locale terms across every act, for Gate 1 to display.
     *
     * Separate from the hard check because these are things like "mum" and
     * "flat" — wrong for a US audience often enough to surface, ambiguous
     * enough that failing a job over them would teach an operator to switch the
     * guard off.
     *
     * @return array<int, array{act: int, term: string, context: string}>
     */
    public function localeWarnings(Story $story): array
    {
        $warnings = [];

        foreach ($story->acts()->orderBy('sequence')->get() as $act) {
            foreach ($this->locale->warnings((string) $act->script, (string) $story->locale_profile) as $hit) {
                $warnings[] = ['act' => $act->sequence, ...$hit];
            }
        }

        return $warnings;
    }

    /**
     * The per-act share of the story's word budget.
     *
     * Derived from the story's own target runtime, not a fixed word count.
     * Runtime is the product in this format; word count is a proxy for it. A
     * 35-minute midpoint at the configured 160 wpm asks 5,600 words, split
     * across the acts. Acts land within ~15% of their share, which is close
     * enough that the total lands in both the runtime window and the
     * 5,500-8,000 word band. See config/render.php -> narration.
     */
    private function targetWordsPerAct(Story $story, int $actCount): int
    {
        $midpointMinutes = ($story->target_duration_min + $story->target_duration_max) / 2;

        $wpm = (int) config('render.narration.words_per_minute');

        return (int) round($midpointMinutes * $wpm / max(1, $actCount));
    }

    private function summaryFor(Act $act): string
    {
        return trim((string) ($act->summary ?: $act->title));
    }
}
