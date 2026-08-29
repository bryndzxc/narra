<?php

namespace App\Actions;

use App\Enums\StoryStatus;
use App\Jobs\ConcatRenderJob;
use App\Jobs\GenerateSubtitlesJob;
use App\Jobs\MuxFinalVideoJob;
use App\Jobs\RenderSceneClipJob;
use App\Models\Story;
use Illuminate\Bus\Batch;
use Illuminate\Support\Facades\Bus;
use RuntimeException;
use Throwable;

/**
 * Puts a story's render on the queue: a batch of scene clips, then a chain.
 *
 *     RenderSceneClipJob x N   (batch, fans out across the render workers)
 *          |
 *          +-- all succeeded --> ConcatRenderJob -> GenerateSubtitlesJob -> MuxFinalVideoJob
 *          |
 *          +-- any failed ------> stop, story back to assets_ready, failures on the page
 *
 * The continuation is a batch completion callback, not a poll. At 200 scenes a
 * sleep-and-poll loop would hold a worker hostage for hours doing nothing —
 * and there are only one or two render workers to hold hostage.
 *
 * `allowFailures()` with a `then` continuation is the deliberate combination:
 * the batch keeps going after a scene fails, so the operator sees every failure
 * in one pass instead of one per re-run, but the chain does not start, because
 * concat with a missing clip is not a partial video — it is a wrong one.
 */
class DispatchRenderPipeline
{
    /**
     * @return array{batch_id: string, scenes: int}
     */
    public function handle(Story $story): array
    {
        if ($story->slug === null) {
            throw new RuntimeException('A story needs a slug before it can be rendered — it names its workspace on disk.');
        }

        $scenes = $story->scenes()->get();

        if ($scenes->isEmpty()) {
            throw new RuntimeException("Story {$story->slug} has no scenes to render.");
        }

        if ($story->status !== StoryStatus::Rendering) {
            $story->transitionTo(StoryStatus::Rendering);
        }

        $storyId = $story->id;

        $batch = Bus::batch(
            $scenes->map(fn ($scene): RenderSceneClipJob => new RenderSceneClipJob($storyId, $scene->id))->all()
        )
            ->name("scene-clips:{$story->slug}")
            ->onQueue(config('render.queues.render'))
            ->allowFailures()
            ->then(function (Batch $batch) use ($storyId): void {
                // Every clip rendered. The rest of the pipeline is sequential by
                // nature — concat needs all the clips, subtitles need concat's
                // offsets, the mux needs both — so it is a chain, not a batch.
                Bus::chain([
                    new ConcatRenderJob($storyId),
                    new GenerateSubtitlesJob($storyId),
                    new MuxFinalVideoJob($storyId),
                ])->onQueue(config('render.queues.render'))->dispatch();
            })
            ->finally(function (Batch $batch) use ($storyId): void {
                if (! $batch->hasFailures()) {
                    return;
                }

                // Park the story where a re-run starts from. The failed scenes
                // are already on the progress page with their errors; this just
                // stops it claiming to be rendering when it is not.
                $story = Story::query()->find($storyId);

                if ($story?->status === StoryStatus::Rendering) {
                    try {
                        $story->transitionTo(StoryStatus::AssetsReady);
                    } catch (Throwable) {
                        // A concurrent transition won. The row is not worth a
                        // second exception inside a completion callback.
                    }
                }
            })
            ->dispatch();

        return ['batch_id' => $batch->id, 'scenes' => $scenes->count()];
    }
}
