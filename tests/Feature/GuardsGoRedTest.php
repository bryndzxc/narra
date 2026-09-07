<?php

namespace Tests\Feature;

use App\Actions\GenerateActScripts;
use App\Actions\ValidateOutlineSpine;
use App\Actions\DraftScenes;
use App\Actions\ValidateSceneDrafts;
use App\Contracts\ScriptWriter;
use App\Enums\ActPhase;
use App\Enums\Gate;
use App\Enums\StoryStatus;
use App\Models\Act;
use App\Models\Character;
use App\Models\Scene;
use App\Models\Story;
use App\Support\GateVoice;
use App\Support\ImagePromptBuilder;
use App\Support\SlotContent;
use Faker\Generator;
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

    // -- The genre guards ----------------------------------------------------

    /**
     * The hook's promise, checked against the departure.
     *
     * RED is a hook that closes on the reckoning — the narrator standing up at
     * the reception and reading out the receipts — on a story whose payoff is a
     * refusal three acts later. GREEN is the same hook with the same first four
     * beats and a closing line that promises the leaving.
     *
     * **The pairing is the whole assertion here and not ceremony.** A rule that
     * warned on every hook would satisfy RED, and this rule is one `>= 2` away
     * from being that: the departure and the exposure are paragraphs about the
     * same family, in the same words, and a check that counted one shared word
     * would fire on both halves. GREEN is what says it does not.
     */
    public function test_the_hook_promise_check_goes_red(): void
    {
        $story = $this->storyWithBothPayoffs();

        $story->update(['hook' => self::HOOK_BEATS.self::PROMISES_THE_EXPOSURE]);

        $review = app(ValidateOutlineSpine::class)->handle($story->refresh());

        $this->assertSame(
            'weak',
            $review['spine']['hook']['state'],
            'A hook closing on the exposure of a story whose payoff is a refusal is selling a '
            .'different video, and the check did not say so.',
        );
        $this->assertStringContainsString(
            'closes on the exposure rather than the departure',
            implode(' ', $review['warnings']),
        );
    }

    public function test_the_hook_promise_check_passes_a_hook_that_promises_the_leaving(): void
    {
        $story = $this->storyWithBothPayoffs();

        $story->update(['hook' => self::HOOK_BEATS.self::PROMISES_THE_DEPARTURE]);

        $review = app(ValidateOutlineSpine::class)->handle($story->refresh());

        $this->assertSame('ok', $review['spine']['hook']['state']);
        $this->assertNotEmpty(
            $review['spine']['hook']['promises'] ?? '',
            'A hook that promises the departure must name WHICH part of it. "It promises '
            .'something" is worth less at Gate 1 than the sentence it promises.',
        );
    }

    /**
     * THE FIXTURE CAN EXPRESS THE FAILING STATE, asserted rather than assumed.
     *
     * This is the case `pageFixtureFor()` had to learn twice, applied before
     * the third time rather than after it. Every earlier instance was the same
     * sentence: the detector was right and the input it was handed could not
     * contain the defect — a null `escalation_beat` that removed the void an
     * empty-track drill was looking for, a null `sized_against_wpm` that hid a
     * whole panel from the claim check, a `queueDepthIs()` that could not
     * describe one queue busy and its neighbour idle.
     *
     * A hook mismatch needs THREE things present at once, and the obvious
     * fixture to reach for has none of them:
     *
     *   1. a DEPARTURE, or there is nothing for a promise to be measured
     *      against and `checkHook()` returns before it looks at anything;
     *   2. an EXPOSURE distinct from it, or the wrong-payoff branch cannot be
     *      told apart from the promises-nothing branch;
     *   3. a REFUSAL, because the mismatch this is named for is a hook selling
     *      a reckoning on a story that pays off in a private no.
     *
     * `GateLayoutContractTest::pageFixtureFor()` is deliberately a PRE-PHASE
     * story — no departure, no refusal — so it cannot hold any of this, and a
     * pair built on it would have been green in both halves. That is asserted
     * below too, so nobody later "simplifies" this onto the shared fixture and
     * quietly makes both halves vacuous.
     */
    public function test_the_hook_fixture_can_express_the_mismatch(): void
    {
        $story = $this->storyWithBothPayoffs();

        foreach (['departure', 'exposure_moment', 'refusal'] as $field) {
            $this->assertNotSame(
                '',
                trim((string) $story->{$field}),
                "The hook pair needs a {$field}: without all three the RED half cannot be a "
                .'mismatch, only an absence.',
            );
        }

        // RED and GREEN differ in the closing line and in nothing else. A pair
        // that also rewrote the setup would prove that some hook somewhere
        // warns, which is not the claim.
        $this->assertSame(
            self::HOOK_BEATS,
            substr(self::HOOK_BEATS.self::PROMISES_THE_EXPOSURE, 0, strlen(self::HOOK_BEATS)),
        );
        $this->assertNotSame(self::PROMISES_THE_EXPOSURE, self::PROMISES_THE_DEPARTURE);

        // And the shared page fixture cannot hold this, on purpose.
        $shared = GateLayoutContractTest::pageFixtureFor(StoryStatus::Outlined);

        $this->assertSame(
            '',
            trim((string) $shared->departure),
            'pageFixtureFor() is the pre-phase story, so a hook pair built on it would be green '
            .'in both halves. This pair keeps its own fixture for that reason.',
        );
    }

    /** Beats 1-4: setup, betrayal, evidence in exact words, a cold action. */
    private const HOOK_BEATS =
        'My older sister Dana got married in June and I paid for all of it. '
        .'The first invoice arrived eleven days after she asked me to stand up with her, and '
        .'there were nine more behind it, and I had never once said that I would pay. '
        .'When I said as much she told me, "You have no kids and no mortgage, and family helps '
        .'family." I opened a spreadsheet that night and named it DANA WEDDING. ';

    /** Beat 5, wrong: the public payoff, which is what the title promises. */
    private const PROMISES_THE_EXPOSURE =
        'At the reception, in front of eighty guests and both families, I stood up and read out '
        .'every receipt.';

    /** Beat 5, right: the gap, which is what the middle third of this is. */
    private const PROMISES_THE_DEPARTURE =
        'Two weeks after the reception I moved out of the apartment and left no address, and '
        .'Dana did not find out that I was gone for three weeks.';

    /**
     * A story with a departure, an exposure and a refusal, and acts phased.
     *
     * Phased deliberately: an unphased outline takes the legacy path, where the
     * whole reversal is reported once as absent and `checkHook()` never runs.
     * A fixture that fell into that branch would make both halves of the pair
     * green without either of them being about the hook.
     */
    private function storyWithBothPayoffs(): Story
    {
        $story = Story::factory()->status(StoryStatus::Outlined)->single()->create([
            'slug' => 'hook-promise-pair',
            'narrator_grievance' => 'My sister Dana billed me for her wedding over eleven months.',
            'antagonist_justification' => 'She says I have no kids and no mortgage, and that '
                .'family helps family, and she believes every word of it.',
            'withheld_information' => 'I had been paying our mother\'s care home fees out of the '
                .'same account the whole time.',
            'exposure_moment' => 'At the reception, in front of eighty guests and both families, '
                .'when Dana stood up to thank everyone who had helped and named everyone but me.',
            'departure' => 'I moved out of the apartment two weeks after the reception and did '
                .'not say where I was going. Nobody was given an address and my number changed '
                .'the same afternoon. Dana found out that I was gone three weeks later.',
            'reversal_beats' => 'She called every relative we have in common, and two of them '
                .'stopped taking her calls. Then she paid a man to look for me.',
            'refusal' => 'When Dana finally found me she asked me to come back, because family '
                .'helps family. I said her own sentence back to her and then I said no.',
        ]);

        app(Generator::class)->unique(reset: true);

        $phases = ActPhase::planFor(6);

        foreach (range(1, 6) as $sequence) {
            Act::factory()->for($story)->atSequence($sequence)->inPhase($phases[$sequence])->create([
                'is_rehook_written' => true,
                'escalation_beat' => "Act {$sequence} costs somebody something they cannot get back.",
            ]);
        }

        return $story->refresh();
    }

    // -- The close-frame setting advisory ------------------------------------

    /**
     * RED, and the input is the real instance rather than one written for it.
     *
     * Story 21 scene 107 asked for "Close on Wei Hongmei's face, mouth set
     * hard, eyes fixed on Lu Wenbin, the dish towel gripped tight in her
     * fists" and came back as her reference sheet holding a dish towel — same
     * flat grey void, same frontal head-and-shoulders framing, same wardrobe,
     * in a scene set in a kitchen. That frame is used verbatim here, so the
     * guard is drilled against the picture it was written from and not against
     * a paraphrase of it.
     */
    public function test_the_close_frame_setting_check_goes_red(): void
    {
        $warnings = $this->warningsForFrame(
            "Close on Wei Hongmei's face, mouth set hard, eyes fixed on Lu Wenbin, "
            .'the dish towel gripped tight in her fists.',
        );

        $this->assertStringContainsString(
            'name no setting',
            implode(' ', $warnings),
            'A close frame on a face with nowhere for the camera to be is the frame that comes '
            .'back as the reference sheet with props added, and the check did not say so.',
        );
    }

    /**
     * GREEN, one clause away from RED.
     *
     * The same frame, the same face, the same props — with the kitchen it was
     * always set in actually written down. If this half goes red the advisory
     * fires on the fix, and a guard that fires on its own remedy can only be
     * made quiet by turning it off.
     */
    public function test_the_close_frame_setting_check_passes_a_frame_that_names_its_room(): void
    {
        $warnings = $this->warningsForFrame(
            "Close on Wei Hongmei's face, mouth set hard, eyes fixed on Lu Wenbin, the dish "
            .'towel gripped tight in her fists, the kitchen dark behind her.',
        );

        $this->assertStringNotContainsString('name no setting', implode(' ', $warnings));
    }

    /**
     * GREEN on the SCOPE, which is the half that keeps the advisory readable.
     *
     * A close-up of a document has the character on its cast list — the hand is
     * theirs — and no face for a head-and-shoulders portrait to overwrite.
     * Without this scope the check flagged 27 frames across four real stories
     * and nine of them were hands and paperwork; with it, 12. An advisory
     * column that is half noise is one nobody finishes reading, and that is the
     * over-report direction this whole class of tool has to be held to.
     */
    public function test_the_close_frame_setting_check_ignores_a_close_up_of_an_object(): void
    {
        $warnings = $this->warningsForFrame(
            "Close on a single printed page held in Lu Wenbin's hands, a signature line at the "
            ."bottom. His thumb presses against the paper's edge, knuckles pale.",
        );

        $this->assertStringNotContainsString('name no setting', implode(' ', $warnings));
    }

    /**
     * THE DRILL WRITTEN THE OTHER WAY, which is the one that has paid three
     * times in this file's history.
     *
     * The setting cues are matched whole-word, and the standing rule is that a
     * text guard is drilled with the input spelled the way the author did NOT
     * have in mind. 'gate' is a cue; "investigating" contains it; 'lit' is a
     * cue and "quality" contains it. A substring matcher would read this frame
     * as having named a setting and clear it silently — which is exactly how
     * CharacterTextGuard ended up carrying 'mic ' with a trailing space until
     * it was moved onto boundaries.
     *
     * This frame names no setting at all, so it must still be reported.
     */
    public function test_the_close_frame_setting_check_is_not_fooled_by_a_cue_inside_another_word(): void
    {
        $warnings = $this->warningsForFrame(
            "Close on Lin Zhaoyang's face, eyes narrowed, investigating the quality of what he "
            .'has just been told, delegating nothing.',
        );

        $this->assertStringContainsString(
            'name no setting',
            implode(' ', $warnings),
            "'gate' inside \"investigating\" and 'lit' inside \"quality\" are not settings. A "
            .'substring matcher clears this frame and the guard goes quiet on half its subject.',
        );
    }

    // -- The hedged-expression advisory --------------------------------------

    /**
     * RED, in the wording that was measured to render nothing.
     *
     * "Jaw tight, eyes narrowed slightly" is story 21 scene 204 verbatim, and in
     * the expression-axis run it came back indistinguishable from the neutral
     * reference portrait while the unhedged rung came back a hard glare.
     */
    public function test_the_hedged_expression_check_goes_red(): void
    {
        $warnings = $this->warningsForFrame(
            'Close on Erin at the kitchen table, the window dark behind her.',
            'jaw tight, eyes narrowed slightly',
        );

        $this->assertStringContainsString('hedge it', implode(' ', $warnings));
    }

    /** GREEN: the same expression at the strength it actually is. */
    public function test_the_hedged_expression_check_passes_a_plain_expression(): void
    {
        $warnings = $this->warningsForFrame(
            'Close on Erin at the kitchen table, the window dark behind her.',
            'jaw tight, brows drawn together, mouth set hard',
        );

        $this->assertStringNotContainsString('hedge it', implode(' ', $warnings));
    }

    /**
     * GREEN, and the half that keeps the advisory from being noise.
     *
     * A hedge belongs to a FACE. "Dust faint on the drawer's edge" and
     * "gesturing slightly as he speaks" are neither wrong nor about an
     * expression, and the first version of this measurement scored the whole
     * frame and over-counted by 4x — 13.4% reported against a true 3.2%. The
     * check reads the expression block and nothing else, and this asserts it.
     */
    public function test_the_hedged_expression_check_ignores_a_hedge_in_the_frame(): void
    {
        $warnings = $this->warningsForFrame(
            'Erin kneels at an open drawer, dust faint on its edge, gesturing slightly as she speaks.',
            'jaw tight, mouth set hard',
        );

        $this->assertStringNotContainsString('hedge it', implode(' ', $warnings));
    }

    // -- Expression is not spent on a shot that cannot show it ----------------

    /**
     * RED-equivalent: a wide establishing shot naming no face loses it.
     *
     * The live instance, from story 12's re-draft: *"A modest single-story house
     * on Ridgeline Drive seen from the street … brows drawn together"* — an
     * expression on a building, inside a 25-45 word budget.
     */
    public function test_an_expression_is_dropped_from_a_wide_shot_with_no_face_in_it(): void
    {
        $prompts = $this->promptsFromDraft(
            'A modest single-story house on Ridgeline Drive seen from the street, a car in the drive.',
            'brows drawn together, mouth set hard',
        );

        $this->assertNotEmpty($prompts);

        foreach ($prompts as $prompt) {
            $this->assertSame(
                '',
                ImagePromptBuilder::expressionFrom($prompt),
                'A wide shot of a house cannot show an expression, and the words are budgeted.',
            );
        }
    }

    /**
     * GREEN, and it is the conservative half.
     *
     * A wide marker plus a named face keeps the expression. `WIDE_MARKERS` is
     * tuned for thumbnail ranking, where a false `wide` costs only a ranking; if
     * it were trusted alone HERE it would silently delete a real expression from
     * a real close-up. 'empty' matched a print-shop counter and 'the street'
     * matched a frame whose subject stands in it, both in real data.
     */
    public function test_an_expression_survives_a_wide_marker_when_a_face_is_named(): void
    {
        $prompts = $this->promptsFromDraft(
            'Erin alone in an empty parking lot at dusk, close on her face, keys in one hand.',
            'brows drawn together, mouth set hard',
        );

        $kept = array_filter(array_map(
            fn (string $p): string => ImagePromptBuilder::expressionFrom($p),
            $prompts,
        ));

        $this->assertNotEmpty(
            $kept,
            'The frame names a face, so the expression is kept whatever the shot markers say.',
        );
    }

    /**
     * Every prompt a drafted story produces from one controlled frame.
     *
     * @return array<int, string>
     */
    private function promptsFromDraft(string $frame, string $expression): array
    {
        $writer = app(ScriptWriter::class);
        $writer->frameOverride = $frame;
        $writer->expressionOverride = $expression;

        $story = Story::factory()->status(StoryStatus::Scripted)->create();
        Act::factory()->for($story)->atSequence(1)->create([
            'script' => 'Erin sat at the table. Kyle would not look at her. She said the number '
                .'out loud. Nobody answered her for a while at all.',
        ]);
        Character::factory()->for($story)->create(['name' => 'Erin Whitfield']);

        app(DraftScenes::class)->handle($story);

        // Only the peopled scenes. The fake makes every third scene a cutaway,
        // which loses its expression for a different and already-tested reason.
        return $story->refresh()->scenes()->with('characters')->get()
            ->filter(fn (Scene $scene): bool => $scene->characters->isNotEmpty())
            ->map(fn (Scene $scene): string => (string) $scene->image_prompt)
            ->values()
            ->all();
    }

    /**
     * One drafted scene carrying one frame, with a character in it.
     *
     * The character is attached because the check is scoped to frames a
     * reference sheet can bleed into, and a scene with an empty cast is
     * skipped before anything else is looked at — so a fixture without one
     * would make every case above vacuously green.
     *
     * @return array<int, string>
     */
    private function warningsForFrame(string $frame, string $expression = ''): array
    {
        $story = Story::factory()->create(['status' => StoryStatus::ScenesDrafted]);
        $act = Act::factory()->for($story)->atSequence(1)->create();
        $character = Character::factory()->for($story)->create([
            'name' => 'Wei Hongmei',
            'description' => 'Sixty-nine years old, steel-gray hair permed into tight short curls.',
        ]);

        $scene = Scene::factory()->forAct($act)->create([
            'sequence' => 1,
            'is_hook' => true,
            'is_thumbnail_candidate' => true,
            // Assembled by the builder rather than hand-written, so these cases
            // read the same section layout production does. A hand-built prompt
            // would pass its own label check and prove nothing about the join.
            'image_prompt' => app(ImagePromptBuilder::class)
                ->build($frame, [$character], ['Wei Hongmei'], $expression),
        ]);

        $scene->characters()->attach($character);

        return app(ValidateSceneDrafts::class)->handle($story->refresh())['warnings'];
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
