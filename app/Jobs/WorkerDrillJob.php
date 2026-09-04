<?php

namespace App\Jobs;

use App\Support\RunFingerprint;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

/**
 * A job that occupies a worker and reports which code version ran it.
 *
 * This exists to be watched, not to do anything. It is the instrument for
 * `workers:drill busy`, which proves the self-restart's most important bound:
 * that a worker does not take itself out while there is work in the queue, and
 * that a batch therefore finishes on the code version it started with.
 *
 * **It cannot bill, and that is structural rather than promised.** It resolves
 * no provider, constructs no client and takes no story. There is nothing in it
 * that could reach a paid API, which is a stronger claim than any assertion
 * that it did not — the second of the three rules in CLAUDE.md.
 *
 * **What it records is the SEALED marker.** `RunFingerprint::code()` is the
 * marker the executing worker froze at its own boot, so N of these coming back
 * with one identical marker is a positive observation that one code version ran
 * the whole batch. If the self-restart ever fired mid-batch, the jobs after the
 * restart would carry a different pid and a different marker, and the drill
 * would say so instead of the run merely looking fine.
 */
class WorkerDrillJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Never retried: a drill that quietly re-ran would report the wrong pid. */
    public int $tries = 1;

    public function __construct(
        public readonly string $runId,
        public readonly int $sequence,
        public readonly int $sleepSeconds,
    ) {}

    public function handle(): void
    {
        $startedAt = microtime(true);

        // Occupy the worker. `sleep` and not a spin, because the point is to
        // hold the job slot open long enough for somebody to edit a file.
        sleep($this->sleepSeconds);

        Cache::put(
            self::key($this->runId, $this->sequence),
            [
                'sequence' => $this->sequence,
                'pid' => (int) getmypid(),

                // The marker this worker BOOTED with. Not codeOnDiskNow() —
                // that would answer a different question and would make the
                // drill agree with itself no matter what happened.
                'code' => RunFingerprint::code(),
                'started_at' => $startedAt,
                'finished_at' => microtime(true),
            ],
            3600,
        );
    }

    public static function key(string $runId, int $sequence): string
    {
        return "narra:drill:{$runId}:{$sequence}";
    }
}
