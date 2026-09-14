<?php

namespace Tests\Feature\Providers;

use Anthropic\Client;
use App\Actions\DraftScenes;
use App\Actions\GenerateActScripts;
use App\Actions\GenerateOutline;
use App\Actions\ValidateOutlineSpine;
use App\Contracts\ScriptWriter;
use App\Enums\ActPhase;
use App\Enums\ActTimeframe;
use App\Enums\Gate;
use App\Enums\StoryFormat;
use App\Enums\StoryStatus;
use App\Livewire\Gates\OutlineGate;
use App\Models\Act;
use App\Models\Character;
use App\Models\Story;
use App\Services\Claude\ClaudeScriptWriter;
use App\Services\Fake\FakeScriptWriter;
use App\Support\CharacterTextGuard;
use App\Support\LocaleGuard;
use App\Support\Providers\ActOutline;
use App\Support\ScriptSizing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use ReflectionMethod;
use Tests\TestCase;

/**
 * The genre spine, and Gate 1's ability to see when it is wrong.
 *
 * The first version of this pipeline produced competent literary fiction:
 * every act had a title, a summary and a word count in range, and there was no
 * reason to keep watching. Nothing in the database could tell you that. This
 * genre has an actual structure — first-person grievance, a self-justifying
 * antagonist, escalation that never resolves, information asymmetry, exposure
 * in front of witnesses, and then a reversal the narrator drives — and these
 * tests are the part of it that is mechanically checkable.
 *
 * The reversal half arrived second, after story 21 was watched back. That story
 * passed every check in the first half of this file and was still the wrong
 * video: it ran escalation -> escalation -> exposure -> end and gave the
 * narrator one scene of power out of two hundred and seventy. What the genre
 * pays off on is a PHASE — the narrator leaves, the antagonist searches, the
 * narrator refuses — and nothing here could see that it was missing, because
 * every field that existed was filled in correctly.
 *
 * What is asserted here is deliberately not "is the writing good". It is the
 * ways this format is actually written wrong, all of which are visible in the
 * text and all of which are cheaper to catch at Gate 1 than at Gate 3.
 */
class OutlineSpineTest extends TestCase
{
    use RefreshDatabase;

    private FakeScriptWriter $writer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->writer = app(ScriptWriter::class);
    }

    // -- The spine is generated and persisted --------------------------------

    public function test_the_outline_produces_and_stores_the_whole_spine(): void
    {
        $story = $this->draftStory();

        $draft = app(GenerateOutline::class)->handle($story, 6);

        foreach ($draft->spine() as $field => $value) {
            $this->assertNotSame('', trim($value), "The outline returned no {$field}.");
        }

        // And it lands on the story, because every act-generation call reads it
        // from there. An outline that produced a spine it did not persist would
        // generate six acts with no idea what the grievance was.
        $story->refresh();

        $this->assertNotEmpty($story->narrator_grievance);
        $this->assertNotEmpty($story->antagonist_justification);
        $this->assertNotEmpty($story->withheld_information);
        $this->assertNotEmpty($story->exposure_moment);
    }

    public function test_every_act_carries_its_own_escalation_beat(): void
    {
        $story = $this->draftStory();

        app(GenerateOutline::class)->handle($story, 6);

        $beats = $story->acts()->orderBy('sequence')->pluck('escalation_beat');

        $this->assertCount(6, $beats);
        $this->assertTrue($beats->every(fn (?string $beat): bool => trim((string) $beat) !== ''));

        // Distinct. Two acts claiming the same escalation is a flat middle,
        // which is exactly where this format loses people.
        $this->assertSame($beats->count(), $beats->unique()->count());
    }

    public function test_the_title_states_the_ending_rather_than_withholding_it(): void
    {
        // This niche does not withhold. The title is the hook precisely because
        // it promises the payoff.
        $story = $this->draftStory();

        $draft = app(GenerateOutline::class)->handle($story, 6);

        $this->assertStringContainsString('So', $draft->title);
        $this->assertSame($draft->title, $story->fresh()->title);
    }

    // -- Gate 1 sees a broken spine ------------------------------------------

    public function test_a_missing_spine_field_is_a_problem_at_gate_one(): void
    {
        $story = $this->outlinedStory();
        $story->update(['antagonist_justification' => '']);

        $review = app(ValidateOutlineSpine::class)->handle($story->refresh());

        $this->assertNotEmpty($review['problems']);
        $this->assertStringContainsString("Antagonist's justification is missing", implode(' ', $review['problems']));
        $this->assertSame('missing', $review['spine']['antagonist_justification']['state']);
    }

    public function test_a_one_line_spine_field_is_flagged_as_thin(): void
    {
        // A label is not an answer. "She was unfair" generates a video about
        // nothing in particular.
        $story = $this->outlinedStory();
        $story->update(['withheld_information' => 'She does not know.']);

        $review = app(ValidateOutlineSpine::class)->handle($story->refresh());

        $this->assertSame('thin', $review['spine']['withheld_information']['state']);
        $this->assertStringContainsString('that is a label, not an answer', implode(' ', $review['warnings']));
    }

    public function test_a_cartoon_antagonist_is_flagged(): void
    {
        // The single most common way this genre is written wrong, and the one a
        // model reaches for first: a villain who knows they are a villain. The
        // format runs on someone who believes they were owed it.
        $story = $this->outlinedStory();
        $story->update([
            'antagonist_justification' => 'She knew it was wrong the whole time and did it out of '
                .'spite because she was jealous of me, and she enjoyed watching me struggle with it.',
        ]);

        $review = app(ValidateOutlineSpine::class)->handle($story->refresh());

        $this->assertStringContainsString('reads as a cartoon', implode(' ', $review['warnings']));
        $this->assertSame('weak', $review['spine']['antagonist_justification']['state']);
    }

    public function test_a_self_justifying_antagonist_passes(): void
    {
        // The counterpart, and the more important assertion: a guard that
        // flagged every antagonist would be turned off within a week.
        $story = $this->outlinedStory();

        $review = app(ValidateOutlineSpine::class)->handle($story);

        $this->assertSame('ok', $review['spine']['antagonist_justification']['state']);
        $this->assertStringNotContainsString('cartoon', implode(' ', $review['warnings']));
    }

    public function test_an_exposure_with_nobody_watching_is_flagged(): void
    {
        // The payoff of this format is exposure before witnesses. The same
        // reveal in private is a different and much worse video.
        $story = $this->outlinedStory();
        $story->update([
            'exposure_moment' => 'I finally told her the truth about the money over the phone one '
                .'evening after she had gone home, and she went quiet for a long time.',
        ]);

        $review = app(ValidateOutlineSpine::class)->handle($story->refresh());

        $this->assertStringContainsString('does not name anyone who is there to see it', implode(' ', $review['warnings']));
        $this->assertSame('weak', $review['spine']['exposure_moment']['state']);
    }

    public function test_an_act_with_no_escalation_beat_is_a_problem(): void
    {
        $story = $this->outlinedStory();
        $story->acts()->where('sequence', 3)->update(['escalation_beat' => '']);

        $review = app(ValidateOutlineSpine::class)->handle($story->refresh());

        $this->assertStringContainsString('Act(s) 3 have no beat', implode(' ', $review['problems']));
    }

    public function test_two_acts_escalating_identically_are_flagged(): void
    {
        // A flat middle. Every act has to cost more than the one before it, not
        // the same again.
        $story = $this->outlinedStory();
        $repeat = $story->acts()->where('sequence', 2)->value('escalation_beat');
        $story->acts()->where('sequence', 4)->update(['escalation_beat' => $repeat]);

        $review = app(ValidateOutlineSpine::class)->handle($story->refresh());

        $this->assertStringContainsString('Acts 2 and 4 escalate identically', implode(' ', $review['warnings']));
    }

    public function test_a_healthy_outline_raises_nothing(): void
    {
        $review = app(ValidateOutlineSpine::class)->handle($this->outlinedStory());

        $this->assertSame([], $review['problems']);
        $this->assertSame([], $review['warnings']);
    }

    public function test_gate_one_surfaces_the_review_and_lets_the_operator_fix_it(): void
    {
        $story = $this->outlinedStory();
        $story->update(['exposure_moment' => '']);

        $component = Livewire::test(OutlineGate::class, ['story' => $story->refresh()]);

        $this->assertNotEmpty($component->instance()->spineReview()['problems']);

        // Editable at Gate 1, which is the only place it is cheap to fix.
        $component
            ->set('spine.exposure_moment', 'At the rehearsal dinner, in front of both families and '
                .'the friends who had been told I refused to help, when she thanked everyone by name.')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame([], app(ValidateOutlineSpine::class)->handle($story->fresh())['problems']);
    }

    public function test_gate_one_does_not_block_on_a_structural_warning(): void
    {
        // The operator's judgment beats the heuristic. A guard that refused
        // approval on "reads as a cartoon" would be a guard that gets removed.
        $story = $this->outlinedStory();
        $story->update(['antagonist_justification' => 'She was jealous and she knew it was wrong.']);

        Livewire::test(OutlineGate::class, ['story' => $story->refresh()])
            ->call('approve')
            ->assertHasNoErrors();

        $this->assertSame(StoryStatus::Scripted, $story->fresh()->status);
    }

    // -- The reversal phase ---------------------------------------------------
    //
    // Story 21 ran escalation -> escalation -> exposure -> end and gave the
    // narrator one scene of power out of two hundred and seventy. Every check
    // above passed on it. What the genre actually pays off on is a phase: the
    // narrator LEAVES, the antagonist SEARCHES, the narrator REFUSES.

    public function test_the_outline_produces_the_reversal_half_of_the_spine(): void
    {
        $story = $this->draftStory();

        $draft = app(GenerateOutline::class)->handle($story);

        $this->assertNotSame('', trim($draft->departure));
        $this->assertNotSame('', trim($draft->reversalBeats));
        $this->assertNotSame('', trim($draft->refusal));

        $story->refresh();

        $this->assertNotEmpty($story->departure);
        $this->assertNotEmpty($story->reversal_beats);
        $this->assertNotEmpty($story->refusal);
    }

    public function test_a_single_narrative_gets_six_acts_by_default(): void
    {
        // It was six, then seven, and it is six again — and the two moves were
        // made for different reasons, both of which are still true.
        //
        // Six -> seven was for the reversal: the arc had been escalation ->
        // exposure -> end, and the departure, the search and the refusal needed
        // somewhere to go. Seven -> six is because the writer's natural act
        // length was then MEASURED at ~1,100 words and the word target barely
        // steers it (fitted slope +0.30). Seven acts of that is 39.9 minutes
        // against a 30-40 window; six is 34.2.
        //
        // The reversal is not what gives ground — see the phase test below.
        $story = $this->draftStory();

        app(GenerateOutline::class)->handle($story);

        // Read off the constant rather than restated here. `ActCountTest` is
        // the one place that pins the NUMBER; a second literal is a second
        // place to correct when it moves, and it has now moved twice.
        $this->assertSame(
            GenerateOutline::DEFAULT_ACTS_SINGLE,
            $story->acts()->count(),
        );
        $this->assertSame(
            GenerateOutline::DEFAULT_ACTS_SINGLE,
            GenerateOutline::defaultActCountFor($story),
        );
    }

    public function test_the_acts_are_laid_out_across_the_four_phases(): void
    {
        // Escalation through roughly the first two thirds, then the departure,
        // then the search and the refusal. The reversal is three acts of six,
        // not the last ninety seconds of act six.
        //
        // THE PART THE ACT-COUNT CHANGE HAD TO NOT BREAK. Dropping seven to six
        // costs one ESCALATION act — four down to three — and nothing else.
        // `departureActFor()` never lets the departure past `count - 2`, so the
        // search and the refusal always have an act each. A six that had eaten
        // one of those would be the trade the seven was chosen to avoid.
        $story = $this->draftStory();

        app(GenerateOutline::class)->handle($story);

        // The plan for whatever the default count is, not a transcription of
        // it. What this test is FOR is that the outline persists the plan
        // `ActPhase` computes; which phases that plan contains at a given
        // count is `ActCountTest`'s subject and is asserted there.
        $this->assertSame(
            ActPhase::planFor(GenerateOutline::DEFAULT_ACTS_SINGLE),
            $story->acts()->orderBy('sequence')->get()->pluck('phase', 'sequence')->all(),
        );

        // And all three reversal phases are present whatever the count is.
        foreach ([ActPhase::Departure, ActPhase::Search, ActPhase::Refusal] as $phase) {
            $this->assertContains(
                $phase,
                $story->acts()->orderBy('sequence')->get()->pluck('phase')->all(),
                sprintf('%s is missing from the default act plan.', $phase->value),
            );
        }
    }

    public function test_an_anthology_act_carries_no_phase(): void
    {
        // Each act is a self-contained story running the whole arc internally.
        // A per-act phase there would be a claim about five different narrators.
        $story = $this->draftStory();
        $story->update(['format' => StoryFormat::Anthology]);

        app(GenerateOutline::class)->handle($story->refresh());

        $this->assertTrue($story->acts()->get()->every(fn (Act $act): bool => $act->phase === null));
    }

    public function test_the_act_writer_is_handed_the_phase_and_the_beat(): void
    {
        // Both of these were computed at outline, shown at Gate 1, and dropped
        // on the way to the call that needed them. A prompt asking for
        // something the caller never sends is a documented guard with nothing
        // behind it.
        $story = $this->draftStory();

        app(GenerateOutline::class)->handle($story);
        $story->refresh()->approveGate(Gate::Outline);

        $this->writer->calls = [];

        app(GenerateActScripts::class)->handle($story->refresh());

        $calls = collect($this->writer->calls)->where('method', 'actScript')->keyBy('sequence');

        $count = GenerateOutline::DEFAULT_ACTS_SINGLE;
        $departure = ActPhase::departureActFor($count);

        $this->assertSame('escalation', $calls[1]['phase']);
        $this->assertSame('departure', $calls[$departure]['phase']);
        $this->assertSame('search', $calls[$departure + 1]['phase']);
        $this->assertSame('refusal', $calls[$count]['phase']);

        $this->assertTrue(
            $calls->every(fn (array $call): bool => trim((string) $call['escalation_beat']) !== ''),
            'An act was written without the beat the outline recorded for it.',
        );
    }

    /**
     * THE SAME ASSERTION AT THE SECOND CALL SITE, which is where it was missing.
     *
     * The test above was written when `escalation_beat` was found never to reach
     * `GenerateActScripts`. It closed that finding — at one call site. The scene
     * generator decides what 150-250 pictures contain, it had been receiving
     * `Act` and `Story` the whole time, and it read neither the phase, the beat
     * nor the spine off them. Nothing was watching, because the fake's scene
     * path did not record what it was handed the way its act path did.
     *
     * The general defect is that NOTHING ASKS WHICH OTHER CALLERS READ A FIELD,
     * and it has now cost twice — here, and `CostUnit::TotalTokens` added in
     * code while eleven migrations built their columns from the enum. This pair
     * of tests is the cheap version of the answer: when a field is wired to one
     * consumer, assert its arrival at every consumer in the same change.
     */
    public function test_the_scene_writer_is_handed_the_phase_the_beat_and_the_spine(): void
    {
        $story = $this->draftStory();

        app(GenerateOutline::class)->handle($story);
        $story->refresh()->approveGate(Gate::Outline);
        app(GenerateActScripts::class)->handle($story->refresh());

        // The cast is a hard precondition of drafting — every prompt is built
        // from it — so it is created rather than extracted, which keeps this
        // case about what the SCENE call is handed.
        Character::factory()->for($story)->create(['name' => 'Dana Whitfield']);
        Character::factory()->for($story)->create(['name' => 'Erin Whitfield']);

        $this->writer->calls = [];

        app(DraftScenes::class)->handle($story->refresh());

        $calls = collect($this->writer->calls)->where('method', 'scenes')->keyBy('act');

        $this->assertNotEmpty($calls, 'No scene call was recorded at all.');

        $this->assertSame('escalation', $calls[1]['phase']);
        $this->assertSame('refusal', $calls[GenerateOutline::DEFAULT_ACTS_SINGLE]['phase']);

        $this->assertTrue(
            $calls->every(fn (array $call): bool => trim((string) $call['escalation_beat']) !== ''),
            'An act had its scenes cut without the beat saying what that act costs and to whom. '
            .'The frame is the picture the narration is spoken over, and what a face should be '
            .'doing depends on which way the ground is moving.',
        );

        $this->assertTrue(
            $calls->every(fn (array $call): bool => trim((string) $call['narrator_grievance']) !== ''
                && trim((string) $call['antagonist_justification']) !== ''),
            'The scene generator was not handed the genre spine it is cutting a story out of.',
        );
    }

    // -- Gate 1 sees a broken reversal ----------------------------------------

    public function test_an_announced_departure_is_flagged(): void
    {
        // The check with the least margin in it. An announced departure does
        // not weaken the reversal, it removes it: there is nothing to search
        // for, and the search is the next third of the video.
        $story = $this->outlinedStory();
        $story->update([
            'departure' => 'She tells the family at Sunday dinner that she is leaving in the morning, '
                .'and she leaves a note for Dana on the kitchen table explaining where she has gone.',
        ]);

        $review = app(ValidateOutlineSpine::class)->handle($story->refresh());

        $this->assertStringContainsString('reads as announced', implode(' ', $review['warnings']));
        $this->assertSame('weak', $review['spine']['departure']['state']);
    }

    public function test_a_departure_that_negates_the_telling_passes(): void
    {
        // The counterpart, and the one that matters more: this field is as
        // likely to say "without telling anyone" as the opposite, and a guard
        // that flagged the good case is a guard that gets turned off.
        $story = $this->outlinedStory();
        $story->update([
            'departure' => 'She goes the week after the reception without telling them she is going, '
                .'and she does not leave a note or a number. Dana works out that she is gone eleven '
                .'days later, from somebody else.',
        ]);

        $review = app(ValidateOutlineSpine::class)->handle($story->refresh());

        $this->assertStringNotContainsString('reads as announced', implode(' ', $review['warnings']));
        $this->assertSame('ok', $review['spine']['departure']['state']);
    }

    public function test_a_search_that_costs_the_antagonist_nothing_is_flagged(): void
    {
        $story = $this->outlinedStory();
        $story->update([
            'reversal_beats' => 'Dana wonders where she went and thinks about her often. She asks '
                .'herself the same question again over the following winter and finds no answer.',
        ]);

        $review = app(ValidateOutlineSpine::class)->handle($story->refresh());

        $this->assertStringContainsString('costs the antagonist nothing', implode(' ', $review['warnings']));
        $this->assertSame('weak', $review['spine']['reversal_beats']['state']);
    }

    public function test_a_search_that_is_one_attempt_is_a_phase_that_is_a_scene(): void
    {
        $story = $this->outlinedStory();
        $story->update([
            'reversal_beats' => 'Dana pays a man a month of her savings to find the new address and '
                .'he never calls her back about any of it, not once, not ever, not even after that',
        ]);

        $review = app(ValidateOutlineSpine::class)->handle($story->refresh());

        $this->assertStringContainsString('reads as a single attempt', implode(' ', $review['warnings']));
    }

    public function test_a_refusal_that_answers_nothing_named_earlier_is_flagged(): void
    {
        $story = $this->outlinedStory();
        $story->update([
            'refusal' => 'When she finally asks, the answer is no, and it stays no however long she '
                .'waits on the step outside for some other answer to arrive instead.',
        ]);

        $review = app(ValidateOutlineSpine::class)->handle($story->refresh());

        $this->assertStringContainsString('does not answer any earlier moment', implode(' ', $review['warnings']));
        $this->assertSame('weak', $review['spine']['refusal']['state']);
    }

    public function test_a_refusal_that_answers_an_earlier_moment_names_which_one(): void
    {
        // "It answers something" is worth less at Gate 1 than "it answers the
        // grievance", so the check reports the match rather than only its
        // absence.
        $story = $this->outlinedStory();

        $review = app(ValidateOutlineSpine::class)->handle($story);

        $this->assertSame('ok', $review['spine']['refusal']['state']);
        $this->assertNotEmpty($review['spine']['refusal']['answers'] ?? '');
    }

    public function test_an_outline_with_no_departure_act_is_a_problem(): void
    {
        // The exact shape story 21 shipped as: escalation all the way down.
        $story = $this->outlinedStory();
        $story->acts()->update(['phase' => ActPhase::Escalation]);

        $review = app(ValidateOutlineSpine::class)->handle($story->refresh());

        $this->assertStringContainsString('No act contains the departure', implode(' ', $review['problems']));
    }

    public function test_a_departure_in_the_last_two_acts_is_flagged_as_compressed(): void
    {
        // Hand-set, not generated: the point is an outline the planner would
        // never produce, so the act numbers move with the default rather than
        // being a second statement of it.
        $story = $this->outlinedStory();
        $last = $story->acts()->max('sequence');

        $story->acts()->where('sequence', '<', $last - 1)->update(['phase' => ActPhase::Escalation]);
        $story->acts()->where('sequence', $last - 1)->update(['phase' => ActPhase::Departure]);
        $story->acts()->where('sequence', $last)->update(['phase' => ActPhase::Refusal]);

        $review = app(ValidateOutlineSpine::class)->handle($story->refresh());

        $this->assertStringContainsString(
            sprintf('The narrator leaves in act %d of %d', $last - 1, $last),
            implode(' ', $review['warnings']),
        );
    }

    public function test_an_outline_written_before_the_reversal_says_so_once(): void
    {
        // Stories 9, 12, 20 and 21 are all this — every outline written before
        // the reversal phase existed. Four "missing" problems on a shipped video
        // would be four red boxes about one nameable thing, and the thing is not
        // that somebody forgot to fill a field in.
        //
        // THE HOOK IS BLANKED HERE TOO, and it has to be: an outline generated
        // before the reversal phase was generated before this field existed, so
        // a fixture that left one behind would describe a story that cannot
        // exist and would take the ordinary per-field path instead.
        $story = $this->outlinedStory();
        $story->acts()->update(['phase' => null]);
        $story->update([
            'hook' => '',
            'departure' => '',
            'reversal_beats' => '',
            'refusal' => '',
        ]);

        $review = app(ValidateOutlineSpine::class)->handle($story->refresh());

        $this->assertSame([], $review['problems']);
        $this->assertStringContainsString(
            'generated before the reversal phase existed',
            implode(' ', $review['warnings']),
        );

        foreach (['hook', 'departure', 'reversal_beats', 'refusal'] as $field) {
            $this->assertSame('absent', $review['spine'][$field]['state']);
        }
    }

    public function test_filling_one_reversal_field_by_hand_brings_the_checks_back(): void
    {
        // The legacy excuse is for an outline that predates the field, not for
        // one somebody is part way through fixing.
        $story = $this->outlinedStory();
        $story->acts()->update(['phase' => null]);
        $story->update([
            'departure' => 'She goes without telling any of them, and changes her number the same day '
                .'so that the only way back to her is through their mother.',
            'reversal_beats' => '',
            'refusal' => '',
        ]);

        $review = app(ValidateOutlineSpine::class)->handle($story->refresh());

        $this->assertStringContainsString('Reversal beats is missing', implode(' ', $review['problems']));
        $this->assertStringContainsString('Refusal is missing', implode(' ', $review['problems']));
    }

    // -- When an act is set, and the narrator at the exposure ----------------
    //
    // Two fields from one measurement, both the shape of `is_hook`: something
    // the genre needs that nothing asked for. Story 28's present-day betrayal
    // lands at 20:18 because two of its three escalation acts stage 2015 and
    // 2017 in full, and the outline had said so in a summary nothing could
    // refuse. Stories 23 and 28 both hear about their own exposure from
    // somebody who was there, because a document produced the withheld
    // information and the narrator was 800 km away. Story 25 does neither,
    // and is the story that works.

    public function test_the_outline_stores_the_timeframe_and_the_narrator_at_exposure(): void
    {
        $story = $this->draftStory();

        app(GenerateOutline::class)->handle($story);
        $story->refresh();

        $this->assertNotSame('', trim((string) $story->narrator_at_exposure));

        $this->assertTrue(
            $story->acts()->get()->every(fn (Act $act): bool => $act->timeframe === ActTimeframe::Present),
            'Every act the outline wrote should carry the timeframe it declared.',
        );
    }

    /**
     * The consumer question, asked in the change that added the field rather
     * than a phase later. Both `escalation_beat` and `phase` were found
     * missing at this call after being wired everywhere else.
     */
    public function test_the_act_writer_is_handed_the_timeframe_and_the_narrator_at_exposure(): void
    {
        $story = $this->draftStory();

        app(GenerateOutline::class)->handle($story);
        $story->refresh()->approveGate(Gate::Outline);

        $this->writer->calls = [];

        app(GenerateActScripts::class)->handle($story->refresh());

        $calls = collect($this->writer->calls)->where('method', 'actScript');

        $this->assertNotEmpty($calls);
        $this->assertTrue(
            $calls->every(fn (array $call): bool => $call['timeframe'] === 'present'),
            'An act was written without being told when it is set.',
        );
        $this->assertTrue(
            $calls->every(fn (array $call): bool => trim((string) $call['narrator_at_exposure']) !== ''),
            'An act was written without knowing how the narrator is in the room for the exposure. '
            .'The escalation acts plant what the narrator will produce; the search acts must not '
            .'have her find it.',
        );
    }

    public function test_the_scene_writer_is_handed_the_timeframe(): void
    {
        $story = $this->draftStory();

        app(GenerateOutline::class)->handle($story);
        $story->refresh()->approveGate(Gate::Outline);
        app(GenerateActScripts::class)->handle($story->refresh());

        Character::factory()->for($story)->create(['name' => 'Dana Whitfield']);
        Character::factory()->for($story)->create(['name' => 'Erin Whitfield']);

        $this->writer->calls = [];

        app(DraftScenes::class)->handle($story->refresh());

        $calls = collect($this->writer->calls)->where('method', 'scenes');

        $this->assertNotEmpty($calls);
        $this->assertTrue(
            $calls->every(fn (array $call): bool => $call['timeframe'] === 'present'),
            'The scene writer is the stage that would draw a flashback still for a cited sentence, '
            .'and it was not told the act is set in the present.',
        );
    }

    /**
     * The prompt half of the same question. The fake records what it is
     * HANDED; this asserts what the real builder SAYS with it, through the
     * same private method the worker calls.
     */
    public function test_the_act_prompt_states_the_timeframe_and_the_narrator_at_exposure(): void
    {
        $story = $this->draftStory();

        app(GenerateOutline::class)->handle($story);
        $story->refresh();

        $act = $story->acts()->where('sequence', 2)->first();
        $outline = $story->acts()->orderBy('sequence')->get()->map(fn (Act $entry): ActOutline => new ActOutline(
            sequence: $entry->sequence,
            title: (string) $entry->title,
            summary: (string) $entry->summary,
            escalationBeat: (string) $entry->escalation_beat,
            phase: $entry->phase,
            timeframe: $entry->timeframe,
        ))->all();

        $writer = new ClaudeScriptWriter(
            app(Client::class),
            app(LocaleGuard::class),
            app(CharacterTextGuard::class),
        );

        $method = new ReflectionMethod($writer, 'actPrompt');
        $prompt = $method->invoke($writer, $story, $outline[1], $outline, ['Act 1 happened.'], 985);

        $this->assertStringContainsString('SET IN THE STORY\'S PRESENT', $prompt);
        $this->assertStringContainsString('How the narrator is in the room for it:', $prompt);
        $this->assertStringContainsString('[ESCALATION · PRESENT]', $prompt);

        $priorSummaries = array_fill(0, count($outline) - 1, 'An act happened.');
        $final = $method->invoke($writer, $story, $outline[count($outline) - 1], $outline, $priorSummaries, 985);

        $this->assertStringContainsString('THE NARRATOR IS IN THE ROOM FOR IT', $final);
        // The found shape is legal since the transcript was read; what the
        // prompt must still say is that the scene is the narrator's.
        $this->assertStringContainsString('or she came to where they are', $final);
        $this->assertStringContainsString('The narrator decides where and how long they talk', $final);

        // The scene call too. The fake's record for that call reads the
        // timeframe off the Act model, so it cannot go red if this line is
        // dropped from the prompt; this can.
        $context = (new ReflectionMethod($writer, 'sceneContext'))->invoke($writer, $story, $act);

        $this->assertStringContainsString('SET IN: the story\'s present', $context);
    }

    public function test_an_act_set_in_the_past_is_a_problem_that_quotes_the_summary(): void
    {
        $story = $this->outlinedStory();
        $story->acts()->where('sequence', 2)->update([
            'timeframe' => ActTimeframe::Prior,
            'summary' => 'Act 2 tells the second betrayal in full. February 2017, eleven months after '
                .'the funeral, she came into the bathroom and told him about the man from Suzhou.',
        ]);

        $review = app(ValidateOutlineSpine::class)->handle($story->refresh());

        $problems = implode(' ', $review['problems']);

        $this->assertStringContainsString('Act 2 is set before the story\'s present', $problems);
        $this->assertStringContainsString('Act 2 tells the second betrayal in full', $problems);
    }

    public function test_a_half_declared_outline_is_a_problem(): void
    {
        $story = $this->outlinedStory();
        $story->acts()->where('sequence', 3)->update(['timeframe' => null]);

        $review = app(ValidateOutlineSpine::class)->handle($story->refresh());

        $this->assertStringContainsString(
            'Act(s) 3 do not say whether they are set',
            implode(' ', $review['problems']),
        );
    }

    public function test_a_missing_narrator_at_exposure_is_a_problem_on_an_outline_that_was_asked(): void
    {
        $story = $this->outlinedStory();
        $story->update(['narrator_at_exposure' => '']);

        $review = app(ValidateOutlineSpine::class)->handle($story->refresh());

        $this->assertStringContainsString(
            'Narrator at the exposure is missing',
            implode(' ', $review['problems']),
        );
        $this->assertSame('missing', $review['spine']['narrator_at_exposure']['state']);
    }

    public function test_the_narrator_at_exposure_names_what_only_they_produce(): void
    {
        $story = $this->outlinedStory();

        $review = app(ValidateOutlineSpine::class)->handle($story);

        $this->assertSame('ok', $review['spine']['narrator_at_exposure']['state']);
        $this->assertNotEmpty(
            $review['spine']['narrator_at_exposure']['produces'] ?? '',
            'The field must name WHICH sentence of the withheld information the narrator produces '
            .'in person. "They produce something" is worth less than "they produce the care home fees".',
        );
    }

    public function test_a_narrator_at_exposure_that_shares_nothing_with_the_withheld_information_is_flagged(): void
    {
        $story = $this->outlinedStory();
        $story->update([
            'narrator_at_exposure' => 'I turn up at the reception unexpected and stand at the back '
                .'of the hall while the toasts are read, and nobody knows I am there until the end.',
        ]);

        $review = app(ValidateOutlineSpine::class)->handle($story->refresh());

        $this->assertSame('weak', $review['spine']['narrator_at_exposure']['state']);
        $this->assertStringContainsString(
            'names nothing that only they can produce',
            implode(' ', $review['warnings']),
        );
    }

    /**
     * A found narrator is NOT weak any more, and the reason is on the record
     * in CLAUDE.md 3d: "the search fails" was derived from a reference title,
     * and the first reference transcript read has her find him — begging the
     * student records office — and kneel in public when she does. What the
     * field still has to do is name what the narrator produces; the overlap
     * check decides that, and the found shape is recorded as a note.
     */
    public function test_a_narrator_the_search_found_is_noted_and_judged_on_what_they_produce(): void
    {
        $story = $this->outlinedStory();
        $story->update([
            'narrator_at_exposure' => 'The investigator she paid finally tracked me down to the care '
                .'home, and she brought me to the reception herself to put the fees on the table.',
        ]);

        $review = app(ValidateOutlineSpine::class)->handle($story->refresh());

        $this->assertSame('ok', $review['spine']['narrator_at_exposure']['state']);
        $this->assertStringContainsString('tracked me down', (string) $review['spine']['narrator_at_exposure']['found']);
        $this->assertStringNotContainsString('reads as the search succeeding', implode(' ', $review['warnings']));
    }

    public function test_a_found_narrator_who_produces_nothing_is_still_weak(): void
    {
        // The found shape is legal; producing nothing is not. The second half
        // of the check is what a found narrator is now judged on.
        $story = $this->outlinedStory();
        $story->update([
            'narrator_at_exposure' => 'The investigator she paid finally tracked me down and she '
                .'brought me to the hall herself, where I stood at the back and watched.',
        ]);

        $review = app(ValidateOutlineSpine::class)->handle($story->refresh());

        $this->assertSame('weak', $review['spine']['narrator_at_exposure']['state']);
        $this->assertStringContainsString('names nothing that only they can produce', implode(' ', $review['warnings']));
    }

    public function test_a_narrator_who_was_not_found_passes(): void
    {
        // The good case contains the marker, negated. "She did not find me. I
        // came." is exactly what the field should say.
        $story = $this->outlinedStory();
        $story->update([
            'narrator_at_exposure' => 'She never found me. I came to the reception on my own, chose '
                .'the moment, and put the care home fees from the same account on the table myself.',
        ]);

        $review = app(ValidateOutlineSpine::class)->handle($story->refresh());

        $this->assertSame('ok', $review['spine']['narrator_at_exposure']['state']);
    }

    public function test_an_outline_written_before_the_timeframe_was_asked_says_so_once(): void
    {
        // Stories 22 through 28: outlined with the reversal phase, before
        // either question existed. One warning naming both, no problems, and
        // the field reads as absent rather than missing.
        $story = $this->outlinedStory();
        $story->acts()->update(['timeframe' => null]);
        $story->update(['narrator_at_exposure' => '']);

        $review = app(ValidateOutlineSpine::class)->handle($story->refresh());

        $this->assertSame([], $review['problems']);
        $this->assertStringContainsString(
            'generated before two questions were asked of it',
            implode(' ', $review['warnings']),
        );
        $this->assertSame('absent', $review['spine']['narrator_at_exposure']['state']);
    }

    public function test_typing_the_narrator_at_exposure_in_by_hand_brings_the_timeframe_check_back(): void
    {
        $story = $this->outlinedStory();
        $story->acts()->update(['timeframe' => null]);
        $story->update([
            'narrator_at_exposure' => 'I come to the reception uninvited and put the care home fees '
                .'from the same account on the table myself. She did not find me.',
        ]);

        $review = app(ValidateOutlineSpine::class)->handle($story->refresh());

        $this->assertStringContainsString(
            'No act says whether it is set in the story\'s present',
            implode(' ', $review['problems']),
        );
        $this->assertStringNotContainsString(
            'generated before two questions were asked',
            implode(' ', $review['warnings']),
        );
    }

    public function test_gate_one_lets_the_operator_write_the_reversal(): void
    {
        $story = $this->outlinedStory();
        $story->update(['refusal' => '']);

        Livewire::test(OutlineGate::class, ['story' => $story->refresh()])
            ->assertSee('Refusal')
            ->set('spine.refusal', 'When Dana found me, she asked for help because family helps '
                .'family, and I gave her the sentence she had used on me for eleven months and '
                .'nothing else.')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertStringContainsString('eleven months', (string) $story->fresh()->refusal);
    }

    // -- Format --------------------------------------------------------------

    public function test_single_is_the_default_shape_for_this_genre(): void
    {
        // Escalation compounds across one continuous narrative and cannot
        // compound across five separate ones.
        $this->assertSame(StoryFormat::Single, Story::factory()->create()->format);
    }

    public function test_an_anthology_is_warned_about_rather_than_refused(): void
    {
        $story = $this->outlinedStory();
        $story->update(['format' => StoryFormat::Anthology]);

        $review = app(ValidateOutlineSpine::class)->handle($story->refresh());

        $this->assertStringContainsString('cannot compound across separate ones', implode(' ', $review['warnings']));
        $this->assertSame([], $review['problems'], 'An anthology is an operator choice, not an error.');
    }

    // -- Fixtures ------------------------------------------------------------

    private function draftStory(): Story
    {
        return Story::factory()->status(StoryStatus::Draft)->create([
            'format' => StoryFormat::Single,
            'locale_profile' => 'en-US',
            'target_duration_min' => 30,
            'target_duration_max' => 40,
            'premise' => 'My sister billed me for her entire wedding over eleven months and told the '
                .'family I had offered.',
        ]);
    }

    // -- The hook -----------------------------------------------------------

    /**
     * The outline answers what the first thirty seconds are.
     *
     * It had no opinion at all until now, which is why the act 1 call could
     * only ever be told what NOT to do. Both shipped stories opened on the
     * chronological beginning and both contain four of the five beats a hook
     * needs, two to seven minutes further down.
     */
    public function test_the_outline_produces_and_stores_a_hook(): void
    {
        $story = $this->draftStory();

        $draft = app(GenerateOutline::class)->handle($story);

        $this->assertNotSame('', trim($draft->hook));
        $this->assertNotEmpty($story->refresh()->hook);
    }

    /**
     * The hook's closing line promises the departure, and Gate 1 says which
     * part of it.
     *
     * The same argument as the refusal naming the moment it answers: "it
     * promises something" is worth less in front of an approve button than the
     * sentence it promises.
     */
    public function test_a_hook_that_promises_the_departure_names_which_part(): void
    {
        $story = $this->outlinedStory();

        $review = app(ValidateOutlineSpine::class)->handle($story);

        $this->assertSame('ok', $review['spine']['hook']['state']);
        $this->assertNotEmpty($review['spine']['hook']['promises'] ?? '');
    }

    /**
     * A hook closing on the exposure is selling a different video.
     *
     * THE MISMATCH CLASS, not a missing field. The exposure is the public
     * payoff and it is what the TITLE promises; the hook promises the gap
     * before it. A closing line about reading out the receipts sells a
     * reckoning, and this outline's middle third is a search.
     */
    public function test_a_hook_that_closes_on_the_exposure_is_flagged(): void
    {
        $story = $this->outlinedStory();

        // Beats 1-4 unchanged and correct. Only the promise moves, which is
        // the point: this is not a badly written hook.
        $story->update([
            'hook' => 'My older sister Dana got married in June and I paid for all of it. '
                .'The first invoice arrived eleven days after she asked me to stand up with her. '
                .'She told me, "You have no kids and no mortgage, and family helps family." '
                .'I opened a spreadsheet that night and named it DANA WEDDING. '
                .'At the reception, in front of eighty guests and both families, I stood up and '
                .'read out every receipt.',
        ]);

        $review = app(ValidateOutlineSpine::class)->handle($story->refresh());

        $this->assertSame('weak', $review['spine']['hook']['state']);
        $this->assertStringContainsString(
            'closes on the exposure rather than the departure',
            implode(' ', $review['warnings']),
        );
    }

    /** A closing line that reaches nothing nameable is reported as that. */
    public function test_a_hook_that_promises_nothing_is_flagged(): void
    {
        $story = $this->outlinedStory();

        $story->update([
            'hook' => 'My older sister Dana got married in June and I paid for all of it. '
                .'The first invoice arrived eleven days after she asked me to stand up with her. '
                .'She told me, "You have no kids and no mortgage, and family helps family." '
                .'I opened a spreadsheet that night and named it DANA WEDDING. '
                .'Nothing was ever the same again after that, and I think about it a lot.',
        ]);

        $review = app(ValidateOutlineSpine::class)->handle($story->refresh());

        $this->assertSame('weak', $review['spine']['hook']['state']);
        $this->assertStringContainsString(
            'does not close on the departure',
            implode(' ', $review['warnings']),
        );
    }

    /**
     * Only the LAST line is the promise.
     *
     * A hook whose betrayal beat happens to reuse the departure's language
     * would otherwise pass while closing on nothing — and beats 2 and 3 are
     * about the same family as the departure, so an overlap between them is
     * expected rather than evidence.
     */
    public function test_the_promise_is_read_from_the_closing_line_only(): void
    {
        $story = $this->outlinedStory();

        $story->update([
            'hook' => 'The week I moved out of the apartment and left no address was the week she '
                .'sent the last invoice. She told me, "You have no kids and no mortgage." '
                .'I think about that sentence a great deal these days.',
        ]);

        $review = app(ValidateOutlineSpine::class)->handle($story->refresh());

        $this->assertSame('weak', $review['spine']['hook']['state']);
    }

    /**
     * A story with no departure gets no hook finding.
     *
     * Four stories are in this position — 9, 12, 20 and 21, every outline
     * written before the reversal phase existed — and the blanket legacy
     * warning has already named it once. A second finding saying the hook
     * promises nothing would be a per-field problem on a shipped video about an
     * absence the line above it already reported, and it is not one the
     * operator can act on without regenerating the outline.
     */
    public function test_a_story_with_nothing_to_promise_gets_no_hook_finding(): void
    {
        $story = $this->outlinedStory();
        $story->acts()->update(['phase' => null]);
        $story->update(['hook' => '', 'departure' => '', 'reversal_beats' => '', 'refusal' => '']);

        $review = app(ValidateOutlineSpine::class)->handle($story->refresh());

        $this->assertSame([], $review['problems']);
        $this->assertSame('absent', $review['spine']['hook']['state']);

        foreach ($review['warnings'] as $warning) {
            $this->assertStringNotContainsString('the departure', $warning);
        }
    }

    /**
     * A hook with no departure BESIDE it raises no promise finding either.
     *
     * THE HALF OF THE GUARD NO TEST COULD REACH, and it was found by a drill
     * PASSING rather than by design: removing the `$departure === ''` half of
     * the early return left every hook test green, because the only fixture
     * that exercised the return had an empty hook as well and stopped on the
     * first half of the condition. A guard whose second clause nothing can
     * reach is indistinguishable from one that is not there.
     *
     * The state is real and is not the legacy one. An operator who types a hook
     * into Gate 1 on a story whose departure is still empty has ENDED the
     * blanket excuse — that is what filling one field by hand does — so the
     * departure is a PROBLEM one panel up. A warning here saying the hook
     * promises nothing is a second finding about that same absence, and both
     * are repaired by one edit.
     */
    public function test_a_hook_with_no_departure_beside_it_raises_no_promise_finding(): void
    {
        $story = $this->outlinedStory();
        $story->update(['departure' => '']);

        $review = app(ValidateOutlineSpine::class)->handle($story->refresh());

        // The departure's own absence is reported, once, where it belongs.
        $this->assertStringContainsString('Departure is missing', implode(' ', $review['problems']));

        foreach ($review['warnings'] as $warning) {
            $this->assertStringNotContainsString('close on the departure', $warning);
        }

        $this->assertSame('ok', $review['spine']['hook']['state']);
    }

    /**
     * An operator who has started writing one by hand gets the ordinary checks.
     *
     * The legacy predicate is about an outline the generator produced before
     * these fields existed. A hook typed into Gate 1 is not that, so the
     * blanket excuse stops applying to every field including this one — which
     * is the behaviour `departure` already had, extended to the field beside
     * it rather than written a second time.
     */
    public function test_a_hand_written_hook_ends_the_blanket_excuse(): void
    {
        $story = $this->outlinedStory();
        $story->acts()->update(['phase' => null]);
        $story->update(['departure' => '', 'reversal_beats' => '', 'refusal' => '']);

        $review = app(ValidateOutlineSpine::class)->handle($story->refresh());

        $this->assertStringNotContainsString(
            'generated before the reversal phase existed',
            implode(' ', $review['warnings']),
        );
        $this->assertNotSame([], $review['problems']);
    }

    /**
     * The twenty seconds is one story's own sizing rate, in one place.
     *
     * Not `NarrationPace`. The budget is an instruction to the WRITER about how
     * much text it may spend, and it rides in the same act 1 prompt as the word
     * target — two rates in one prompt is the $2.12 / $4.24 / 42,017 shape
     * reproduced inside a single string.
     */
    public function test_the_hook_word_budget_uses_the_frozen_sizing_rate(): void
    {
        $story = $this->draftStory();

        // Frozen at 160, like every story generated before the correction.
        $story->forceFill(['sized_against_wpm' => 160])->save();
        $this->assertSame(53, ScriptSizing::hookBetrayalWords($story));

        // Sized today, at the measured rate. Same twenty seconds.
        $story->forceFill(['sized_against_wpm' => 197])->save();
        $this->assertSame(66, ScriptSizing::hookBetrayalWords($story));
    }

    /**
     * Act 1 is handed the beats, the budget and the outline's own hook.
     *
     * Read off the REAL prompt builder rather than off the fake, because the
     * fake has no prompt: this is the seam where a field required at outline,
     * checked at Gate 1 and shown on the page can go missing on the way to the
     * only call it exists for. `escalation_beat` did exactly that for two
     * phases, and nothing could see it.
     */
    public function test_act_one_is_handed_the_hook_the_outline_wrote(): void
    {
        $story = $this->outlinedStory();
        $act = new ActOutline(sequence: 1, title: 'The First Invoice', summary: 'It arrives.');

        $prompt = $this->actPrompt($story, $act, 985);

        $this->assertStringContainsString('THIS ACT OPENS THE VIDEO', $prompt);
        $this->assertStringContainsString('THIS IS THE OPENING THE OUTLINE WROTE FOR THIS STORY', $prompt);
        $this->assertStringContainsString(trim((string) $story->hook), $prompt);

        // AND IT IS THE LAST THING IN THE PROMPT BEFORE THE REQUEST. Story 30
        // dropped the stored hook while it sat mid-prompt, trailing the beats
        // as "the hook this outline asks for" with the answer-back rule and
        // the chapter announcement arriving after it.
        $this->assertGreaterThan(
            mb_strpos($prompt, 'WRITE THIS ACT AS CHAPTERS'),
            mb_strpos($prompt, 'THIS IS THE OPENING THE OUTLINE WROTE FOR THIS STORY'),
            'The stored hook must come after the blocks that displaced it.',
        );
    }

    /**
     * The betrayal deadline reaches the prompt as WORDS, at this story's rate.
     *
     * 53 at the frozen 160, 66 at the measured 197 — the same twenty seconds,
     * and the same rate `$targetWords` was derived from. A prompt carrying the
     * act target at one rate and the hook budget at another would be two
     * beliefs about one narration inside a single string.
     */
    public function test_the_hook_budget_in_the_prompt_follows_the_stories_own_rate(): void
    {
        $story = $this->outlinedStory();
        $act = new ActOutline(sequence: 1, title: 'The First Invoice', summary: 'It arrives.');

        $story->forceFill(['sized_against_wpm' => 160])->save();
        $this->assertStringContainsString(
            'inside the first 53 words',
            $this->actPrompt($story->refresh(), $act, 985),
        );

        $story->forceFill(['sized_against_wpm' => 197])->save();
        $this->assertStringContainsString(
            'inside the first 66 words',
            $this->actPrompt($story->refresh(), $act, 985),
        );
    }

    /**
     * A story outlined before the field existed still gets the beats.
     *
     * Four stories are in that position. Handing act 1 nothing at all because
     * the outline predates the column would leave the one instruction this
     * whole change is about un-given on exactly the stories that demonstrated
     * the need for it.
     */
    public function test_a_story_with_no_hook_still_gets_the_beats(): void
    {
        $story = $this->outlinedStory();
        $story->update(['hook' => '']);

        $act = new ActOutline(sequence: 1, title: 'The First Invoice', summary: 'It arrives.');
        $prompt = $this->actPrompt($story->refresh(), $act, 985);

        $this->assertStringContainsString('THIS ACT OPENS THE VIDEO', $prompt);
        $this->assertStringNotContainsString('THE HOOK THIS OUTLINE ASKS FOR', $prompt);
    }

    /** Act 2 gets the re-hook, not the hook. */
    public function test_a_later_act_gets_the_rehook_instead(): void
    {
        $story = $this->outlinedStory();
        $act = new ActOutline(sequence: 2, title: 'The Second Invoice', summary: 'And another.');

        $prompt = $this->actPrompt($story, $act, 985);

        $this->assertStringContainsString('are a re-hook', $prompt);
        $this->assertStringNotContainsString('THIS ACT OPENS THE VIDEO', $prompt);
    }

    /** The real prompt builder, which the fake does not have. */
    private function actPrompt(Story $story, ActOutline $act, int $targetWords): string
    {
        $writer = new ClaudeScriptWriter(
            // Never called: only the private prompt builder is invoked.
            client: app(Client::class),
            locale: app(LocaleGuard::class),
            text: app(CharacterTextGuard::class),
        );

        $method = new ReflectionMethod($writer, 'actPrompt');
        $method->setAccessible(true);

        // A two-act outline so the sequence-2 case is not also the last act,
        // which would change the ending instruction rather than the opening one.
        $outline = [
            $act->sequence === 1 ? $act : new ActOutline(sequence: 1, title: 'One', summary: 'One.'),
            $act->sequence === 2 ? $act : new ActOutline(sequence: 2, title: 'Two', summary: 'Two.'),
            new ActOutline(sequence: 3, title: 'Three', summary: 'Three.'),
        ];

        return (string) $method->invoke($writer, $story, $act, $outline, [], $targetWords);
    }

    /** A story whose outline has been generated and whose spine is sound. */
    private function outlinedStory(): Story
    {
        $story = $this->draftStory();

        // No act count: the default is the structure this genre is written to,
        // and a fixture pinned to the old six would have gone on passing every
        // phase assertion below while production used seven.
        app(GenerateOutline::class)->handle($story);

        $this->writer->calls = [];

        return $story->refresh();
    }
}
