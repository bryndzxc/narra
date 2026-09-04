<?php

namespace App\Actions;

use App\Enums\OperatorAction;
use App\Enums\RenderJobStatus;
use App\Enums\StoryStatus;
use App\Exceptions\GateViolationException;
use App\Models\RenderJob;
use App\Models\Story;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;

/**
 * Stop a running batch and put the story back somewhere a re-run starts from.
 *
 * Extracted from `render:cancel` so a button can reach it. That extraction is
 * not cosmetic: this app's own refusal text tells an operator at `rendering` to
 * "cancel it first (php artisan render:cancel <story>)" — which meant the one
 * documented way out of a stuck render was a terminal, on a page that exists so
 * there would not have to be one.
 *
 * Two sources of batch ids, and the second is the one that matters most. A
 * `render_jobs` row is written when a job STARTS, so discovering batches
 * through that table can only ever find work a worker has already picked up. A
 * batch dispatched and not yet touched has no rows at all — and that is
 * precisely the moment cancelling is most useful and least destructive.
 * `job_batches` knows about it from the moment of dispatch.
 */
class CancelRenderBatch
{
    /**
     * Batch names this app dispatches, so an unstarted batch can be found by
     * name. Kept next to the code that needs them rather than derived, since
     * the producing Actions name their batches as literals too.
     */
    public const BATCH_PREFIXES = ['scene-clips', 'scene-assets', 'scene-timings'];

    /**
     * @return array{batches: int, rows: int, landed: ?StoryStatus, reconciled: bool}
     */
    public function handle(Story $story): array
    {
        // The same predicate the button and the command consult. Without it
        // this accepted any status and reported "Cancelled 0 batch(es)" at a
        // status where there was never anything to cancel — true, and no help
        // at all about what to do instead.
        if (OperatorAction::CancelRender->refusalReason($story->status) !== null) {
            throw GateViolationException::actionUnavailable(OperatorAction::CancelRender, $story->status);
        }

        $cancelled = 0;

        foreach ($this->batchIds($story) as $batchId) {
            $batch = Bus::findBatch($batchId);

            if ($batch === null || $batch->finished() || $batch->cancelled()) {
                continue;
            }

            $batch->cancel();
            $cancelled++;
        }

        // Queued rows would otherwise sit at `queued` forever, which reads as
        // "about to run" on the progress page rather than "never will".
        $marked = RenderJob::query()
            ->where('story_id', $story->id)
            ->whereIn('status', [RenderJobStatus::Queued, RenderJobStatus::Running])
            ->update(['status' => RenderJobStatus::Cancelled, 'finished_at' => now()]);

        return $this->land($story) + ['batches' => $cancelled, 'rows' => $marked];
    }

    /**
     * @return Collection<int, string>
     */
    private function batchIds(Story $story): Collection
    {
        return RenderJob::query()
            ->where('story_id', $story->id)
            ->whereNotNull('batch_id')
            ->distinct()
            ->pluck('batch_id')
            ->merge(
                DB::table('job_batches')
                    ->whereNull('finished_at')
                    ->whereNull('cancelled_at')
                    ->where(function ($q) use ($story): void {
                        foreach (self::BATCH_PREFIXES as $prefix) {
                            $q->orWhere('name', "{$prefix}:{$story->slug}");
                        }
                    })
                    ->pluck('id')
            )
            ->unique()
            ->values();
    }

    /**
     * Put the story back on a status a re-run can start from.
     *
     * **This is the half that was missing once, and it left the operator with
     * no way out.** The command cancelled the batch and the job rows and never
     * touched `stories.status`, so a cancelled render stayed at `rendering` —
     * which has no reopen edge by design, because a clip batch is supposed to
     * be in flight there.
     *
     * Cancelling an ASSET batch lands nowhere by itself: whether the story is
     * finished depends on which scenes got their assets before it was called
     * off, and reconcile is what knows.
     *
     * @return array{landed: ?StoryStatus, reconciled: bool}
     */
    private function land(Story $story): array
    {
        $target = OperatorAction::CancelRender->targetStatus($story->status);

        if ($target !== null) {
            $story->transitionTo($target);

            return ['landed' => $target, 'reconciled' => false];
        }

        if ($story->status === StoryStatus::AssetsGenerating) {
            DispatchAssetGeneration::reconcile($story->id);

            return ['landed' => $story->fresh()?->status, 'reconciled' => true];
        }

        return ['landed' => null, 'reconciled' => false];
    }
}
