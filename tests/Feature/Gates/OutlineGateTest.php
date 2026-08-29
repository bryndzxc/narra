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
