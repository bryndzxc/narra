<?php

namespace Tests\Feature;

use App\Support\RunFingerprint;
use App\Support\StaleWorkerRestart;
use App\Support\WorkerRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Mockery;
use ReflectionClass;
use Tests\TestCase;

/**
 * A worker that has gone stale takes itself out, and the ways that could go
 * wrong.
 *
 * The dispatch-time refusal is not what changed here and is not what these
 * test. `AssertWorkersCurrent` still refuses a spend into stale workers, in the
 * dispatching process, as loudly as before. What changed is that the machine no
 * longer sits in the refused state until somebody restarts three services by
 * hand — which mattered because the panel that went red is the one read before
 * authorising a spend, and a red meaning "somebody saved a file" is
 * indistinguishable from a red meaning "your pipeline has stopped".
 *
 * Each test below names the way this could have been a bad idea.
 */
class StaleWorkerRestartTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['render.workers.restart_when_stale' => true]);
        Cache::forget('illuminate:queue:restart');
        $this->resetThrottle();
    }

    /**
     * THE ONE THAT MATTERS.
     *
     * `RunFingerprint` seals its code marker at boot and explains at length
     * that a worker recomputing it from disk is the whole thing the design
     * forbids: a stale worker that read the new files and announced itself
     * current would defeat the guard completely, and the incident behind that
     * design cost 117 scenes narrated at the wrong speed.
     *
     * `codeOnDiskNow()` is that forbidden read, allowed to exist for exactly
     * one purpose — deciding to die. This asserts it never reaches the
     * registry: what a worker ANNOUNCES is still the sealed marker, so a stale
     * worker still looks stale to the dispatcher for as long as it is alive.
     *
     * If this test ever goes red, the fingerprint guard is over.
     */
    public function test_the_recomputed_marker_is_never_announced(): void
    {
        WorkerRegistry::flush('render');
        WorkerRegistry::heartbeat('render', RunFingerprint::shared());

        $entry = (Cache::get('narra:workers:render') ?? [])[(string) getmypid()] ?? null;

        $this->assertNotNull($entry);
        $this->assertSame(
            RunFingerprint::code(),
            $entry['fingerprint']['code'],
            'A worker must announce the marker it BOOTED with, never the one on disk now.',
        );
    }

    /**
     * The way this could have cost real money: interrupting work.
     *
     * A queue holding jobs is a batch in flight, possibly a paid one. No amount
     * of staleness is worth stopping a 270-scene asset run part way, and a
     * batch that ran half on one code version and half on another would be a
     * new kind of mess rather than a fix for an old one.
     */
    public function test_it_does_not_restart_while_the_queue_holds_work(): void
    {
        $this->queueDepthIs(152);
        $this->pretendTheCodeChanged();

        StaleWorkerRestart::consider('render');

        $this->assertNull(
            Cache::get('illuminate:queue:restart'),
            'A worker must never take itself out while there is work waiting.',
        );
    }

    /**
     * THE ONE THE TEST SUITE COULD NOT SEE, AND A DRILL FOUND IN ONE RUN.
     *
     * The bound above is evaluated per worker. The action — `queue:restart` —
     * is a machine-wide broadcast. So an idle worker on an empty queue stood
     * down correctly by its own lights and took every busy worker with it.
     *
     * `workers:drill busy` produced it immediately: ten jobs on `text`, a file
     * touched mid-batch. The `text` worker's own bound declined exactly as
     * designed and never logged a restart, while the idle `assets` worker
     * broadcast a stop into it. Jobs 1-2 ran on one code marker and jobs 3-10
     * on another — the split this bound exists to prevent, produced by this
     * bound's own mechanism.
     *
     * The reason the suite missed it is worth keeping: `queueDepthIs()` mocks
     * one depth for every queue, so no existing test could express "mine is
     * empty and my neighbour's is not". A check that cannot distinguish the two
     * cases cannot fail on one of them.
     */
    public function test_an_idle_worker_does_not_broadcast_a_stop_into_a_busy_sibling(): void
    {
        // This worker's own queue is empty. The assets queue is mid-batch.
        $this->queueDepthsAre(['text' => 0, 'render' => 0, 'assets' => 152]);
        $this->pretendTheCodeChanged();

        StaleWorkerRestart::consider('text');

        $this->assertNull(
            Cache::get('illuminate:queue:restart'),
            'A stop that reaches every worker on the machine may only be sent when every queue is idle.',
        );
    }

    /**
     * And a depth that cannot be READ blocks it too.
     *
     * A queue that cannot be counted is not a queue known to be empty. Reading
     * an unreadable check as a pass is the failure this codebase names most
     * often; it must not be reintroduced by the thing that restarts workers.
     */
    public function test_an_unreadable_queue_blocks_the_restart(): void
    {
        $connection = Mockery::mock();
        $connection->shouldReceive('size')->andReturnUsing(function (string $queue): int {
            if ($queue === 'assets') {
                throw new \RuntimeException('redis is gone');
            }

            return 0;
        });
        Queue::shouldReceive('connection')->andReturn($connection);

        $this->pretendTheCodeChanged();

        StaleWorkerRestart::consider('text');

        $this->assertNull(Cache::get('illuminate:queue:restart'));
    }

    /**
     * The ordinary case, and the one this was built for: code edited while
     * nothing is running.
     */
    public function test_it_restarts_when_the_code_moved_and_nothing_is_queued(): void
    {
        $this->queueDepthIs(0);
        $this->pretendTheCodeChanged();

        StaleWorkerRestart::consider('render');

        $this->assertNotNull(
            Cache::get('illuminate:queue:restart'),
            'A worker running superseded code with an empty queue should stand down.',
        );

        // And leaves a breadcrumb, so a restart nobody ordered is explainable
        // rather than just a worker that vanished and came back.
        $this->assertNotNull(StaleWorkerRestart::lastSelfRestart());
    }

    /**
     * It cannot loop on its own. After a restart the sealed marker IS the disk
     * marker, so the condition is false until somebody edits another file —
     * which is what stops a restart storm being self-sustaining rather than
     * merely throttled.
     */
    public function test_a_current_worker_stays_put(): void
    {
        $this->queueDepthIs(0);

        StaleWorkerRestart::consider('render');

        $this->assertNull(
            Cache::get('illuminate:queue:restart'),
            'A worker whose code has not moved must not restart anything.',
        );
    }

    /**
     * Off is off. Production restarts its workers as part of a deploy, and a
     * worker that restarts itself part way through a file copy is booting on a
     * half-deployed tree.
     */
    public function test_it_can_be_turned_off(): void
    {
        config(['render.workers.restart_when_stale' => false]);
        $this->queueDepthIs(0);
        $this->pretendTheCodeChanged();

        StaleWorkerRestart::consider('render');

        $this->assertNull(Cache::get('illuminate:queue:restart'));
    }

    /**
     * A cache or a queue that cannot be read must never stop a worker working.
     * The dispatch-time refusal is still in place either way, so failing quietly
     * here cannot turn into a silent pass there.
     */
    public function test_a_broken_queue_does_not_take_the_worker_down(): void
    {
        Queue::shouldReceive('connection')->andThrow(new \RuntimeException('redis is gone'));
        $this->pretendTheCodeChanged();

        StaleWorkerRestart::consider('render');

        $this->assertNull(Cache::get('illuminate:queue:restart'));
    }

    /**
     * Make this process look as though the files moved under it, by aging its
     * SEALED marker rather than by editing the tree.
     */
    private function pretendTheCodeChanged(): void
    {
        $sealed = new ReflectionClass(RunFingerprint::class);
        $property = $sealed->getProperty('code');
        $property->setAccessible(true);
        $property->setValue(null, 'bootedwithsomethingelse');
    }

    private function queueDepthIs(int $jobs): void
    {
        Queue::shouldReceive('connection')->andReturn(Mockery::mock(['size' => $jobs]));
    }

    /**
     * A depth PER QUEUE, which is the distinction the suite could not make.
     *
     * `queueDepthIs()` answers the same number for every queue, so every test
     * written with it describes a machine that is uniformly busy or uniformly
     * idle. The failure that shipped lives in between — mine empty, my
     * neighbour's full — and was therefore inexpressible rather than merely
     * untested.
     *
     * @param  array<string, int>  $depths
     */
    private function queueDepthsAre(array $depths): void
    {
        $connection = Mockery::mock();
        $connection->shouldReceive('size')->andReturnUsing(
            static fn (string $queue): int => $depths[$queue] ?? 0,
        );

        Queue::shouldReceive('connection')->andReturn($connection);
    }

    /** The check is throttled per process; these tests are all one process. */
    private function resetThrottle(): void
    {
        $throttle = new ReflectionClass(StaleWorkerRestart::class);
        $property = $throttle->getProperty('lastCheck');
        $property->setAccessible(true);
        $property->setValue(null, null);
    }

    protected function tearDown(): void
    {
        // Put the real marker back: it is a static, and a later test comparing
        // fingerprints would otherwise inherit the fake one.
        $sealed = new ReflectionClass(RunFingerprint::class);
        $property = $sealed->getProperty('code');
        $property->setAccessible(true);
        $property->setValue(null, null);

        parent::tearDown();
    }
}
