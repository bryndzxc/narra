<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

/**
 * Which workers are alive on a queue, and what each of them believes.
 *
 * **Why this has to exist at all.** The provider pin and the run fingerprint
 * both catch a stale worker, but they catch it *after* the operator has pressed
 * the button — one scene fails loudly and the rest of the batch is a wasted
 * run. The fingerprint is the seatbelt; this is looking before pulling out.
 *
 * There is no Horizon on this platform to ask, and no supervisor to ask either
 * — `queue:work` processes are started by hand or by NSSM and neither registers
 * anything anywhere. So the workers announce themselves: every worker writes its
 * boot time and its fingerprint into the cache on each poll, and the dispatching
 * process — which by definition has fresh code, because it is the one the
 * operator just started — reads them and decides.
 *
 * **Announced on `Looping`, not on boot.** `Looping` fires on every poll of the
 * queue, which makes the entry a heartbeat rather than a registration: a worker
 * that was killed stops refreshing and ages out on its own. A boot-time-only
 * registration would leave a dead worker in the list forever, and a preflight
 * that refuses because of a worker that no longer exists is a preflight that
 * gets disabled within a week.
 *
 * One cache key per queue holding a map of pid => entry, rather than a key per
 * worker. The Cache facade cannot enumerate keys on the Redis store without
 * reaching past it into a raw connection, and a preflight that only works on one
 * cache driver is a preflight that is silently absent in tests.
 */
final class WorkerRegistry
{
    /**
     * How long an entry counts as alive, in seconds.
     *
     * Comfortably longer than the heartbeat interval so an ordinary pause — a
     * worker busy inside a long job and not looping — never reads as death.
     * `queue:work` polls on a 3-second sleep when idle; a worker mid-job can be
     * quiet for as long as the job runs, which is why the entry is refreshed
     * from the job path too.
     */
    private const TTL_SECONDS = 300;

    /** Do not rewrite the map more often than this. */
    private const HEARTBEAT_INTERVAL = 15;

    private static ?float $lastWrite = null;

    /**
     * When THIS process started.
     *
     * `LARAVEL_START` is defined by `artisan` before the framework loads, which
     * makes it the earliest honest answer available. Captured once.
     */
    private static ?float $bootedAt = null;

    /**
     * Queue names this process has announced itself on, so it can withdraw from
     * all of them when it stops.
     *
     * @var array<int, string>
     */
    private static array $announcedOn = [];

    /**
     * Record this worker as alive on `$queue`, with what it believes.
     *
     * @param  array<string, scalar|null>  $fingerprint
     */
    public static function heartbeat(string $queue, array $fingerprint, bool $force = false): void
    {
        $now = microtime(true);

        if (! $force && self::$lastWrite !== null && ($now - self::$lastWrite) < self::HEARTBEAT_INTERVAL) {
            return;
        }

        self::$lastWrite = $now;

        foreach (self::split($queue) as $name) {
            self::write($name, $fingerprint, $now);

            if (! in_array($name, self::$announcedOn, true)) {
                self::$announcedOn[] = $name;
            }
        }
    }

    /**
     * The workers currently alive on `$queue`, newest boot first.
     *
     * @return array<int, array{pid: int, booted_at: float, seen_at: float, fingerprint: array<string, scalar|null>, digest: string}>
     */
    public static function live(string $queue): array
    {
        $cutoff = microtime(true) - self::TTL_SECONDS;

        $entries = array_filter(
            self::read($queue),
            fn (array $entry): bool => ($entry['seen_at'] ?? 0) >= $cutoff,
        );

        usort($entries, fn (array $a, array $b): int => $b['booted_at'] <=> $a['booted_at']);

        return array_values($entries);
    }

    /**
     * Workers on `$queue` whose fingerprint is not `$fingerprint`.
     *
     * This is the whole question the preflight asks. A worker with a DIFFERENT
     * fingerprint booted before whatever changed, and will either refuse the job
     * or — for anything the fingerprint does not cover — do it the old way.
     *
     * @param  array<string, scalar|null>  $fingerprint
     * @return array<int, array{pid: int, booted_at: float, seen_at: float, fingerprint: array<string, scalar|null>, digest: string}>
     */
    public static function stale(string $queue, array $fingerprint): array
    {
        $want = RunFingerprint::digest($fingerprint);

        return array_values(array_filter(
            self::live($queue),
            fn (array $entry): bool => $entry['digest'] !== $want,
        ));
    }

    /**
     * Drop this process's entry from every queue it announced itself on.
     *
     * `WorkerStopping` does not say which queue the worker was serving, and by
     * then the answer would be ambiguous anyway for a worker serving several. So
     * the queues it announced on are remembered as it announces.
     */
    public static function deregisterEverywhere(): void
    {
        foreach (self::$announcedOn as $queue) {
            self::deregister($queue);
        }

        self::$announcedOn = [];
    }

    /** Drop this process's entry. Called when a worker stops cleanly. */
    public static function deregister(string $queue): void
    {
        foreach (self::split($queue) as $name) {
            $map = self::read($name);
            unset($map[(string) getmypid()]);
            Cache::put(self::key($name), $map, self::TTL_SECONDS);
        }
    }

    /** Forget every worker on a queue. Tests, and `queue:restart` follow-ups. */
    public static function flush(string $queue): void
    {
        foreach (self::split($queue) as $name) {
            Cache::forget(self::key($name));
        }

        self::$lastWrite = null;
    }

    public static function bootedAt(): float
    {
        return self::$bootedAt ??= defined('LARAVEL_START') ? (float) LARAVEL_START : microtime(true);
    }

    /**
     * @param  array<string, scalar|null>  $fingerprint
     */
    private static function write(string $queue, array $fingerprint, float $now): void
    {
        $map = self::read($queue);
        $cutoff = $now - self::TTL_SECONDS;

        // Prune here rather than only on read, so a machine that has restarted
        // its workers a hundred times does not carry a hundred dead pids.
        $map = array_filter($map, fn (array $e): bool => ($e['seen_at'] ?? 0) >= $cutoff);

        $map[(string) getmypid()] = [
            'pid' => (int) getmypid(),
            'booted_at' => self::bootedAt(),
            'seen_at' => $now,
            'fingerprint' => $fingerprint,
            'digest' => RunFingerprint::digest($fingerprint),
        ];

        Cache::put(self::key($queue), $map, self::TTL_SECONDS);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function read(string $queue): array
    {
        $map = Cache::get(self::key($queue));

        return is_array($map) ? $map : [];
    }

    /**
     * `queue:work --queue=assets,render` is one process serving both, and it is
     * alive on each of them.
     *
     * @return array<int, string>
     */
    private static function split(string $queue): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $queue))));
    }

    private static function key(string $queue): string
    {
        return 'narra:workers:'.$queue;
    }
}
