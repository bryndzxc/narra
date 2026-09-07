<?php

namespace Tests\Feature;

use App\Support\RunFingerprint;
use App\Support\WorkerHealth;
use App\Support\WorkerRegistry;
use App\Support\WorkerServices;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

/**
 * A worker that is listening and taking nothing, and the panel that could not
 * say so.
 *
 * ---------------------------------------------------------------------------
 * THE INSTANCE, AND WHAT IS AND IS NOT KNOWN ABOUT IT
 * ---------------------------------------------------------------------------
 *
 * Story 23's asset batch: 550 jobs on `assets`, a live worker with a fresh
 * heartbeat, a code marker matching disk, a stable pid and 5h54m of uptime
 * against a 9h `--max-time` window, consuming exactly zero of them for about
 * twenty minutes. `WorkerHealth` said, in its own words:
 *
 *     1 worker(s) on "assets" agree with this process, working through 550
 *     queued job(s).
 *
 * Every clause true, the sentence as a whole false. A service restart drained
 * the queue. **Why it had stopped consuming was never established** and the
 * evidence went with the process.
 *
 * ---------------------------------------------------------------------------
 * WHY THE PANEL WAS STRUCTURALLY BLIND TO IT
 * ---------------------------------------------------------------------------
 *
 * `AppServiceProvider::announceWorker()` heartbeat on `Looping`, which Laravel
 * dispatches on EVERY poll of the queue, including the empty ones. So `live`,
 * `state = ok` and a fresh heartbeat all mean one thing — the loop is turning —
 * and none of them has ever meant work is being consumed. The panel's central
 * signal could not tell working from idling with a full queue.
 *
 * The registry records the poll and the job start as two separate clocks now,
 * so the question is a pure read of one snapshot: no page has to remember a
 * previous reading, which is what made the state impossible to express before.
 *
 * ---------------------------------------------------------------------------
 * EVERY CASE HERE IS A PAIR
 * ---------------------------------------------------------------------------
 *
 * A rule that reports everything satisfies the red half, and the loudest
 * category on this page is the one that stops being read if it fills with
 * findings nobody can act on. So each red case has a green one written as close
 * to it as it can be made — and the nastiest green is a worker forty minutes
 * into a mux, which has an old `last_job_at` and a full queue and must never be
 * called stopped.
 */
class WorkerTakingNothingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['text', 'assets', 'render'] as $queue) {
            WorkerRegistry::flush($queue);
        }

        WorkerHealth::forget();

        // The suite runs on `sync`, where WorkerHealth short-circuits to INLINE
        // — correctly, since the dispatcher IS the worker there. This file is
        // about the driver that has a separate worker.
        config(['queue.default' => 'redis']);
    }

    // ---------------------------------------------------------------------
    // The state itself
    // ---------------------------------------------------------------------

    /**
     * RED. The shape story 23 took: polling, work waiting, nothing taken.
     */
    public function test_a_worker_polling_a_full_queue_and_taking_nothing_is_reported(): void
    {
        $this->queueDepthIs(550);
        $this->workerOnAssets(lastLoopAgo: 2, lastJobAgo: 1_200);

        $health = WorkerHealth::forQueue('assets');

        $this->assertSame(WorkerHealth::NOT_CONSUMING, $health['state']);
        $this->assertStringContainsString('are polling and have taken nothing off it', $health['headline']);
        $this->assertStringContainsString('550 job(s) waiting', $health['headline']);
    }

    /**
     * GREEN, and as close to the red case as it can be made: the same worker,
     * the same depth, one job taken ten seconds ago.
     *
     * A queue being deep is not an alarm. A live worker with 416 jobs behind it
     * is a worker working, and this file must not quietly turn that into a
     * failure.
     */
    public function test_a_worker_working_through_a_backlog_is_not_reported(): void
    {
        $this->queueDepthIs(550);
        $this->workerOnAssets(lastLoopAgo: 2, lastJobAgo: 10);

        $health = WorkerHealth::forQueue('assets');

        $this->assertSame(WorkerHealth::OK, $health['state']);
        $this->assertStringContainsString('working through 550 queued job(s)', $health['headline']);
    }

    /**
     * GREEN, and the one that matters most.
     *
     * A worker forty minutes into a mux has an old `last_job_at` — the job
     * started forty minutes ago — and a queue that may well be deep. On
     * `last_job_at` alone it is indistinguishable from the red case above, and
     * calling it stopped would put a red box on the screen every time this
     * pipeline did the longest thing it does.
     *
     * What separates them is `looped_at`. `Looping` does not fire while a job
     * runs, so a busy worker has an OLD poll and a not-consuming one has a
     * fresh one. That is the whole discrimination, and it is why the beat from
     * inside a long job is deliberately not marked as a poll.
     */
    public function test_a_worker_inside_a_long_job_is_not_reported(): void
    {
        $this->queueDepthIs(120);

        // Last polled when it picked the job up, 40 minutes ago. Still alive:
        // `RenderJob::heartbeat()` keeps `seen_at` fresh from inside the job.
        $this->workerOn('render', lastLoopAgo: 2_400, lastJobAgo: 2_400, seenAgo: 3);

        $health = WorkerHealth::forQueue('render');

        $this->assertSame(WorkerHealth::OK, $health['state'], 'A worker mid-mux is being called stopped.');
        $this->assertSame(1, $health['live']);
    }

    /**
     * GREEN. Nothing waiting is nothing to take.
     *
     * An idle worker on an empty queue is the ordinary resting state of this
     * machine, and it looks identical to the red case on every reading except
     * the depth.
     */
    public function test_an_idle_worker_on_an_empty_queue_is_not_reported(): void
    {
        $this->queueDepthIs(0);
        $this->workerOnAssets(lastLoopAgo: 2, lastJobAgo: 90_000);

        $this->assertSame(WorkerHealth::OK, WorkerHealth::forQueue('assets')['state']);
    }

    /**
     * RED. A worker that has never taken a job measures from boot.
     *
     * This is the story 23 shape as it would look after a restart that did not
     * help: up for an hour, polling, 550 jobs in front of it, `last_job_at`
     * still null. Falling back to boot is what keeps that visible; without it a
     * worker that has never consumed anything reports as unknown for ever.
     */
    public function test_a_worker_that_has_never_taken_a_job_measures_from_boot(): void
    {
        $this->queueDepthIs(550);
        $this->workerOnAssets(lastLoopAgo: 2, lastJobAgo: null, bootedAgo: 3_600);

        $this->assertSame(WorkerHealth::NOT_CONSUMING, WorkerHealth::forQueue('assets')['state']);
    }

    /**
     * GREEN, paired with the case above. A worker that booted four seconds ago
     * has not failed at anything yet.
     */
    public function test_a_worker_that_has_only_just_booted_is_not_reported(): void
    {
        $this->queueDepthIs(550);
        $this->workerOnAssets(lastLoopAgo: 1, lastJobAgo: null, bootedAgo: 4);

        $this->assertSame(WorkerHealth::OK, WorkerHealth::forQueue('assets')['state']);
    }

    /**
     * GREEN. Four workers with one wedged is a queue that is draining.
     *
     * The predicate asks about EVERY live worker rather than any, because a
     * queue that is still moving is not a stopped pipeline — and a red box on a
     * queue that is visibly emptying is the over-report that retires a
     * detector.
     */
    public function test_a_queue_with_one_stuck_worker_and_one_working_is_not_reported(): void
    {
        $this->queueDepthIs(550);

        $now = microtime(true);
        $this->writeEntries('assets', [
            $this->entry(pid: 111, now: $now, lastLoopAgo: 2, lastJobAgo: 1_200),
            $this->entry(pid: 222, now: $now, lastLoopAgo: 2, lastJobAgo: 8),
        ]);

        $this->assertSame(WorkerHealth::OK, WorkerHealth::forQueue('assets')['state']);
    }

    /**
     * RED, paired with the case above: every worker on the queue taking
     * nothing.
     */
    public function test_a_queue_where_every_worker_is_taking_nothing_is_reported(): void
    {
        $this->queueDepthIs(550);

        $now = microtime(true);
        $this->writeEntries('assets', [
            $this->entry(pid: 111, now: $now, lastLoopAgo: 2, lastJobAgo: 1_200),
            $this->entry(pid: 222, now: $now, lastLoopAgo: 4, lastJobAgo: 900),
        ]);

        $this->assertSame(WorkerHealth::NOT_CONSUMING, WorkerHealth::forQueue('assets')['state']);
    }

    /**
     * GREEN. A worker that cannot answer is not accused.
     *
     * An entry written before this signal existed has neither clock. Reporting
     * it as taking nothing would be inventing a reading, and inventing readings
     * in the loudest category is how a category stops being read.
     *
     * It is not absence read as agreement either, and the reason is worth
     * pinning: such a worker booted on code that no longer matches disk, so it
     * is already STALE — which is checked first and is louder. The unknown
     * window is the length of one worker restart.
     */
    public function test_a_worker_with_no_clocks_is_not_accused(): void
    {
        $this->queueDepthIs(550);

        $now = microtime(true);
        $entry = $this->entry(pid: 111, now: $now, lastLoopAgo: 2, lastJobAgo: 1_200);
        unset($entry['looped_at'], $entry['last_job_at']);

        $this->writeEntries('assets', [$entry]);

        $this->assertSame(WorkerHealth::OK, WorkerHealth::forQueue('assets')['state']);
    }

    // ---------------------------------------------------------------------
    // The signal underneath it
    // ---------------------------------------------------------------------

    /**
     * The registry records the two moments separately, and only the poll path
     * marks a poll.
     *
     * This is the defect stated as an assertion. `Looping` fires on the empty
     * polls, so a beat alone can never mean work was consumed; and the beat
     * from inside a long job must not claim to be a poll, or the mux case above
     * would report as stopped.
     */
    public function test_the_registry_separates_a_poll_from_a_job_start(): void
    {
        $print = RunFingerprint::shared();

        WorkerRegistry::heartbeat('assets', $print, force: true, polling: true);
        $polled = $this->entriesFor('assets')[(string) getmypid()];

        $this->assertNotNull($polled['looped_at'], 'A poll did not record looped_at.');
        $this->assertNull($polled['last_job_at'], 'A poll must not look like a job start.');

        WorkerRegistry::jobStarted('assets', $print);
        $took = $this->entriesFor('assets')[(string) getmypid()];

        $this->assertNotNull($took['last_job_at'], 'A job start did not record last_job_at.');
    }

    /**
     * And a beat from INSIDE a running job does not mark a poll.
     *
     * `RenderJob::heartbeat()` reaches the registry through
     * `touchAnnounced()`, which exists so a long job does not age its own
     * worker out. If that beat set `looped_at` the mux case would read as
     * between-jobs and this whole state would fire on the longest thing the
     * pipeline does.
     */
    public function test_a_beat_from_inside_a_job_does_not_claim_to_be_a_poll(): void
    {
        $print = RunFingerprint::shared();

        WorkerRegistry::heartbeat('render', $print, force: true, polling: true);
        $before = $this->entriesFor('render')[(string) getmypid()]['looped_at'];

        // What touchAnnounced() does: a plain beat, no polling flag.
        WorkerRegistry::heartbeat('render', $print, force: true);
        $after = $this->entriesFor('render')[(string) getmypid()]['looped_at'];

        $this->assertSame($before, $after, 'An in-job beat moved the poll clock.');
    }

    // ---------------------------------------------------------------------
    // The restart command
    // ---------------------------------------------------------------------

    /**
     * RED, in the sense that matters here: the console must not print a command
     * that cannot run.
     *
     * `nssm` is not on PATH on this machine. The installer knows — it resolves
     * through `Get-Command`, a vendored copy under `tools`, and a download —
     * and the panel did neither, printing a bare `nssm start NarraText` that
     * fails with a command-not-found at the moment somebody is trying to
     * unstick a stopped pipeline.
     */
    public function test_no_console_page_offers_a_bare_nssm_command(): void
    {
        $this->queueDepthIs(152);

        $html = $this->get(route('renders.index'))->getContent();

        $this->assertStringNotContainsString('nssm start', $html);
        $this->assertStringContainsString('Restart-Service Narra', $html);
    }

    /**
     * GREEN half of the same question: the fix is still THERE, per queue, and
     * pasteable. A command withheld would satisfy the assertion above perfectly
     * and be a worse page.
     */
    public function test_every_troubled_queue_still_names_its_own_command(): void
    {
        $this->queueDepthIs(152);

        $html = $this->get(route('renders.index'))->getContent();

        foreach (['NarraText', 'NarraAssets', 'NarraRender'] as $service) {
            $this->assertStringContainsString('Restart-Service '.$service, $html);
        }
    }

    /**
     * A queue nothing is mapped to gets NO command.
     *
     * The fallback this replaces built a name out of the queue, which is right
     * for the three queues that exist — and that is exactly what made it the
     * worst option available. Rename a queue and the page prints a confident,
     * pasteable command naming a service that is not there. A plausible name
     * that happens to be right today is a guess wearing the clothes of a fact.
     */
    public function test_an_unmapped_queue_gets_no_command_rather_than_a_guess(): void
    {
        $this->assertSame('Restart-Service NarraAssets', WorkerServices::restartCommand('assets'));
        $this->assertNull(WorkerServices::restartCommand('transcode'));
        $this->assertNull(WorkerServices::forQueue('transcode'));
    }

    /**
     * The mapping follows a renamed queue rather than falling off it.
     *
     * Keyed by ROLE and resolved through config in one direction, because the
     * queue names are configurable and the service names are not.
     */
    public function test_the_mapping_follows_a_renamed_queue(): void
    {
        config(['render.queues.assets' => 'stills']);

        $this->assertSame('NarraAssets', WorkerServices::forQueue('stills'));
        $this->assertNull(WorkerServices::forQueue('assets'), 'The old name should no longer resolve.');
    }

    /**
     * And the COMPONENT withholds the command rather than printing nothing and
     * leaving a gap.
     *
     * Written at component level on purpose, and the reason is worth keeping
     * because the first version of this test was wrong in an instructive way.
     * It renamed `render.queues.assets` and expected the page to lose its
     * command — which is exactly backwards: the map is keyed by ROLE and
     * resolved through config, so a renamed queue KEEPS its service. That is
     * the behaviour `test_the_mapping_follows_a_renamed_queue` asks for.
     *
     * **So the null branch is not reachable from `WorkerHealth::all()` today**,
     * because that method builds its three rows from the three role keys and
     * nothing else can appear there. It is a defensive branch, and this file
     * says so rather than letting a green page-level assertion read as coverage
     * of a state the page cannot enter. What it defends against is a fourth
     * queue — the alternative on that day is a guessed service name, which is
     * the thing being removed.
     *
     * The gap matters as much as the guess. A blade that rendered an empty
     * `<code>` under an alarm would read as "nothing needed here", which is the
     * same defect as a tick box beside a blank field on the Gate 4 checklist.
     */
    public function test_an_unmapped_queue_is_told_there_is_no_command_not_shown_a_gap(): void
    {
        $rendered = (string) $this->blade(
            '<x-worker-restart queue="transcode" :command="null" />',
        );

        $this->assertStringContainsString('No worker service is mapped to', $rendered);
        $this->assertStringContainsString('transcode', $rendered);
        $this->assertStringNotContainsString('Restart-Service', $rendered);

        // And the mapped case still prints the command, so the assertion above
        // cannot be satisfied by a component that never offers one.
        $mapped = (string) $this->blade(
            '<x-worker-restart queue="assets" command="Restart-Service NarraAssets" />',
        );

        $this->assertStringContainsString('Restart-Service NarraAssets', $mapped);
        $this->assertStringNotContainsString('No worker service is mapped', $mapped);
    }

    // ---------------------------------------------------------------------
    // The page
    // ---------------------------------------------------------------------

    /**
     * The alarm reaches the page, and says plainly that the cause is unknown.
     *
     * That last clause is the point of the whole state. The reader is looking
     * at a table showing a live worker, a fresh heartbeat and a matching code
     * marker, so an alert that only shouted would be arguing with the row above
     * it. What it can honestly say is what was seen and what was never
     * established.
     */
    public function test_the_page_raises_the_alarm_and_does_not_name_a_cause(): void
    {
        $this->queueDepthIs(550);
        $this->workerOnAssets(lastLoopAgo: 2, lastJobAgo: 1_200);

        $html = $this->get(route('renders.index'))->getContent();

        $this->assertStringContainsString('is not moving', $html);
        $this->assertStringContainsString('taking nothing', $html);
        $this->assertStringContainsString('Restart-Service NarraAssets', $html);
        $this->assertStringContainsString('never found', $html);
    }

    // ---------------------------------------------------------------------
    // Fixture
    // ---------------------------------------------------------------------

    /**
     * One live worker on `assets` with the clocks set explicitly.
     *
     * Ages rather than timestamps, so a case reads as the state it describes
     * rather than as arithmetic.
     */
    private function workerOnAssets(int $lastLoopAgo, ?int $lastJobAgo, int $bootedAgo = 21_240): void
    {
        $this->workerOn('assets', $lastLoopAgo, $lastJobAgo, bootedAgo: $bootedAgo);
    }

    private function workerOn(
        string $queue,
        int $lastLoopAgo,
        ?int $lastJobAgo,
        int $bootedAgo = 21_240,
        int $seenAgo = 2,
    ): void {
        $now = microtime(true);

        $this->writeEntries($queue, [
            $this->entry(
                pid: 16492,
                now: $now,
                lastLoopAgo: $lastLoopAgo,
                lastJobAgo: $lastJobAgo,
                bootedAgo: $bootedAgo,
                seenAgo: $seenAgo,
            ),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function entry(
        int $pid,
        float $now,
        int $lastLoopAgo,
        ?int $lastJobAgo,
        int $bootedAgo = 21_240,
        int $seenAgo = 2,
    ): array {
        $print = RunFingerprint::shared();

        return [
            'pid' => $pid,
            'booted_at' => $now - $bootedAgo,
            'seen_at' => $now - $seenAgo,
            'looped_at' => $now - $lastLoopAgo,
            'last_job_at' => $lastJobAgo === null ? null : $now - $lastJobAgo,
            'fingerprint' => $print,
            'digest' => RunFingerprint::digest($print),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $entries
     */
    private function writeEntries(string $queue, array $entries): void
    {
        $map = [];

        foreach ($entries as $entry) {
            $map[(string) $entry['pid']] = $entry;
        }

        Cache::put('narra:workers:'.$queue, $map, 300);
        WorkerHealth::forget();
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function entriesFor(string $queue): array
    {
        return Cache::get('narra:workers:'.$queue) ?? [];
    }

    private function queueDepthIs(int $jobs): void
    {
        Queue::shouldReceive('connection')->andReturn(Mockery::mock(['size' => $jobs]));
    }
}
