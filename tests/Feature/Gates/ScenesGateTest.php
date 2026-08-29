<?php

namespace Tests\Feature\Gates;

use App\Enums\Gate;
use App\Enums\MotionPreset;
use App\Enums\SceneStatus;
use App\Enums\StoryStatus;
use App\Exceptions\GateViolationException;
use App\Livewire\Gates\ScenesGate;
use App\Models\Act;
use App\Models\CostEntry;
use App\Models\Scene;
use App\Models\Story;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Gate 2 — the money line.
 *
 * The most consequential gate in the product: everything past it bills, and
 * images alone are ~70% of a video's cost across 150-250 stills. These tests
 * exist to make sure it cannot be crossed sideways.
 */
class ScenesGateTest extends TestCase
{
    use RefreshDatabase;

    public function test_no_paid_asset_can_exist_before_this_gate_is_approved(): void
    {
        $story = $this->storyWithScenes(StoryStatus::ScenesDrafted);

        $this->assertFalse($story->canGeneratePaidAssets());

        // The invariant, enforced at the data layer rather than by the UI:
        // even a direct write is refused.
        $this->expectException(GateViolationException::class);

        CostEntry::factory()->for($story)->image()->create();
    }

    public function test_approving_unlocks_spending_and_marks_the_scenes_approved(): void
    {
        $story = $this->storyWithScenes(StoryStatus::ScenesDrafted);

        Livewire::test(ScenesGate::class, ['story' => $story])
            ->call('askToApprove')
            ->assertSet('confirmingApproval', true)
            ->call('approve');

        $story->refresh();

        $this->assertSame(StoryStatus::ScenesApproved, $story->status);
        $this->assertTrue($story->hasPassedGate(Gate::Scenes));
        $this->assertTrue($story->canGeneratePaidAssets());
        $this->assertSame(0, $story->scenes()->where('status', '!=', SceneStatus::Approved)->count());

        // And now a charge is allowed to exist.
        CostEntry::factory()->for($story)->image()->create();
        $this->assertSame('0.0400', $story->fresh()->total_cost_usd);
    }

    public function test_approval_takes_two_presses(): void
    {
        // Not ceremony for its own sake: the second press is where the cost of
        // what is about to be authorised is spelled out.
        $story = $this->storyWithScenes(StoryStatus::ScenesDrafted);

        $component = Livewire::test(ScenesGate::class, ['story' => $story]);

        $this->assertFalse($component->get('confirmingApproval'));
        $this->assertSame(StoryStatus::ScenesDrafted, $story->fresh()->status);

        $component->call('askToApprove')->call('cancelApproval');

        $this->assertSame(StoryStatus::ScenesDrafted, $story->fresh()->status);
    }

    public function test_a_scene_can_be_edited_before_approval(): void
    {
        $story = $this->storyWithScenes(StoryStatus::ScenesDrafted);
        $scene = $story->scenes()->where('sequence', 2)->firstOrFail();

        Livewire::test(ScenesGate::class, ['story' => $story])
            ->call('edit', $scene->id)
            ->set('narration', 'She read the sign twice before she understood it.')
            ->set('imagePrompt', 'A hand-lettered CLOSED sign taped inside a diner window at dusk')
            ->set('motion', MotionPreset::PanLeft->value)
            ->set('isThumbnailCandidate', true)
            ->call('saveScene')
            ->assertHasNoErrors();

        $scene->refresh();

        $this->assertSame('She read the sign twice before she understood it.', $scene->narration_text);
        $this->assertSame(MotionPreset::PanLeft, $scene->motion_preset);
        $this->assertTrue($scene->is_thumbnail_candidate);
    }

    public function test_flagging_a_new_hook_unflags_the_old_one(): void
    {
        // Two scenes both claiming to be the opening is not a state the format
        // has an answer for.
        $story = $this->storyWithScenes(StoryStatus::ScenesDrafted);
        $story->scenes()->where('sequence', 1)->update(['is_hook' => true]);

        $third = $story->scenes()->where('sequence', 3)->firstOrFail();

        Livewire::test(ScenesGate::class, ['story' => $story])
            ->call('edit', $third->id)
            ->set('isHook', true)
            ->call('saveScene');

        $this->assertSame(1, $story->scenes()->where('is_hook', true)->count());
        $this->assertTrue($third->fresh()->is_hook);
    }

    public function test_scenes_can_be_reordered_despite_the_unique_sequence_index(): void
    {
        // (story_id, sequence) is unique, so a naive swap collides mid-update.
        $story = $this->storyWithScenes(StoryStatus::ScenesDrafted);
        $second = $story->scenes()->where('sequence', 2)->firstOrFail();
        $text = $second->narration_text;

        Livewire::test(ScenesGate::class, ['story' => $story])->call('move', $second->id, -1);

        $this->assertSame(1, $second->fresh()->sequence);
        $this->assertSame($text, $story->scenes()->where('sequence', 1)->value('narration_text'));

        // No holes, no duplicates.
        $this->assertSame([1, 2, 3, 4, 5], $story->scenes()->pluck('sequence')->all());
    }

    public function test_deleting_a_scene_renumbers_the_rest(): void
    {
        // Scene numbers are how an operator refers to a scene — on the progress
        // page, in a failure, out loud. 1,2,4,5 makes every later reference
        // ambiguous.
        $story = $this->storyWithScenes(StoryStatus::ScenesDrafted);
        $second = $story->scenes()->where('sequence', 2)->firstOrFail();

        Livewire::test(ScenesGate::class, ['story' => $story])->call('deleteScene', $second->id);

        $this->assertSame([1, 2, 3, 4], $story->scenes()->pluck('sequence')->all());
        $this->assertDatabaseCount('scenes', 4);
    }

    public function test_scenes_are_locked_once_the_gate_is_approved(): void
    {
        $story = $this->storyWithScenes(StoryStatus::ScenesApproved);
        $scene = $story->scenes()->first();

        Livewire::test(ScenesGate::class, ['story' => $story])
            ->call('edit', $scene->id)
            ->set('narration', 'Edited after paying for the assets')
            ->call('saveScene')
            ->assertForbidden();

        $this->assertNotSame('Edited after paying for the assets', $scene->fresh()->narration_text);
    }

    public function test_the_gate_can_be_reopened_to_fix_a_scene(): void
    {
        $story = $this->storyWithScenes(StoryStatus::ScenesApproved);

        Livewire::test(ScenesGate::class, ['story' => $story])->call('reopen');

        $this->assertSame(StoryStatus::ScenesDrafted, $story->fresh()->status);
        $this->assertFalse($story->fresh()->canGeneratePaidAssets());
    }

    public function test_the_operator_is_warned_about_what_is_missing(): void
    {
        $story = $this->storyWithScenes(StoryStatus::ScenesDrafted);
        $story->scenes()->update(['image_prompt' => null, 'is_hook' => false, 'is_thumbnail_candidate' => false]);

        $warnings = Livewire::test(ScenesGate::class, ['story' => $story])->instance()->warnings();

        $this->assertCount(3, $warnings);
        $this->assertStringContainsString('no image prompt', $warnings[0]);
        $this->assertStringContainsString('opening hook', $warnings[1]);
        $this->assertStringContainsString('thumbnail', $warnings[2]);
    }

    private function storyWithScenes(StoryStatus $status): Story
    {
        $story = Story::factory()->status($status)->create(['slug' => 'gate-two']);
        $act = Act::factory()->for($story)->atSequence(1)->create();

        for ($i = 1; $i <= 5; $i++) {
            Scene::factory()->forAct($act)->atSequence($i)->create();
        }

        return $story;
    }
}
