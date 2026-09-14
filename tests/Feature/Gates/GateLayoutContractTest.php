<?php

namespace Tests\Feature\Gates;

use App\Enums\Gate;
use App\Enums\StoryStatus;
use App\Livewire\Gates\MetadataGate;
use App\Livewire\Gates\OutlineGate;
use App\Livewire\Gates\PreviewGate;
use App\Livewire\Gates\ScenesGate;
use App\Models\Act;
use App\Models\Story;
use App\Support\GateVoice;
use Faker\Generator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\PageProbe;
use Tests\TestCase;

/**
 * The three states every gate body has to answer for, written BEFORE the bodies.
 *
 * ---------------------------------------------------------------------------
 * WHY THIS FILE EXISTS AT ALL
 * ---------------------------------------------------------------------------
 *
 * Gate 2 was redesigned three times and each pass shipped a defect the previous
 * pass's tests could not see, because each test was written from the same
 * assumption as the layout it checked:
 *
 *   - built for the busy case, tested on the busy case. A story past the gate
 *     rendered three near-empty panels as islands while the one actionable
 *     thing sat two rows below.
 *   - fixed the ordering, asserted the ordering. The panels then rendered at a
 *     third of the viewport beside a full-width strip, because `.alert` caps its
 *     measure and the only rule lifting that cap was scoped to a container the
 *     new layout deletes.
 *   - checked at the mock's width, on the one story that has every panel filled.
 *
 * A check written alongside a design tests the case the designer had in mind.
 * So for Gates 1, 3 and 4 the three cases are written first and are expected to
 * FAIL until each body is rebuilt. Their failure is the specification.
 *
 * ---------------------------------------------------------------------------
 * THE THREE CASES
 * ---------------------------------------------------------------------------
 *
 *   EMPTY   The gate has no decision to make — passed already, or not reached.
 *           The layout must become a function of that: what is not actionable
 *           collapses, and anything that has actually failed leads.
 *
 *   WIDE    Every alert the layout renders carries its own width. A rule that
 *           lifts the 96ch measure cap must live on the element, never under a
 *           container an arrangement can remove.
 *
 *   ABSENT  Something the page reports on does not exist — no acts, no video,
 *           no metadata. The page must SAY so. An empty region is the failure
 *           this codebase keeps finding: absence read as agreement.
 *
 * Every ordering assertion goes through positionOf(), never strpos(), because
 * `strpos` returns false, false coerces to 0, and an ordering assertion about a
 * missing element passes silently. That was found by drilling, in the test
 * written to prevent exactly it.
 */
class GateLayoutContractTest extends TestCase
{
    use RefreshDatabase;

    // -- Gate 1: outline -----------------------------------------------------

    public function test_gate_one_collapses_when_there_is_no_outline_decision(): void
    {
        $story = $this->story(StoryStatus::Published);

        $html = Livewire::test(OutlineGate::class, ['story' => $story])->html();

        $this->assertStringContainsString(
            'class="strip"',
            $html,
            'Gate 1 past its decision must collapse what is not actionable into a strip, '
            .'the way the dashboard and Gate 2 do, rather than rendering empty decision panels.',
        );
    }

    public function test_gate_one_alerts_carry_their_own_width(): void
    {
        $story = $this->story(StoryStatus::Published);

        $html = Livewire::test(OutlineGate::class, ['story' => $story])->html();

        $this->assertSame(
            [],
            PageProbe::alertsWithoutTheirOwnWidth($html),
            'An alert on Gate 1 renders at the 96ch cap. Give it `wide`, or it will be a third '
            .'of the viewport beside a full-width neighbour the moment its container changes.',
        );
    }

    public function test_gate_one_says_so_when_there_is_no_outline(): void
    {
        $story = $this->story(StoryStatus::Draft);

        $html = Livewire::test(OutlineGate::class, ['story' => $story])->html();

        $this->assertMatchesRegularExpression(
            '/nothing has been written|no acts|not been outlined/i',
            $html,
            'A story with no acts must say the outline is absent. An empty region reads as a page '
            .'that failed to draw, which is absence presented as agreement.',
        );
    }

    /**
     * The three advisory groups lead, and they are a ROW.
     *
     * From the design, not invented here: Gate 1 opens on the spine problems,
     * the locale terms and the structural warnings, side by side, above the
     * premise. Today all three are stacked BELOW the premise panel and under
     * the spine heading, so the reader meets a 4-row textarea before the two
     * things that are cheap to fix at this gate and expensive at Gate 3.
     */
    public function test_gate_one_leads_with_the_advisories_not_the_premise(): void
    {
        $story = $this->storyWithAProblemOutline();

        $html = Livewire::test(OutlineGate::class, ['story' => $story])->html();

        $this->assertLessThan(
            $this->positionOf($html, 'id="premise"'),
            $this->positionOf($html, 'missing part of its structure'),
            'The structural problems must come before the premise, as the design has them.',
        );

        $this->assertStringContainsString(
            'class="gatecols"',
            $html,
            'The three advisory groups are a row in the design, not a stack.',
        );
    }

    /**
     * Premise and cast age are the pair the operator writes together.
     *
     * The design puts them two-up. They are one panel of stacked fields today,
     * which on a wide screen is two short textareas and a column of whitespace.
     */
    public function test_gate_one_pairs_the_premise_with_the_cast_age(): void
    {
        $story = $this->story(StoryStatus::Outlined);

        $html = Livewire::test(OutlineGate::class, ['story' => $story])->html();

        $this->assertStringContainsString(
            'class="twoup"',
            $html,
            'Premise and cast age range are written together and belong side by side.',
        );
    }

    /**
     * Where a string is, asserting that it is there at all.
     *
     * Through PageProbe, which returns null rather than false for an absent
     * needle — `strpos` returns false, PHP coerces false to 0, and an ordering
     * assertion about a missing element passes silently. Drilled in
     * GuardsGoRedTest.
     */
    private function positionOf(string $html, string $needle): int
    {
        $at = PageProbe::offsetOf($html, $needle);

        $this->assertNotNull($at, sprintf('"%s" is not on the page at all.', $needle));

        return (int) $at;
    }

    private function storyWithAProblemOutline(): Story
    {
        $story = Story::factory()->status(StoryStatus::Outlined)->create([
            'slug' => 'gate-one-problems',
            'departure' => null,
            'refusal' => null,
        ]);

        Act::factory()->for($story)->atSequence(1)->create();

        return $story->refresh();
    }

    // -- Gate 3: preview -----------------------------------------------------

    public function test_gate_three_collapses_when_there_is_nothing_to_watch(): void
    {
        $story = $this->story(StoryStatus::ScenesDrafted);

        $html = Livewire::test(PreviewGate::class, ['story' => $story])->html();

        $this->assertStringContainsString(
            'class="strip"',
            $html,
            'Gate 3 before a render has no decision and no video. What is not happening belongs '
            .'in a line, not in panels sized for a finished render.',
        );
    }

    public function test_gate_three_alerts_carry_their_own_width(): void
    {
        $story = $this->story(StoryStatus::Rendered);

        $html = Livewire::test(PreviewGate::class, ['story' => $story])->html();

        $this->assertSame(
            [],
            PageProbe::alertsWithoutTheirOwnWidth($html),
            'An alert on Gate 3 renders at the 96ch cap beside a full-width player.',
        );
    }

    public function test_gate_three_says_so_when_the_video_is_absent(): void
    {
        $story = $this->story(StoryStatus::Rendered);

        $html = Livewire::test(PreviewGate::class, ['story' => $story])->html();

        $this->assertMatchesRegularExpression(
            '/no video|not on disk|render has not/i',
            $html,
            'A story marked rendered whose file is gone must say the file is gone. A missing '
            .'player element is the same defect as a stage that never ran leaving no failure row.',
        );
    }

    // -- Gate 4: metadata ----------------------------------------------------

    public function test_gate_four_collapses_when_the_sheet_is_settled(): void
    {
        $story = $this->story(StoryStatus::Published);

        $html = Livewire::test(MetadataGate::class, ['story' => $story])->html();

        $this->assertStringContainsString(
            'class="strip"',
            $html,
            'A published story has no metadata decision left. The checklist and the pickers must '
            .'not keep the room they need while the decision is live.',
        );
    }

    public function test_gate_four_alerts_carry_their_own_width(): void
    {
        $story = $this->story(StoryStatus::MetadataReady);

        $html = Livewire::test(MetadataGate::class, ['story' => $story])->html();

        $this->assertSame(
            [],
            PageProbe::alertsWithoutTheirOwnWidth($html),
            'An alert on Gate 4 renders at the 96ch cap beside a full-width publish sheet.',
        );
    }

    public function test_gate_four_says_so_when_there_is_no_metadata(): void
    {
        $story = $this->story(StoryStatus::Rendered);

        $html = Livewire::test(MetadataGate::class, ['story' => $story])->html();

        $this->assertMatchesRegularExpression(
            '/no publish sheet|has not been generated|nothing to review/i',
            $html,
            'A story with no youtube_metadata row must say the sheet has not been generated, '
            .'rather than rendering a form with nothing behind it — the Gate 4 defect this '
            .'project already found once.',
        );
    }

    // -- The travelling contract --------------------------------------------

    /**
     * Every gate page, at every status a story can be at.
     *
     * Both assertions below run over this. They are written as one pass over
     * the whole grid rather than as a check on the page that happened to show
     * the defect, because that is the difference the last three passes over
     * these pages keep failing to make: Gate 2 fixed the layout-as-constant
     * defect and Gate 1 shipped it again a session later, in a narrower place
     * the Gate 2 tests could not look at.
     *
     * Gates 3 and 4 are in the table already. Their bodies have not been
     * rebuilt, so anything these find there is one more of the deliberately
     * failing contracts waiting for them — and when either is built it opts in
     * by existing, not by somebody remembering to add it here.
     *
     * @return array<int, array{0: Gate, 1: class-string}>
     */
    public static function everyGatePage(): array
    {
        return [
            'gate 1' => [Gate::Outline, OutlineGate::class],
            'gate 2' => [Gate::Scenes, ScenesGate::class],
            'gate 3' => [Gate::Preview, PreviewGate::class],
            'gate 4' => [Gate::Metadata, MetadataGate::class],
        ];
    }

    /**
     * No advisory or decision row reserves a track for a group with nothing in
     * it.
     *
     * THE DEFECT, GENERALISED. Gate 1's row has three groups — spine problems,
     * locale terms, structural warnings — and the published story had findings
     * in two of them. The third rendered an empty `<div>`, the grid cut it a
     * `1fr` track anyway, and the row opened on a void that pushed the two real
     * groups right.
     *
     * `.dash.quiet` is this defect at the width of a page and Gate 2's quiet
     * state is this defect at the width of a layout. Both were fixed; both were
     * tested by asserting that a WHOLE page is empty, which is exactly why
     * neither test could see one group of three being absent while the row
     * still reserved its track.
     *
     * So this asks the question at the size the defect actually comes in: not
     * "is this page empty" but "does this row hold something that occupies
     * space and says nothing". A row with no groups at all renders no row.
     *
     */
    #[DataProvider('everyGatePage')]
    public function test_no_row_reserves_a_track_for_a_group_with_nothing_in_it(
        Gate $gate,
        string $component,
    ): void {
        foreach (StoryStatus::cases() as $status) {
            $html = Livewire::test($component, ['story' => self::pageFixtureFor($status)])->html();

            $this->assertSame(
                [],
                PageProbe::emptyRowGroups($html),
                sprintf(
                    '%s at "%s" renders a decision-row group with nothing in it. The grid cuts it a '
                    .'track, so it is a void that pushes the groups beside it out of position. '
                    .'x-gate-group renders no element when its slot is empty.',
                    $gate->label(),
                    $status->value,
                ),
            );
        }
    }

    /**
     * No gate page makes a claim its state does not entitle it to.
     *
     * TWO KINDS, AND THE SECOND ONE ARRIVED BY BEING MISSED.
     *
     * AN ACTION CLAIM was what this started as. Gate 1 on the published story
     * said "none of these block approval ... all of them are cheaper to fix here
     * than at Gate 3" and "judging these is yours", one panel below a strip
     * saying the outline is read-only and reopening is not available. Three
     * sentences offering decisions that do not exist. Gate 2 had already fixed
     * one instance of that by hand, by passing its advisory heading in per
     * state; a fix applied at one call site is not a mechanism, and the proof is
     * that Gate 1 was built afterwards and carried two more.
     *
     * A POSITION CLAIM is the kind that then walked past this check. Gate 4's
     * strip said "Gate 4 is behind this story" at eight statuses where the gate
     * is ahead of it, and its disclosure called `draft` terminal. Gate 2 said
     * the same thing at three more. Neither sentence names an action, so every
     * fragment this knew about missed both — and this test runs over four gates
     * at eleven statuses each and was green about it for a whole phase. **It was
     * found by hand, during a hunt for an unrelated predicate.** Nothing that
     * runs could have found it, which is the sharpest version of this project's
     * standing rule: a check that is right about the axis it watches is silent
     * about the one beside it, and silence reads as coverage.
     *
     * Nothing in this method changed to cover it. The claims come from GateVoice
     * rather than from a list written here, so a new KIND of claim arrives the
     * same way a reworded one does. They are FRAGMENTS, so this also catches a
     * claim somebody writes by hand without going through the voice at all —
     * which is the only kind it can catch on a page that has not adopted the
     * mechanism yet, and is how Gate 4's hand-written strip is caught today.
     */
    #[DataProvider('everyGatePage')]
    public function test_no_gate_page_makes_a_claim_it_is_not_entitled_to(
        Gate $gate,
        string $component,
    ): void {
        foreach (StoryStatus::cases() as $status) {
            $voice = GateVoice::for($gate, $status);
            $html = Livewire::test($component, ['story' => self::pageFixtureFor($status)])->html();

            $this->assertSame(
                [],
                PageProbe::claimsNotEntitledTo($html, $voice),
                sprintf(
                    '%s at "%s" claims something its state does not entitle it to. An action clause '
                    .'is a function of whether the action is available here; a position clause is a '
                    .'function of which side of this gate the story is on. Both live in GateVoice, '
                    .'which holds every phrasing.',
                    $gate->label(),
                    $status->value,
                ),
            );
        }
    }

    /**
     * A story furnished enough that every gate has something to say about it.
     *
     * The advisory groups have to be UNEVENLY filled or the row assertion is
     * vacuous: a row whose groups all have findings has no empty track to find,
     * and a row with no findings at all renders no row. This reproduces the live
     * shape — the reversal spine absent, so the structural check warns and does
     * not problem; one act script carrying a term the locale warns on; and
     * nothing at all in the third group.
     */
    public static function pageFixtureFor(StoryStatus $status): Story
    {
        $story = Story::factory()->status($status)->create([
            'slug' => 'gate-grid-'.strtolower($status->value),
            'narrator_grievance' => 'Her mother-in-law took the deed to the house and called it family.',
            'antagonist_justification' => 'She says the house was always meant for her son.',
            'withheld_information' => 'The narrator paid the mortgage from her own salary for nine years.',
            'exposure_moment' => 'The bank statements are read aloud at the anniversary dinner.',
            // The reversal spine, absent — the pre-phase outline both shipped
            // stories have, which the structural check warns about once.
            'departure' => null,
            'reversal_beats' => null,
            'refusal' => null,

            // And outlined before the betrayal scene was asked, which every
            // pre-phase outline was. Without it the field reads MISSING, the
            // spine-problems group fills, and the empty-track contract has no
            // void to find — which is how this was noticed: adding the field
            // turned `test_the_page_fixture_leaves_one_advisory_group_empty`
            // red at all eleven statuses. The fixture had not been given a
            // new state; it had been left describing one that cannot exist.
            'betrayal_scene' => null,
            'outlined_before_betrayal_scene' => true,

            // THE FIXTURE MUST BE ABLE TO EXPRESS "THIS SCRIPT WAS SIZED", or
            // the claim check below cannot see Gate 1's sizing panel at all.
            // Found by drilling a DIFFERENT test: replacing that panel's voice
            // call with a hand-written "the next run fixes this" left this
            // contract GREEN across four gates and eleven statuses, because
            // with a null rate every non-writable status renders the panel's
            // UNKNOWN branch, which emits no clause. The claim could only
            // appear in a state this fixture never produced.
            //
            // The same lesson as the empty escalation_beat below and as
            // `queueDepthIs()` before it: a fixture that cannot describe the
            // failing state makes the suite blind to it however carefully the
            // assertions are written. A measured rate, so the panel renders the
            // branch that actually carries the sentence.
            'voice_id' => 'nPczCjzI2devNBz1zQrb',
            'sized_against_wpm' => 197,
        ]);

        // The act factory takes a unique sequence out of 1-8 before atSequence()
        // overrides it, so eleven statuses' worth of acts exhaust the pool and
        // the fixture dies of the factory's bookkeeping rather than of anything
        // these tests are about.
        app(Generator::class)->unique(reset: true);

        foreach ([1, 2] as $sequence) {
            Act::factory()->for($story)->atSequence($sequence)->create([
                'is_rehook_written' => true,
                // A beat on every act, so the structural check has nothing to
                // PROBLEM about. That is the point of the fixture: the first
                // advisory group must be empty while the other two are not, or
                // the row has no void to find and the assertion is vacuous. It
                // was written without this and it was — the drill that should
                // have failed passed instead.
                'escalation_beat' => 'She loses the last room in the house that was hers.',
                // "flat" is on the en-US warnlist and has a legitimate reading,
                // so it warns at Gate 1 rather than failing the stage.
                'script' => 'I laid the deed out flat on the table so they could both see it.',
            ]);
        }

        return $story->refresh();
    }

    // -- Shared -------------------------------------------------------------

    /**
     * A story with acts, one of which has no re-hook written.
     *
     * THE SECOND ACT IS THE FIXTURE'S WHOLE POINT AND IT WAS NOT THERE.
     *
     * This built ONE act, at sequence 1. `actsMissingRehooks()` filters
     * `sequence > 1`, so the re-hook advisory could not render for any story
     * this method produced — and `pageFixtureFor()` sets `is_rehook_written`
     * true on both of its acts, so the travelling contract could not render it
     * either. **No test in this suite had ever drawn that element**, and it was
     * live on story 22 as an `.alert.warn` with no `wide`, at the 96ch cap
     * beside full-width neighbours.
     *
     * The detector was right. `alertsWithoutTheirOwnWidth` would have reported
     * it on sight; it was never handed a page containing it. That is the same
     * sentence as the null `escalation_beat` that made the empty-track drill
     * vacuous, as the `sized_against_wpm` that hid Gate 1's sizing clause from
     * the claim check, and as `queueDepthIs()` before both — a fixture that
     * cannot describe the failing state makes the suite blind to it however
     * carefully the assertion is written.
     *
     * So act 2 carries the factory default of `false`, deliberately, and the
     * re-hook advisory renders wherever this fixture is used.
     */
    private function story(StoryStatus $status): Story
    {
        $story = Story::factory()->status($status)->create([
            'slug' => 'gate-contract-'.strtolower($status->value),
        ]);

        if ($status !== StoryStatus::Draft) {
            // The factory takes a unique sequence out of 1-8 before
            // atSequence() overrides it, so a run's worth of acts can exhaust
            // the pool and the fixture dies of the factory's bookkeeping
            // rather than of anything these tests are about.
            app(Generator::class)->unique(reset: true);

            Act::factory()->for($story)->atSequence(1)->create();

            // No re-hook, so the advisory exists. Act 1 opens the video and is
            // exempt; only an act after the first can be missing one.
            Act::factory()->for($story)->atSequence(2)->create();
        }

        return $story->refresh();
    }
}
