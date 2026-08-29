<?php

namespace Tests\Feature;

use App\Actions\DispatchRenderPipeline;
use App\Enums\StoryStatus;
use App\Exceptions\GateViolationException;
use App\Jobs\RenderSceneClipJob;
use App\Models\Act;
use App\Models\Scene;
use App\Models\Story;
use Illuminate\Bus\PendingBatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use RuntimeException;
use Tests\TestCase;

/**
 * How the render reaches the queue.
 *
 * The shape matters as much as the work: a fan-out batch whose continuation is
 * a completion callback rather than a poll loop, on the render queue, with
 * failures allowed so one bad scene does not hide the other 199.
 */
class RenderQueueDispatchTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_scene_becomes_one_batched_job_on_the_render_queue(): void
    {
        Bus::fake();

        $story = $this->storyWithScenes(12);

        app(DispatchRenderPipeline::class)->handle($story);

        Bus::assertBatched(function (PendingBatch $batch) use ($story): bool {
            $this->assertCount(12, $batch->jobs);
            $this->assertContainsOnlyInstancesOf(RenderSceneClipJob::class, $batch->jobs);

            // A 40-minute mux must never sit in the same queue as a script
            // draft, so the queue is named explicitly rather than defaulted.
            $this->assertSame(config('render.queues.render'), $batch->queue());
            $this->assertSame("scene-clips:{$story->slug}", $batch->name);

            // The batch finishes even when a scene fails, so one pass shows
            // every failure. The `then` continuation still will not fire.
            $this->assertTrue($batch->allowsFailures());

            return true;
        });
    }

    public function test_dispatching_moves_the_story_into_rendering(): void
    {
        Bus::fake();

        $story = $this->storyWithScenes(3);

        app(DispatchRenderPipeline::class)->handle($story);

        $this->assertSame(StoryStatus::Rendering, $story->fresh()->status);
    }

    public function test_a_story_with_no_scenes_is_refused_before_anything_is_queued(): void
    {
        Bus::fake();

        $story = Story::factory()->status(StoryStatus::AssetsReady)->create();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no scenes to render');

        try {
            app(DispatchRenderPipeline::class)->handle($story);
        } finally {
            Bus::assertNothingBatched();
            $this->assertSame(StoryStatus::AssetsReady, $story->fresh()->status);
        }
    }

    public function test_the_render_cannot_start_from_a_status_that_has_no_assets(): void
    {
        Bus::fake();

        // scenes_drafted -> rendering is not a legal move, and the gate machine
        // refuses it rather than the dispatcher having its own opinion.
        $story = $this->storyWithScenes(2);
        $story->forceFill(['status' => StoryStatus::ScenesDrafted])->save();

        $this->expectException(GateViolationException::class);

        try {
            app(DispatchRenderPipeline::class)->handle($story);
        } finally {
            Bus::assertNothingBatched();
        }
    }

    private function storyWithScenes(int $count): Story
    {
        $story = Story::factory()->status(StoryStatus::AssetsReady)->create(['slug' => 'queue-test']);
        $act = Act::factory()->for($story)->atSequence(1)->create();

        for ($i = 1; $i <= $count; $i++) {
            Scene::factory()->forAct($act)->atSequence($i)->ready()->create();
        }

        return $story;
    }
}
