<?php

namespace App\Actions;

use App\Contracts\ScriptWriter;
use App\Enums\ActPhase;
use App\Enums\FailureKind;
use App\Enums\RenderStage;
use App\Enums\StoryFormat;
use App\Enums\StoryStatus;
use App\Models\Act;
use App\Models\RenderJob;
use App\Models\Story;
use App\Support\AccompliceArc;
use App\Support\LocaleGuard;
use App\Support\OutlineCast;
use App\Support\PartnerEnding;
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
     * Five for a single narrative, and all three reversal phases still fit.
     *
     * ---------------------------------------------------------------------
     * SIX -> FIVE, AND THE REASON IS THE CHAPTER, NOT THE ACT
     * ---------------------------------------------------------------------
     *
     * Nothing about the act got longer on purpose. The chapter became the
     * unit an act is RETURNED as, and the count of them is now derived by the
     * writer from the length it actually wrote rather than stated to it — so
     * an act comes back as three chapters instead of two, and a chapter costs
     * a spoken number, a re-hook and a boundary. Measured on story 31, the
     * same premise and the same outline as story 30: **1,139 words per act
     * became 1,473, a 29% rise with nothing in the prompt asking for a word
     * more**, and six acts of that is 44.4 minutes against a 30-40 window.
     *
     * **The act count is the right thing to give, and that is a decision
     * about which number was measured.** Three numbers could absorb this: the
     * chapter budget, the word target, or the act count. `chapters.
     * target_seconds` is now 133 because the reference transcript's fourteen
     * boundaries mean a mean of 134 and a median of 133 — it is the one
     * figure here derived from a measurement of the thing itself, so moving
     * it to fix a runtime would be moving a measurement to make an outcome
     * pass. The word target is advisory and steers at +0.30, so it cannot
     * move a runtime at all. The act count multiplies a length the prompt
     * cannot argue with, which is what makes it the lever — the same
     * sentence that moved it from seven to six.
     *
     * Five acts of story 31's measured length is ~7,360 words and **37.0
     * minutes**, in the window and near its middle rather than at an edge.
     *
     * ---------------------------------------------------------------------
     * WHAT FIVE COSTS, EXACTLY — AND WHICH TERM SAVES IT
     * ---------------------------------------------------------------------
     *
     * **NOT a reversal phase. The escalation loses the act again, three down
     * to two.** `ActPhase::planFor(5)` gives escalation 1-2, departure 3,
     * search 4, refusal 5: departure, search and refusal keep one act each,
     * exactly as at six and seven. The reversal now occupies three acts of
     * five.
     *
     * **AND AT FIVE THE `$actCount - 2` CLAMP BINDS FOR THE FIRST TIME.**
     * This is the part that would be easy to miss and it is load-bearing.
     * The two-thirds point of five acts is act 4; unclamped, a departure in
     * act 4 of 5 makes act 5 the refusal and there is NO SEARCH ACT AT ALL —
     * the compressed ending this whole structure exists to replace. The clamp
     * pulls it back to act 3 and the search survives. At six and seven the
     * two-thirds point already lands correctly and the clamp is inert, which
     * is why it has never been the term that decided anything before; it was
     * written as a guarantee and at five it becomes the active one. Touch
     * `departureActFor()` and five acts is the count that breaks first.
     *
     * What is worth naming rather than hiding: the reversal is now 60% of the
     * acts, against 50% at six and 43% at seven, and the genre guidance used
     * to call it "roughly the last third of the runtime". That sentence now
     * says THE LAST THREE ACTS, which is true by construction at five, six
     * and seven and does not drift when the count moves again. Two escalation
     * acts is one rung of the "each act costs more than the last" ladder
     * before the departure, and that is the thinnest this has ever been — the
     * betrayal is in the hook, act 1 escalates, act 2 escalates, act 3
     * leaves. If a story ever reads as leaving too early, this is the number
     * that did it.
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
     * At six, an act of natural length gives 6,738 words and 34.2 minutes --
     * a figure measured when an act came back as TWO chapters, and the reason
     * it no longer holds is under `ScriptSizing::naturalActWords()`.
     */
    public const DEFAULT_ACTS_SINGLE = 5;

    /**
     * Five for an anthology, which fights the genre: escalation cannot compound
     * across five self-contained stories, and none of them has room for a
     * departure and a search on top of its own escalation. Supported because
     * the operator may choose it; not the default, and Gate 1 says so.
     */
    public const DEFAULT_ACTS_ANTHOLOGY = 5;

    /**
     * A re-outline would delete acts that carry scripts. Said here, at the
     * dispatch and at the button, from one copy.
     *
     * The status cannot answer this and never could: `outlined` is BOTH the
     * state a re-outline is for (acts, no scripts — the outline is still a
     * plan) and the ordinary state of a story whose scripts are all written
     * and waiting for approval. `assertReady()` was position-only, which was
     * safe for exactly as long as nothing called this Action on a story with
     * acts — and the re-outline press is that caller. The axis question, asked
     * before the press existed rather than after it cost something.
     */
    public const WRITTEN_ACTS = 'This story already has act scripts written against its outline. Writing '
        .'the outline again deletes every act and replaces it, so those scripts would be orphaned — the '
        .'words are in the prose by now, and an outline is only a plan until they are. Rewrite the acts '
        .'instead, or fork the story if you want the outline changed from here. Nothing was billed.';

    /** Said at the dispatch and here, from one copy. */
    public const NO_ENDING = 'Choose the ending before the outline is written: the narrator\'s new life, '
        .'or the antagonist\'s year in their own voice. The outline writes the fields the chosen ending '
        .'needs, so it cannot be picked afterwards without writing the outline again. Nothing was billed.';

    /**
     * Said at the dispatch, at the button and here, from one copy.
     *
     * Only when the answer was knowable before the call — the cast came WITH
     * the premise and names a future partner. See PartnerEnding::
     * requiredBeforeOutline() for why a typed premise is not asked.
     */
    public const NO_PARTNER_END_STATE = 'This story\'s cast names someone the narrator ends up with, and '
        .'nothing says what they are to each other by the end. Choose it beside the ending: married, '
        .'engaged, living together, or together. Story 39 was written without it and came back "introduces '
        .'me to a room as her partner" against an idea that said married — not a writer ignoring an '
        .'instruction, a writer obeying the only words on offer. Nothing was billed.';

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
     * @param  bool  $keepCast  Whether the cast already on the story is fixed.
     *                          True on every ordinary press. The re-outline
     *                          confirm turns it off, which is the operator
     *                          saying this cast is the thing to repair — the
     *                          one case the old "no acts" scope served, and the
     *                          one it served by also destroying good casts. See
     *                          OutlineCast::chosenBeforeOutline().
     */
    public function handle(Story $story, ?int $actCount = null, bool $keepCast = true): OutlineDraft
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
            fn (RenderJob $job): OutlineDraft => $this->generate($story, $actCount, $job, $keepCast),
        );
    }

    private function generate(Story $story, int $actCount, RenderJob $job, bool $keepCast = true): OutlineDraft
    {
        $job->note(sprintf(
            'Asking for %d acts on %s.%s',
            $actCount,
            $story->format->value,
            $story->format === StoryFormat::Anthology
                ? ''
                : ' Departure in act '.ActPhase::departureActFor($actCount).'.',
        ));

        $draft = $this->writer->outline($story, $actCount, $keepCast);

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
                'Asked for %d acts and got %d. The call was billed and nothing was stored. The act count '
                .'decides the chapter structure of the finished video, so quietly accepting a '
                .'different number changes the product.',
                $draft->requestedActCount,
                $draft->actCount()
            ), kind: FailureKind::OutlineRefused, facts: ['check' => 'act_count']);
        }

        // Same place and same reason as the act count above: a bound the
        // schema cannot carry (structured outputs honour neither minItems nor
        // maxLength), checked against the decoded response after the cost row
        // is written. Gate 1's form validates these three columns and the
        // outline is the first thing that writes them; the act-script stage
        // rewrites `summary` and carries the same check. See Act::textBounds().
        foreach ($draft->acts as $act) {
            $over = Act::textOverflows([
                'title' => $act->title,
                'summary' => $act->summary,
                'escalation_beat' => $act->escalationBeat,
            ]);

            if ($over !== []) {
                throw new ScriptWriterException(sprintf(
                    'Act %d of the outline came back with a %s. The call was billed and nothing was stored.',
                    $act->sequence,
                    implode(' and a ', $over),
                ), kind: FailureKind::OutlineRefused, facts: ['check' => 'act_text_bounds']);
            }
        }

        // The cast, checked where the act count is and for the same reason: a
        // shape the schema cannot carry, verified after the cost row. A cast
        // with no narrator, two antagonists or one name twice cannot be handed
        // to the act writer or the extractor, and repairing it at Gate 1 would
        // come after the acts are written against it.
        $castProblems = OutlineCast::structuralProblems($draft->cast, $story->format);

        if ($castProblems !== []) {
            throw new ScriptWriterException(
                'The outline\'s cast cannot be used: '.implode(' ', $castProblems).' The call was billed and nothing was stored.',
                kind: FailureKind::OutlineRefused,
                facts: ['check' => 'cast_structure'],
            );
        }

        // The cast on the story is handed to the writer as fixed, and this is
        // the check that it came back so. Read from the story BEFORE anything
        // is stored, so it is the chosen cast and not this draft's. A
        // re-outline is held to it too, unless the operator released it on the
        // confirm — which is the whole of `$keepCast`, and the correction to a
        // scope that used to go inert the moment a story had acts. See
        // OutlineCast::chosenBeforeOutline() for story 39, where that inertia
        // bought a swapped partner, and chosenCastChanges() for story 38, where
        // the same swap happened at exactly this step.
        $chosenChanges = OutlineCast::chosenCastChanges(OutlineCast::chosenBeforeOutline($story, $keepCast), $draft->cast);

        if ($chosenChanges !== []) {
            throw new ScriptWriterException(
                'The outline changed the cast picked with the premise: '.implode(' ', $chosenChanges)
                .' The call was billed and nothing was stored.',
                kind: FailureKind::OutlineRefused,
                facts: ['check' => 'chosen_cast'],
            );
        }

        // Names a recent story already used. The prompt listed them as
        // unavailable, so a reuse here is the writer not reading the list, and
        // a viewer hearing the same full name in two videos is the defect this
        // exists for. See OutlineCast for why this checks the outcome rather
        // than removing the locale guidance's example names.
        $reused = OutlineCast::reused($draft->cast, $story);

        if ($reused !== []) {
            throw new ScriptWriterException(sprintf(
                'The outline reused %s from a recent story, after being told %s unavailable. The '
                .'call was billed and nothing was stored.',
                implode(', ', array_map(static fn (array $hit): string => "{$hit['name']} ({$hit['story']})", $reused)),
                count($reused) === 1 ? 'that name was' : 'those names were',
            ), kind: FailureKind::OutlineRefused, facts: ['check' => 'reused_name']);
        }

        // The accomplice's act built on orientation, or on a manner mocked as
        // unmanly. Excluded by the operator (CLAUDE.md 3g); the prompt says so,
        // and this is the invariant. Same place and reason as the checks above:
        // a property of the OUTPUT, verified after the cost row. Gate 1 reports
        // the same list for an edit, so the two cannot disagree.
        $coded = AccompliceArc::codedTerms(array_intersect_key(
            $draft->spine(),
            array_flip(AccompliceArc::FIELDS),
        ));

        if ($coded !== []) {
            throw new ScriptWriterException(sprintf(
                'The outline built the accomplice\'s act on orientation or on a manner mocked as unmanly '
                .'(%s). That is excluded: the device is a harmless ROLE the narrator sees through, and it '
                .'needs none of it. The call was billed and nothing was stored.',
                implode('; ', array_map(
                    static fn (string $field, array $terms): string => $field.': "'.implode('", "', $terms).'"',
                    array_keys($coded),
                    $coded,
                )),
            ), kind: FailureKind::OutlineRefused, facts: ['check' => 'coded_terms']);
        }

        // KEPT AND SHOWN, NOT REFUSED (2026-09-17). The outline is read and
        // edited at Gate 1, so a denied term here costs the operator a glance
        // and a keystroke; refusing it cost the whole call. Gate 1 recomputes
        // the terms from the stored text (GenerateActScripts::localeDenied),
        // so an edit that removes one removes its alert. Named on the job row
        // too, so the stage history says the outline was kept with them.
        $denied = $this->locale->denied($draft->proseForInspection(), (string) $story->locale_profile);

        DB::transaction(function () use ($story, $draft): void {
            /*
             * The guidance this outline was written against, frozen now.
             *
             * Inside the transaction on purpose: a failed outline leaves the
             * story with no acts and will be re-run, so a fingerprint written
             * before the acts landed would attribute a story to guidance that
             * produced nothing.
             *
             * Here rather than at the act or scene call because THIS is where
             * the names, the setting and the spine are decided — every later
             * stage is handed the outline as context and follows it. See
             * `LocaleGuard::fingerprintFor()` for why the column exists.
             */
            $this->locale->freezeFingerprintFor($story);

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
                    // Whether the act is set in the story's present. Declared
                    // by the writer, and Gate 1 refuses an escalation act that
                    // says `prior`: story 28 staged 2015 and 2017 across two of
                    // its three escalation acts, and the outline had said so.
                    'timeframe' => $act->timeframe,
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

            // This outline WAS asked for a betrayal scene, whatever it
            // answered. The flag is a fact about when an outline was written,
            // frozen by the migration that added the field; a regenerated one
            // is not that outline any more, and an empty answer from it is a
            // missing field rather than an unasked one. Not fillable, so
            // forced — the same reason `sized_against_wpm` is.
            if ($story->outlined_before_betrayal_scene) {
                $story->forceFill(['outlined_before_betrayal_scene' => false])->save();
            }

            // The cast replaces whatever was there, and the flag clears for
            // the reason the betrayal flag does: this outline WAS asked.
            $story->forceFill([
                'outline_cast' => $draft->castRows(),
                'outlined_before_cast' => false,
            ])->save();

            // Written whether or not they are empty. The spine above goes
            // through array_filter, which is harmless for fields every outline
            // fills; the accomplice fields are legitimately EMPTY on a story
            // whose cast has no accomplice, and filtering them would leave the
            // previous outline's accomplice standing on a story that no longer
            // has one. Same for the thought, so a regenerated outline never
            // inherits a joke from the one it replaced. The flag clears for the
            // reason the other two do: this outline WAS asked.
            // The antagonist's regret goes the same way and for the same reason:
            // a regenerated outline must not inherit her chapter from the one
            // it replaced, and its age flag clears because this outline was
            // asked.
            // On the narrator's-new-life ending the regret was not asked for,
            // and anything a writer returned anyway is dropped rather than
            // stored: a stored regret is what asks the refusal act for her
            // chapter on a story with no ending, and a field nothing reads is
            // a field somebody later believes. See StoryEnding.
            $spineFields = array_intersect_key($draft->spine(), array_flip([...AccompliceArc::FIELDS, 'running_thought', 'antagonist_regret']));

            if ($story->ending !== null && ! $story->ending->asksForRegret()) {
                $spineFields['antagonist_regret'] = '';
            }

            $story->forceFill(array_map(
                static fn (string $value): ?string => trim($value) === '' ? null : $value,
                $spineFields,
            ) + [
                'outlined_before_accomplice_and_thought' => false,
                'outlined_before_antagonist_regret' => false,
            ])->save();

            if ($story->status === StoryStatus::Draft) {
                $story->transitionTo(StoryStatus::Outlined);
            }
        });

        $job->note(sprintf(
            '%d acts written. Cast: %d named. Spine: %s. Reversal: %s.',
            $draft->actCount(),
            count($draft->cast),
            trim($draft->narratorGrievance) !== '' ? 'recorded' : 'MISSING',
            // Named separately from the rest of the spine because it is the
            // half story 21 shipped without, and "the spine is recorded" was
            // true of that story too.
            trim($draft->departure) !== '' && trim($draft->refusal) !== '' ? 'recorded' : 'MISSING',
        ));

        if ($denied !== []) {
            $job->note(sprintf(
                'Kept with %d term(s) the %s denylist names, for judgement at Gate 1: %s.',
                count($denied),
                $story->locale_profile,
                implode(', ', array_map(static fn (array $hit): string => '"'.$hit['term'].'"', $denied)),
            ));
        }

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
        // The ending is chosen BEFORE the outline, because the outline writes
        // the fields it needs: the regret for hers, and the new life reads the
        // cast. Refused here, before the call, so nothing is billed for an
        // outline that would have to be written again. An anthology has no
        // ending — each act runs the whole arc — and is not asked.
        if ($story->format === StoryFormat::Single && $story->ending === null) {
            throw new RuntimeException(self::NO_ENDING);
        }

        // And what the partner is by the END, wherever the answer exists
        // before the call: a story already carrying a cast that names one,
        // whether it was picked with the premise or written by the outline
        // this one replaces. The outline writes the relationship line and
        // every act summary from it, so it is the ending's own argument, one
        // field over.
        if (PartnerEnding::requiredBeforeOutline($story)) {
            throw new RuntimeException(self::NO_PARTNER_END_STATE);
        }

        // A PRECONDITION, not a position, and the two disagree at exactly one
        // status. `outlined` permits a re-outline because the acts are still a
        // plan; it is also where a story sits with every script written,
        // waiting for Gate 1. Asking the status alone would let a re-outline
        // delete work the operator is about to approve.
        if ($story->hasWrittenActs()) {
            throw new RuntimeException(self::WRITTEN_ACTS);
        }

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
