<?php

namespace App\Support;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Throwable;

/**
 * A worker that has gone stale takes itself out.
 *
 * ---------------------------------------------------------------------------
 * WHAT THIS DOES NOT CHANGE
 * ---------------------------------------------------------------------------
 *
 * `AssertWorkersCurrent` is untouched. A stale worker is still refused at
 * dispatch, still loudly, still by the dispatching process — the only one with
 * fresh code by construction. Nothing here weakens the guard; it shortens the
 * time a machine spends in the state the guard refuses.
 *
 * The problem was never the refusal. It was that recovery was entirely manual:
 * every code change turned the panel red, and the panel that goes red is the
 * one an operator reads before authorising a spend. A red that means "somebody
 * edited a file" and a red that means "your pipeline has stopped" look the same,
 * and the cheapest way to make an alarm ignorable is to have it fire for
 * something the reader cannot do anything useful about.
 *
 * ---------------------------------------------------------------------------
 * WHY THIS IS SAFE, WHICH IS NOT OBVIOUS
 * ---------------------------------------------------------------------------
 *
 * `RunFingerprint` seals its code marker at boot and says, at length, that a
 * worker recomputing it from disk is the whole thing the design forbids. That
 * warning is about SELF-CERTIFICATION: a stale worker reading the new files and
 * announcing itself current would defeat the guard completely.
 *
 * This reads the same value with the opposite polarity. It is used only to
 * decide to DIE, never to decide that anything is fine:
 *
 *   a wrong "I am current"  → 117 scenes narrated at the wrong speed;
 *   a wrong "I am stale"    → one restart.
 *
 * The announced fingerprint is still `RunFingerprint::shared()`, still built
 * from the SEALED marker. A test asserts that the registry never receives the
 * recomputed one.
 *
 * ---------------------------------------------------------------------------
 * THE THREE BOUNDS
 * ---------------------------------------------------------------------------
 *
 * 1. **Only when there is nothing to lose, ANYWHERE.** The check is skipped
 *    whenever any queue on this machine holds work. A batch in flight is never
 *    interrupted, so a 40-minute mux and a 270-scene asset run are not at risk,
 *    and a batch cannot end up split across two code versions mid-run. The
 *    moment the queues drain, the workers refresh themselves. In the case this
 *    was built for — code edited while nothing is running — everything is empty
 *    and recovery is immediate.
 *
 *    **It used to check only the worker's OWN queue, and that was wrong in a
 *    way no test could see.** The bound was evaluated per worker; the action is
 *    `queue:restart`, which is a machine-wide broadcast. So an idle worker on
 *    an empty queue stood down — correctly, by its own lights — and took every
 *    busy worker with it.
 *
 *    `workers:drill busy` caught it on the first run. Ten jobs on `text`, a
 *    file touched mid-batch: the `text` worker's own bound declined exactly as
 *    designed and never logged a restart, while the idle `assets` worker
 *    broadcast a stop into it. Jobs 1-2 ran on one code marker and jobs 3-10 on
 *    another — the split this bound exists to prevent, produced BY this bound's
 *    own mechanism.
 *
 *    The docblock here previously called the broadcast "correct rather than
 *    over-broad". It is over-broad, and the fix is to make the bound as wide as
 *    the action: a broadcast may only be sent when the whole machine is idle.
 *    The cost is accepted and is the conservative direction — a long mux on
 *    `render` delays `text` and `assets` refreshing until it finishes.
 *
 *    An UNREADABLE depth blocks the restart too. A queue that cannot be counted
 *    is not a queue known to be empty, and this codebase reads a failed check
 *    as a failed check rather than as a pass.
 *
 * 2. **Only between jobs.** It hangs off `Looping`, which fires when the daemon
 *    polls rather than while it works, and the exit itself goes through the
 *    cache flag Laravel's own loop reads — the same one `queue:restart` sets,
 *    checked in `stopIfNecessary()` after a job completes. No job is ever
 *    killed. That flag is also the ONLY mechanism available here: a graceful
 *    stop by signal needs `pcntl`, which does not exist in Windows PHP.
 *
 * 3. **Bounded against looping, twice.** The check is throttled, and NSSM is
 *    configured with `AppThrottle 10000`, which backs off any service that
 *    exits within ten seconds of starting. A restart storm during an editing
 *    session is therefore self-limiting on both sides. It also cannot loop on
 *    its own: after a restart the sealed marker IS the disk marker, so the
 *    condition is false until somebody edits another file.
 *
 * Config-gated, because this is a development affordance. On a Linux box the
 * deploy restarts the workers and this should be off — an unexpected restart
 * during a deploy is a worker booting on half-copied code, which it would then
 * correct on the next pass, but there is no reason to invite it.
 */
final class StaleWorkerRestart
{
    /** How often a worker is willing to walk `app/` and `config/` to check. */
    private const CHECK_INTERVAL = 15;

    /** A breadcrumb, so a restart the operator did not order is explainable. */
    public const LAST_RESTART_KEY = 'narra:workers:self-restarted-at';

    private static ?float $lastCheck = null;

    /**
     * Exit if this process is running code the disk no longer holds.
     *
     * Called from the `Looping` listener. Every failure path is swallowed: a
     * cache that is down, a queue that cannot be counted, a file system that
     * refuses a read — none of them is a reason to stop a worker working, and
     * the dispatch-time refusal is still in place either way.
     */
    public static function consider(string $queue): void
    {
        if (! (bool) config('render.workers.restart_when_stale', true)) {
            return;
        }

        $now = microtime(true);

        if (self::$lastCheck !== null && ($now - self::$lastCheck) < self::CHECK_INTERVAL) {
            return;
        }

        self::$lastCheck = $now;

        try {
            foreach (self::queuesToConsider($queue) as $name) {
                /*
                 * Nothing to lose, on ANY queue.
                 *
                 * A queue holding work is a batch in flight — possibly a paid
                 * one — and no amount of staleness is worth interrupting it.
                 * This asks about every queue on the machine and not just this
                 * worker's, because the stop below is a broadcast: scoping the
                 * question more narrowly than the answer is what let an idle
                 * worker stand a busy one down. See bound 1 above.
                 *
                 * On Redis, `size()` counts waiting, delayed AND reserved, so a
                 * job currently being run by a sibling still reads as depth.
                 * That is the reading wanted here.
                 */
                if (Queue::connection()->size($name) > 0) {
                    return;
                }
            }

            // The sealed marker this process booted with, against the code that
            // is on disk now. See RunFingerprint::codeOnDiskNow() for why this
            // comparison is allowed to exist at all.
            if (RunFingerprint::code() === RunFingerprint::codeOnDiskNow()) {
                return;
            }

            Log::info('Worker is running superseded code and is restarting itself.', [
                'queue' => $queue,
                'pid' => getmypid(),
                'booted_with' => RunFingerprint::code(),
                'on_disk_now' => RunFingerprint::codeOnDiskNow(),
            ]);

            Cache::put(self::LAST_RESTART_KEY, time(), 3600);

            /*
             * The framework's own graceful stop. Every worker on the machine
             * exits after its current job, which is correct rather than
             * over-broad: the code changed, so all of them are stale, and the
             * operator's manual fix was always this same command.
             */
            Artisan::call('queue:restart');
        } catch (Throwable $e) {
            // A convenience must never be able to stop a worker working.
            Log::warning('Could not check whether this worker is stale: '.$e->getMessage());
        }
    }

    /**
     * When the workers last restarted themselves, if they did recently.
     *
     * Read by the worker-health panel. A restart nobody ordered is otherwise
     * indistinguishable from a crash, and "the workers bounced and I do not
     * know why" is exactly the sort of unexplained state this console exists to
     * remove rather than to add.
     */
    public static function lastSelfRestart(): ?int
    {
        try {
            $at = Cache::get(self::LAST_RESTART_KEY);
        } catch (Throwable) {
            return null;
        }

        return is_numeric($at) ? (int) $at : null;
    }

    /**
     * Every queue whose contents this process is about to gamble.
     *
     * The worker's own queues, plus the three the app dispatches to. Both
     * halves are needed: the configured list is what `queue:restart` will
     * actually affect, and the worker's own is there in case somebody starts a
     * `queue:work --queue=something-else` that config does not know about.
     *
     * @return array<int, string>
     */
    private static function queuesToConsider(string $queue): array
    {
        $configured = array_filter([
            (string) config('render.queues.text'),
            (string) config('render.queues.assets'),
            (string) config('render.queues.render'),
        ]);

        return array_values(array_unique([...self::split($queue), ...$configured]));
    }

    /**
     * @return array<int, string>
     */
    private static function split(string $queue): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $queue))));
    }
}
