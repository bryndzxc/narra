<?php

namespace App\Actions;

use App\Contracts\ScriptWriter;
use App\Enums\ActPhase;
use App\Enums\RenderStage;
use App\Enums\StoryFormat;
use App\Enums\StoryStatus;
use App\Models\Act;
use App\Models\RenderJob;
use App\Models\Story;
use App\Support\LocaleGuard;
use App\Support\Providers\OutlineDraft;
use App\Support\Providers\ScriptWriterException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Premise -> act outline. The first paid call, and Gate 1's subject.
 *
 * Writes `acts` rows and moves the story to `outlined`, where it waits for an
 * operator. Nothing further runs until they approve, which is the point: the
 * outline is what the entire 7,000-word script is generated against, so an act
 * structure that is wrong here is wrong in every act that follows it.
 *
 * Three things happen in a fixed order, and the order is the design:
 *
 *   1. generate    — one call, priced by the provider
 *   2. cost        — recorded before anything else can fail, because the money
 *                    was spent whether or not the rest succeeds
 *   3. locale check — fails the stage loudly BEFORE the acts are written, so a
 *                    leaked idiom never reaches Gate 1 at all
 *
 * A locale failure after step 2 still leaves the cost row, which is correct.
 * The tokens were burned; a cost table that only records successful calls
 * cannot answer what a video actually cost.
 */
class GenerateOutline
{
    /**
     * Six for a single narrative, and all three reversal phases still fit.
     *
     * ---------------------------------------------------------------------
     * IT WAS SEVEN, AND BOTH REASONS BELONG ON THE RECORD
     * ---------------------------------------------------------------------
     *
     * The move from six to seven was made FOR THE REVERSAL PHASE, and that
     * reasoning was right: the arc had been escalation -> exposure -> end, and
     * the departure, the search and the refusal needed somewhere to go.
     *
     * What nobody had measured at the time is how long an act actually comes
     * back. It is ~1,100 words almost regardless of what the prompt asks for —
     * story 21 was asked for 800 and wrote 1,152; a probe was asked for 985 and
     * wrote 1,123; the fitted slope across five observations is **+0.30**, so a
     * hundred more words asked buys about thirty. **The word target is
     * advisory. The act count is not.** Seven acts of natural length is ~7,900
     * words, which is 39.9 minutes against a 30-40 window: inside it by seconds,
     * at the ceiling rather than the midpoint, and one long act puts it over.
     *
     * ---------------------------------------------------------------------
     * WHAT SIX COSTS, EXACTLY
     * ---------------------------------------------------------------------
     *
     * NOT a reversal phase. `ActPhase::departureActFor()` clamps the departure
     * to `$actCount - 2`, so there are always two acts behind it — the search
     * and the refusal — at every count. Six gives escalation 1-3, departure 4,
     * search 5, refusal 6. All three reversal phases survive intact.
     *
     * What it costs is **one escalation act, four down to three**: 25% of the
     * escalation, not a missing phase. The humiliation compounds over three
     * beats before the departure instead of four.
     *
     * ---------------------------------------------------------------------
     * WHY THIS SIDE OF THE WINDOW
     * ---------------------------------------------------------------------
     *
     * Six and seven both put three of five measured stories inside the window.
     * They fail on OPPOSITE sides: six lands two under the floor, seven lands
     * two over the ceiling. This file's own position decides it — the floor is
     * a preference and the 8-minute mid-roll threshold is the only law, and the
     * reference channels in this niche run 44 and 54 minutes. Over the ceiling
     * costs nothing measurable; under the floor costs ad density.
     *
     * At six, an act of natural length gives 6,738 words and 34.2 minutes.
     */
    public const DEFAULT_ACTS_SINGLE = 6;

    /**
     * Five for an anthology, which fights the genre: escalation cannot compound
     * across five self-contained stories, and none of them has room for a
     * departure and a search on top of its own escalation. Supported because
     * the operator may choose it; not the default, and Gate 1 says so.
     */
    public const DEFAULT_ACTS_ANTHOLOGY = 5;

    /**
     * The act count a story gets when nobody names one.
     *
     * A method rather than a number written wherever it is needed. Gate 1's
     * pre-spend estimate was a hand-written `6` beside this one's `6`, agreeing
     * only for as long as nobody changed either — which is the shape this
     * project has been bitten by more than once.
     *
     * **And it had been bitten again, twice, before this was checked.** Both
     * remaining copies were on money screens and both UNDERSTATED the bill for
     * a single story, which is the expensive direction:
     *
     *   NewStory::estimate()        said 6 acts / 7 calls · the run made 8
     *   StoryWrite::confirmSpend()  said 5 acts / 6 calls · the run made 8
     *
     * Both agreed with this method for an anthology and disagreed for a single,
     * which is why nobody noticed: the shape that is wrong only on one branch
     * is the shape that survives a spot-check. Both read this now.
     */
    public static function defaultActCountFor(Story $story): int
    {
        return self::defaultActCountForFormat($story->format);
    }

    /**
     * The same answer for a story that does not exist yet.
     *
     * The new-story form is estimating a spend before there is a row to ask
     * about, and the alternative to this overload is the form keeping its own
     * copy of the number — which is exactly what it was doing, and exactly what
     * was wrong with it.
     */
    public static function defaultActCountForFormat(StoryFormat $format): int
    {
        return $format === StoryFormat::Anthology
            ? self::DEFAULT_ACTS_ANTHOLOGY
            : self::DEFAULT_ACTS_SINGLE;
    }

    public function __construct(
        private readonly ScriptWriter $writer,
        private readonly LocaleGuard $locale,
        private readonly RecordProviderCost $costs,
    ) {}

    /**
     * @param  int|null  $actCount  Defaults per format. See the constants above.
     */
    public function handle(Story $story, ?int $actCount = null): OutlineDraft
    {
        $this->assertReady($story);

        $actCount ??= self::defaultActCountFor($story);

        // Wrapped in a render_jobs row even though this runs synchronously in
        // the console. Without one, an outline that failed left the progress
        // page identical to a story nobody had started — and this stage bills an
        // Opus call, so "did it run" is a question with money behind it.
        return RenderJob::record(
            $story->id,
            RenderStage::Outline,
            fn (RenderJob $job): OutlineDraft => $this->generate($story, $actCount, $job),
        );
    }

    private function generate(Story $story, int $actCount, RenderJob $job): OutlineDraft
    {
        $job->note(sprintf(
            'Asking for %d acts on %s.%s',
            $actCount,
            $story->format->value,
            $story->format === StoryFormat::Anthology
                ? ''
                : ' Departure in act '.ActPhase::departureActFor($actCount).'.',
        ));

        $draft = $this->writer->outline($story, $actCount);

        // Before the locale check, deliberately. The call has already been
        // billed and a cost table that drops the rows for failed stages cannot
        // answer what a video cost.
        $this->costs->handle($story, $draft->usage);

        // After the cost row, for the same reason the locale check is: the
        // call has already been billed, and this is a check on its OUTPUT.
        // Structured outputs cannot express an exact array length — minItems
        // must be 0 or 1 — so the count is verified rather than constrained,
        // and a provider that threw on it would lose the cost row for a call
        // that cost money.
        if (! $draft->actCountMatches()) {
            throw new ScriptWriterException(sprintf(
                'Asked for %d acts and got %d. The outline is one call — re-run it. The act count '
                .'decides the chapter structure of the finished video, so quietly accepting a '
                .'different number changes the product.',
                $draft->requestedActCount,
                $draft->actCount()
            ));
        }

        $this->locale->assert(
            $draft->proseForInspection(),
            (string) $story->locale_profile,
            'outline generation'
        );

        DB::transaction(function () use ($story, $draft): void {
            // Re-running replaces the outline rather than appending to it.
            // Nothing downstream exists yet — the story is at `draft` or
            // `outlined`, so no act has a script and no scene references one.
            $story->acts()->delete();

            foreach ($draft->acts as $act) {
                Act::create([
                    'story_id' => $story->id,
                    'sequence' => $act->sequence,
                    // Which of the four phases this act is. Persisted because
                    // the act generator reads it: it decides whether the act
                    // ends worse for the narrator or worse for the antagonist,
                    // and a sequence number cannot say that. Null on an
                    // anthology, where each act runs the whole arc itself.
                    'phase' => $act->phase,
                    'title' => $act->title,
                    'summary' => $act->summary,
                    // What this act costs the narrator. Stored separately from
                    // the summary because a summary can describe a sequence of
                    // events in which nothing gets worse, and that is exactly
                    // the failure this genre dies of.
                    'escalation_beat' => $act->escalationBeat,
                    // No script yet, and no rehook — GenerateActScripts writes
                    // both. Gate 1 surfaces `is_rehook_written` so an act that
                    // never got one is visible rather than merely weak.
                    'script' => null,
                    'is_rehook_written' => false,
                ]);
            }

            // The spine, written before the acts are of any use: every
            // act-generation call reads these four fields off the story, so an
            // outline that produced acts without them would generate five acts
            // with no idea what the grievance was.
            $spine = array_filter($draft->spine(), fn (string $value): bool => trim($value) !== '');

            if ($spine !== [] || (trim($draft->title) !== '' && $story->title !== $draft->title)) {
                $story->update($spine + (
                    trim($draft->title) !== '' ? ['title' => $draft->title] : []
                ));
            }

            if ($story->status === StoryStatus::Draft) {
                $story->transitionTo(StoryStatus::Outlined);
            }
        });

        $job->note(sprintf(
            '%d acts written. Spine: %s. Reversal: %s.',
            $draft->actCount(),
            trim($draft->narratorGrievance) !== '' ? 'recorded' : 'MISSING',
            // Named separately from the rest of the spine because it is the
            // half story 21 shipped without, and "the spine is recorded" was
            // true of that story too.
            trim($draft->departure) !== '' && trim($draft->refusal) !== '' ? 'recorded' : 'MISSING',
        ));

        return $draft;
    }

    /**
     * Re-running is legal but never accidental.
     *
     * Permitted from `draft` (first run) and `outlined` (the operator asked for
     * a different structure at Gate 1). Refused from `scripted` onward, because
     * by then acts carry scripts that were written against this outline and
     * replacing it would orphan every one of them.
     */
    private function assertReady(Story $story): void
    {
        if (in_array($story->status, [StoryStatus::Draft, StoryStatus::Outlined], true)) {
            return;
        }

        throw new RuntimeException(sprintf(
            "Cannot generate an outline for a story at '%s'. The acts already carry scripts written "
            .'against the current outline, and replacing it would orphan all of them. Take the story '
            .'back to Gate 1 first.',
            $story->status->value
        ));
    }
}
