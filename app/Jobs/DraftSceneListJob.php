<?php

namespace App\Jobs;

use App\Actions\DraftScenes;
use App\Actions\ExtractCharacters;
use App\Enums\RenderStage;
use App\Models\RenderJob;
use App\Models\Story;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * The cast, then the scenes, on the `text` queue.
 *
 * Cast first and always. Character descriptions are pasted verbatim into every
 * image prompt, so a scene drafted before the cast exists has to invent one —
 * and an invented description is a face that drifts across 150-250 stills,
 * which is the single biggest quality risk in this format.
 *
 * Still text-only. Nothing here generates an image or a second of audio; Gate 2
 * is where the operator decides whether any of that happens, and this job's
 * work is what they read while deciding.
 *
 * As with WriteStoryJob, no `render_jobs` row is opened here: DraftScenes wraps
 * itself in `RenderJob::record()` already. ExtractCharacters does not, which is
 * a gap this job does not paper over — a second row for a stage the Action
 * thinks it is not recording would be a worse lie than a missing one.
 *
 * `$tries = 1`. Both stages are billed calls.
 */
class DraftSceneListJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /**
     * Meaningless on Windows — pcntl does not exist, so Laravel's alarm-based
     * enforcement is silently ineffective. The HTTP client timeout is the one
     * that works.
     */
    public int $timeout = 7200;

    /**
     * Not readonly: Laravel rehydrates a queued job by writing properties back
     * through reflection, and a readonly promotion fails at unserialize time.
     *
     * @param  array<int, int>  $onlyActs  Act sequences to re-cut, or [] for all of them.
     */
    public function __construct(
        public int $storyId,
        public bool $rebuild = false,
        public array $onlyActs = [],
        public bool $castOnly = false,
    ) {
        $this->onQueue((string) config('render.queues.text'));
    }

    public function handle(ExtractCharacters $characters, DraftScenes $scenes): void
    {
        $story = Story::query()->findOrFail($this->storyId);

        // A partial re-cut names acts, and re-extracting the cast underneath it
        // would change the descriptions the untouched acts' prompts were built
        // from. So the cast is rebuilt only on a whole-story rebuild.
        $characters->handle($story, rebuild: $this->rebuild && $this->onlyActs === []);

        if ($this->castOnly) {
            return;
        }

        $scenes->handle(
            $story->refresh(),
            rebuild: $this->rebuild,
            onlyActs: $this->onlyActs,
        );
    }

    /**
     * The job died where the Action could not catch it — a killed worker, a
     * serialisation error. Without this the row sits at `running` forever and
     * reports as a stale heartbeat rather than as the failure it is.
     */
    public function failed(?Throwable $e): void
    {
        if ($e === null) {
            return;
        }

        $job = RenderJob::query()
            ->where('story_id', $this->storyId)
            ->where('stage', RenderStage::DraftScenes)
            ->whereNull('scene_id')
            ->first();

        if ($job !== null && ! $job->status->isFinished()) {
            $job->fail($e);
        }
    }
}
