<?php

namespace Tests\Feature\Providers;

use App\Actions\GenerateOutline;
use App\Actions\ValidateOutlineSpine;
use App\Contracts\ScriptWriter;
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
 * in front of witnesses — and these tests are the part of it that is
 * mechanically checkable.
 *
 * What is asserted here is deliberately not "is the writing good". It is the
 * four ways this format is actually written wrong, all of which are visible in
 * the text and all of which are cheaper to catch at Gate 1 than at Gate 3.
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

        $this->assertStringContainsString('Act(s) 3 have no escalation beat', implode(' ', $review['problems']));
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

        app(GenerateOutline::class)->handle($story, 6);

        $this->writer->calls = [];

        return $story->refresh();
    }
}
