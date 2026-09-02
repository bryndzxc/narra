<?php

namespace App\Jobs;

use App\Enums\RenderStage;
use App\Models\RenderJob;
use App\Models\Story;
use App\Services\Ffmpeg;
use App\Support\RenderWorkspace;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Base for every queued render stage.
 *
 * The subclasses are thin on purpose: each one wraps an Action that was already
 * proven at full length in Phase 0, and adds nothing but bookkeeping. The
 * bookkeeping is what Phase 1 is for —
 *
 *  - a `render_jobs` row per stage, so the operator can see what happened;
 *  - a heartbeat while FFmpeg runs, because `queue:work --timeout` needs pcntl
 *    and there is none on this platform, so a hung encode is otherwise
 *    completely silent;
 *  - batch awareness, so a cancelled batch stops rather than grinding through
 *    another 190 scenes.
 *
 * `$tries = 1`. Re-running a render stage is minutes to hours of CPU and, from
 * Phase 2, real money — it is an operator decision, not an automatic one.
 */
abstract class RenderStageJob implements ShouldQueue
{
    use Batchable, Queueable;

    public int $tries = 1;

    /**
     * Meaningless on Windows — Laravel enforces this with a pcntl alarm and
     * pcntl does not exist here, so the flag is silently ineffective. Set for
     * the Linux production box; the protection that actually works on both is
     * the Symfony Process timeout inside the FFmpeg wrapper.
     */
    public int $timeout = 21600;

    /**
     * Not readonly, deliberately: Laravel rehydrates a queued job by writing
     * properties back through reflection, and a readonly property cannot be
     * initialised from outside the class that declared it. A readonly promotion
     * here fails at unserialize time, in the worker, not at dispatch — so it
     * looks like every job in the batch failing instantly for no reason.
     */
    public function __construct(public int $storyId, public ?int $sceneId = null)
    {
        $this->onQueue($this->queueName());
    }

    abstract protected function stage(): RenderStage;

    /**
     * Which of the three queues this stage runs on.
     *
     * Overridable rather than hardcoded, because the split is a real
     * requirement and not a naming convention: a 40-minute mux must never block
     * an image batch, and there are only one or two `render` workers for it to
     * block. The asset stages wait on somebody else's HTTP server and can run
     * many at a time; the render stages are CPU-bound and cannot.
     *
     * Defaults to `render` so every FFmpeg stage keeps the queue it already
     * had. The paid stages override it.
     */
    protected function queueName(): string
    {
        return (string) config('render.queues.render');
    }

    /**
     * Do the work. Returns [output path, one-line log] for the render_jobs row.
     *
     * @return array{0: ?string, 1: ?string}
     */
    abstract protected function run(Story $story, RenderWorkspace $workspace, RenderJob $job): array;

    public function handle(Ffmpeg $ffmpeg): void
    {
        // A cancelled batch must not keep spending CPU on the remaining 190
        // scenes just because they were already queued.
        if ($this->batch()?->cancelled()) {
            return;
        }

        $story = Story::query()->findOrFail($this->storyId);
        $workspace = RenderWorkspace::for($story);
        $workspace->ensureExists();

        $job = RenderJob::open($this->storyId, $this->stage(), $this->sceneId, $this->batch()?->id);

        // The one thing standing between a hung FFmpeg and an invisible worker.
        $ffmpeg->heartbeatUsing(fn () => $job->heartbeat());

        try {
            [$outputPath, $log] = $this->run($story, $workspace, $job);

            $job->succeed($outputPath, $log);
        } catch (Throwable $e) {
            $job->fail($e);

            // Rethrown so the batch and failed_jobs both see it. The row is
            // written first, because it is the thing the operator reads.
            throw $e;
        } finally {
            $ffmpeg->heartbeatUsing(null);
        }
    }

    /**
     * Last resort: the job died somewhere handle() could not catch — a worker
     * killed mid-run, a serialisation error. Without this the row would sit at
     * `running` forever and be reported as a stale heartbeat rather than as the
     * failure it is.
     */
    public function failed(?Throwable $e): void
    {
        $job = RenderJob::query()
            ->where('story_id', $this->storyId)
            ->where('stage', $this->stage())
            ->where('scene_id', $this->sceneId)
            ->first();

        if ($job !== null && ! $job->status->isFinished() && $e !== null) {
            $job->fail($e);
        }
    }
}
