<?php

namespace Tests\Feature\Providers;

use App\Actions\SplitScene;
use App\Enums\MotionPreset;
use App\Enums\StoryStatus;
use App\Models\Act;
use App\Models\Character;
use App\Models\Scene;
use App\Models\Story;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * Cutting a long scene in two.
 *
 * Two things have to hold, and the second one bit: the narration must survive
 * word for word, and the half that was cut off has to end up immediately after
 * the half it came from. The first version parked the new row above the live
 * range and renumbered afterwards — and `renumber()` orders by sequence alone,
 * so all four second halves landed at the end of the video.
 */
class SplitSceneTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_second_half_lands_immediately_after_the_first(): void
    {
        $story = $this->story();
        $scene = $this->scene($story, 2);
        $followingId = $this->scene($story, 3)->id;

        $created = app(SplitScene::class)->handle(
            $scene,
            'First half of the line.',
            'Second half of the line.',
            'A kitchen door standing open on an empty hall.',
        );

        $this->assertSame(3, $created->sequence, 'The new half did not land behind its parent.');
        $this->assertSame(2, $scene->refresh()->sequence);

        // And what used to be next got out of the way rather than being
        // overwritten.
        $this->assertSame(4, Scene::find($followingId)->sequence);
    }

    public function test_the_story_stays_contiguous_and_in_act_order(): void
    {
        $story = $this->story();

        app(SplitScene::class)->handle(
            $this->scene($story, 2),
            'First half of the line.',
            'Second half of the line.',
            'A kitchen door standing open on an empty hall.',
        );

        $scenes = $story->scenes()->orderBy('sequence')->with('act')->get();

        $this->assertSame(
            range(1, $scenes->count()),
            $scenes->pluck('sequence')->map(fn ($s): int => (int) $s)->all(),
        );

        $acts = $scenes->pluck('act.sequence')->all();
        $sorted = $acts;
        sort($sorted);

        $this->assertSame($sorted, $acts);
    }

    public function test_a_rewrite_disguised_as_a_split_is_refused(): void
    {
        $story = $this->story();

        // The guarantee that makes this free. Narration is the act script word
        // for word; a split chooses where the picture changes and nothing else.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/rewrite rather than a split/');

        app(SplitScene::class)->handle(
            $this->scene($story, 2),
            'First half of the line.',
            'Second half of the line, tidied up a bit.',
            'A kitchen door.',
        );
    }

    public function test_the_second_half_gets_the_mirrored_camera_move(): void
    {
        $story = $this->story();
        $scene = $this->scene($story, 2);
        $scene->forceFill(['motion_preset' => MotionPreset::ZoomIn])->save();

        $created = app(SplitScene::class)->handle(
            $scene,
            'First half of the line.',
            'Second half of the line.',
            'A kitchen door standing open on an empty hall.',
        );

        // Repeating the parent's move would read as one continuous push with a
        // jump in the middle.
        $this->assertSame(MotionPreset::ZoomOut, $created->motion_preset);
    }

    public function test_the_new_scene_is_never_the_hook_and_never_a_thumbnail(): void
    {
        $story = $this->story();
        $scene = $this->scene($story, 2);
        $scene->forceFill(['is_thumbnail_candidate' => true])->save();

        $created = app(SplitScene::class)->handle(
            $scene,
            'First half of the line.',
            'Second half of the line.',
            'A kitchen door standing open on an empty hall.',
        );

        $this->assertFalse($created->is_hook);
        // A thumbnail nomination points at a picture, and this is a different
        // picture.
        $this->assertFalse($created->is_thumbnail_candidate);
    }

    public function test_splitting_is_refused_once_the_gate_is_approved(): void
    {
        $story = $this->story();
        $scene = $this->scene($story, 2);

        $story->forceFill(['status' => StoryStatus::ScenesApproved])->save();

        $this->expectExceptionMessageMatches('/locked/');

        app(SplitScene::class)->handle(
            $scene->refresh(),
            'First half of the line.',
            'Second half of the line.',
            'A kitchen door.',
        );
    }

    private function story(): Story
    {
        $story = Story::factory()->status(StoryStatus::ScenesDrafted)->create(['slug' => 'split-test']);
        $act = Act::factory()->for($story)->atSequence(1)->create();
        Character::factory()->for($story)->create(['name' => 'Erin Vasquez']);

        for ($i = 1; $i <= 4; $i++) {
            Scene::factory()->forAct($act)->atSequence($i)->create([
                'narration_text' => $i === 2
                    ? 'First half of the line. Second half of the line.'
                    : "Narration for scene {$i}.",
            ]);
        }

        return $story;
    }

    private function scene(Story $story, int $sequence): Scene
    {
        return $story->scenes()->where('sequence', $sequence)->firstOrFail();
    }
}
