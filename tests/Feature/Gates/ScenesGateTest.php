<?php

namespace Tests\Feature\Gates;

use App\Enums\Gate;
use App\Enums\MotionPreset;
use App\Enums\SceneStatus;
use App\Enums\StoryStatus;
use App\Exceptions\GateViolationException;
use App\Livewire\Gates\ScenesGate;
use App\Models\Act;
use App\Models\Character;
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

        $warnings = implode(' ', Livewire::test(ScenesGate::class, ['story' => $story])->instance()->warnings());

        // Asserted on content rather than count. Gate 2's warnings now include
        // the structural scene checks as well as the completeness ones, and a
        // test pinned to an exact number breaks every time a real check is
        // added — which trains whoever hits it to loosen the assertion rather
        // than read it.
        $this->assertStringContainsString('no image prompt', $warnings);
        $this->assertStringContainsString('opening hook', $warnings);
        $this->assertStringContainsString('thumbnail', $warnings);
    }

    /**
     * Headwear reaches the operator, and nothing else stops it.
     *
     * The whole point of it being an advisory rather than a rule: a hat is real
     * clothing and a script can require one, so extraction must not refuse it —
     * but it covers the hair silhouette this cast is told apart by, so it must
     * not be silent either. Gate 2 is the last screen before those prompts are
     * bought, which makes it the only place the reading can land.
     */
    public function test_gate_two_reports_headwear_without_blocking_it(): void
    {
        $story = $this->storyWithScenes(StoryStatus::ScenesDrafted);

        Character::factory()->for($story)->create([
            'name' => 'Kyle Ostergaard',
            'description' => 'Late thirties, broad through the chest, short wavy brown hair, square jaw.',
            'style_notes' => 'Wears fitted polo shirts and jeans with a ball cap pushed back.',
        ]);

        $component = Livewire::test(ScenesGate::class, ['story' => $story]);
        $warnings = implode(' ', $component->instance()->warnings());

        $this->assertStringContainsString('headwear', $warnings);
        $this->assertStringContainsString('Kyle Ostergaard', $warnings);

        // Reported, not enforced. The gate stays crossable and the cast stays
        // as written — an operator who wants the hat keeps it.
        $this->assertTrue($story->fresh()->status === StoryStatus::ScenesDrafted);
    }

    public function test_gate_two_reports_stored_ageing_texture(): void
    {
        // A story whose cast was extracted before the rule existed has the
        // defect baked into every prompt that character appears in, and
        // throwing at extraction cannot reach data already on disk.
        $story = $this->storyWithScenes(StoryStatus::ScenesDrafted);

        Character::factory()->for($story)->create([
            'name' => 'Diane Kessler',
            'description' => 'Late sixties, small and frail, thinning white hair in a low bun, '
                .'deeply lined round face, soft sagging jawline.',
            'style_notes' => 'Simple floral housedresses and a buttoned cardigan.',
        ]);

        $warnings = implode(' ', Livewire::test(ScenesGate::class, ['story' => $story])->instance()->warnings());

        $this->assertStringContainsString('ageing texture', $warnings);
        $this->assertStringContainsString('Diane Kessler', $warnings);
    }

    /**
     * The style block is read out of the PROMPTS, never asserted from config.
     *
     * `GenerateSceneImage` sends `image_prompt` verbatim and nothing re-appends
     * the art style at dispatch, so a story drafted before the look was retuned
     * carries the OLD style in every one of its stored prompts for ever. Config
     * describes what the NEXT story would get.
     *
     * Reading config and printing it as "identical on all 168 prompts" would
     * therefore put a false sentence on the one screen where 150-250 stills are
     * authorised. It is not hypothetical: measured on live data, rent-will has
     * 0 of 168 prompts carrying the configured style, my-younger-brother 0 of
     * 186, and my-wife 270 of 270.
     *
     * This is the failure this check exists to catch, so it is the case the
     * test builds: prompts whose shared block is NOT the configured one.
     */
    public function test_the_style_block_is_read_from_the_prompts_and_reports_drift(): void
    {
        config(['scenes.art_style' => 'anime, cel shaded, glossy strand-rendered hair', 'scenes.constraints' => '']);

        $story = $this->storyWithScenes(StoryStatus::ScenesDrafted);

        // Drafted under an EARLIER style. Frame differs per scene; the trailing
        // block is identical across all of them, as ImagePromptBuilder writes it.
        foreach ($story->scenes as $scene) {
            $scene->forceFill([
                'image_prompt' => "frame for scene {$scene->sequence}

painted realism, oil on canvas, muted palette",
            ])->save();
        }

        $block = Livewire::test(ScenesGate::class, ['story' => $story])->instance()->styleBlock();

        $this->assertSame(
            'painted realism, oil on canvas, muted palette',
            $block['text'],
            'The shared block must come from what the prompts actually carry.',
        );
        $this->assertSame(7, $block['words']);
        $this->assertFalse(
            $block['matches_config'],
            'A story drafted under an earlier style must be reported as drifted, not as current.',
        );

        // And it says so where the operator is about to spend.
        Livewire::test(ScenesGate::class, ['story' => $story])
            ->assertSee('It is not the style currently declared');
    }

    /** The agreeing case, so the check is not simply always-red. */
    public function test_a_story_drafted_under_the_current_style_reports_as_matching(): void
    {
        config(['scenes.art_style' => 'painted realism, oil on canvas', 'scenes.constraints' => '']);

        $story = $this->storyWithScenes(StoryStatus::ScenesDrafted);

        foreach ($story->scenes as $scene) {
            $scene->forceFill([
                'image_prompt' => "frame for scene {$scene->sequence}

painted realism, oil on canvas",
            ])->save();
        }

        $block = Livewire::test(ScenesGate::class, ['story' => $story])->instance()->styleBlock();

        $this->assertTrue($block['matches_config']);
        $this->assertSame(5, $block['scenes']);
    }

    /**
     * The frame is never swallowed by the shared block.
     *
     * If every prompt in a story were byte-identical the "longest common
     * trailing run" would be the whole prompt, and the page would report that
     * there is no per-scene content at all — which is true but useless, and it
     * would leave the rows blank. The loop stops one section short.
     */
    public function test_the_shared_block_never_consumes_the_frame(): void
    {
        $story = $this->storyWithScenes(StoryStatus::ScenesDrafted);

        foreach ($story->scenes as $scene) {
            $scene->forceFill(['image_prompt' => "same frame

same style"])->save();
        }

        $block = Livewire::test(ScenesGate::class, ['story' => $story])->instance()->styleBlock();

        $this->assertSame('same style', $block['text'], 'The first section is the frame and is never shared away.');
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
