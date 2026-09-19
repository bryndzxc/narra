<?php

namespace App\Jobs;

use App\Actions\GeneratePremises;
use App\Enums\RenderStage;
use App\Models\RenderJob;
use App\Models\Story;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * One premise roll, on the text queue.
 *
 * Queued rather than run in the request for the reason the cast sheets taught:
 * a billed call that holds the browser for a minute or more reads as a click
 * that never landed, and the natural answer to that is a second click, which
 * is a second bill. The page shows the stage's row while it runs.
 *
 * One try. A retry is a second billed call, and whether to make one is the
 * operator's decision, taken by pressing the button again.
 */
class GeneratePremisesJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /** Symfony-level timeouts on the HTTP client are the real protection; see ProviderBindings. */
    public int $timeout = 1800;

    public function __construct(
        public int $storyId,
        public string $idea,
    ) {
        $this->onQueue((string) config('render.queues.text'));
    }

    public function handle(GeneratePremises $premises): void
    {
        $premises->handle(Story::query()->findOrFail($this->storyId), $this->idea);
    }

    /** The job died where the Action could not catch it; mark its open row failed. */
    public function failed(?Throwable $e): void
    {
        if ($e === null) {
            return;
        }

        RenderJob::query()
            ->where('story_id', $this->storyId)
            ->where('stage', RenderStage::Premises)
            ->whereNull('scene_id')
            ->get()
            ->each(function (RenderJob $job) use ($e): void {
                if (! $job->status->isFinished()) {
                    $job->fail($e);
                }
            });
    }
}
