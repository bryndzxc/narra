<?php

namespace App\Jobs;

use App\Actions\GenerateActScripts;
use App\Actions\GenerateOutline;
use App\Enums\RenderStage;
use App\Models\RenderJob;
use App\Models\Story;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * The outline and then every act script, on the `text` queue.
 *
 * `story:write` ran both of these SYNCHRONOUSLY, in the console process, and
 * that was correct for a command: somebody is watching, and six sequential Opus
 * calls at minutes each is a reasonable thing to sit through when you chose to
 * sit through it. It is not a reasonable thing to hold a Livewire request open
 * for, so the button gets a job and the command keeps its console.
 *
 * **Nothing here opens a `render_jobs` row.** GenerateOutline and
 * GenerateActScripts already wrap themselves in `RenderJob::record()` — that
 * was the fix for act scripts dying on act 4 of 6 and leaving `/renders/{slug}`
 * looking like a story nobody had started. A second row opened here would be a
 * second answer to the same question, and the two would disagree the first time
 * one of them changed.
 *
 * `$tries = 1`, like every stage that spends money. The seven calls this makes
 * are billed, and an automatic retry of a call that already billed is an
 * automatic second charge nobody asked for. A partial run is resumable by hand:
 * the outline is kept and only the acts without scripts are rewritten.
 */
class WriteStoryJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /**
     * Meaningless on Windows — Laravel enforces this with a pcntl alarm and
     * pcntl does not exist here, so the flag is silently ineffective. The
     * protection that works on both platforms is the explicit HTTP client
     * timeout in ProviderBindings.
     */
    public int $timeout = 7200;

    /**
     * Not readonly: Laravel rehydrates a queued job by writing properties back
     * through reflection, and a readonly promotion fails at unserialize time —
     * in the worker, not at dispatch, which looks like the job failing
     * instantly for no reason.
     *
     * @param  array<int, int>  $actsOnly  Act sequences to rewrite, or [] for all of them.
     */
    public function __construct(
        public int $storyId,
        public ?int $actCount = null,
        public array $actsOnly = [],
        public bool $outlineOnly = false,
    ) {
        $this->onQueue((string) config('render.queues.text'));
    }

    public function handle(GenerateOutline $outline, GenerateActScripts $scripts): void
    {
        $story = Story::query()->findOrFail($this->storyId);

        // The outline is written once and kept. Re-running this job on a story
        // that already has acts is a resume, not a rewrite — replacing the
        // outline would orphan every script written against it, which is the
        // refusal GenerateOutline::assertReady() states in full.
        if ($this->actsOnly === [] && $story->acts()->count() === 0) {
            $outline->handle($story, $this->actCount);
            $story->refresh();
        }

        if ($this->outlineOnly) {
            return;
        }

        // No progress closure, deliberately: there is no console to print to.
        // The progress an operator reads is the note the Action writes onto its
        // own `render_jobs` row — "acts 1 and 2 written, act 3 in flight" — and
        // that is written whether or not anybody is looking, which is the whole
        // difference between this and the command.
        $scripts->handle($story, $this->actsOnly);
    }

    /**
     * The job died somewhere the Actions could not catch — a killed worker, a
     * serialisation error. Without this the row they opened sits at `running`
     * forever and reports as a stale heartbeat rather than as the failure it is.
     */
    public function failed(?Throwable $e): void
    {
        if ($e === null) {
            return;
        }

        RenderJob::query()
            ->where('story_id', $this->storyId)
            ->whereIn('stage', [RenderStage::Outline, RenderStage::ActScripts])
            ->whereNull('scene_id')
            ->get()
            ->each(function (RenderJob $job) use ($e): void {
                if (! $job->status->isFinished()) {
                    $job->fail($e);
                }
            });
    }
}
