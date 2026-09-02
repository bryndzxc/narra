<?php

namespace App\Jobs;

use App\Actions\GenerateMetadata;
use App\Enums\RenderStage;
use App\Models\RenderJob;
use App\Models\Story;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * The publish sheet, on the `text` queue.
 *
 * Queued rather than run inline from the page for the ordinary reason — three
 * calls on three models, one of them a thinking model at high effort, is longer
 * than a web request should be held open for — and on `text` rather than
 * `render` or `assets` for the reason the three-queue split exists at all: a
 * 40-minute mux must never block a script draft, and this is a script draft.
 *
 * It is also the first thing in the app that has ever put a job on `text`. That
 * queue has been in config, in the setup docs and in the NSSM instructions
 * since Phase 1, and until now an operator following those instructions was
 * running a worker that could never receive anything.
 *
 * Not a `RenderStageJob`. That base class opens a render workspace and hands
 * the job an FFmpeg heartbeat, and this stage touches neither — it writes text.
 * What it does share is the `render_jobs` row, because that table is the
 * operator's only window into what a worker is doing on this platform and
 * `RenderStage::Metadata` has been enumerated in it, unwritten, since Phase 1.
 *
 * `$tries = 1`, like every other stage that spends money. An automatic retry of
 * a call that already billed is an automatic second charge nobody asked for.
 */
class GenerateMetadataJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /**
     * Meaningless on Windows — Laravel enforces this with a pcntl alarm and
     * pcntl does not exist here. The protection that works on both platforms is
     * the explicit HTTP client timeout in ProviderBindings.
     */
    public int $timeout = 3600;

    /**
     * Not readonly: Laravel rehydrates a queued job by writing properties back
     * through reflection, and a readonly promotion fails at unserialize time —
     * in the worker, not at dispatch.
     */
    public function __construct(public int $storyId, public bool $force = false)
    {
        $this->onQueue((string) config('render.queues.text'));
    }

    public function handle(GenerateMetadata $generate): void
    {
        $story = Story::query()->findOrFail($this->storyId);

        $job = RenderJob::open($this->storyId, RenderStage::Metadata);

        try {
            $notes = $generate->handle($story, $this->force);

            $job->succeed(null, $notes === []
                ? 'Publish sheet written. Nothing was trimmed or dropped.'
                : implode("\n", $notes));
        } catch (Throwable $e) {
            $job->fail($e);

            throw $e;
        }
    }

    /**
     * The job died somewhere handle() could not catch — a killed worker, a
     * serialisation error. Without this the row sits at `running` forever and
     * is reported as a stale heartbeat rather than as the failure it is.
     */
    public function failed(?Throwable $e): void
    {
        $job = RenderJob::query()
            ->where('story_id', $this->storyId)
            ->where('stage', RenderStage::Metadata)
            ->whereNull('scene_id')
            ->first();

        if ($job !== null && ! $job->status->isFinished() && $e !== null) {
            $job->fail($e);
        }
    }
}
