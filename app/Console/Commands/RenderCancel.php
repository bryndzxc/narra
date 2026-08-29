<?php

namespace App\Console\Commands;

use App\Enums\RenderJobStatus;
use App\Models\RenderJob;
use App\Models\Story;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;

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

        $batchIds = RenderJob::query()
            ->where('story_id', $story->id)
            ->whereNotNull('batch_id')
            ->distinct()
            ->pluck('batch_id');

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

        return self::SUCCESS;
    }
}
