<?php

namespace Tests\Feature\Providers;

use App\Actions\GenerateActScripts;
use App\Actions\GenerateOutline;
use App\Actions\ValidateOutlineSpine;
use App\Contracts\ScriptWriter;
use App\Enums\ActPhase;
use App\Enums\Gate;
use App\Enums\StoryFormat;
use App\Enums\StoryStatus;
use App\Livewire\Gates\OutlineGate;
use App\Models\Act;
use App\Models\Story;
use App\Services\Fake\FakeScriptWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
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

    public function test_a_single_narrative_gets_seven_acts_by_default(): void
    {
        // Six was the count while the arc was escalation -> exposure -> end.
        // The reversal needs somewhere to go, and taking it out of the
        // escalation would trade one missing phase for another.
        $story = $this->draftStory();

        app(GenerateOutline::class)->handle($story);

        $this->assertSame(7, $story->acts()->count());
        $this->assertSame(7, GenerateOutline::defaultActCountFor($story));
    }

    public function test_the_acts_are_laid_out_across_the_four_phases(): void
    {
        // Escalation through roughly the first two thirds, then the departure,
        // then the search and the refusal. The reversal is three acts of seven,
        // not the last ninety seconds of act seven.
        $story = $this->draftStory();

        app(GenerateOutline::class)->handle($story);

        $this->assertSame(
            [
                1 => ActPhase::Escalation,
                2 => ActPhase::Escalation,
                3 => ActPhase::Escalation,
                4 => ActPhase::Escalation,
                5 => ActPhase::Departure,
                6 => ActPhase::Search,
                7 => ActPhase::Refusal,
            ],
            $story->acts()->orderBy('sequence')->get()->pluck('phase', 'sequence')->all(),
        );
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

        $this->assertSame('escalation', $calls[1]['phase']);
        $this->assertSame('departure', $calls[5]['phase']);
        $this->assertSame('search', $calls[6]['phase']);
        $this->assertSame('refusal', $calls[7]['phase']);

        $this->assertTrue(
            $calls->every(fn (array $call): bool => trim((string) $call['escalation_beat']) !== ''),
            'An act was written without the beat the outline recorded for it.',
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
        $story = $this->outlinedStory();
        $story->acts()->where('sequence', '<', 6)->update(['phase' => ActPhase::Escalation]);
        $story->acts()->where('sequence', 6)->update(['phase' => ActPhase::Departure]);
        $story->acts()->where('sequence', 7)->update(['phase' => ActPhase::Refusal]);

        $review = app(ValidateOutlineSpine::class)->handle($story->refresh());

        $this->assertStringContainsString('The narrator leaves in act 6 of 7', implode(' ', $review['warnings']));
    }

    public function test_an_outline_written_before_the_reversal_says_so_once(): void
    {
        // Story 9 and story 21 are both this. Three "missing" problems on a
        // shipped video would be three red boxes about one nameable thing, and
        // the thing is not that somebody forgot to fill a field in.
        $story = $this->outlinedStory();
        $story->acts()->update(['phase' => null]);
        $story->update(['departure' => '', 'reversal_beats' => '', 'refusal' => '']);

        $review = app(ValidateOutlineSpine::class)->handle($story->refresh());

        $this->assertSame([], $review['problems']);
        $this->assertStringContainsString(
            'generated before the reversal phase existed',
            implode(' ', $review['warnings']),
        );

        foreach (['departure', 'reversal_beats', 'refusal'] as $field) {
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
