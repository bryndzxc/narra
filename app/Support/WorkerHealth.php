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
 *
 * **And depth with somebody listening who is taking nothing is NOT_CONSUMING.**
 * The third thing this panel could not distinguish, and the worst of the three,
 * because every component of it reads healthy: a live worker, a fresh
 * heartbeat, a matching code marker, a stable pid. It was invisible because
 * every reading here is a LEVEL and this state is a relationship between two of
 * them — so the registry now records the poll and the job start as separate
 * moments, and the question becomes a pure read of one snapshot rather than
 * something a page would have to remember across requests.
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

    /**
     * A worker is polling this queue, work is waiting, and nothing has been
     * taken off it in a long time.
     *
     * ---------------------------------------------------------------------
     * NAMED FOR WHAT WAS OBSERVED, NOT FOR A CAUSE.
     * ---------------------------------------------------------------------
     *
     * The instance: story 23's asset batch, 550 jobs on `assets`, a live worker
     * with a fresh heartbeat, a matching code marker, a stable pid and 5h54m of
     * uptime, consuming exactly zero of them for about twenty minutes. A
     * service restart unblocked it. **Why it stopped consuming is still
     * unknown**, the process was replaced, and the evidence went with it.
     *
     * So this constant says only what a reading can support: LISTENING AND
     * TAKING NOTHING. It is not `wedged`, not `half_open_redis`, not
     * `max_time_exhausted` — the last of those is the plausible name that was
     * nearly used and is REFUTED for that incident, because the worker was
     * 5h54m into a 9h window. A state named after a diagnosis that turns out to
     * be wrong is worse than one named after the symptom, because the name then
     * argues against the next investigation.
     *
     * **Why the panel could not say it before.** Every other reading here is a
     * LEVEL — a heartbeat age, a depth, an uptime — and this state is about the
     * relationship between two of them over time. `Looping` fires on every poll
     * INCLUDING the empty ones, so a fresh heartbeat has only ever meant the
     * loop is turning. The registry records the two moments separately now, so
     * the question is a pure read of one snapshot and needs no memory of a
     * previous page load.
     */
    public const NOT_CONSUMING = 'not_consuming';

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
            /*
             * Somebody IS listening, work is waiting, and nothing is being
             * taken. Last of the troubled states because the three above it are
             * all narrower: stale is a fingerprint mismatch and outranks this
             * because restarting fixes both and the refusal is already in
             * force, and the other two require no live worker at all, which
             * this one cannot have.
             */
            self::takingNothing($live, $pending) => self::NOT_CONSUMING,
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
                self::NOT_CONSUMING => sprintf(
                    '%d worker(s) on "%s" are polling and have taken nothing off it %s, with %d job(s) waiting.',
                    count($live),
                    $queue,
                    self::sinceLastJob($live),
                    $pending,
                ),
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
                /*
                 * What this does NOT say is why. The one observed instance was
                 * never explained — the process was restarted to unblock the
                 * pipeline and the evidence went with it — so naming a cause
                 * here would be inventing one, and an operator who acts on an
                 * invented cause stops reading the ones that are real.
                 *
                 * `Restart-Service` and not `nssm start`: a service in this
                 * state is RUNNING, so `start` reports it already running and
                 * changes nothing. That is what the panel used to print.
                 */
                self::NOT_CONSUMING => 'The loop is turning and the queue is not moving. Why is not known '
                    .'— the one time this was seen the worker was current, alive and two thirds through '
                    .'its --max-time window, and restarting it was what unblocked the queue.',
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
                self::STRANDED, self::ABSENT, self::NOT_CONSUMING => $fact.' '.$advice,
                default => $fact,
            },
        ];
    }

    /**
     * A state as a reader should see it.
     *
     * Exists because one surface — the dashboard's health table — prints the
     * constant itself, and `not_consuming` is a value, not a sentence. The
     * wording matches the badges elsewhere on purpose: two names for one state
     * on two pages is how an operator ends up believing they are two states.
     */
    public static function label(string $state): string
    {
        return match ($state) {
            self::NOT_CONSUMING => 'taking nothing',
            self::ABSENT => 'nothing listening',
            self::OK => 'current',
            default => $state,
        };
    }

    /**
     * Is every live worker on this queue polling and taking nothing?
     *
     * -------------------------------------------------------------------
     * THE PAIR OF CLOCKS, AND WHY ONE WOULD NOT DO
     * -------------------------------------------------------------------
     *
     * `seen_at` cannot answer this and never could. It is ticked by a poll, by
     * a job start AND from inside a long job by `RenderJob::heartbeat()`, which
     * is exactly what makes it a good liveness signal and a useless activity
     * one. `Looping` in particular fires on the EMPTY polls too, so `live = 1`,
     * `state = ok` and a fresh heartbeat have only ever meant the loop is
     * turning.
     *
     * So two narrower clocks are read instead, each written by one thing:
     *
     *  - `looped_at` — a poll. Fresh means the worker is BETWEEN jobs, because
     *    the daemon does not poll while it is running one. This is what keeps a
     *    forty-minute mux out of the alarm: that worker's `looped_at` is as old
     *    as the job, so it reads as busy, correctly.
     *  - `last_job_at` — a job start. Old means nothing has been taken.
     *
     * Fresh poll + old job start + work waiting is the observation. It is a
     * pure read of one snapshot: no page needs to remember a previous reading,
     * which is what made this state impossible to express before.
     *
     * **EVERY live worker, not any.** Four `assets` workers with one wedged is a
     * queue that is draining, and calling that stopped would put a finding
     * nobody can act on in the loudest category on the page.
     *
     * **An entry with no clocks is UNKNOWN, and unknown is not an alarm here.**
     * A worker that booted before this signal existed cannot answer, and
     * reporting it as taking nothing would be inventing a reading — the
     * over-report failure this project treats as retiring the detector. It is
     * not absence read as agreement either: such a worker booted on code that
     * no longer matches disk, so it is already STALE, which is checked first
     * and is louder.
     *
     * @param  array<int, array<string, mixed>>  $live
     */
    private static function takingNothing(array $live, ?int $pending): bool
    {
        if (($pending ?? 0) <= 0) {
            return false;
        }

        $now = microtime(true);
        $pollFresh = (int) config('render.workers.poll_fresh_seconds', 60);
        $jobIdle = (int) config('render.workers.job_idle_seconds', 120);

        foreach ($live as $entry) {
            $looped = $entry['looped_at'] ?? null;

            // Cannot answer, so it is not answered. See above.
            if (! is_numeric($looped)) {
                return false;
            }

            // Not polling: inside a job, which is a worker working.
            if (($now - (float) $looped) > $pollFresh) {
                return false;
            }

            /*
             * Never started a job, so the age is measured from boot. A worker
             * up for four seconds on a full queue has not failed at anything
             * yet; one up for an hour that has taken nothing is the state.
             */
            $since = $entry['last_job_at'] ?? $entry['booted_at'] ?? null;

            if (! is_numeric($since) || ($now - (float) $since) <= $jobIdle) {
                return false;
            }
        }

        return $live !== [];
    }

    /**
     * How long since the most recent job start across these workers, as a
     * phrase — or since boot, for a worker that has never started one.
     *
     * The MOST RECENT, so a queue with several workers reports the shortest
     * gap rather than the worst. Overstating it would be the loudest reading
     * available rather than the true one.
     *
     * @param  array<int, array<string, mixed>>  $live
     */
    private static function sinceLastJob(array $live): string
    {
        $stamps = [];

        foreach ($live as $entry) {
            $since = $entry['last_job_at'] ?? $entry['booted_at'] ?? null;

            if (is_numeric($since)) {
                $stamps[] = (float) $since;
            }
        }

        if ($stamps === []) {
            return 'for an unknown time';
        }

        $seconds = max(0, (int) round(microtime(true) - max($stamps)));

        return 'in '.CarbonInterval::seconds($seconds)->cascade()->forHumans(short: true, parts: 2);
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
