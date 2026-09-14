<?php

namespace Tests\Feature\Gates;

use App\Enums\ActPhase;
use App\Enums\StoryStatus;
use App\Livewire\Gates\OutlineGate;
use App\Models\Act;
use App\Models\Story;
use Faker\Generator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\PageProbe;
use Tests\TestCase;

/**
 * Gate 1's layout, asserted structurally.
 *
 * ---------------------------------------------------------------------------
 * WHY THIS FILE DID NOT EXIST, AND WHY THAT IS THE FINDING
 * ---------------------------------------------------------------------------
 *
 * Gates 2, 3 and 4 each have one of these. Gate 1 did not — and Gate 1 is the
 * page that drifted furthest from its design, which is not a coincidence and is
 * not really about Gate 1.
 *
 * Its blade was destroyed by a `git checkout --` on uncommitted work and rebuilt
 * from the test suite. Everything the suite pinned came back: eleven statuses,
 * the three-state sizing panel, the GateVoice clauses, quiet-state ordering, the
 * empty-track contract. Everything it did NOT pin came back as whatever the
 * rebuilder happened to write — the spine as one column instead of two, the acts
 * as a stack instead of a grid, the premise and cast age in one panel instead of
 * a pair, no panel header bars, no count badge, no sticky decision, and the
 * re-hook advisory stranded three screens below the fold at the 96ch cap.
 *
 * **The page with the thinnest coverage drifted furthest, and the amount it
 * drifted was exactly the amount nothing was watching.** That is the same
 * finding as every fixture entry in CLAUDE.md, arrived at from the other end: a
 * fixture that cannot express a state makes the suite blind to it, and a page
 * with no layout test has no state expressed at all. A rebuild from the tests is
 * a rebuild to the tests — it can only restore what somebody wrote down.
 *
 * So the assertions below are the DESIGN written down, at the resolution the
 * drift actually came in. They were written against the drifted page and every
 * one of them failed; their failure was the specification, which is the same
 * order GateLayoutContractTest was built in for Gates 1, 3 and 4.
 *
 * ---------------------------------------------------------------------------
 * WHAT THIS FILE DELIBERATELY DOES NOT ASSERT
 * ---------------------------------------------------------------------------
 *
 * The advisory row's track weighting. The design draws the spine-problems column
 * at `1.05fr` against two `1fr`s; `.gatecols` uses uniform auto-columns, and the
 * stylesheet says why in its own comment — weighted tracks were tried and left
 * the panels at their own widths with voids between them at 1750px. That is a
 * measured decision that outranks the mock, the same way the locked banner's
 * gradient does.
 */
class OutlineGateLayoutTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The advisories lead, and the re-hook finding is one of them.
     *
     * D1's lasting assertion. The re-hook advisory was its own `.alert.warn`
     * below the spine panel — three screens down on a seven-act story — and
     * NOTHING IN THE SUITE HAD EVER RENDERED IT: the contract's Gate 1 fixture
     * built one act at sequence 1 and this check exempts act 1, while the
     * travelling fixture wrote a re-hook on both of its acts. It was live on
     * story 22, at the 96ch cap, beside full-width neighbours.
     *
     * It is a bullet in the structural warnings now, which is where the design
     * has it and which also fixes the width by construction — a finding inside
     * a list has no width of its own to get wrong. Prefer an arrangement where
     * the bad outcome is unreachable over a check that it did not happen.
     */
    public function test_the_advisories_lead_and_carry_the_re_hook_finding(): void
    {
        $html = Livewire::test(OutlineGate::class, ['story' => $this->outlinedStory()])->html();

        $premise = $this->positionOf($html, 'id="premise"');

        $this->assertLessThan(
            $premise,
            $this->positionOf($html, 'missing part of its structure'),
            'The structural problems are the first thing on this page in the design.',
        );

        $this->assertLessThan(
            $premise,
            $this->positionOf($html, 'have no re-hook written'),
            'The re-hook finding belongs in the advisory row with the other structural '
            .'warnings, not stranded below the spine where it drew at the 96ch cap.',
        );
    }

    /**
     * It names the acts, not just how many.
     *
     * "2 act(s) have no re-hook written" is a count the operator then has to go
     * and find. The same warning at the same volume, already actionable, is the
     * refusal-answers-act-3 argument one advisory down.
     */
    public function test_the_re_hook_finding_names_the_acts(): void
    {
        $html = Livewire::test(OutlineGate::class, ['story' => $this->outlinedStory()])->html();

        // A LITERAL EM DASH, not `&mdash;`. Blade's `{{ }}` escapes through
        // htmlspecialchars, which leaves a non-ASCII character alone — so the
        // first version of this expected an entity that is never emitted and
        // was red for the wrong reason. A text-matching assertion has to be
        // written against what the renderer actually produces.
        $this->assertMatchesRegularExpression(
            '/2 acts have no re-hook written — acts 3 and 5\./u',
            $html,
            'The finding must name which acts, the way the design does. "2 acts" is a count the '
            .'operator then has to go and find.',
        );
    }

    /**
     * The spine is a grid, not a column.
     *
     * Seven textareas stacked down the left of a 1770px viewport with a column
     * of whitespace beside them, which is the argument `.twoup` already exists
     * for one section up.
     */
    public function test_the_spine_is_two_columns(): void
    {
        $html = Livewire::test(OutlineGate::class, ['story' => $this->outlinedStory()])->html();

        $this->assertGreaterThan(
            0,
            PageProbe::matchCount($html, '.spinegrid'),
            'The seven spine fields are a two-column grid in the design.',
        );
    }

    /**
     * The acts are a grid, not a stack.
     *
     * Seven full-width panels at roughly 400px each is three screens to read an
     * outline the design fits in one and a half — on the page whose one job is
     * a single approve decision about that outline.
     */
    public function test_the_acts_are_a_grid_and_carry_their_phase_on_the_card(): void
    {
        $html = Livewire::test(OutlineGate::class, ['story' => $this->outlinedStory()])->html();

        $this->assertGreaterThan(
            0,
            PageProbe::matchCount($html, '.actgrid'),
            'The act cards are a two-column grid in the design, not a stack of full-width panels.',
        );

        // The phase edge is on the CARD, so the act's direction is legible
        // while scanning rather than only when the badge is read.
        foreach (['.actcard.leaving', '.actcard.turning'] as $selector) {
            $this->assertGreaterThan(
                0,
                PageProbe::matchCount($html, $selector),
                sprintf('Nothing matches "%s", so the phase edge paints nothing.', $selector),
            );
        }
    }

    /**
     * Premise and cast age are a PAIR OF PANELS, not one panel of two fields.
     *
     * `.twoup` was already here and already pinned, holding two `.field`s in a
     * single panel — so the two halves of one decision shared a header, a
     * bottom edge and a setting line, and neither could carry its own note. The
     * design gives each its own panel: the premise's header states the setting
     * it is fixed against, the cast age's states that this is the last free
     * place to say it.
     */
    public function test_the_premise_and_cast_age_are_two_panels_side_by_side(): void
    {
        $html = Livewire::test(OutlineGate::class, ['story' => $this->outlinedStory()])->html();

        $this->assertSame(
            2,
            PageProbe::matchCount($html, '.twoup > .panel'),
            'Premise and cast age are two panels in one .twoup, each with its own header.',
        );
    }

    /**
     * The decision does not sit three screens below the outline it is about.
     *
     * And it is a function of state: a story that cannot be edited has no Save
     * and no Approve, so the bar is not there to be sticky. An empty sticky bar
     * would be `.dash.quiet`'s defect nailed to the bottom of the viewport.
     */
    public function test_the_decision_is_sticky_while_it_exists_and_gone_when_it_does_not(): void
    {
        $editable = Livewire::test(OutlineGate::class, ['story' => $this->outlinedStory()])->html();

        $this->assertGreaterThan(
            0,
            PageProbe::matchCount($editable, '.gatebar'),
            'The Save/Approve decision is a sticky bar in the design, not a row at the '
            .'bottom of a three-screen document.',
        );

        $settled = Livewire::test(OutlineGate::class, ['story' => $this->settledStory()])->html();

        $this->assertSame(
            0,
            PageProbe::matchCount($settled, '.gatebar'),
            'A story past Gate 1 has no Save and no Approve. A sticky bar with nothing in it '
            .'is the empty-container defect, pinned to the bottom of the screen.',
        );
    }

    /**
     * Every scoped class on this page can actually be reached by its rule.
     *
     * The guard that caught `.measure` on Gate 3 in its own first build.
     * class-audit answers these CONTEXT, which is its BENIGN verdict, and it
     * reads the stylesheet and the markup separately — so it cannot see that
     * the ancestor a rule needs is not there. `.warnfill` was "defined" by a
     * grep for a whole phase on exactly that basis.
     */
    public function test_every_scoped_class_reaches_its_rule(): void
    {
        $html = $this->everyStateOfThePage();

        $required = [
            'the advisory head' => '.alerthead h2',
            'the advisory count' => '.alerthead .count',
            'the advisory icon' => '.alerthead .ico',
            'a locale hit row' => '.localehits > .hit',
            'the locale act label' => '.localehits .at',
            'the section description' => '.sectionhead p',
            'a ruled panel header' => '.panelhead.ruled',
            'the gate bar note' => '.gatebar > .note',
        ];

        foreach ($required as $what => $selector) {
            $this->assertGreaterThan(
                0,
                PageProbe::matchCount($html, $selector),
                sprintf(
                    'Nothing on Gate 1 matches "%s", so %s is unstyled. class-audit still '
                    .'calls this CONTEXT — it cannot see that the ancestor is gone.',
                    $selector,
                    $what,
                ),
            );
        }
    }

    /**
     * Every alert, at every status, carries its own width.
     *
     * The travelling version of the check that D1 broke. `GateLayoutContractTest`
     * asks this once, at `published`; the alert that was wrong rendered at
     * `outlined` and at nine other statuses, on a fixture that could not
     * describe it. This asks at all eleven, on a fixture that can.
     */
    public function test_every_alert_carries_its_own_width_at_every_status(): void
    {
        foreach (StoryStatus::cases() as $status) {
            $html = Livewire::test(OutlineGate::class, [
                'story' => $this->outlinedStory($status),
            ])->html();

            $this->assertSame(
                [],
                PageProbe::alertsWithoutTheirOwnWidth($html),
                sprintf(
                    'An alert on Gate 1 at "%s" renders at the 96ch cap. A rule lifting that cap '
                    .'must live on the element, never under a container the layout can remove.',
                    $status->value,
                ),
            );
        }
    }

    /**
     * And no row reserves a track for a group with nothing in it — here, on a
     * fixture that fills the groups UNEVENLY at every status.
     *
     * The contract asks this over four gates on one shared fixture. This one is
     * Gate 1's own, and its groups are deliberately lopsided: spine problems
     * and structural warnings have findings, the locale group has none, so
     * there is a real void to find if `x-gate-group` ever stops eliding.
     */
    public function test_no_advisory_group_occupies_a_track_and_says_nothing(): void
    {
        foreach (StoryStatus::cases() as $status) {
            $html = Livewire::test(OutlineGate::class, [
                'story' => $this->outlinedStory($status, locale: false),
            ])->html();

            $this->assertSame(
                [],
                PageProbe::emptyRowGroups($html),
                sprintf('Gate 1 at "%s" cuts a track for a group with nothing in it.', $status->value),
            );
        }
    }

    // -- Fixtures ------------------------------------------------------------

    /**
     * A seven-act story with findings in two advisory groups and not the third.
     *
     * THE UNEVENNESS IS THE POINT, and it is the lesson `pageFixtureFor()` had
     * to learn twice. A fixture whose groups all have content has no empty
     * track to find; a fixture with no findings renders no row at all. Both
     * make an empty-track assertion vacuously green.
     *
     * The spine is missing its departure and its refusal, so the structural
     * check PROBLEMS. Acts 3 and 5 have no re-hook, so the structural warnings
     * carry the finding D1 was about. Whether the locale group has anything is
     * the caller's choice, so both shapes of the row can be built.
     */
    /**
     * A refused save renders inside the sticky bar, carries its own width, and
     * makes no claim the page is not entitled to.
     *
     * This is the one state of the page no other case in this file can build —
     * every fixture here saves cleanly — which is exactly how the summary's
     * missing `@error` went unrendered by any test for a phase. The state is
     * built on purpose: one act summary one character over the bound.
     *
     * Position is asserted by document order: the refusal must come AFTER the
     * bar opens and BEFORE its Save button, or it is not where the press was.
     */
    public function test_a_refused_save_lands_inside_the_gate_bar_and_passes_the_page_contracts(): void
    {
        $component = Livewire::test(OutlineGate::class, ['story' => $this->outlinedStory()])
            ->set('acts.3.summary', str_repeat('x', Act::SUMMARY_MAX_CHARS + 1))
            ->call('save');

        $html = $component->html();

        $bar = $this->positionOf($html, 'class="gatebar"');
        $refusal = $this->positionOf($html, 'class="alert err wide refused"');
        $save = $this->positionOf($html, 'wire:click="save"');

        $this->assertLessThan($refusal, $bar, 'The refusal must be inside the gate bar, not above it.');
        $this->assertLessThan($save, $refusal, 'The refusal must read before the Save button it is about.');

        $this->assertSame(
            [],
            PageProbe::alertsWithoutTheirOwnWidth($html),
            'The refusal alert must carry `wide` — it sits beside full-width controls.',
        );

        $this->assertSame(
            [],
            PageProbe::claimsNotEntitledTo($html, $component->instance()->voice()),
            'The refusal wording must not trip a GateVoice fragment.',
        );

        // The field-level copy exists too, directly after the textarea it is
        // about — nothing but the closing tag and Livewire's own block markers
        // (`<!--[if BLOCK]><![endif]-->`) may sit between them.
        $this->assertMatchesRegularExpression(
            '/id="act-summary-3"[^>]*>[^<]*<\/textarea>\s*(?:<!--.*?-->\s*)*<div class="error">/',
            $html,
            'The summary textarea must carry its own @error, which it never had.',
        );
    }

    private function outlinedStory(
        StoryStatus $status = StoryStatus::Outlined,
        bool $locale = true,
    ): Story {
        $story = Story::factory()->status($status)->single()->create([
            'slug' => 'gate-one-layout-'.strtolower($status->value).($locale ? '' : '-nolocale'),
            'narrator_grievance' => 'Her brother moves into their mother\'s house nineteen days after the funeral.',
            'antagonist_justification' => 'He was the one who stayed in town, and he has decided that settles it.',
            'withheld_information' => 'The real will is in the kitchen drawer, and he watched her put it there.',
            'exposure_moment' => 'At the estate sale, in front of the neighbours.',
            // Missing, so the spine check PROBLEMS and the first advisory group
            // has content.
            'departure' => null,
            'refusal' => null,
            'voice_id' => 'nPczCjzI2devNBz1zQrb',
            'sized_against_wpm' => 197,
        ]);

        // The factory takes a unique sequence out of 1-8 before atSequence()
        // overrides it, so a run's worth of acts exhausts the pool and the
        // fixture dies of the factory's bookkeeping rather than of anything
        // this file is about.
        app(Generator::class)->unique(reset: true);

        $phases = ActPhase::planFor(7);

        foreach (range(1, 7) as $sequence) {
            // planFor() is keyed by sequence, 1-based, not by offset.
            Act::factory()->for($story)->atSequence($sequence)->inPhase($phases[$sequence])->create([
                // Acts 3 and 5 have no re-hook. Act 1 opens the video and is
                // exempt, so the finding names 3 and 5 and nothing else.
                'is_rehook_written' => ! in_array($sequence, [3, 5], true),
                'escalation_beat' => "Act {$sequence} costs her something she cannot get back.",
                // "flat" is on the en-US warn list and has a legitimate
                // reading, so it warns at Gate 1 rather than failing the stage.
                'script' => $locale
                    ? 'I laid the deed out flat on the table so they could both see it.'
                    : 'I put the deed on the table so they could both see it.',
            ]);
        }

        return $story->refresh();
    }

    /** Past the gate, where there is no decision left to make. */
    private function settledStory(): Story
    {
        return $this->outlinedStory(StoryStatus::Published);
    }

    /**
     * Every state this page has, concatenated, for the reachability sweep.
     *
     * A scoped class only has to be reachable SOMEWHERE on the page, and the
     * states do not all render the same elements — the advisory heads exist
     * wherever there are findings, the ruled panel headers wherever the premise
     * pair renders.
     */
    private function everyStateOfThePage(): string
    {
        $html = '';

        foreach ([StoryStatus::Outlined, StoryStatus::Scripted, StoryStatus::Published] as $status) {
            $html .= Livewire::test(OutlineGate::class, [
                'story' => $this->outlinedStory($status),
            ])->html();
        }

        return $html;
    }

    /**
     * Where a string is, asserting that it is there at all.
     *
     * Through PageProbe, which returns null rather than false — `strpos`
     * returns false, PHP coerces false to 0, and an ordering assertion about a
     * missing element passes silently. Found by drilling, in the test written
     * to prevent exactly it.
     */
    private function positionOf(string $html, string $needle): int
    {
        $at = PageProbe::offsetOf($html, $needle);

        $this->assertNotNull($at, sprintf('"%s" is not on the page at all.', $needle));

        return (int) $at;
    }
}
