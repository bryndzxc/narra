<?php

namespace App\Support;

use Carbon\CarbonInterval;
use Illuminate\Support\Facades\Queue;

/**
 * What the three queue workers are, for a page to show.
 *
 * **This reports. It does not decide.** The decision — refuse a dispatch into
 * stale workers — lives in AssertWorkersCurrent, in the dispatching process,
 * which is the only one with fresh code by construction. A second opinion here
 * would be a guard downstream of the thing it distrusts, evaluated by whatever
 * process happens to be rendering a page, which is exactly the shape of every
 * defence this project has had fail. So every fact below is read from the same
 * WorkerRegistry the refusal reads, and nothing is recomputed.
 *
 * **Why a readout is needed at all, given the refusal works.** Because the
 * console is replacing the terminal, and three terminal windows are currently
 * the health display — you can see them scrolling. Take them away and worker
 * liveness becomes unobservable, while ABSENCE stays a warning rather than a
 * refusal (correctly: nothing is lost, the job waits). A New Story page that
 * queued an outline into a dead `text` queue would look exactly like one that
 * worked. That is absence read as agreement, on the page the operator watches
 * instead of the pipeline — the fifth entry in the false-success table, which
 * is the one that was not in the pipeline either.
 *
 * NSSM does not close this. It closes "I forgot to start it" and it makes the
 * other half worse: a service up for six days across four config edits is
 * precisely the stale worker, and it restarts itself after every crash somebody
 * might otherwise have noticed.
 *
 * **The depth is the one number here that is not ours.** Every other fact comes
 * from the registry the refusal reads. `pending` comes from the queue itself,
 * and it has to, because `render_jobs` cannot answer it: a row is opened by
 * `RenderJob::open()` inside the RUNNING job, so a scene still sitting in Redis
 * has no row at all. A 270-scene asset run whose worker exited at `--max-time`
 * therefore leaves the progress page reading "118 done, 0 queued" while 152
 * scenes wait and nothing listens — every number on the page correct, and the
 * page as a whole false. That is absence read as agreement one more time, and
 * only the queue knows better.
 *
 * Depth alone is not an alarm; depth with nobody listening is. That pairing is
 * STRANDED, and it is the difference between "queued" and "queued forever".
 *
 * A depth that cannot be READ is reported as unreadable and never as zero. An
 * unreadable check is a failed check, not a passed one, so an absent queue
 * whose depth is unknown says exactly that rather than quietly settling into
 * the calm case.
 */
final class WorkerHealth
{
    public const OK = 'ok';

    public const STALE = 'stale';

    public const ABSENT = 'absent';

    /**
     * Jobs are waiting on this queue and nothing is listening.
     *
     * ABSENT with work in it. Its own state rather than a footnote on ABSENT
     * because the two want opposite reactions: an empty queue with no worker is
     * a note, and a queue holding 152 scenes with no worker is the pipeline
     * stopped while the page says it is running.
     */
    public const STRANDED = 'stranded';

    public const INLINE = 'inline';

    /**
     * Queue depths already read this second, keyed by queue name.
     *
     * The stories index calls forQueue() once per row through NextAction, so
     * without this a 25-row page asks Redis the same three questions 25 times.
     * Held for a second rather than for the process, because a queue:work
     * daemon lives for hours and a value memoised forever there would be a
     * stale reading presented as current — the exact thing this class is for.
     *
     * @var array<string, array{0: float, 1: ?int}>
     */
    private static array $depths = [];

    /**
     * Every queue this app dispatches to, in pipeline order.
     *
     * @return array<int, array{
     *     queue: string,
     *     role: string,
     *     state: string,
     *     live: int,
     *     stale: int,
     *     oldest_boot: ?string,
     *     pids: array<int, int>,
     *     read_at: float,
     *     pending: ?int,
     *     fact: string,
     *     advice: string,
     *     headline: string,
     * }>
     */
    public static function all(): array
    {
        return array_map(
            fn (array $q): array => self::forQueue($q['queue'], $q['role']),
            [
                ['queue' => (string) config('render.queues.text'), 'role' => 'scripts, scenes, publish sheet'],
                ['queue' => (string) config('render.queues.assets'), 'role' => 'stills, narration, word timings'],
                ['queue' => (string) config('render.queues.render'), 'role' => 'clips, concat, subtitles, mux'],
            ],
        );
    }

    /**
     * @return array{
     *     queue: string,
     *     role: string,
     *     state: string,
     *     live: int,
     *     stale: int,
     *     oldest_boot: ?string,
     *     pids: array<int, int>,
     *     read_at: float,
     *     pending: ?int,
     *     fact: string,
     *     advice: string,
     *     headline: string,
     * }
     */
    public static function forQueue(string $queue, string $role = ''): array
    {
        $driver = self::driver();

        // `sync` runs the job in the process that dispatched it, so the thing
        // that would execute it is the thing that queued it — it cannot
        // disagree with itself. Saying "no workers" here would be alarming and
        // wrong. Stated the same way AssertWorkersCurrent states it.
        if (in_array($driver, ['sync', 'null'], true)) {
            return [
                'queue' => $queue,
                'role' => $role,
                'state' => self::INLINE,
                'live' => 0,
                'stale' => 0,
                'oldest_boot' => null,
                'pids' => [],
                'read_at' => microtime(true),
                'pending' => 0,
                'fact' => $inline = sprintf(
                    'Queue driver is "%s" — jobs run in the web process, so no worker can be stale.',
                    $driver,
                ),
                'advice' => '',
                'headline' => $inline,
            ];
        }

        $current = RunFingerprint::shared();
        $live = WorkerRegistry::live($queue);
        $stale = WorkerRegistry::stale($queue, $current);
        $pending = self::depth($queue);

        $state = match (true) {
            $stale !== [] => self::STALE,
            // Nothing listening AND work waiting. Checked before the bare
            // ABSENT case so the loud reading wins whenever both are true.
            $live === [] && ($pending ?? 0) > 0 => self::STRANDED,
            $live === [] => self::ABSENT,
            default => self::OK,
        };

        return [
            'queue' => $queue,
            'role' => $role,
            'state' => $state,
            'live' => count($live),
            'stale' => count($stale),
            'oldest_boot' => self::oldestBoot($live),

            /*
             * The pids, so this panel can be CHECKED rather than believed.
             *
             * Nothing else in the console can be verified against the machine
             * it runs on. Every other number here comes from our own
             * bookkeeping; a pid is the one fact an operator can put next to
             * `Get-CimInstance` and see agree or disagree in one glance. That
             * matters most on exactly the reading this panel exists for — the
             * one taken before authorising a spend.
             */
            'pids' => array_map(static fn (array $e): int => (int) $e['pid'], $live),

            /*
             * WHEN this reading was taken.
             *
             * Every value above is true as of a moment, and a rendered page has
             * no way to say which moment. The renders pages deliberately stop
             * refreshing when nothing is running — which is precisely when
             * workers get restarted — so a page left open across a restart goes
             * on showing the old registry, correctly, forever. Nothing on it is
             * wrong; the page as a whole is, which is the false-success shape
             * this project keeps finding.
             *
             * The reading cannot know its own age, so it carries its timestamp
             * and the browser ages it. See `.reading` in the stylesheet.
             */
            'read_at' => microtime(true),

            'pending' => $pending,
            /*
             * The message, in two halves.
             *
             * `fact` is about THIS queue — its name, its counts. `advice` is
             * about the STATE and is identical for every queue in it. They are
             * separate because the dashboard's alarm band shows one block per
             * state rather than one per queue: three stranded queues get their
             * three facts and one copy of the ~30 words explaining what
             * stranded means, instead of the same paragraph three times.
             *
             * Splitting the message rather than paraphrasing it in the blade is
             * the point. A view that composed its own summary would be a second
             * author for a message this class already writes, and the two would
             * eventually say different things about the same state.
             *
             * `headline` is DERIVED from the two, so every existing caller — the
             * worker-health panel, the stories index, the render page, the
             * per-button compact form — sees exactly the string it saw before.
             */
            'fact' => $fact = match ($state) {
                self::STALE => sprintf(
                    '%d of %d worker(s) on "%s" booted before the current code.',
                    count($stale),
                    count($live),
                    $queue,
                ),
                self::STRANDED => sprintf(
                    '%d job(s) are waiting on "%s" and nothing is listening.',
                    $pending,
                    $queue,
                ),
                self::ABSENT => sprintf('Nothing is listening on "%s".', $queue),
                default => sprintf(
                    '%d worker(s) on "%s" agree with this process (fingerprint %s)%s.',
                    count($live),
                    $queue,
                    RunFingerprint::digest($current),
                    ($pending ?? 0) > 0 ? sprintf(', working through %d queued job(s)', $pending) : '',
                ),
            },

            'advice' => $advice = match ($state) {
                self::STALE => 'Anything dispatched here is refused until they are restarted.',
                // Phrased without "this queue": it is the per-STATE half of the
                // message and the dashboard prints one copy of it above three
                // queue names, so a singular reference would be quietly wrong
                // in exactly the place the collapse was meant to improve.
                self::STRANDED => 'This is not "about to start" — nothing waiting on a queue with no '
                    .'worker will run until one starts.',
                self::ABSENT => $pending === null
                    // An unreadable depth is not an empty one. Without the
                    // number this cannot be told apart from a queue that is
                    // simply idle, and saying so is the whole of the fix —
                    // a check that could not run reports that it could not
                    // run, never that it passed.
                    ? 'Its depth could not be read, so an idle queue and a stalled one look the '
                      .'same from here.'
                    : 'It is empty, so nothing is waiting — but nothing dispatched here will run '
                      .'either.',
                default => '',
            },

            'headline' => match ($state) {
                // Fact, advice, then the aside — the order this sentence has
                // always been read in.
                self::STALE => $fact.' '.$advice.(($pending ?? 0) > 0
                    ? sprintf(' %d job(s) are already waiting on it.', $pending)
                    : ''),
                self::STRANDED, self::ABSENT => $fact.' '.$advice,
                default => $fact,
            },
        ];
    }

    /**
     * How many jobs are waiting on `$queue`, or null if that cannot be read.
     *
     * The queue is asked directly rather than `render_jobs` counted, because
     * `render_jobs` structurally cannot know: `RenderJob::open()` runs inside
     * the job, so an unstarted scene has no row. This is the one fact on the
     * page that comes from outside the app's own bookkeeping, and it is the
     * only reason the page can tell "nothing queued" from "nothing running".
     *
     * Laravel's redis driver sums the ready list, the delayed set and the
     * reserved set, which is the number an operator means by "still to do".
     *
     * Null on failure, never 0. A Redis that is down would otherwise report
     * every queue as calmly empty, which is the exact substitution — absence
     * read as agreement — this whole readout exists to stop.
     */
    private static function depth(string $queue): ?int
    {
        $now = microtime(true);

        if (isset(self::$depths[$queue]) && ($now - self::$depths[$queue][0]) < 1.0) {
            return self::$depths[$queue][1];
        }

        try {
            $depth = (int) Queue::connection((string) config('queue.default'))->size($queue);
        } catch (\Throwable) {
            $depth = null;
        }

        self::$depths[$queue] = [$now, $depth];

        return $depth;
    }

    /** Drop the memo. Tests, which change the answer inside one second. */
    public static function forget(): void
    {
        self::$depths = [];
    }

    /**
     * How long the longest-running worker has been up, as a phrase.
     *
     * Shown because uptime is the readable proxy for the risk NSSM introduces:
     * a worker up for days has survived every config change made in those days.
     * It is not evidence of staleness — the fingerprint is — but it is the
     * thing worth looking at when the fingerprint says everything is fine and
     * something is still wrong.
     *
     * @param  array<int, array{booted_at: float}>  $live
     */
    private static function oldestBoot(array $live): ?string
    {
        if ($live === []) {
            return null;
        }

        $oldest = min(array_column($live, 'booted_at'));
        $seconds = max(0, (int) round(microtime(true) - $oldest));

        return CarbonInterval::seconds($seconds)->cascade()->forHumans(short: true, parts: 2);
    }

    /** The queue driver jobs would actually be handed to. */
    private static function driver(): string
    {
        $connection = (string) config('queue.default');

        return (string) config("queue.connections.{$connection}.driver", $connection);
    }
}
