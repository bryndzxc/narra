<?php

namespace App\Actions;

use App\Exceptions\DispatchRefusedException;
use App\Support\RunFingerprint;
use App\Support\WorkerRegistry;

/**
 * Refuse to queue work for a worker that booted before the current code.
 *
 * Extracted from PreflightAssetDispatch because it was needed on a second queue
 * within minutes of being written, in the most literal way possible: the render
 * workers were started, PadSceneAudio and Ffmpeg were then fixed, and the render
 * was dispatched into workers still holding the old classes. It failed with
 * `Call to undefined method App\Services\Ffmpeg::sampleRate()` — a stale-worker
 * failure, on the one dispatch path that had no stale-worker check, twice in ten
 * minutes.
 *
 * That is the spec's seam rule catching its own author. The guard existed, it
 * worked, and it was wired into one caller out of two — which is precisely the
 * shape of every dead-code gap this project has found: a mechanism built in one
 * place with its second caller arriving later and not wiring it.
 *
 * **The render queue is free, and it is still worth refusing.** Nothing on it
 * bills. But a stale render worker costs a full clip batch and however long the
 * operator takes to notice, and the failure mode is not always as loud as a
 * missing method — a worker holding an older PadSceneAudio would have produced
 * scenes 84% too long and been caught only by the concat assertion, tens of
 * minutes in. Cheap to check, expensive to skip.
 */
class AssertWorkersCurrent
{
    /**
     * @return array<int, array{level: string, message: string}>
     */
    public function handle(string $queue): array
    {
        // On a driver with no worker, there is no worker to be stale.
        //
        // `sync` runs the job inline in THIS process, so the thing that would
        // execute it is the thing that just computed the fingerprint — it cannot
        // disagree with itself. `null` discards the job entirely. Skipping here
        // is a statement about those drivers rather than an escape hatch.
        $driver = $this->driver();

        if (in_array($driver, ['sync', 'null'], true)) {
            return [self::ok(sprintf(
                'queue driver is "%s" — jobs run in this process, so no worker can be stale.',
                $driver,
            ))];
        }

        // The SHARED half of the fingerprint, not the per-story one. A worker
        // serves every story on its queue and announced itself long before any
        // particular story was named.
        $current = RunFingerprint::shared();
        $live = WorkerRegistry::live($queue);

        // A STALE worker is a refusal. An ABSENT one is not, and the difference
        // is what each costs to be wrong about: a stale worker does the work
        // incorrectly, an empty queue does nothing at all and loses nothing.
        $stale = WorkerRegistry::stale($queue, $current);

        if ($stale !== []) {
            throw DispatchRefusedException::staleWorkers($queue, $stale, $current);
        }

        if ($live === []) {
            return [self::warn(sprintf(
                'Nothing is listening on the "%s" queue. The jobs will queue and wait — nothing is '
                ."lost — but nothing happens until a worker starts:\n"
                .'    php artisan queue:work redis --queue=%s --tries=1 --max-time=21600',
                $queue,
                $queue,
            ))];
        }

        return [self::ok(sprintf(
            '%d worker(s) on "%s" agree with this process (fingerprint %s).',
            count($live),
            $queue,
            RunFingerprint::digest($current),
        ))];
    }

    /** The queue driver jobs would actually be handed to. */
    private function driver(): string
    {
        $connection = (string) config('queue.default');

        return (string) config("queue.connections.{$connection}.driver", $connection);
    }

    /** @return array{level: string, message: string} */
    private static function ok(string $message): array
    {
        return ['level' => 'ok', 'message' => $message];
    }

    /** @return array{level: string, message: string} */
    private static function warn(string $message): array
    {
        return ['level' => 'warn', 'message' => $message];
    }
}
