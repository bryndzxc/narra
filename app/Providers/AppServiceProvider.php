<?php

namespace App\Providers;

use App\Services\Ffmpeg;
use App\Support\RunFingerprint;
use App\Support\WorkerRegistry;
use Illuminate\Pagination\Paginator;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\Events\WorkerStopping;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Throwable;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(Ffmpeg::class, fn (): Ffmpeg => new Ffmpeg(
            ffmpeg: config('render.ffmpeg'),
            ffprobe: config('render.ffprobe'),
            probeTimeout: (int) config('render.timeouts.probe'),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // FIRST, and the ordering is the whole point.
        //
        // The code marker has to be frozen while it still describes the code
        // this process actually booted with. Left to compute lazily, a worker
        // that had been running for hours would read the CURRENT files off disk
        // the first time anything asked, and report itself up to date — a check
        // passing precisely because the thing it exists to catch is the thing
        // running it. See RunFingerprint.
        RunFingerprint::sealCode();

        $this->announceWorker();

        // Laravel's default paginator view assumes Tailwind. This app ships one
        // hand-written stylesheet and no Tailwind at all, so every utility class
        // in that view is inert — including the ones that hide the desktop block
        // and size its inline SVG chevrons, which is how a pagination arrow ends
        // up rendering the height of the viewport.
        //
        // This covers plain Blade pages. Livewire ignores it and picks its own
        // view, so components paginate through PaginatesWithProjectTheme.
        Paginator::defaultView('pagination.narra');
        Paginator::defaultSimpleView('pagination.narra');
    }

    /**
     * Make this process visible to the dispatcher, if it is a queue worker.
     *
     * There is no Horizon on this platform and no supervisor that registers
     * anything, so a worker is otherwise completely anonymous: nothing outside
     * it can discover that it exists, let alone when it booted or what it
     * believes. PreflightAssetDispatch needs both to be able to refuse a spend,
     * so the workers announce themselves.
     *
     * `Looping` fires on every poll, which makes the entry a heartbeat that ages
     * out on its own when a worker is killed. `JobProcessing` refreshes it too,
     * because a worker inside a long job is not looping and must not be mistaken
     * for a dead one. `WorkerStopping` removes it on a clean exit so a restarted
     * worker does not appear twice.
     *
     * The fingerprint is deliberately NOT story-specific here — a worker serves
     * every story on the queue. RunFingerprint::for() reads the per-story fields
     * off the story it is given; the preflight compares the shared fields, which
     * are the ones a worker can be stale about.
     */
    private function announceWorker(): void
    {
        // Fingerprint per queue, resolved lazily inside the listener: config is
        // fully loaded by the time a worker loops, and a throw here would take
        // down the whole worker for a bookkeeping concern.
        $announce = function (string $queue): void {
            try {
                WorkerRegistry::heartbeat($queue, RunFingerprint::shared());
            } catch (Throwable) {
                // A cache that is down must never stop a worker from working.
                // The preflight reads an empty registry as "no workers", which
                // refuses rather than waves through — so failing quietly here
                // cannot turn into a silent pass there.
            }
        };

        Event::listen(Looping::class, fn (Looping $e) => $announce($e->queue));
        Event::listen(JobProcessing::class, fn (JobProcessing $e) => $announce($e->job->getQueue() ?? ''));
        Event::listen(WorkerStopping::class, function (): void {
            try {
                WorkerRegistry::deregisterEverywhere();
            } catch (Throwable) {
                // Same reasoning: the entry ages out on its own.
            }
        });
    }
}
