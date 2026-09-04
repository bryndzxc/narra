<?php

namespace Tests\Feature;

use App\Actions\GenerateActScripts;
use App\Actions\ValidateOutlineSpine;
use App\Enums\Gate;
use App\Enums\StoryStatus;
use App\Support\GateVoice;
use App\Support\SlotContent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Gates\GateLayoutContractTest;
use Tests\Support\PageProbe;
use Tests\TestCase;

/**
 * Every structural guard, pointed at an input whose answer is known.
 *
 * ---------------------------------------------------------------------------
 * WHY THIS FILE EXISTS
 * ---------------------------------------------------------------------------
 *
 * `ToolsAnswerKnownCasesTest` exists because no tool here had ever been run
 * against a known answer, and three defects in three turns came from trusting
 * output that merely looked plausible. **The same was true of the assertions,
 * and it had cost more.** Four self-defeating checks so far, none found on
 * purpose:
 *
 *   1. `strpos` returns false, false coerces to 0, so every ordering assertion
 *      passed for an element that had been DELETED from the page.
 *   2. an ordering test built its story at `scenes_drafted` — the one state
 *      where all three panels have content — and was silent about the state the
 *      page spends most of its life in.
 *   3. `theme-audit --against` resolved the baseline in light and the sheet in
 *      dark: 159 of 343 rules "MOVED" comparing a file to itself.
 *   4. the row assertion's own fixture gave every act a null `escalation_beat`,
 *      so the spine check reported a PROBLEM, so all three advisory groups had
 *      content and there was no void left for it to find. It passed when it was
 *      drilled.
 *
 * Every one of those was GREEN, and every one of them was green about nothing.
 * A guard that cannot be shown to go red is indistinguishable from a guard that
 * passed — which is this file's own rule, applied to the things that enforce it.
 *
 * ---------------------------------------------------------------------------
 * WHAT A CASE HERE HAS TO DO
 * ---------------------------------------------------------------------------
 *
 * Two halves, and the second is the one that catches a rule written backwards:
 *
 *   RED    a known-bad input the guard must report.
 *   GREEN  a known-good input, as close to the bad one as possible, that it
 *          must NOT report.
 *
 * The pairing matters. A rule that reports everything satisfies RED. The first
 * version of blade-php-scan's component-tag rule satisfied RED against the wrong
 * comment entirely — it flagged a blade comment, which is inert, and missed the
 * CSS comment that took the console down.
 *
 * The fixture cases at the bottom are defect 4 above, made checkable: they
 * assert that the shared page fixture actually PRODUCES the failing shape,
 * because a fixture that cannot express the failure makes every assertion built
 * on it vacuous however carefully it is written.
 */
class GuardsGoRedTest extends TestCase
{
    use RefreshDatabase;

    // -- The layout guards ---------------------------------------------------

    /**
     * The empty-track detector.
     *
     * RED is the live Gate 1 shape: three groups, two with findings. GREEN is
     * the same row with the third group ABSENT rather than empty, which is what
     * `x-gate-group` produces — and, separately, a group holding only a
     * Livewire morph marker, which is what a false `@if` leaves behind and what
     * a naive `trim()` predicate would call content.
     */
    public function test_the_empty_track_detector_goes_red(): void
    {
        $withVoid = '<div class="gatecols">'
            .'<div></div>'
            .'<div><div class="alert warn">locale</div></div>'
            .'<div><div class="alert warn">structure</div></div>'
            .'</div>';

        $this->assertNotSame(
            [],
            PageProbe::emptyRowGroups($withVoid),
            'A group that renders no content still takes a grid track. The detector must say so.',
        );

        $withoutVoid = '<div class="gatecols">'
            .'<div><div class="alert warn">locale</div></div>'
            .'<div><div class="alert warn">structure</div></div>'
            .'</div>';

        $this->assertSame([], PageProbe::emptyRowGroups($withoutVoid));
    }

    /** A group holding only Livewire's morph marker is empty, not content. */
    public function test_the_empty_track_detector_is_not_fooled_by_a_morph_marker(): void
    {
        $html = '<div class="gatecols">'
            .'<div><!--[if BLOCK]><![endif]--><!--[if ENDBLOCK]><![endif]--></div>'
            .'<div><div class="alert warn">structure</div></div>'
            .'</div>';

        $this->assertCount(
            1,
            PageProbe::emptyRowGroups($html),
            'A false @if leaves a comment behind. Counting it as content reports a void as filled.',
        );
    }

    /**
     * The measure-cap detector.
     *
     * GREEN includes an uncapped alert INSIDE `.advisories`, which is the
     * deliberate exclusion — that container widens its own children as a grid,
     * and reporting it would make the real finding impossible to see.
     */
    public function test_the_uncapped_alert_detector_goes_red(): void
    {
        $this->assertSame(
            ['alert warn'],
            PageProbe::alertsWithoutTheirOwnWidth('<div class="alert warn">no width of its own</div>'),
        );

        $this->assertSame(
            [],
            PageProbe::alertsWithoutTheirOwnWidth(
                '<div class="alert warn wide">mine</div>'
                .'<div class="advisories"><div class="alert warn">the grid widens me</div></div>',
            ),
        );
    }

    /**
     * The claim detector.
     *
     * RED is a settled page carrying an available-state clause. GREEN is the
     * same page in the state that entitles it to say so — the point being that
     * the sentence is not banned, only the sentence in the wrong state.
     */
    public function test_the_claim_detector_goes_red(): void
    {
        $settled = GateVoice::for(Gate::Outline, StoryStatus::Published);
        $open = GateVoice::for(Gate::Outline, StoryStatus::Outlined);

        $html = '<div class="alert warn">None of these block approval.</div>';

        $this->assertNotSame([], PageProbe::claimsNotEntitledTo($html, $settled));
        $this->assertSame([], PageProbe::claimsNotEntitledTo($html, $open));
    }

    /**
     * And the settled phrasing does not trip its own check.
     *
     * The near-miss that makes this worth asserting: "None of these blocked
     * approval." is one character from containing "block approval". A guard that
     * fired on its own fix could only be made green by weakening it.
     *
     * Over every clause at every gate at every status, because the position
     * clauses have no single settled wording to check — they have three, two of
     * which are claims, and which one is safe depends on the voice.
     */
    public function test_the_claim_detector_passes_every_clause_in_its_own_state(): void
    {
        foreach (Gate::cases() as $gate) {
            foreach (StoryStatus::cases() as $status) {
                $voice = GateVoice::for($gate, $status);

                foreach ($voice->everyClause() as $clause => $text) {
                    $this->assertSame(
                        [],
                        PageProbe::claimsNotEntitledTo('<div>'.$text.'</div>', $voice),
                        sprintf(
                            '%s trips the claim check at gate %d / %s, saying "%s".',
                            $clause,
                            $gate->value,
                            $status->value,
                            $text,
                        ),
                    );
                }
            }
        }
    }

    /**
     * The POSITION half of the same detector, which is the extension.
     *
     * THE DEFECT IT IS FOR. Gate 4's strip said "Gate 4 is behind this story" at
     * eight statuses where the gate is ahead of it, and called `draft` terminal.
     * Gate 2 said the same thing at three more. Neither sentence names an
     * action, so every fragment the claim check knew about missed both, and a
     * contract running over four gates at eleven statuses each was green about
     * it for a phase. It was found by hand during a hunt for an unrelated
     * predicate.
     *
     * Each case is a PAIR, and for position clauses the pairing does more work
     * than it does for an action: an action claim has one wrong state and one
     * right one, while "is behind this story" and "has not been reached" are
     * each wrong in the state where the OTHER is right. A detector that reported
     * whichever fragment it found would satisfy every RED here and be useless.
     */
    public function test_the_position_claim_detector_goes_red(): void
    {
        $behind = '<div class="strip"><strong>Gate 4 is behind this story.</strong></div>';
        $ahead = '<div class="strip"><strong>Gate 4 has not been reached.</strong></div>';
        $terminal = '<div>The story is at <span>draft</span>, which is terminal.</div>';

        $atDraft = GateVoice::for(Gate::Metadata, StoryStatus::Draft);
        $atPublished = GateVoice::for(Gate::Metadata, StoryStatus::Published);
        $parked = GateVoice::for(Gate::Metadata, StoryStatus::MetadataReady);

        // RED — the live defect, in the words it shipped in.
        $this->assertNotSame(
            [],
            PageProbe::claimsNotEntitledTo($behind, $atDraft),
            'A page telling a draft story that Gate 4 is behind it must be reported.',
        );
        // GREEN — the same sentence where it is true.
        $this->assertSame([], PageProbe::claimsNotEntitledTo($behind, $atPublished));

        // RED and GREEN the other way round. Both phrasings are claims, so the
        // detector has to be wrong-in-both-directions rather than one-sided.
        $this->assertNotSame(
            [],
            PageProbe::claimsNotEntitledTo($ahead, $atPublished),
            'A published story has reached Gate 4. Saying otherwise must be reported.',
        );
        $this->assertSame([], PageProbe::claimsNotEntitledTo($ahead, $atDraft));

        // And PARKED at the gate is entitled to neither, which is the state a
        // two-valued position predicate would have had to describe as one of
        // them.
        $this->assertNotSame([], PageProbe::claimsNotEntitledTo($behind, $parked));
        $this->assertNotSame([], PageProbe::claimsNotEntitledTo($ahead, $parked));

        // The status claim, which is about the status rather than the gate: every
        // gate is behind a story at `scripted` and none may call it terminal.
        $this->assertNotSame(
            [],
            PageProbe::claimsNotEntitledTo($terminal, GateVoice::for(Gate::Outline, StoryStatus::Scripted)),
        );
        $this->assertSame([], PageProbe::claimsNotEntitledTo($terminal, $atPublished));
    }

    /**
     * A claim wrapped across a line break is still a claim.
     *
     * WITHOUT THIS THE EXTENSION ABOVE IS DECORATIVE, and it was: Gate 4's blade
     * wrapped its disclosure between "which is" and "terminal", so grepping the
     * rendered page for that fragment returned nothing while the sentence was
     * plainly on screen. The claim check ran on raw HTML, so the one fragment it
     * most needed to find was the one it structurally could not — a check that
     * cannot fire, wearing a newline.
     *
     * The GREEN case is the reason the fix is a whitespace collapse and not a
     * tag strip: a fragment split across an ELEMENT is two pieces of text with
     * markup between them, the words are not adjacent on the page, and matching
     * it would be a false positive that no author could act on.
     */
    public function test_the_claim_detector_sees_a_fragment_a_template_wrapped(): void
    {
        $voice = GateVoice::for(Gate::Metadata, StoryStatus::Draft);

        // RED — the exact shape the live blade had.
        $wrapped = "<div class=\"small muted\">\n"
            ."                    The story is at <span class=\"mono\">draft</span>, which is\n"
            ."                    terminal: the file is on YouTube.\n"
            .'</div>';

        $this->assertNotSame(
            [],
            PageProbe::claimsNotEntitledTo($wrapped, $voice),
            'A fragment the template wrapped is still on the page. A detector a line break defeats '
            .'is indistinguishable from one that passed.',
        );

        // GREEN — split across an element, where the words are genuinely not
        // adjacent. This must NOT be reported.
        $this->assertSame(
            [],
            PageProbe::claimsNotEntitledTo('<div>which is <em>not</em> terminal</div>', $voice),
            'Collapsing whitespace must not collapse markup. These words are not the claim.',
        );
    }

    /**
     * The ordering probe, and the coercion that started this whole class.
     *
     * `strpos` returns false for an absent needle and PHP coerces false to 0, so
     * `assertLessThan($later, $earlier)` passes for an element that is not on
     * the page. `offsetOf()` returns null instead, which cannot be silently
     * compared to an int.
     */
    public function test_the_ordering_probe_reports_absence_rather_than_zero(): void
    {
        $html = '<div>first</div><div>second</div>';

        $this->assertSame(strpos($html, 'first'), PageProbe::offsetOf($html, 'first'));
        $this->assertNull(
            PageProbe::offsetOf($html, 'deleted'),
            'An absent needle must be null. Returning false makes every ordering assertion about a '
            .'missing element vacuously true.',
        );

        // The shape being prevented, stated as an assertion: the vacuous
        // comparison must no longer be expressible with what the probe returns.
        $this->assertFalse(
            is_int(PageProbe::offsetOf($html, 'deleted')),
            'A missing element must not come back as an integer position.',
        );
    }

    /** The slot-emptiness predicate, whose trap is the same morph marker. */
    public function test_the_slot_emptiness_predicate_goes_red(): void
    {
        $this->assertTrue(SlotContent::hasContent('<div class="alert">something</div>'));
        $this->assertFalse(SlotContent::hasContent('   '));
        $this->assertFalse(
            SlotContent::hasContent('<!--[if BLOCK]><![endif]--> <!--[if ENDBLOCK]><![endif]-->'),
            'A slot whose every child was behind a false @if still carries morph markers. Reading '
            .'them as content renders a wrapper around a void — the defect x-gate-group exists to '
            .'make unreachable.',
        );
    }

    // -- The fixture behind the travelling contract --------------------------

    /**
     * The shared page fixture really does produce the shape it is for.
     *
     * DEFECT 4 IN THE HEADER, MADE CHECKABLE. The row assertion needs a row
     * whose groups are UNEVENLY filled: all three filled has no void to find,
     * and none filled renders no row. The first fixture gave every act a null
     * `escalation_beat`, so the spine check reported a problem, so group one had
     * content and the assertion measured nothing. It passed when drilled.
     *
     * Asserting the fixture's discriminating property is the cheapest way to
     * stop that recurring: change a factory default and this goes red here,
     * beside an explanation, rather than quietly making a contract vacuous.
     *
     * @param  StoryStatus  $status  every status, since the contract renders all of them
     */
    #[DataProvider('everyStatus')]
    public function test_the_page_fixture_leaves_one_advisory_group_empty(StoryStatus $status): void
    {
        $story = GateLayoutContractTest::pageFixtureFor($status);

        $review = app(ValidateOutlineSpine::class)->handle($story);

        $this->assertSame(
            [],
            $review['problems'],
            'The fixture must leave the spine-problems group EMPTY. With a finding in it, all three '
            .'groups have content and the empty-track assertion has no void to find.',
        );

        $this->assertNotSame(
            [],
            $review['warnings'],
            'The structural-warnings group must have a finding, or there is no row at all.',
        );

        $this->assertNotSame(
            [],
            app(GenerateActScripts::class)->localeWarnings($story),
            'The locale group must have a finding, or the row has one group and no shape to test.',
        );
    }

    /** @return array<string, array{0: StoryStatus}> */
    public static function everyStatus(): array
    {
        $cases = [];

        foreach (StoryStatus::cases() as $status) {
            $cases[$status->value] = [$status];
        }

        return $cases;
    }
}
