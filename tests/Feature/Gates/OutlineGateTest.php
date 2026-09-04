<?php

namespace Tests\Feature\Gates;

use App\Enums\Gate;
use App\Enums\StoryStatus;
use App\Livewire\Gates\OutlineGate;
use App\Models\Act;
use App\Models\Story;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Gate 1 — the operator approves the act outline.
 *
 * The cheapest gate to get right. Everything downstream is generated against
 * this outline, so the tests here are mostly about it being impossible to move
 * past it by accident.
 */
class OutlineGateTest extends TestCase
{
    use RefreshDatabase;

    public function test_saving_the_outline_moves_a_draft_to_outlined_but_does_not_cross_the_gate(): void
    {
        $story = $this->draftStory();

        Livewire::test(OutlineGate::class, ['story' => $story])
            ->set('premise', 'A diner closes without telling the woman who has worked there for nineteen years.')
            ->call('save')
            ->assertHasNoErrors();

        // Moved, but only to the near side of the gate. Approving is a separate
        // press, by a person.
        $this->assertSame(StoryStatus::Outlined, $story->fresh()->status);
        $this->assertFalse($story->fresh()->hasPassedGate(Gate::Outline));
    }

    /**
     * The cast age range has a producer, and it is this page.
     *
     * `stories.target_publish_at` sat in the schema for two phases with two
     * display helpers and no input anywhere, so the column was null on every
     * story and the block never rendered — while the Gate 4 checklist asked the
     * operator to confirm a scheduled publish time the app had no way to hold.
     * A column read by a prompt and written by nothing is that defect exactly,
     * so the write path is pinned here rather than assumed.
     */
    public function test_the_cast_age_range_is_editable_at_gate_one(): void
    {
        $story = $this->draftStory();

        Livewire::test(OutlineGate::class, ['story' => $story])
            ->set('premise', 'A diner closes without telling the woman who has worked there for nineteen years.')
            ->set('castAgeProfile', 'Spouses in their late twenties and thirties. Nobody over forty.')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(
            'Spouses in their late twenties and thirties. Nobody over forty.',
            $story->fresh()->cast_age_profile
        );
    }

    public function test_a_blank_cast_age_range_is_stored_as_null_rather_than_an_empty_string(): void
    {
        // The extraction prompt tests this field for emptiness to decide
        // whether to state a range at all, so '' and null must not be two
        // different kinds of nothing.
        $story = $this->draftStory();

        Livewire::test(OutlineGate::class, ['story' => $story])
            ->set('premise', 'A diner closes without telling the woman who has worked there for nineteen years.')
            ->set('castAgeProfile', '   ')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertNull($story->fresh()->cast_age_profile);
    }

    public function test_approving_crosses_gate_one(): void
    {
        $story = $this->draftStory(StoryStatus::Outlined);

        Livewire::test(OutlineGate::class, ['story' => $story])
            ->set('premise', 'A long enough premise to satisfy the validator.')
            ->call('approve');

        $this->assertSame(StoryStatus::Scripted, $story->fresh()->status);
        $this->assertTrue($story->fresh()->hasPassedGate(Gate::Outline));
    }

    public function test_act_titles_are_capped_at_the_youtube_chapter_limit(): void
    {
        $story = $this->draftStory();

        Livewire::test(OutlineGate::class, ['story' => $story])
            ->set('premise', 'A premise long enough to pass validation on its own.')
            ->set('acts.0.title', str_repeat('a', 101))
            ->call('save')
            ->assertHasErrors('acts.0.title');
    }

    public function test_the_outline_is_locked_once_the_gate_is_approved(): void
    {
        $story = $this->draftStory(StoryStatus::Scripted);

        $component = Livewire::test(OutlineGate::class, ['story' => $story]);

        $this->assertFalse($component->instance()->editable());

        // Not merely hidden in the markup — the write itself is refused, because
        // a Livewire action is reachable by anything that can post to it.
        $component->set('premise', 'Rewritten behind the gate')->call('save')->assertForbidden();

        $this->assertNotSame('Rewritten behind the gate', $story->fresh()->premise);
    }

    public function test_the_gate_can_be_reopened_deliberately(): void
    {
        $story = $this->draftStory(StoryStatus::Scripted);

        Livewire::test(OutlineGate::class, ['story' => $story])->call('reopen');

        $this->assertSame(StoryStatus::Outlined, $story->fresh()->status);
    }

    public function test_acts_without_a_rehook_are_surfaced_rather_than_left_to_be_noticed(): void
    {
        $story = $this->draftStory();

        // Act 1 opens the video and needs no re-hook; act 3 does and has none.
        $story->acts()->where('sequence', 2)->update(['is_rehook_written' => true]);

        $component = Livewire::test(OutlineGate::class, ['story' => $story]);
        $missing = $component->instance()->actsMissingRehooks();

        $this->assertCount(1, $missing);
        $this->assertSame(3, $missing[0]['sequence']);
    }

    private function draftStory(StoryStatus $status = StoryStatus::Draft): Story
    {
        $story = Story::factory()->status($status)->create(['slug' => 'gate-one']);

        foreach ([1, 2, 3] as $sequence) {
            Act::factory()->for($story)->atSequence($sequence)->create();
        }

        return $story;
    }
}
