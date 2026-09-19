<?php

namespace Tests\Feature;

use App\Actions\GenerateActScripts;
use App\Actions\GenerateOutline;
use App\Actions\ValidateOutlineSpine;
use App\Actions\DraftScenes;
use App\Actions\ValidateSceneDrafts;
use App\Contracts\ScriptWriter;
use App\Enums\ActPhase;
use App\Enums\ActTimeframe;
use App\Enums\Gate;
use App\Enums\StoryEnding;
use App\Enums\StoryStatus;
use App\Models\Act;
use App\Models\Character;
use App\Models\Scene;
use App\Models\Story;
use App\Support\ChapterAnnouncement;
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
            // In the room by choice, producing the withheld information in
            // person, in its own words — the state the presence pair below
            // needs to be able to express. "care home fees" and "account"
            // are the overlap.
            'narrator_at_exposure' => 'I come to the reception uninvited, having chosen the moment, '
                .'and put eleven months of care home fees from the same account on the table '
                .'myself. She did not find me. I came.',
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
                // Declared, so the outline is one that was ASKED and the
                // timeframe pair can turn one act prior without the whole
                // story reading as pre-field.
                'timeframe' => ActTimeframe::Present,
            ]);
        }

        return $story->refresh();
    }

    // -- The betrayal scene ---------------------------------------------------
    //
    // Four checks, four pairs, each GREEN the RED input with one thing changed.
    // Built on `storyWithBothPayoffs()` plus a scene and an act 1 summary, and
    // the fixture's ability to hold both halves is asserted at the end.

    /** The healthy scene every pair varies from. */
    private const BETRAYAL_SCENE = 'At Dana\'s engagement dinner, in front of both families and twenty '
        .'relatives, Dana stood up with her fiance Mark beside her and announced that I had agreed to pay '
        .'the venue balance. Aunt Ruth asked when I had offered. Dana said it to my face, to the whole '
        .'room: I have no kids and no mortgage, and family helps family. Mark looked at his plate and said '
        .'nothing. I asked whether I was also paying for the soup, and the table laughed at me instead of '
        .'at her.';

    private const ACT_ONE_STAGES_IT = 'At the engagement dinner, in front of twenty relatives, Dana '
        .'announces I agreed to pay the venue balance and says family helps family to my face. Mark '
        .'stays silent. I answer back and the table laughs at me.';

    /**
     * RED: the kitchen table — stories 29-32's staging, with every other part
     * of the scene intact, including the justification said to her face.
     */
    public function test_the_betrayal_audience_check_goes_red(): void
    {
        $review = $this->reviewBetrayal(
            'At our kitchen table, with her fiance Mark beside her, Dana told me I had agreed to pay the '
            .'venue balance. Dana said it to my face: I have no kids and no mortgage, and family helps '
            .'family. Mark looked at his plate and said nothing. I asked whether I was also paying for '
            .'the soup, and she told me not to be petty.'
        );

        $this->assertStringContainsString('The betrayal scene names nobody watching', implode(' ', $review['warnings']));
        $this->assertSame('weak', $review['spine']['betrayal_scene']['state']);
    }

    public function test_the_betrayal_audience_check_passes_a_room_with_people_in_it(): void
    {
        $review = $this->reviewBetrayal(self::BETRAYAL_SCENE);

        $this->assertStringNotContainsString('names nobody watching', implode(' ', $review['warnings']));
        $this->assertSame('ok', $review['spine']['betrayal_scene']['state']);
    }

    /** RED: the scene happens in public and she never says her justification. */
    public function test_the_said_aloud_check_goes_red(): void
    {
        $review = $this->reviewBetrayal(str_replace(
            'I have no kids and no mortgage, and family helps family.',
            'I had offered at Christmas and was now embarrassing her.',
            self::BETRAYAL_SCENE,
        ));

        $this->assertStringContainsString('is not said in the betrayal scene', implode(' ', $review['warnings']));
        $this->assertArrayNotHasKey('says', $review['spine']['betrayal_scene']);
    }

    public function test_the_said_aloud_check_names_the_sentence_she_says(): void
    {
        $review = $this->reviewBetrayal(self::BETRAYAL_SCENE);

        $this->assertStringNotContainsString('is not said in the betrayal scene', implode(' ', $review['warnings']));
        $this->assertStringContainsString('no kids and no mortgage', (string) ($review['spine']['betrayal_scene']['says'] ?? ''));
    }

    /** RED: story 23's shape — the betrayal found in posted photos. */
    public function test_the_discovery_check_goes_red(): void
    {
        $review = $this->reviewBetrayal('I found out from the photos Mark posted that morning. '.self::BETRAYAL_SCENE);

        $warnings = implode(' ', $review['warnings']);

        $this->assertStringContainsString('reads as FOUND rather than done', $warnings);
        $this->assertStringContainsString('"found out"', $warnings);
        $this->assertSame([], array_filter(
            $review['problems'],
            fn (string $p): bool => str_contains($p, 'FOUND'),
        ), 'A discovery is a warning, never a problem: not every premise can stage a public betrayal.');
    }

    /** GREEN: the same markers, negated — the good case says them out loud. */
    public function test_the_discovery_check_passes_a_negated_finding(): void
    {
        $review = $this->reviewBetrayal('Not from any photos anybody posted: from her own mouth. '.self::BETRAYAL_SCENE);

        $this->assertStringNotContainsString('reads as FOUND', implode(' ', $review['warnings']));
    }

    /**
     * GREEN, verbatim: story 36's betrayal_scene, which this check reported as
     * found on "her phone" — she holds it out to have the narrator take the
     * photo. Live on story 36's Gate 1 and never noticed.
     */
    public function test_the_discovery_check_passes_story_36s_phone_held_out_for_a_photo(): void
    {
        $review = $this->reviewBetrayal(<<<'TEXT'
            A private room at the Jinshui hotel in Zhengzhou, a Friday in May, twelve people: eight from the institute, Amy's mother Nie Guifang, her colleague Grace Tian, Leo Duan and me. Amy stands, raises her glass to Leo and says he is the person who got her here. Then she holds her phone out over the cold dishes and asks me to take the photo. Grace Tian asks whether her husband shouldn't be in it. Leo does his line — "Brother Aaron, I told her you should be in the picture, I'd rather step out of the frame than have anyone here uncomfortable, that's on me" — and Amy tells him to sit down, that he is not to apologize for other people's moods, and the table murmurs that he's a decent man. Then she says it to my face, in front of all of them: "Somebody has to hold the camera. Aaron is good at the things nobody claps for — that's a compliment, and he knows it is. I would like one evening where it isn't turned into something." I said, "It is a compliment." Then I lifted the phone and told Leo to move left, because he was standing in the light. The table laughed — at me, because they thought I was fussing with a phone, and at him for half a second, which he absorbed with a smile. I took the picture. Amy did not look at me again for the rest of the night, Leo put his arm along the back of her chair, and her mother told the woman beside her that a man who takes good photographs has found his level.
            TEXT);

        $this->assertStringNotContainsString('reads as FOUND', implode(' ', $review['warnings']));
    }

    /**
     * GREEN, verbatim: story 38's second premise candidate, reported as found
     * on "booked" — the restaurant booked for the anniversary.
     */
    public function test_the_discovery_check_passes_story_38s_restaurant_booked_for_the_anniversary(): void
    {
        $review = $this->reviewBetrayal(<<<'TEXT'
            At the restaurant booked for their fifth wedding anniversary, in front of twelve of their closest friends, Amy Deng leans her head on Ethan Bao's shoulder during the toasts; when a friend asks if he is her new assistant she answers without lowering her voice, then turns to the narrator and delivers the justification to his face, and Ethan follows with his rehearsed line about trying to end it kindly, which turns the table's sympathy toward him.
            TEXT);

        $this->assertStringNotContainsString('reads as FOUND', implode(' ', $review['warnings']));
    }

    /**
     * RED: the same kind of noun, with somebody coming upon it in its sentence.
     * Story 25's shape, hand-written: no real betrayal_scene has ever held a
     * found betrayal, so every RED here is a fixture, not a catch.
     */
    public function test_the_discovery_check_goes_red_on_evidence_somebody_comes_upon(): void
    {
        $review = $this->reviewBetrayal('I was copied on a hotel booking for two in Sanya under her name. '.self::BETRAYAL_SCENE);

        $this->assertStringContainsString('"booking"', implode(' ', $review['warnings']));
    }

    /**
     * THE KNOWN WRONG CASE, pinned so it is not mistaken for coverage: evidence
     * read ALOUD in the room is the betrayal done in public, the good case, and
     * the check still calls it found. If this goes green, the limit recorded on
     * `DISCOVERY_MARKERS` and in CLAUDE.md has changed and both want updating.
     */
    public function test_the_discovery_check_still_misreads_evidence_read_aloud(): void
    {
        $review = $this->reviewBetrayal('She reads the booking aloud to the table. '.self::BETRAYAL_SCENE);

        $this->assertStringContainsString('reads as FOUND', implode(' ', $review['warnings']));
    }

    /**
     * RED: act 1's summary is about something else and shares only the cast's
     * NAMES with the scene — three of them, which is exactly enough to pass a
     * three-word overlap if names were counted. That is the drill for the
     * proper-noun filter as well as for the check.
     */
    public function test_the_act_one_check_goes_red(): void
    {
        $review = $this->reviewBetrayal(
            self::BETRAYAL_SCENE,
            'On the telephone, Dana, Mark and Ruth argue over a seating chart.',
        );

        $this->assertStringContainsString('Act 1\'s summary does not stage the betrayal scene', implode(' ', $review['warnings']));
    }

    public function test_the_act_one_check_passes_an_act_that_stages_the_scene(): void
    {
        $review = $this->reviewBetrayal(self::BETRAYAL_SCENE, self::ACT_ONE_STAGES_IT);

        $this->assertStringNotContainsString('does not stage the betrayal scene', implode(' ', $review['warnings']));
    }

    /**
     * The pairs above are only as good as the fixture under them: an outline
     * that was ASKED (so the field is not read as unasked), with an act 1, and
     * a justification to overlap. And the shared page fixture cannot hold any
     * of it, on purpose — it is pre-phase and outlined before the field.
     */
    public function test_the_betrayal_fixture_can_express_every_state(): void
    {
        $story = $this->storyWithBothPayoffs();

        $this->assertFalse((bool) $story->outlined_before_betrayal_scene);
        $this->assertNotSame('', trim((string) $story->antagonist_justification));
        $this->assertNotNull($story->acts()->where('sequence', 1)->first());

        $shared = GateLayoutContractTest::pageFixtureFor(StoryStatus::Outlined);

        $this->assertTrue($shared->outlined_before_betrayal_scene);
        $this->assertSame('', trim((string) $shared->betrayal_scene));
    }

    /**
     * @return array{problems: array<int, string>, warnings: array<int, string>, spine: array<string, array<string, string>>}
     */
    private function reviewBetrayal(string $scene, string $actOneSummary = self::ACT_ONE_STAGES_IT): array
    {
        $story = $this->storyWithBothPayoffs();
        $story->update(['betrayal_scene' => $scene]);
        $story->acts()->where('sequence', 1)->update(['summary' => $actOneSummary]);

        return app(ValidateOutlineSpine::class)->handle($story->refresh());
    }

    // -- The accomplice and the running thought (3g) -------------------------
    //
    // Six checks, each a pair whose GREEN is the RED with one thing changed.
    // Built on `storyWithAnAccomplice()`, whose ability to hold every state is
    // asserted at the end — and the shared page fixture's inability, so
    // nobody consolidates onto it and makes both halves vacuous.

    private const ACCOMPLICE_MOTIVE = 'Paul Ostrander wants the commission on selling our mother\'s house, '
        .'which he can only arrange once Dana holds power of attorney.';

    private const ACCOMPLICE_PERFORMANCE = 'Paul plays the old family adviser who only wants the sisters to '
        .'get along. He tells me, "I would hate for money to come between two sisters," and offers to '
        .'apologize so that Dana defends him.';

    private const ACCOMPLICE_FALL = 'After I have gone, Dana asks Paul in front of both aunts why the care '
        .'home was never paid. At the family meeting the power of attorney he drafted is read aloud to '
        .'everyone. At the reception, in front of eighty guests, Dana hears that he wanted the commission '
        .'on the house all along.';

    // Carries its figure. The first version had three words of its own and
    // matched "fund" alone against the refusal ("dollar" is not "dollars"), so
    // the GREEN half went red — the check was right and the tally had no tally.
    private const RUNNING_THOUGHT = 'Every time Dana says family helps family, I add a dollar in my head to '
        .'the family helps family fund, and by the reception it stands at four hundred and twelve dollars.';

    private const REFUSAL_PAYS_IT_OFF = 'When Dana finally found me she asked me to come back, because family '
        .'helps family. I said her own sentence back to her and then I said no. Then I told her the fund '
        .'had closed at four hundred and twelve dollars.';

    /** RED: Gerald's own tell, the line the act was built on. A problem, not a warning. */
    public function test_the_coded_act_check_goes_red(): void
    {
        $review = $this->reviewAccomplice(['accomplice_performance' => str_replace(
            'who only wants the sisters to get along',
            'who says he is not into women and only wants the sisters to get along',
            self::ACCOMPLICE_PERFORMANCE,
        )]);

        $this->assertStringContainsString('builds the accomplice\'s act on orientation', implode(' ', $review['problems']));
        $this->assertStringContainsString('"not into women"', implode(' ', $review['problems']));
    }

    /**
     * GREEN: the same sentence built on a role. And no negation window: "not
     * into women" is not the good case of this check, it is the tell.
     */
    public function test_the_coded_act_check_passes_a_role(): void
    {
        $review = $this->reviewAccomplice(['accomplice_performance' => str_replace(
            'who only wants the sisters to get along',
            'who says he has known the family for thirty years and only wants the sisters to get along',
            self::ACCOMPLICE_PERFORMANCE,
        )]);

        $this->assertStringNotContainsString('on orientation', implode(' ', $review['problems']));
        $this->assertSame('ok', $review['spine']['accomplice_performance']['state']);
    }

    /** RED: the act described and never spoken — the silent accomplice in a new coat. */
    public function test_the_act_has_a_line_check_goes_red(): void
    {
        $review = $this->reviewAccomplice(['accomplice_performance' => str_replace(
            'He tells me, "I would hate for money to come between two sisters," and',
            'He tells me money should never come between two sisters, and',
            self::ACCOMPLICE_PERFORMANCE,
        )]);

        $this->assertStringContainsString('has no line in it', implode(' ', $review['warnings']));
        $this->assertSame('weak', $review['spine']['accomplice_performance']['state']);
    }

    public function test_the_act_has_a_line_check_passes_a_quoted_line_in_curly_quotes(): void
    {
        $review = $this->reviewAccomplice(['accomplice_performance' => str_replace(
            ['"I would', 'sisters,"'],
            ['“I would', 'sisters,”'],
            self::ACCOMPLICE_PERFORMANCE,
        )]);

        $this->assertStringNotContainsString('has no line in it', implode(' ', $review['warnings']));
    }

    /**
     * GREEN: a line in SINGLE quotes, with an apostrophe inside it. Story 38's
     * premise roll quoted the accomplice this way in all three candidates and
     * the check, knowing only double quotes, called every one silent.
     */
    public function test_the_act_has_a_line_check_passes_a_quoted_line_in_single_quotes(): void
    {
        $review = $this->reviewAccomplice(['accomplice_performance' => str_replace(
            '"I would hate for money to come between two sisters,"',
            '\'I would hate for money to come between two sisters, I couldn\'t bear it,\'',
            self::ACCOMPLICE_PERFORMANCE,
        )]);

        $this->assertStringNotContainsString('has no line in it', implode(' ', $review['warnings']));
    }

    /**
     * RED: apostrophes are not quotes. Possessives and contractions around a
     * described line must not read as a quoted one — the other half of
     * accepting single quotes at all.
     *
     * The elided 'cause is load-bearing: it is an apostrophe that CAN open a
     * quote, so only the closing rule (no letter after the mark) stops
     * "family'" or "can'" from closing it. The first version of this input had
     * possessives only, and a drill that removed the closing rule stayed green.
     */
    public function test_the_act_has_a_line_check_is_not_fooled_by_apostrophes(): void
    {
        $review = $this->reviewAccomplice(['accomplice_performance' => str_replace(
            'He tells me, "I would hate for money to come between two sisters," and',
            'He tells me the sisters\' money shouldn\'t come between them, \'cause the family\'s name can\'t take it, and',
            self::ACCOMPLICE_PERFORMANCE,
        )]);

        $this->assertStringContainsString('has no line in it', implode(' ', $review['warnings']));
    }

    /** RED: one humiliation. Two sentences, both public, both about the motive. */
    public function test_the_fall_is_a_run_check_goes_red(): void
    {
        $review = $this->reviewAccomplice(['accomplice_fall' => 'At the reception, in front of eighty guests, '
            .'the power of attorney he drafted is read aloud. Dana hears he wanted the commission on the house.']);

        $this->assertStringContainsString('reads as one humiliation', implode(' ', $review['warnings']));
        $this->assertSame('thin', $review['spine']['accomplice_fall']['state']);
    }

    public function test_the_fall_is_a_run_check_passes_three_losses(): void
    {
        $review = $this->reviewAccomplice([]);

        $this->assertStringNotContainsString('reads as one humiliation', implode(' ', $review['warnings']));
        $this->assertSame('ok', $review['spine']['accomplice_fall']['state']);
    }

    /** RED: three losses, in private. */
    public function test_the_fall_in_public_check_goes_red(): void
    {
        $review = $this->reviewAccomplice(['accomplice_fall' => 'After I have gone, Dana asks Paul on the phone '
            .'why the care home was never paid. Later she reads the power of attorney he drafted alone at her '
            .'desk. Then she works out that he wanted the commission on the house all along.']);

        $this->assertStringContainsString('fall names nobody watching', implode(' ', $review['warnings']));
    }

    public function test_the_fall_in_public_check_passes_a_room_with_people_in_it(): void
    {
        $review = $this->reviewAccomplice([]);

        $this->assertStringNotContainsString('fall names nobody watching', implode(' ', $review['warnings']));
    }

    /**
     * RED: a public run of losses that never says why he was there. Shares
     * only his NAME with the motive, which the proper-noun filter removes —
     * the drill for that filter as well as for the check.
     */
    public function test_the_fall_exposes_the_motive_check_goes_red(): void
    {
        $review = $this->reviewAccomplice(['accomplice_fall' => 'After I have gone, Dana asks Paul in front of '
            .'both aunts about the seating chart. At the family meeting Paul is shouted at by everyone. At the '
            .'reception, in front of eighty guests, Ostrander spills wine on the cake.']);

        $this->assertStringContainsString('never exposes his motive', implode(' ', $review['warnings']));
        $this->assertArrayNotHasKey('exposes', $review['spine']['accomplice_fall']);
    }

    public function test_the_fall_exposes_the_motive_check_names_the_motive_sentence(): void
    {
        $review = $this->reviewAccomplice([]);

        $this->assertStringNotContainsString('never exposes his motive', implode(' ', $review['warnings']));
        $this->assertStringStartsWith('Paul Ostrander wants the commission', (string) ($review['spine']['accomplice_fall']['exposes'] ?? ''));
    }

    /**
     * RED: a refusal that shares the grievance's words with the thought —
     * "family helps family" — and never mentions the fund. Only the thought's
     * OWN words count, which is what makes this red rather than trivially
     * green.
     */
    public function test_the_thought_pays_off_check_goes_red(): void
    {
        $review = $this->reviewAccomplice(['refusal' => 'When Dana finally found me she asked me to come back, '
            .'because family helps family. I said her own sentence back to her and then I said no.']);

        $this->assertStringContainsString('never pays off the running thought', implode(' ', $review['warnings']));
        $this->assertArrayNotHasKey('pays_off', $review['spine']['running_thought']);
    }

    public function test_the_thought_pays_off_check_names_the_refusal_sentence(): void
    {
        $review = $this->reviewAccomplice([]);

        $this->assertStringNotContainsString('never pays off the running thought', implode(' ', $review['warnings']));
        $this->assertStringStartsWith('Then I told her the fund', (string) ($review['spine']['running_thought']['pays_off'] ?? ''));
    }

    /** RED: a "joke" made entirely of the spine's own words cannot be told from the grievance. */
    public function test_a_thought_with_nothing_of_its_own_goes_red(): void
    {
        $review = $this->reviewAccomplice(['running_thought' => 'Dana says family helps family.']);

        $this->assertStringContainsString('has almost nothing of its own', implode(' ', $review['warnings']));
        $this->assertSame('thin', $review['spine']['running_thought']['state']);
    }

    public function test_the_accomplice_fixture_can_express_every_state(): void
    {
        $story = $this->storyWithAnAccomplice();

        $this->assertFalse((bool) $story->outlined_before_accomplice_and_thought);
        $this->assertTrue(\App\Support\AccompliceArc::declared($story->outline_cast));
        $this->assertNotSame('', trim((string) $story->refusal));
        $this->assertNotSame('', trim((string) $story->narrator_grievance));

        // The healthy fixture raises nothing from these checks, so each RED
        // above is its one change and nothing else.
        $review = app(ValidateOutlineSpine::class)->handle($story);
        // By the checks' own sentences, not by the word "accomplice": the
        // betrayal scene's description mentions him, and this fixture has no
        // betrayal scene, so a bare word match reports a finding about
        // something else. The first version of this assertion did exactly that.
        $findings = implode(' ', [...$review['problems'], ...$review['warnings']]);

        foreach ([
            'builds the accomplice\'s act', 'has no line in it', 'reads as one humiliation',
            'fall names nobody watching', 'never exposes his motive', 'spine describes an accomplice',
            'never pays off the running thought', 'has almost nothing of its own', 'is missing. His own stake',
        ] as $finding) {
            $this->assertStringNotContainsString($finding, $findings);
        }

        $shared = GateLayoutContractTest::pageFixtureFor(StoryStatus::Outlined);

        $this->assertTrue($shared->outlined_before_accomplice_and_thought);
        $this->assertSame('', trim((string) $shared->accomplice_fall));
        $this->assertSame('', trim((string) $shared->running_thought));
    }

    private function storyWithAnAccomplice(): Story
    {
        $story = $this->storyWithBothPayoffs();

        $story->update([
            'outline_cast' => [
                ['name' => 'Erin Vasquez', 'role' => 'narrator', 'relationship' => 'the narrator'],
                ['name' => 'Dana Vasquez', 'role' => 'antagonist', 'relationship' => 'her sister'],
                ['name' => 'Paul Ostrander', 'role' => 'accomplice', 'relationship' => 'the family adviser'],
            ],
            'accomplice_motive' => self::ACCOMPLICE_MOTIVE,
            'accomplice_performance' => self::ACCOMPLICE_PERFORMANCE,
            'accomplice_fall' => self::ACCOMPLICE_FALL,
            'running_thought' => self::RUNNING_THOUGHT,
            'refusal' => self::REFUSAL_PAYS_IT_OFF,
        ]);

        return $story->refresh();
    }

    /**
     * @param  array<string, string>  $changes
     * @return array{problems: array<int, string>, warnings: array<int, string>, spine: array<string, array<string, string>>}
     */
    private function reviewAccomplice(array $changes): array
    {
        $story = $this->storyWithAnAccomplice();
        $story->update($changes);

        return app(ValidateOutlineSpine::class)->handle($story->refresh());
    }

    // -- The antagonist's regret -----------------------------------------------

    /** The chance, offered on the day of the refusal, in that moment's own words; then the year. */
    private const REGRET_ANCHORED = 'The night Dana finally found me, our mother phoned her and said to take the '
        .'four hundred and twelve dollars from the closed fund to me as an apology. Dana said family helps '
        .'family and hung up. About a year later the watch is still in its box on the hall shelf.';

    /** The same shape, offered on a day the story does not contain. */
    private const REGRET_UNANCHORED = 'In April a cousin rang Dana from Tucson and said to write a letter of '
        .'apology before Easter. Dana laughed and deleted the voicemail. About a year later the watch is '
        .'still in its box on the hall shelf.';

    /**
     * The fixture question first: the shared accomplice story can express both
     * halves — it has a refusal to anchor on and an antagonist to name. Without
     * either, the anchor check returns early and RED passes for nothing.
     */
    public function test_the_regret_fixture_can_express_the_mismatch(): void
    {
        $story = $this->storyWithAnAccomplice();

        $this->assertNotSame('', trim((string) $story->refusal));
        $this->assertSame('Dana Vasquez', \App\Support\AntagonistPointOfView::nameFor(
            tap($story)->forceFill(['antagonist_regret' => self::REGRET_ANCHORED, 'ending' => StoryEnding::AntagonistVoice])
        ));

        // And the ending is what makes it expressible. On the new life every
        // regret check returns early — which is how the negated-jump GREEN
        // below stayed green for the wrong reason the day endings arrived.
        $this->assertNull(\App\Support\AntagonistPointOfView::nameFor(
            tap($story)->forceFill(['ending' => StoryEnding::NewLife])
        ));
    }

    /**
     * The regret cases, on a story whose chosen ending is the antagonist's.
     *
     * @param  array<string, string>  $changes
     */
    private function reviewRegret(array $changes): array
    {
        return $this->reviewAccomplice(['ending' => StoryEnding::AntagonistVoice] + $changes);
    }

    /** RED: a last chance offered on a day the story never contains. */
    public function test_the_regret_anchor_check_goes_red(): void
    {
        $review = $this->reviewRegret(['antagonist_regret' => self::REGRET_UNANCHORED]);

        $this->assertSame('weak', $review['spine']['antagonist_regret']['state']);
        $this->assertArrayNotHasKey('anchored_in', $review['spine']['antagonist_regret']);
        $this->assertNotEmpty(array_filter($review['warnings'], fn (string $w): bool => str_contains($w, 'last chance is not tied')));
    }

    /** GREEN: the same shape, offered at the refusal, and the badge names it. */
    public function test_the_regret_anchor_check_stays_green_on_a_real_day(): void
    {
        $review = $this->reviewRegret(['antagonist_regret' => self::REGRET_ANCHORED]);

        $this->assertSame('ok', $review['spine']['antagonist_regret']['state']);
        $this->assertSame('the refusal', $review['spine']['antagonist_regret']['anchored_in']);
    }

    /** RED: twenty years, which one reference sheet at her age cannot draw. */
    public function test_the_long_time_jump_check_goes_red(): void
    {
        $review = $this->reviewRegret([
            'antagonist_regret' => str_replace('About a year later', 'Twenty years later', self::REGRET_ANCHORED),
        ]);

        $this->assertNotEmpty(array_filter($review['warnings'], fn (string $w): bool => str_contains($w, 'jumps "Twenty years" ahead')));
    }

    /** GREEN: the same words behind a negation are the good case. */
    public function test_the_long_time_jump_check_stays_green_when_negated(): void
    {
        $review = $this->reviewRegret([
            'antagonist_regret' => str_replace('About a year later', 'Not twenty years later but one', self::REGRET_ANCHORED),
        ]);

        $this->assertSame([], array_values(array_filter($review['warnings'], fn (string $w): bool => str_contains($w, ' ahead. It is set about a year'))));
    }

    // -- The spoken chapter number --------------------------------------------

    /**
     * RED: an act whose chapters never say their number out loud.
     *
     * The state story 30's acts 1 and 4 came back in — chapters present,
     * titled, sequenced and boundaried, with no announcement anywhere in the
     * prose. Nothing in the database looks wrong, which is why nothing saw
     * it: the Gate 1 page listed twelve chapters and every one of them had a
     * title.
     *
     * Act 4 is the one silenced here rather than act 1, deliberately. Act 1
     * chapter 1 is the one chapter whose announcement is allowed to be late,
     * so a pair built on it would be testing the exception; act 4 is the
     * other act story 30 lost, and it is an ordinary act with an ordinary
     * rule.
     */
    public function test_the_chapter_announcement_check_goes_red(): void
    {
        $story = $this->storyWrittenAsChapters(silentAct: 4);

        $review = app(ValidateOutlineSpine::class)->handle($story);
        $found = $this->announcementWarnings($review);

        $this->assertNotEmpty($found, 'An act whose chapters never announce themselves was not reported.');
        $this->assertStringContainsString('Act 4, chapter 1', implode(' ', $found));
        $this->assertStringContainsString('never says its number out loud', implode(' ', $found));

        // And it names the number that was missing, not just that one was.
        $this->assertStringContainsString('"'.ChapterAnnouncement::sentenceFor(7).'"', implode(' ', $found));
    }

    /** GREEN: the identical story with every act announcing. */
    public function test_the_chapter_announcement_check_passes_an_announced_story(): void
    {
        $review = app(ValidateOutlineSpine::class)->handle($this->storyWrittenAsChapters());

        $this->assertSame([], $this->announcementWarnings($review));
    }

    /**
     * GREEN, and the case the check is most likely to be got wrong on: act 1
     * chapter 1 announces AFTER the hook, because the cold open precedes
     * "chapter 1" in the reference and in our contract.
     *
     * A check that demanded the number as the first sentence everywhere would
     * report the correct shape as the defect on every story — which is how a
     * guard gets switched off within a week.
     */
    public function test_the_chapter_announcement_check_allows_act_ones_late_announcement(): void
    {
        $story = $this->storyWrittenAsChapters();
        $this->delayTheAnnouncement($story, actSequence: 1, by: 4);

        $review = app(ValidateOutlineSpine::class)->handle($story->refresh());

        $this->assertSame([], $this->announcementWarnings($review));
    }

    /** RED: the same delay anywhere else, which is a chapter marker nobody can navigate to. */
    public function test_the_chapter_announcement_check_reports_a_buried_number_in_a_later_act(): void
    {
        $story = $this->storyWrittenAsChapters();
        $this->delayTheAnnouncement($story, actSequence: 3, by: 4);

        $review = app(ValidateOutlineSpine::class)->handle($story->refresh());
        $found = $this->announcementWarnings($review);

        $this->assertNotEmpty($found);
        $this->assertStringContainsString('Act 3, chapter 1', implode(' ', $found));
        $this->assertStringContainsString('4 sentence(s) in rather than opening on it', implode(' ', $found));
    }

    /**
     * The fixture can express both states, asserted rather than assumed.
     *
     * `pageFixtureFor()` has silently voided a contract twice by not varying
     * the field the assertion reads, and `PartialSceneRedraftTest` did it a
     * third way by being too SMALL to hold the collision. So this asserts the
     * size — more than one act, more than one chapter per act — and that the
     * two halves of the pair actually differ in the prose.
     */
    public function test_the_chapter_announcement_fixture_can_express_the_silence(): void
    {
        $this->assertTrue(ChapterAnnouncement::enabled(), 'The check is silent with announcements off.');

        $announced = $this->storyWrittenAsChapters();
        $silent = $this->storyWrittenAsChapters(silentAct: 4);

        $this->assertGreaterThan(3, $announced->acts()->count());
        $this->assertGreaterThan(1, $announced->acts()->where('sequence', 4)->first()->chapters()->count());

        $this->assertStringContainsString(
            ChapterAnnouncement::sentenceFor(7),
            (string) $announced->acts()->where('sequence', 4)->first()->script,
        );
        $this->assertStringNotContainsString(
            ChapterAnnouncement::sentenceFor(7),
            (string) $silent->acts()->where('sequence', 4)->first()->script,
        );

        // And the silenced act is the ONLY thing that differs: act 3 still
        // announces in both, so the red case cannot be satisfied by a story
        // that announces nothing anywhere.
        $this->assertStringContainsString(
            ChapterAnnouncement::sentenceFor(5),
            (string) $silent->acts()->where('sequence', 3)->first()->script,
        );
    }

    /**
     * A story written as chapters, optionally with one act's announcements
     * suppressed.
     *
     * Acts are written one at a time so the suppression lands on exactly one
     * of them — the fake resets the flag after each act, the way it resets
     * the re-hook one.
     */
    private function storyWrittenAsChapters(int $silentAct = 0): Story
    {
        $story = Story::factory()->status(StoryStatus::Draft)->single()->create();

        app(GenerateOutline::class)->handle($story);
        $story->refresh();

        $writer = app(ScriptWriter::class);

        foreach ($story->acts()->orderBy('sequence')->pluck('sequence') as $sequence) {
            $writer->suppressChapterNumbers = ((int) $sequence === $silentAct);

            app(GenerateActScripts::class)->handle($story, only: [(int) $sequence]);
        }

        return $story->refresh();
    }

    /**
     * Push an act's first announcement later into its own chapter, keeping
     * every boundary consistent — which is what act 1 legitimately looks like
     * once the five hook beats sit in front of "Chapter one."
     */
    private function delayTheAnnouncement(Story $story, int $actSequence, int $by): void
    {
        $act = $story->acts()->where('sequence', $actSequence)->first();
        $filler = str_repeat('The invoice sat on the table. ', $by);

        $act->update(['script' => $filler.$act->script]);

        // Every boundary after the first shifts by the sentences inserted
        // ahead of it. The first chapter still starts at sentence 1.
        foreach ($act->chapters()->where('sequence', '>', 1)->get() as $chapter) {
            $chapter->update(['first_sentence' => $chapter->first_sentence + $by]);
        }
    }

    /** @param  array{warnings: array<int, string>}  $review */
    private function announcementWarnings(array $review): array
    {
        return array_values(array_filter(
            $review['warnings'],
            static fn (string $warning): bool => str_contains($warning, 'its number out loud')
                || str_contains($warning, 'announces itself as chapter')
                || str_contains($warning, 'rather than opening on it'),
        ));
    }

    // -- When an act is set ---------------------------------------------------

    /**
     * RED: one escalation act declared prior. The input is story 28's own
     * summary sentence, which was read at Gate 1 and approved.
     */
    public function test_the_timeframe_check_goes_red(): void
    {
        $story = $this->storyWithBothPayoffs();
        $story->acts()->where('sequence', 2)->update([
            'timeframe' => ActTimeframe::Prior,
            'summary' => 'Act 2 tells the second betrayal in full.',
        ]);

        $review = app(ValidateOutlineSpine::class)->handle($story->refresh());

        $this->assertStringContainsString(
            'Act 2 is set before the story\'s present: "Act 2 tells the second betrayal in full"',
            implode(' ', $review['problems']),
            'An escalation act staged in the past was not refused, and the script is written from '
            .'the summary and nothing else.',
        );
    }

    public function test_the_timeframe_check_passes_an_outline_set_in_the_present(): void
    {
        $review = app(ValidateOutlineSpine::class)->handle($this->storyWithBothPayoffs());

        $this->assertStringNotContainsString('set before the story', implode(' ', $review['problems']));
        $this->assertStringNotContainsString('do not say whether', implode(' ', $review['problems']));
    }

    // -- The narrator at the exposure ----------------------------------------

    /**
     * RED: a found narrator who produces nothing. The search succeeding
     * stopped being a weakness on 2026-09-13 — the reference transcript has
     * her find him and kneel — so what a found narrator is judged on is the
     * same thing an uninvited one is: does the field name what only they
     * produce. GREEN below is the identical found shape WITH the thing named,
     * so the pair cannot be satisfied by refusing every "found me".
     */
    public function test_the_found_narrator_check_goes_red(): void
    {
        $story = $this->storyWithBothPayoffs();
        $story->update([
            'narrator_at_exposure' => 'The man she paid found me in March and she brought me to the '
                .'hall herself, where I stood at the back and watched her make the toast.',
        ]);

        $review = app(ValidateOutlineSpine::class)->handle($story->refresh());

        $this->assertSame('weak', $review['spine']['narrator_at_exposure']['state']);
        $this->assertStringContainsString('names nothing that only they can produce', implode(' ', $review['warnings']));
        $this->assertStringContainsString('found me', (string) ($review['spine']['narrator_at_exposure']['found'] ?? ''));
    }

    public function test_the_found_narrator_check_passes_a_found_narrator_who_produces(): void
    {
        $story = $this->storyWithBothPayoffs();
        $story->update([
            'narrator_at_exposure' => 'The man she paid found me at the care home in March and she '
                .'brought me to the reception, where I put eleven months of the fees on the table '
                .'from the same account myself.',
        ]);

        $review = app(ValidateOutlineSpine::class)->handle($story->refresh());

        $this->assertSame('ok', $review['spine']['narrator_at_exposure']['state']);
        $this->assertNotEmpty($review['spine']['narrator_at_exposure']['produces'] ?? '');
        $this->assertStringContainsString('found me', (string) $review['spine']['narrator_at_exposure']['found']);
        $this->assertStringNotContainsString('reads as the search succeeding', implode(' ', $review['warnings']));
    }

    public function test_the_found_narrator_check_passes_a_negated_finding(): void
    {
        $review = app(ValidateOutlineSpine::class)->handle($this->storyWithBothPayoffs());

        $this->assertSame('ok', $review['spine']['narrator_at_exposure']['state']);
        $this->assertNotEmpty($review['spine']['narrator_at_exposure']['produces'] ?? '');
        $this->assertArrayNotHasKey('found', $review['spine']['narrator_at_exposure']);
    }

    /**
     * The fixture can express both states, asserted rather than assumed — the
     * lesson `pageFixtureFor()` taught twice, applied at the third field
     * before the drill rather than after it.
     */
    public function test_the_presence_fixture_can_express_both_states(): void
    {
        $story = $this->storyWithBothPayoffs();

        $this->assertNotSame('', trim((string) $story->withheld_information));
        $this->assertNotSame('', trim((string) $story->narrator_at_exposure));
        $this->assertTrue(
            $story->acts()->get()->every(fn (Act $act): bool => $act->timeframe !== null),
            'Every act must be declared, or turning one prior would read as a half-fixed outline '
            .'rather than as the refused shape.',
        );

        // And the shared page fixture cannot hold this, on purpose: it is
        // pre-phase, so its narrator has no departure to come back from.
        $shared = GateLayoutContractTest::pageFixtureFor(StoryStatus::Outlined);

        $this->assertSame('', trim((string) $shared->narrator_at_exposure));
        $this->assertTrue($shared->acts()->get()->every(fn (Act $act): bool => $act->timeframe === null));
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

    /**
     * GREEN: a compound is a named expression, not a hedge. Story 33 scenes
     * 34, 116, 92 and 24, verbatim in shape — four of the 21 the advisory
     * flagged there, and "half-smile" is an ordinary thing to write.
     */
    public function test_the_hedged_expression_check_ignores_a_hyphenated_compound(): void
    {
        foreach (['mouth set in a bitter half-smile', 'tired half-smile, eyes already elsewhere', 'bored, eyes half-lidded', 'driver respectful, half-standing'] as $expression) {
            $warnings = $this->warningsForFrame('Close on Erin at the kitchen table, the window dark behind her.', $expression);

            $this->assertStringNotContainsString('hedge it', implode(' ', $warnings), "\"{$expression}\" was read as a hedge");
        }
    }

    /**
     * RED, beside it: the same word standing alone is still a hedge, and a
     * hedge that sits beside a compound still fires. A boundary that simply
     * stopped matching `half` would pass the case above and this is what says
     * it did not.
     */
    public function test_the_hedged_expression_check_still_fires_on_half_as_a_word(): void
    {
        foreach (['a half smile, eyes down', 'bitter half-smile, eyes slightly narrowed'] as $expression) {
            $warnings = $this->warningsForFrame('Close on Erin at the kitchen table, the window dark behind her.', $expression);

            $this->assertStringContainsString('hedge it', implode(' ', $warnings), "\"{$expression}\" was not read as a hedge");
        }
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
