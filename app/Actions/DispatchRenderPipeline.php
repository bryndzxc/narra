<?php

namespace App\Actions;

use App\Enums\OperatorAction;
use App\Enums\RenderStage;
use App\Enums\StoryStatus;
use App\Exceptions\GateViolationException;
use App\Jobs\ConcatRenderJob;
use App\Jobs\DeliverFinalVideoJob;
use App\Jobs\GenerateSubtitlesJob;
use App\Jobs\MuxFinalVideoJob;
use App\Jobs\PurgeRenderScratchJob;
use App\Jobs\RenderSceneClipJob;
use App\Models\RenderJob;
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
 *          |                                                                     |
 *          |                                        PurgeRenderScratchJob -> DeliverFinalVideoJob
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
 *
 * **The purge's safety comes from being in the chain, not from being last.**
 * `Bus::chain` stops at the first failure, so a mux that threw never reaches it — and the Action refuses independently unless `final.mp4`
 * exists AND its tail decodes, because existence is not success. Two
 * mechanisms, and neither is trusted alone: the chain decides whether the purge
 * is REACHED, the guard decides whether it PROCEEDS.
 *
 * The cost of putting it here, stated because it is real: scratch is what a
 * re-run reuses, so a render rejected at Gate 3 now re-encodes all 186 clips
 * rather than only the stage that was wrong. That trade was made deliberately
 * against ~700 MB of clips and padded PCM per story at every-other-day uploads
 * — the disk fills faster than Gate 3 rejects. If rejections ever become
 * common, the honest fix is to move this to Gate 3 approval, not to make the
 * guard weaker.
 */
class DispatchRenderPipeline
{
    public function __construct(private readonly AssertWorkersCurrent $workers) {}

    /**
     * @return array{batch_id: string, scenes: int, notes: array<int, array{level: string, message: string}>}
     */
    public function handle(Story $story, bool $checkWorkers = true): array
    {
        if ($story->slug === null) {
            throw new RuntimeException('A story needs a slug before it can be rendered — it names its workspace on disk.');
        }

        $scenes = $story->scenes()->get();

        if ($scenes->isEmpty()) {
            throw new RuntimeException("Story {$story->slug} has no scenes to render.");
        }

        // The same predicate render:dispatch and PreviewGate::reject() consult.
        // Without it this accepted any status and failed at the transition with
        // a bare "cannot move from X to rendering" — true, and no help at all
        // about what to do instead.
        if (OperatorAction::DispatchRender->refusalReason($story->status) !== null) {
            throw GateViolationException::actionUnavailable(OperatorAction::DispatchRender, $story->status);
        }

        // BEFORE the transition, because everything after it is state an
        // operator would have to unwind.
        //
        // Nothing on the render queue bills, and this is still a refusal. A
        // stale render worker does not cost money; it costs a full clip batch
        // and however long it takes somebody to notice. That is not theoretical
        // — this check was added because the render workers were started, then
        // PadSceneAudio and Ffmpeg were fixed, then the render was dispatched
        // into workers still holding the old classes. It failed twice in ten
        // minutes with `Call to undefined method Ffmpeg::sampleRate()`, on the
        // one dispatch path that had the guard available and not wired in.
        //
        // The louder version of that failure is the lucky one. A worker holding
        // an older PadSceneAudio would not have thrown at all — it would have
        // produced scenes 84% too long and been caught only by the concat
        // assertion, tens of minutes in.
        $notes = $checkWorkers
            ? $this->workers->handle((string) config('render.queues.render'))
            : [];

        // Every stage this dispatch will run goes back to `queued` first.
        //
        // Without it the progress page reports a PREVIOUS run's success as this
        // one's: render_jobs holds one row per stage, reused across dispatches,
        // so a subtitles or mux row from an earlier render survives untouched
        // and reads as complete. A render whose concat had just failed showed
        // Subtitles 1/1 and Mux 1/1 done, from rows 21 hours old.
        //
        // The operator page is the one surface where a false success is most
        // expensive, because it is the thing being trusted instead of watching
        // the render. See RenderJob::queueStages().
        RenderJob::queueStages($story->id, [
            RenderStage::SceneClips,
            RenderStage::Concat,
            RenderStage::Subtitles,
            RenderStage::Mux,
            RenderStage::Purge,
        ]);

        $target = OperatorAction::DispatchRender->targetStatus($story->status);

        if ($target !== null) {
            $story->transitionTo($target);
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
                    // Only reached if the mux succeeded — a chain stops at the
                    // first failure. It refuses on its own account too, unless
                    // final.mp4 decodes at its tail. A purge that ran on a
                    // failed render would delete the clips the re-run needs,
                    // which is the one way this stage can be expensive.
                    new PurgeRenderScratchJob($storyId),

                    // Last, and after the purge rather than before it. Both
                    // orders work — the purge keeps final.mp4 — so the only
                    // question is which failure is cheaper. Delivery is the one
                    // stage that touches a path this app does not control, and
                    // ahead of the purge a dismounted drive would strand
                    // ~700 MB of scratch on a render that actually succeeded.
                    new DeliverFinalVideoJob($storyId),
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

        return ['batch_id' => $batch->id, 'scenes' => $scenes->count(), 'notes' => $notes];
    }
}
