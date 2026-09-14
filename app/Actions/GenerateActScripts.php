<?php

namespace App\Actions;

use App\Contracts\ScriptWriter;
use App\Enums\RenderStage;
use App\Models\Act;
use App\Models\Chapter;
use App\Models\RenderJob;
use App\Models\Story;
use App\Support\LocaleGuard;
use App\Support\Providers\ActOutline;
use App\Support\Providers\ActScriptDraft;
use App\Support\Providers\ChapterDraft;
use App\Support\Providers\ScriptWriterException;
use App\Support\ScriptSizing;
use App\Support\SentenceSplitter;
use App\Support\TextBounds;
use Closure;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
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
        // The same splitter DraftScenes cuts scenes with, so a chapter's
        // first_sentence and a scene's sentence range are the same unit by
        // construction rather than by two parsers agreeing.
        private readonly SentenceSplitter $splitter,
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

        // The stage this row matters most for. It is the longest text stage —
        // six sequential Opus calls, minutes each — the only one that cannot
        // fan out, and the one that fails halfway: acts 1-3 written and billed,
        // act 4 dead, and until now nothing on the progress page to say so.
        // Each act writes its own note as it lands, so the row shows how far it
        // got rather than only that it stopped.
        return RenderJob::record(
            $story->id,
            RenderStage::ActScripts,
            fn (RenderJob $job): array => $this->writeActs($story, $acts, $only, $progress, $job),
        );
    }

    /**
     * @param  Collection<int, Act>  $acts
     * @param  array<int, int>  $only
     * @return array<int, ActScriptDraft>
     */
    private function writeActs(
        Story $story,
        Collection $acts,
        array $only,
        ?Closure $progress,
        RenderJob $job,
    ): array {
        $outline = $acts->map(fn (Act $act): ActOutline => new ActOutline(
            sequence: $act->sequence,
            title: (string) $act->title,
            summary: (string) $act->summary,
            // Both of these were dropped here, and the prompt has been asking
            // for them the whole time. `actPrompt()` prints "COSTS: %s" for
            // every act and "What this act must cost the narrator: %s" for the
            // one being written; with the beat left at its default both lines
            // rendered empty, so the field required at outline, checked at
            // Gate 1 and surfaced on the page never reached the generator that
            // needed it. The phase is new and would have gone the same way.
            escalationBeat: (string) $act->escalation_beat,
            phase: $act->phase,
            // Wired to this consumer in the same change that added it, and
            // recorded by the fake, because the two fields above were each
            // found missing here a phase after they were added.
            timeframe: $act->timeframe,
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
                $job->note(sprintf('Act %d of %d — kept existing script.', $act->sequence, $acts->count()));

                continue;
            }

            // Before the call, not after. A note written only on success says
            // nothing about the act that was in flight when the stage died,
            // which is the exact act somebody will want named.
            $job->note(sprintf('Act %d of %d — writing...', $act->sequence, $acts->count()));

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

            // The writer's summary REPLACES the outline's below, and Gate 1's
            // form validates whatever is stored. Story 28's act 4 came back at
            // 2,026 characters against a form rule of 2,000 and the gate could
            // not be approved — text the operator never typed, refused by a
            // rule sized for a different stage. The bound is one constant now
            // (Act::SUMMARY_MAX_CHARS), stated in the prompt, and enforced HERE
            // rather than at the schema, because structured outputs do not
            // honour maxLength and the outline's act count is enforced the same
            // way for the same reason. After the cost row, like the act count:
            // a refusal is loud and the ledger still says what it cost.
            if ($over = Act::textOverflows(['summary' => $draft->summary])) {
                throw new ScriptWriterException(sprintf(
                    'Act %d came back with a %s. The call was billed and the act is NOT stored; '
                    .'re-run this act (story:write --acts-only=%d). The prompt states the bound, so '
                    .'a writer exceeding it is new information about the writer, not a reason to '
                    .'raise the bound.',
                    $act->sequence,
                    implode(', ', $over),
                    $act->sequence,
                ));
            }

            // The chapter shape, the same way and for the same reason: stated
            // in the prompt, unenforceable at the schema, checked after the
            // cost row. The boundaries are computed here too, because a
            // chapter whose text does not end on a sentence would merge into
            // the next when the texts are joined, and the only place that can
            // be seen is beside the splitter that will cut the scenes.
            $boundaries = $this->chapterBoundaries($act, $draft);

            // Loudly, and here rather than at Gate 1. An operator reading 7,000
            // words will not reliably catch one "sari-sari store"; a US viewer
            // will catch it immediately.
            $this->locale->assert(
                $draft->proseForInspection(),
                (string) $story->locale_profile,
                "act {$act->sequence} generation"
            );

            DB::transaction(function () use ($act, $draft, $story, $boundaries): void {
                $act->update([
                    'script' => $draft->script,
                    // The outline summary is replaced by what was actually written.
                    // They diverge — the act is written from the outline but does
                    // not always land exactly on it — and the next act needs the
                    // truth, not the plan.
                    'summary' => $draft->summary !== '' ? $draft->summary : $act->summary,
                    'is_rehook_written' => trim($draft->rehookLine) !== '',
                ]);

                // Replaced whole. A rewritten act is a new set of boundaries,
                // and the scenes pointing at the old ones are set null by the
                // foreign key — they are about to be re-drafted anyway.
                $act->chapters()->delete();

                foreach ($draft->chapters as $index => $chapter) {
                    Chapter::create([
                        'story_id' => $story->id,
                        'act_id' => $act->id,
                        'sequence' => $index + 1,
                        'title' => $chapter->title,
                        'rehook_line' => trim($chapter->rehookLine) !== '' ? $chapter->rehookLine : null,
                        'first_sentence' => $boundaries[$index],
                    ]);
                }
            });

            $priorSummaries[] = $this->summaryFor($act->refresh());
            $drafts[] = $draft;

            $progress?->__invoke($act, $draft, sprintf('%s words', number_format($draft->wordCount())));

            $job->note(sprintf(
                'Act %d of %d — %s words in %d chapters%s%s.',
                $act->sequence,
                $acts->count(),
                number_format($draft->wordCount()),
                count($draft->chapters),
                trim($draft->rehookLine) !== '' ? ', rehook written' : ', NO REHOOK',
                $this->chaptersWithoutRehook($draft) === []
                    ? ''
                    : ', NO RE-HOOK on chapter '.implode(', ', $this->chaptersWithoutRehook($draft)),
            ));
        }

        return $drafts;
    }

    /**
     * Where each chapter starts in the joined script, as 1-indexed sentence
     * offsets, after refusing the shapes the prompt states are not allowed.
     *
     * Every refusal here is thrown AFTER the cost row — the call was billed
     * whatever it returned — and stores nothing, the way the summary bound
     * above does. The prompt states every one of these bounds, so a writer
     * exceeding one is new information about the writer, not a reason to
     * move the bound.
     *
     * @return array<int, int>  chapter index => first sentence
     */
    private function chapterBoundaries(Act $act, ActScriptDraft $draft): array
    {
        $count = count($draft->chapters);
        $min = (int) config('chapters.min_per_act', 2);
        $max = (int) config('chapters.max_per_act', 4);
        $minWords = (int) config('chapters.min_words', 150);

        $refuse = function (string $what) use ($act): never {
            throw new ScriptWriterException(sprintf(
                'Act %d came back with %s. The call was billed and the act is NOT stored; re-run '
                .'this act (story:write --acts-only=%d). The prompt states the bound, so a writer '
                .'exceeding it is new information about the writer, not a reason to move the bound.',
                $act->sequence,
                $what,
                $act->sequence,
            ));
        };

        if ($count < $min || $count > $max) {
            $refuse(sprintf('%d chapter(s) against a bound of %d-%d per act', $count, $min, $max));
        }

        $boundaries = [];
        $cursor = 1;

        foreach ($draft->chapters as $index => $chapter) {
            if ($chapter->title === '') {
                $refuse(sprintf('no title on chapter %d', $index + 1));
            }

            if ($over = TextBounds::overflows(['title' => Chapter::TITLE_MAX_CHARS], ['title' => $chapter->title])) {
                $refuse(sprintf('chapter %d whose %s', $index + 1, implode(', ', $over)));
            }

            if ($chapter->wordCount() < $minWords) {
                $refuse(sprintf(
                    'chapter %d at %d words against a floor of %d',
                    $index + 1,
                    $chapter->wordCount(),
                    $minWords,
                ));
            }

            $boundaries[$index] = $cursor;
            $cursor += count($this->splitter->split($chapter->text));
        }

        // The boundaries are offsets into the JOINED script, and they are only
        // right if joining did not merge a sentence across a chapter edge.
        // Splitting the chapters one at a time and splitting their join must
        // count the same sentences; if they do not, some chapter's last
        // sentence has no terminator and its boundary would land one sentence
        // late in every consumer downstream.
        if ($cursor - 1 !== count($this->splitter->split($draft->script))) {
            $refuse(sprintf(
                'chapter texts that do not join cleanly (%d sentences chapter by chapter, %d joined); a '
                .'chapter did not end on a complete sentence',
                $cursor - 1,
                count($this->splitter->split($draft->script)),
            ));
        }

        return $boundaries;
    }

    /**
     * @return array<int, int>  1-based chapter numbers with no opening line
     */
    private function chaptersWithoutRehook(ActScriptDraft $draft): array
    {
        $missing = [];

        foreach ($draft->chapters as $index => $chapter) {
            if (trim($chapter->rehookLine) === '') {
                $missing[] = $index + 1;
            }
        }

        return $missing;
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
     * The per-act share of the story's word budget, and the rate it is frozen
     * against.
     *
     * Both moved to `ScriptSizing`, which is now the only thing in the app that
     * answers "how many words is this script". Four places used to derive
     * something from the raw constant — this, the dispatch estimate, two prompt
     * figures in ClaudeScriptWriter and `story:write`'s reported runtime — and a
     * constant corrected in one of four places is the shape that gave one
     * narration three different prices.
     *
     * The freeze happens HERE rather than in the getter, because this is the
     * moment the budget becomes binding on a script somebody is about to be
     * billed for. Everything else asks the same question without writing.
     */
    private function targetWordsPerAct(Story $story, int $actCount): int
    {
        ScriptSizing::freezeFor($story);

        return ScriptSizing::targetWordsPerAct($story, $actCount);
    }

    private function summaryFor(Act $act): string
    {
        return trim((string) ($act->summary ?: $act->title));
    }
}
