<?php

namespace App\Console\Commands;

use App\Actions\AssertWorkersCurrent;
use App\Exceptions\DispatchRefusedException;
use App\Jobs\WorkerDrillJob;
use App\Models\RenderJob;
use App\Support\RunFingerprint;
use App\Support\StaleWorkerRestart;
use App\Support\WorkerRegistry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Prove the worker self-restart, deliberately, instead of assuming it.
 *
 * ---------------------------------------------------------------------------
 * WHY A DRILL AND NOT A TEST
 * ---------------------------------------------------------------------------
 *
 * `StaleWorkerRestartTest` covers the DECISION — given a stale marker and an
 * empty queue, does `consider()` set the flag. It cannot cover the MECHANISM,
 * because every part of that lives outside PHP: whether `Looping` fires often
 * enough, whether Laravel's own loop reads the cache flag on the database store
 * this app uses, whether NSSM treats a clean exit as a recycle rather than as
 * "the app is finished", and whether the worker that comes back has genuinely
 * re-read `app/`. A test mocks all four of those away.
 *
 * Same argument as the stale-heartbeat rehearsal in docs/queue-workers.md: an
 * alarm nobody can rehearse is not an alarm, and a recovery nobody has watched
 * is not a recovery.
 *
 * ---------------------------------------------------------------------------
 * THE THREE THINGS IT SHOWS
 * ---------------------------------------------------------------------------
 *
 *   idle   A worker goes stale, notices, exits, and comes back current with
 *          nobody touching anything. Prints the wall-clock cost of that.
 *
 *   busy   The same edit made while the queue holds work. The worker must NOT
 *          stand down: every job in the batch comes back with the same pid and
 *          the same sealed marker, so the batch demonstrably finished on the
 *          code it started with — and the restart happens afterwards, once the
 *          queue drains, rather than never.
 *
 *   check  What a FRESH dispatching process makes of the workers right now.
 *          Shelled out to by the other two, and the reason is rule 1: this
 *          command is a long-lived process whose code marker sealed at ITS
 *          boot, so seconds after the touch the watcher is stale too and would
 *          wave a stale worker through. Only a process started after the edit
 *          can answer the dispatcher's question.
 *
 * ---------------------------------------------------------------------------
 * WHAT THE WATCHER COMPARES AGAINST, AND WHY IT IS NOT WorkerHealth
 * ---------------------------------------------------------------------------
 *
 * `WorkerHealth` judges staleness against `RunFingerprint::shared()`, which is
 * sealed at the reading process's boot. That is right for a web request, which
 * is short-lived, and wrong here for exactly the reason above. So the poll loop
 * compares each worker's ANNOUNCED marker against `codeOnDiskNow()`, re-read
 * every poll, and labels the columns accordingly. The dispatcher's verdict
 * still comes from a fresh process, never from this one.
 *
 * Nothing here can bill. `WorkerDrillJob` resolves no provider and takes no
 * story, and the drill refuses to start while any queue holds work or any
 * render job is running.
 */
class WorkersDrill extends Command
{
    protected $signature = 'workers:drill
        {mode=idle : idle | busy | check}
        {--queue= : Which queue to occupy in busy mode. Defaults to the text queue.}
        {--jobs=8 : How many sleeping jobs to dispatch in busy mode.}
        {--seconds=5 : How long each of those jobs sleeps.}
        {--touch= : The file whose mtime is moved. Defaults to the mechanism\'s own file.}
        {--timeout=180 : Seconds to watch before giving up.}
        {--force : Run even though something is queued or a render job is running.}
        {--json : check mode only — machine-readable output.}';

    protected $description = 'Rehearse the stale-worker self-restart and watch it happen.';

    /** How often the watcher re-reads the registry. */
    private const POLL_SECONDS = 2;

    private float $t0;

    public function handle(): int
    {
        $this->t0 = microtime(true);

        return match ((string) $this->argument('mode')) {
            'check' => $this->check(),
            'idle' => $this->drillIdle(),
            'busy' => $this->drillBusy(),
            default => $this->badMode(),
        };
    }

    private function badMode(): int
    {
        $this->error('Mode must be idle, busy or check.');

        return self::FAILURE;
    }

    // -----------------------------------------------------------------------
    // check — the dispatcher's verdict, from a process that booted just now
    // -----------------------------------------------------------------------

    /**
     * What `AssertWorkersCurrent` says about a queue right now.
     *
     * The whole value of this mode is that it is a SEPARATE PROCESS. The guard
     * has to run somewhere with fresh code by construction, and the only way to
     * get that inside a drill which has been running for a minute is to start
     * another one.
     */
    private function check(): int
    {
        $queue = $this->queueName();

        $verdict = [
            'queue' => $queue,
            'refused' => false,
            'level' => 'ok',
            'message' => '',
            'code_marker' => RunFingerprint::code(),
        ];

        try {
            $notes = (new AssertWorkersCurrent)->handle($queue);
            $verdict['message'] = implode(' ', array_column($notes, 'message'));
            $verdict['level'] = $notes[0]['level'] ?? 'ok';
        } catch (DispatchRefusedException $e) {
            $verdict['refused'] = true;
            $verdict['level'] = 'refused';
            $verdict['message'] = $e->getMessage();
        }

        if ($this->option('json')) {
            $this->output->writeln((string) json_encode($verdict));

            return self::SUCCESS;
        }

        $this->line($verdict['refused'] ? '<fg=red>REFUSED</>' : '<fg=green>allowed</>');
        $this->line($verdict['message']);

        return self::SUCCESS;
    }

    // -----------------------------------------------------------------------
    // idle — the case this was built for: code edited while nothing is running
    // -----------------------------------------------------------------------

    private function drillIdle(): int
    {
        if (! $this->safeToStart()) {
            return self::FAILURE;
        }

        $queues = $this->allQueues();
        $before = $this->snapshot($queues);
        $wasOnDisk = RunFingerprint::codeOnDiskNow();

        $this->preamble('IDLE DRILL', [
            "A file's mtime is moved. Nothing is queued, so every worker should notice within one",
            'check interval, stand down through the same cache flag `queue:restart` sets, and be',
            'restarted by NSSM on current code — with nobody touching anything.',
        ]);

        $this->say('before', $before, $wasOnDisk);

        if ($this->pidsIn($before) === []) {
            $this->error('No workers are announcing themselves. Start the services first — there is nothing to restart.');

            return self::FAILURE;
        }

        $this->touchTheCode();
        $nowOnDisk = RunFingerprint::codeOnDiskNow();

        if ($nowOnDisk === $wasOnDisk) {
            $this->error('The code marker did not move, so this drill would prove nothing. The touch did not take.');

            return self::FAILURE;
        }

        $this->line(sprintf('  code on disk: <fg=yellow>%s</> -> <fg=yellow>%s</>', $wasOnDisk, $nowOnDisk));
        $this->newLine();

        $observed = $this->watch($queues, $nowOnDisk, $this->pidsIn($before));

        return $this->verdictIdle($observed, $nowOnDisk);
    }

    // -----------------------------------------------------------------------
    // busy — the bound that stops this costing money
    // -----------------------------------------------------------------------

    private function drillBusy(): int
    {
        if (! $this->safeToStart()) {
            return self::FAILURE;
        }

        $queue = $this->queueName();
        $jobs = max(2, (int) $this->option('jobs'));
        $seconds = max(1, (int) $this->option('seconds'));
        $runId = Str::random(8);

        $before = $this->snapshot([$queue]);
        $wasOnDisk = RunFingerprint::codeOnDiskNow();
        $pidsBefore = $this->pidsIn($before);

        $this->preamble('BUSY DRILL', [
            sprintf('%d sleeping jobs (%ds each, ~%ds of work) go onto the "%s" queue.', $jobs, $seconds, $jobs * $seconds, $queue),
            'The same edit is then made MID-RUN. The worker must not stand down while the queue',
            'holds anything: every job should come back with one pid and one sealed marker, and',
            'the restart should happen after the queue drains rather than never.',
            '',
            'These jobs resolve no provider and take no story. Nothing here can bill.',
        ]);

        $this->say('before', $before, $wasOnDisk);

        if ($pidsBefore === []) {
            $this->error(sprintf('Nothing is listening on "%s". Start the worker first.', $queue));

            return self::FAILURE;
        }

        for ($i = 1; $i <= $jobs; $i++) {
            Cache::forget(WorkerDrillJob::key($runId, $i));
            WorkerDrillJob::dispatch($runId, $i, $seconds)->onQueue($queue);
        }

        $this->stamp(sprintf('dispatched %d jobs to "%s" (run %s)', $jobs, $queue, $runId));

        // Wait for the batch to actually be underway before editing anything.
        // Touching while the queue is still cold would be the idle drill with
        // extra steps, and would prove the opposite of what is wanted.
        if (! $this->waitForFirstJob($runId, $jobs)) {
            $this->error('No drill job started. Is the worker on that queue actually running?');

            return self::FAILURE;
        }

        $this->touchTheCode();
        $nowOnDisk = RunFingerprint::codeOnDiskNow();
        $this->line(sprintf('  code on disk: <fg=yellow>%s</> -> <fg=yellow>%s</>', $wasOnDisk, $nowOnDisk));
        $this->newLine();

        $violations = [];
        $depthSeen = [];
        $refusalSeen = null;
        $restartedAt = null;
        $done = 0;
        $deadline = microtime(true) + (int) $this->option('timeout');

        while (microtime(true) < $deadline) {
            $snap = $this->snapshot([$queue], $nowOnDisk);
            $row = $snap[$queue];
            $depth = $row['pending'];
            $pids = $row['pids'];
            $done = $this->drillJobsDone($runId, $jobs);

            $depthSeen[] = $depth;

            // THE ASSERTION. A pid that changes while the queue still holds
            // work is the self-restart interrupting a batch, which is the one
            // outcome that would make this feature not worth having.
            if (($depth ?? 0) > 0 && $pids !== [] && $pids !== $pidsBefore) {
                $violations[] = sprintf(
                    't+%.1fs the worker pid changed to %s while %d job(s) were still queued',
                    microtime(true) - $this->t0,
                    implode(',', $pids),
                    $depth,
                );
            }

            // Claim 3, from the dispatcher's side, taken once while the worker
            // is alive and superseded. Shelled out, because this process is
            // stale too by now.
            if ($refusalSeen === null && $pids === $pidsBefore && $row['superseded'] > 0) {
                $refusalSeen = $this->dispatcherVerdict($queue);
                $this->stamp(sprintf(
                    'fresh dispatcher asked about "%s" while the worker is alive and superseded: <fg=%s>%s</>',
                    $queue,
                    ($refusalSeen['refused'] ?? false) ? 'red' : 'yellow',
                    ($refusalSeen['refused'] ?? false) ? 'REFUSED' : 'allowed',
                ));
            }

            $this->say(sprintf('%d/%d done', $done, $jobs), $snap, $nowOnDisk, ['depth' => $depth]);

            if ($restartedAt === null && $pids !== [] && $pids !== $pidsBefore) {
                $restartedAt = microtime(true) - $this->t0;
            }

            if ($done >= $jobs && $restartedAt !== null) {
                break;
            }

            sleep(self::POLL_SECONDS);
        }

        return $this->verdictBusy($runId, $jobs, $wasOnDisk, $violations, $depthSeen, $refusalSeen, $restartedAt);
    }

    // -----------------------------------------------------------------------
    // Watching
    // -----------------------------------------------------------------------

    /**
     * Poll until the workers have gone superseded, exited, and come back.
     *
     * @param  array<int, string>  $queues
     * @param  array<int, int>  $pidsBefore
     * @return array<string, mixed>
     */
    private function watch(array $queues, string $onDisk, array $pidsBefore): array
    {
        $deadline = microtime(true) + (int) $this->option('timeout');

        $observed = [
            'superseded_at' => null,
            'announced_while_superseded' => [],
            'gone_at' => null,
            'back_at' => null,
            'current_at' => null,
            'all_back_at' => null,
            'pids_after' => [],
            'refusal' => null,
        ];

        while (microtime(true) < $deadline) {
            $snap = $this->snapshot($queues, $onDisk);
            $pids = $this->pidsIn($snap);
            $superseded = array_sum(array_column($snap, 'superseded'));
            $current = array_sum(array_column($snap, 'current'));
            $elapsed = microtime(true) - $this->t0;

            if ($superseded > 0 && $observed['superseded_at'] === null) {
                $observed['superseded_at'] = $elapsed;

                // Claim 3: what does a live superseded worker ANNOUNCE?
                foreach ($snap as $row) {
                    foreach ($row['announces'] as $marker) {
                        $observed['announced_while_superseded'][$marker] = true;
                    }
                }

                $observed['refusal'] = $this->dispatcherVerdict($queues[0]);
                $this->stamp(sprintf(
                    'fresh dispatcher asked about "%s" while the workers are alive and superseded: <fg=%s>%s</>',
                    $queues[0],
                    ($observed['refusal']['refused'] ?? false) ? 'red' : 'yellow',
                    ($observed['refusal']['refused'] ?? false) ? 'REFUSED' : 'allowed',
                ));
            }

            if ($pids === [] && $observed['gone_at'] === null && $observed['superseded_at'] !== null) {
                $observed['gone_at'] = $elapsed;
            }

            $fresh = array_values(array_diff($pids, $pidsBefore));

            if ($fresh !== [] && $observed['back_at'] === null) {
                $observed['back_at'] = $elapsed;
            }

            if ($fresh !== [] && $superseded === 0 && $current === count($pids) && $observed['current_at'] === null) {
                $observed['current_at'] = $elapsed;
            }

            /*
             * Recovery is when they are ALL back, not when the first one is.
             *
             * Reporting the first worker's return as "recovery took 10.6s"
             * while two queues were still empty would be a small, tidy instance
             * of the thing this whole drill exists to catch: the optimistic
             * number, correct in isolation, standing in for the one that was
             * asked for.
             */
            if (
                $observed['all_back_at'] === null
                && count($pids) >= count($pidsBefore)
                && $superseded === 0
                && $current === count($pids)
                && $pids !== []
            ) {
                $observed['all_back_at'] = $elapsed;
                $observed['pids_after'] = $pids;
            }

            $this->say('watch', $snap, $onDisk);

            if ($observed['all_back_at'] !== null) {
                break;
            }

            sleep(self::POLL_SECONDS);
        }

        return $observed;
    }

    // -----------------------------------------------------------------------
    // Verdicts
    // -----------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $observed
     */
    private function verdictIdle(array $observed, string $nowOnDisk): int
    {
        $this->newLine();
        $this->line('<options=bold>VERDICT</>');

        $announced = array_keys($observed['announced_while_superseded']);

        $checks = [
            [
                'A live worker noticed the code had moved',
                $observed['superseded_at'] !== null,
                $observed['superseded_at'] === null
                    ? 'no worker ever read as superseded'
                    : sprintf('t+%.1fs', $observed['superseded_at']),
            ],
            [
                'While superseded and ALIVE it announced the marker it BOOTED with',
                $announced !== [] && ! in_array($nowOnDisk, $announced, true),
                $announced === []
                    ? 'nothing announced'
                    : 'announced '.implode(', ', $announced).' — on disk: '.$nowOnDisk,
            ],
            [
                'A fresh dispatcher refused to queue work into it',
                (bool) ($observed['refusal']['refused'] ?? false),
                ($observed['refusal']['refused'] ?? false)
                    ? 'AssertWorkersCurrent threw'
                    : 'no refusal seen: '.(string) ($observed['refusal']['message'] ?? '—'),
            ],
            [
                'It exited on its own',
                $observed['gone_at'] !== null || $observed['back_at'] !== null,
                $observed['gone_at'] !== null
                    ? sprintf('registry emptied at t+%.1fs', $observed['gone_at'])
                    : 'not seen empty between polls — inferred from the new pid',
            ],
            [
                'A supervisor brought it back',
                $observed['back_at'] !== null,
                $observed['back_at'] === null
                    ? 'no new pid appeared'
                    : sprintf('t+%.1fs', $observed['back_at']),
            ],
            [
                'The worker that came back announces the CURRENT marker',
                $observed['current_at'] !== null,
                $observed['current_at'] === null
                    ? 'never read as current'
                    : sprintf('first one current at t+%.1fs', $observed['current_at']),
            ],
            [
                'EVERY queue is listened to again by a current worker',
                $observed['all_back_at'] !== null,
                $observed['all_back_at'] === null
                    ? 'at least one queue was still unattended when the watch ended'
                    : sprintf('t+%.1fs, pids %s', $observed['all_back_at'], implode(',', $observed['pids_after'])),
            ],
            [
                'A breadcrumb explains the restart nobody ordered',
                StaleWorkerRestart::lastSelfRestart() !== null,
                StaleWorkerRestart::lastSelfRestart() === null
                    ? 'no self-restart recorded'
                    : 'StaleWorkerRestart::lastSelfRestart() is set',
            ],
        ];

        return $this->report($checks, $observed['all_back_at'] === null ? null : sprintf(
            'Unattended recovery took %.1f seconds — every queue listened to again, on current code.',
            $observed['all_back_at'],
        ));
    }

    /**
     * @param  array<int, string>  $violations
     * @param  array<int, ?int>  $depthSeen
     * @param  array<string, mixed>|null  $refusal
     */
    private function verdictBusy(
        string $runId,
        int $jobs,
        string $wasOnDisk,
        array $violations,
        array $depthSeen,
        ?array $refusal,
        ?float $restartedAt,
    ): int {
        $ran = $this->drillJobs($runId, $jobs);
        $pids = array_values(array_unique(array_column($ran, 'pid')));
        $markers = array_values(array_unique(array_column($ran, 'code')));
        $busyPolls = count(array_filter($depthSeen, static fn (?int $d): bool => ($d ?? 0) > 0));

        $this->newLine();
        $this->line('<options=bold>VERDICT</>');

        $checks = [
            [
                'Every dispatched job ran',
                count($ran) === $jobs,
                sprintf('%d of %d', count($ran), $jobs),
            ],
            [
                'The queue was observed holding work AFTER the edit',
                $busyPolls > 0,
                sprintf('%d of %d polls saw a non-empty queue', $busyPolls, count($depthSeen)),
            ],
            [
                'The worker did NOT stand down while the queue held work',
                $violations === [],
                $violations === []
                    ? 'no pid change while anything was queued'
                    : implode('; ', $violations),
            ],
            [
                'The whole batch ran in one process',
                count($pids) === 1,
                'pids: '.implode(', ', $pids),
            ],
            [
                'The whole batch ran on ONE code version — the one it started with',
                count($markers) === 1 && in_array($wasOnDisk, $markers, true),
                sprintf('markers: %s (queue started on %s)', implode(', ', $markers), $wasOnDisk),
            ],
            [
                'A fresh dispatcher refused new work into it in the meantime',
                (bool) ($refusal['refused'] ?? false),
                ($refusal['refused'] ?? false)
                    ? 'AssertWorkersCurrent threw'
                    : 'no refusal seen: '.(string) ($refusal['message'] ?? '—'),
            ],
            [
                'The restart happened AFTER the queue drained, not never',
                $restartedAt !== null,
                $restartedAt === null
                    ? 'no restart within the watch window'
                    : sprintf('t+%.1fs', $restartedAt),
            ],
        ];

        return $this->report($checks);
    }

    /**
     * @param  array<int, array{0: string, 1: bool, 2: string}>  $checks
     */
    private function report(array $checks, ?string $footer = null): int
    {
        $failed = 0;

        foreach ($checks as [$label, $pass, $detail]) {
            if (! $pass) {
                $failed++;
            }

            $this->line(sprintf('  %s %s', $pass ? '<fg=green>PASS</>' : '<fg=red>FAIL</>', $label));
            $this->line(sprintf('       <fg=gray>%s</>', $detail));
        }

        $this->newLine();

        if ($footer !== null) {
            $this->line('  '.$footer);
            $this->newLine();
        }

        if ($failed > 0) {
            $this->error(sprintf('%d check(s) failed. The self-restart is not doing what it says.', $failed));

            return self::FAILURE;
        }

        $this->info('All checks passed.');

        return self::SUCCESS;
    }

    // -----------------------------------------------------------------------
    // Reading the world
    // -----------------------------------------------------------------------

    /**
     * The registry, judged against the code that is on disk RIGHT NOW.
     *
     * Deliberately not `WorkerHealth`, which judges against this process's own
     * sealed marker and would therefore call a superseded worker current the
     * moment the watcher itself went stale.
     *
     * @param  array<int, string>  $queues
     * @return array<string, array{queue: string, pids: array<int, int>, announces: array<int, string>, current: int, superseded: int, pending: ?int}>
     */
    private function snapshot(array $queues, ?string $onDisk = null): array
    {
        $onDisk ??= RunFingerprint::codeOnDiskNow();
        $out = [];

        foreach ($queues as $queue) {
            $live = WorkerRegistry::live($queue);
            $announces = [];
            $current = 0;
            $superseded = 0;

            foreach ($live as $entry) {
                $marker = (string) ($entry['fingerprint']['code'] ?? '?');
                $announces[$marker] = true;

                if ($marker === $onDisk) {
                    $current++;
                } else {
                    $superseded++;
                }
            }

            $out[$queue] = [
                'queue' => $queue,
                'pids' => array_map(static fn (array $e): int => (int) $e['pid'], $live),
                'announces' => array_keys($announces),
                'current' => $current,
                'superseded' => $superseded,
                'pending' => $this->depth($queue),
            ];
        }

        return $out;
    }

    private function depth(string $queue): ?int
    {
        try {
            return (int) Queue::connection()->size($queue);
        } catch (Throwable) {
            // Unreadable is unreadable, never zero.
            return null;
        }
    }

    /**
     * Ask a process that booted just now what it makes of the workers.
     *
     * @return array<string, mixed>
     */
    private function dispatcherVerdict(string $queue): array
    {
        try {
            $process = new Process(
                [PHP_BINARY, 'artisan', 'workers:drill', 'check', '--queue='.$queue, '--json'],
                base_path(),
                null,
                null,
                60,
            );

            $process->run();

            $decoded = json_decode(trim($process->getOutput()), true);

            return is_array($decoded)
                ? $decoded
                : ['refused' => false, 'message' => trim($process->getOutput().' '.$process->getErrorOutput())];
        } catch (Throwable $e) {
            return ['refused' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function drillJobs(string $runId, int $jobs): array
    {
        $out = [];

        for ($i = 1; $i <= $jobs; $i++) {
            $row = Cache::get(WorkerDrillJob::key($runId, $i));

            if (is_array($row)) {
                $out[] = $row;
            }
        }

        return $out;
    }

    private function drillJobsDone(string $runId, int $jobs): int
    {
        return count($this->drillJobs($runId, $jobs));
    }

    private function waitForFirstJob(string $runId, int $jobs): bool
    {
        $deadline = microtime(true) + 90;

        while (microtime(true) < $deadline) {
            if ($this->drillJobsDone($runId, $jobs) > 0) {
                $this->stamp('first drill job has run — the batch is underway');

                return true;
            }

            usleep(500_000);
        }

        return false;
    }

    // -----------------------------------------------------------------------
    // Doing the deliberate thing
    // -----------------------------------------------------------------------

    /**
     * Move a file's mtime, which is half of what the code marker is built from.
     *
     * mtime only. The file's CONTENT is not touched, so there is nothing to
     * revert and `git status` is unchanged — the drill leaves no diff behind.
     * The default target is the mechanism's own source file, which makes an
     * accidental touch unambiguous rather than a mystery edit in a random class.
     */
    private function touchTheCode(): void
    {
        $path = (string) ($this->option('touch') ?: app_path('Support/StaleWorkerRestart.php'));

        if (! is_file($path)) {
            $path = base_path($path);
        }

        touch($path, time());
        clearstatcache(true, $path);

        $this->stamp(sprintf(
            '<fg=yellow>TOUCH</> %s (mtime only — no content change)',
            str_replace(base_path().DIRECTORY_SEPARATOR, '', $path),
        ));
    }

    // -----------------------------------------------------------------------
    // Guards and output
    // -----------------------------------------------------------------------

    /**
     * Never rehearse on top of real work.
     *
     * A drill that dispatched sleeping jobs in front of a 270-scene asset run,
     * or that restarted the workers during a mux, would be a self-inflicted
     * version of the failure it exists to prevent.
     */
    private function safeToStart(): bool
    {
        if ($this->option('force')) {
            return true;
        }

        $busy = [];

        foreach ($this->allQueues() as $queue) {
            $depth = $this->depth($queue);

            if ($depth === null) {
                $busy[] = sprintf('"%s" depth is unreadable — that is a failed check, not a passed one', $queue);
            } elseif ($depth > 0) {
                $busy[] = sprintf('"%s" is holding %d job(s)', $queue, $depth);
            }
        }

        if (RenderJob::where('status', 'running')->exists()) {
            $busy[] = 'a render job is running';
        }

        if ($busy === []) {
            return true;
        }

        $this->error('Not starting a drill while there is real work about:');

        foreach ($busy as $line) {
            $this->line('  - '.$line);
        }

        $this->line('Wait for it, or pass --force if you know what you are doing.');

        return false;
    }

    /** @return array<int, string> */
    private function allQueues(): array
    {
        return [
            (string) config('render.queues.text'),
            (string) config('render.queues.assets'),
            (string) config('render.queues.render'),
        ];
    }

    private function queueName(): string
    {
        return (string) ($this->option('queue') ?: config('render.queues.text'));
    }

    /**
     * @param  array<string, array<string, mixed>>  $snapshot
     * @return array<int, int>
     */
    private function pidsIn(array $snapshot): array
    {
        $pids = [];

        foreach ($snapshot as $row) {
            foreach ($row['pids'] as $pid) {
                $pids[] = $pid;
            }
        }

        sort($pids);

        return $pids;
    }

    /**
     * @param  array<int, string>  $lines
     */
    private function preamble(string $title, array $lines): void
    {
        $this->newLine();
        $this->line('<options=bold>'.$title.'</>');

        foreach ($lines as $line) {
            $this->line('<fg=gray>'.$line.'</>');
        }

        $this->newLine();
        $this->line('<fg=gray>Staleness below is judged against the code ON DISK, re-read every poll — not against</>');
        $this->line('<fg=gray>this process, which sealed its own marker at boot and goes stale along with everything else.</>');
        $this->newLine();
    }

    /**
     * @param  array<string, array<string, mixed>>  $snapshot
     * @param  array<string, mixed>  $extra
     */
    private function say(string $label, array $snapshot, string $onDisk, array $extra = []): void
    {
        $parts = [];

        foreach ($snapshot as $row) {
            $parts[] = sprintf(
                '%-7s %-9s pid %-16s %s',
                $row['queue'],
                $row['pids'] === []
                    ? '<fg=red>gone</>'
                    : ($row['superseded'] > 0 ? '<fg=yellow>superseded</>' : '<fg=green>current</>'),
                $row['pids'] === [] ? '-' : implode(',', $row['pids']),
                $row['announces'] === [] ? '' : implode('/', $row['announces']),
            );
        }

        $this->line(sprintf(
            '  t+%6.1fs  %-11s %s%s',
            microtime(true) - $this->t0,
            $label,
            implode('  |  ', $parts),
            array_key_exists('depth', $extra) ? '  queued='.($extra['depth'] ?? '?') : '',
        ));
    }

    private function stamp(string $message): void
    {
        $this->line(sprintf('  t+%6.1fs  %s', microtime(true) - $this->t0, $message));
    }
}
