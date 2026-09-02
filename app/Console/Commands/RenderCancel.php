<?php

namespace App\Console\Commands;

use App\Actions\DispatchAssetGeneration;
use App\Enums\OperatorAction;
use App\Enums\RenderJobStatus;
use App\Enums\StoryStatus;
use App\Models\RenderJob;
use App\Models\Story;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;

/**
 * Stop a running render batch.
 *
 * Horizon's dashboard has a cancel button; this platform does not have Horizon,
 * and a 200-scene batch queued by mistake is otherwise an hour of CPU nobody
 * can call off. Cancelling marks the batch, and each job checks that before it
 * starts — the one already in flight finishes, the other 190 do not start.
 */
class RenderCancel extends Command
{
    protected $signature = 'render:cancel {story : Story slug or id.}';

    /**
     * Batch names this app dispatches, so an unstarted batch can be found by
     * name. Kept next to the command that needs them rather than derived, since
     * the producing Actions name their batches as literals too.
     */
    private const BATCH_PREFIXES = ['scene-clips', 'scene-assets', 'scene-timings'];

    protected $description = 'Cancel the in-flight render batch for a story.';

    public function handle(): int
    {
        $key = (string) $this->argument('story');

        $story = Story::query()
            ->where('slug', $key)
            ->orWhere('id', ctype_digit($key) ? (int) $key : 0)
            ->first();

        if ($story === null) {
            $this->error("No story matching '{$key}'.");

            return self::FAILURE;
        }

        // Two sources, and the second is the one that matters most.
        //
        // A `render_jobs` row is written when a job STARTS, so discovering
        // batches through that table can only ever find work a worker has
        // already picked up. A batch that was dispatched and not yet touched
        // has no rows at all — and that is precisely the moment cancelling is
        // most useful and least destructive: the operator has realised the
        // dispatch was a mistake before any CPU or money went into it. Asked
        // for that case, this command used to report "Cancelled 0 batch(es)"
        // and leave 186 jobs sitting in redis waiting for the next worker.
        //
        // `job_batches` knows about them from the moment of dispatch, so the
        // unstarted case is found by name.
        $batchIds = RenderJob::query()
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
            ->unique();

        $cancelled = 0;

        foreach ($batchIds as $batchId) {
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

        $this->info(sprintf('Cancelled %d batch(es), marked %d job row(s).', $cancelled, $marked));
        $this->line('A job already inside FFmpeg will finish; nothing new starts.');

        $this->land($story);

        return self::SUCCESS;
    }

    /**
     * Put the story back on a status a re-run can start from.
     *
     * **This is the half that was missing, and it left the operator with no way
     * out.** The command cancelled the batch and the job rows and never touched
     * `stories.status`, so a cancelled render stayed at `rendering` — which has
     * no reopen edge by design, because a clip batch is supposed to be in
     * flight there. It no longer is.
     *
     * Worse, the app routes people here. `StoryStatus::reopenRefusalReason()`
     * tells an operator at `rendering` to "cancel it first (php artisan
     * render:cancel <story>) — that lands on assets_ready, which can reopen."
     * That promise was simply false, so following the app's own instructions
     * led into a dead end. StoryStatusAuditTest now pins the promise.
     *
     * Cancelling an ASSET batch lands nowhere by itself: whether the story is
     * finished depends on which scenes got their assets before it was called
     * off, and reconcile is what knows.
     */
    private function land(Story $story): void
    {
        $target = OperatorAction::CancelRender->targetStatus($story->status);

        if ($target !== null) {
            $story->transitionTo($target);

            $this->line(sprintf('Story moved to %s — a re-run starts from there.', $target->value));

            return;
        }

        if ($story->status === StoryStatus::AssetsGenerating) {
            DispatchAssetGeneration::reconcile($story->id);

            $this->line(sprintf(
                'Story reconciled to %s — the scenes that did not finish are flagged for retry.',
                (string) $story->fresh()?->status->value,
            ));
        }
    }
}
