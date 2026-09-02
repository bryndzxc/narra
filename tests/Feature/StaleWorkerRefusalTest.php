<?php

namespace Tests\Feature;

use App\Actions\PreflightAssetDispatch;
use App\Exceptions\DispatchRefusedException;
use App\Exceptions\StaleWorkerException;
use App\Jobs\GenerateSceneNarrationJob;
use App\Models\Story;
use App\Support\NarrationPace;
use App\Support\RunFingerprint;
use App\Support\WorkerRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The stale-worker defences, tested against the failure they are named for.
 *
 * The spec's rule about guards applies here more than anywhere: a check that
 * only tests the axis a component is already strong on always passes. The three
 * defences that existed before these were all strong on provider identity and
 * all blind to everything else, so every one of them passed while 117 scenes
 * were narrated at the wrong speed with no provenance recorded.
 *
 * So each test below names a real instance of that failure and confirms the
 * guard fires against it — a worker that disagrees about SPEED with the right
 * provider, and a worker whose CODE differs while every setting matches.
 */
class StaleWorkerRefusalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        WorkerRegistry::flush('assets');

        // The suite runs on `sync`, where the preflight correctly short-circuits
        // — there is no separate worker to be stale. These tests are about the
        // driver that DOES have one, so they say so. Config only: nothing here
        // connects to Redis, because the registry lives in the cache.
        config(['queue.default' => 'redis']);
    }

    /**
     * The incident, reduced: same provider, same voice, different speed.
     *
     * This is the case the provider pin could not see. It compared provider
     * names, the provider name was correct, and it passed.
     */
    public function test_a_worker_that_disagrees_about_narration_speed_is_refused(): void
    {
        // Both sides derived, for the same reason as above: the assertion is
        // that a DIFFERENCE is caught, not that two particular numbers are.
        $was = NarrationPace::configuredSpeed();

        $this->registerWorkerBelieving([
            'providers.elevenlabs.tts.voice_settings.speed' => $was,
        ]);

        config(['providers.elevenlabs.tts.voice_settings.speed' => $was + 0.5]);

        $stale = WorkerRegistry::stale('assets', RunFingerprint::shared());

        $this->assertCount(1, $stale, 'A speed disagreement must be detected.');

        $this->expectException(DispatchRefusedException::class);
        $this->expectExceptionMessageMatches('/narration_speed/');

        throw DispatchRefusedException::staleWorkers('assets', $stale, RunFingerprint::shared());
    }

    /**
     * The axis config alone cannot express: the worker's CODE is older.
     *
     * In the real incident the speed setting did not merely differ between the
     * two processes, it did not exist in the worker's — and neither did the
     * pace guard or the narration_speed column. A settings-only fingerprint
     * agrees perfectly in that situation, which is exactly why the code marker
     * is frozen at boot rather than recomputed on demand.
     */
    public function test_a_worker_running_different_code_is_refused_even_when_every_setting_matches(): void
    {
        $shared = RunFingerprint::shared();

        // A worker that booted with an older tree. Nothing else about it
        // differs — every provider, the speed, the voice table, the tolerances.
        $older = $shared;
        $older['code'] = 'aaaaaaaaaaaaaaaa';

        $this->registerWorkerWithFingerprint($older);

        $stale = WorkerRegistry::stale('assets', $shared);

        $this->assertCount(1, $stale, 'A code difference alone must be detected.');

        $diff = RunFingerprint::diff($shared, $older);

        $this->assertSame(['code'], array_keys($diff), 'Only the code marker should differ.');
        $this->assertStringContainsString('CODE marker differs', RunFingerprint::explain($diff));
    }

    /** A worker that agrees about everything is not refused. */
    public function test_a_current_worker_passes(): void
    {
        $this->registerWorkerWithFingerprint(RunFingerprint::shared());

        $this->assertSame([], WorkerRegistry::stale('assets', RunFingerprint::shared()));

        $notes = app(PreflightAssetDispatch::class)->handle(
            story: $this->story(),
            checkWorkers: true,
            // The aligner spawns a real Python process; it has its own test.
            checkAligner: false,
        );

        $this->assertNotEmpty($notes);
        $this->assertSame('ok', $notes[0]['level']);
        $this->assertStringContainsString('agree with this process', $notes[0]['message']);
    }

    /**
     * No worker at all warns but does not refuse, and the asymmetry is the point.
     *
     * A stale worker does the work wrong and reports success — unrecoverable
     * once it has billed. An empty queue does nothing at all: the jobs wait,
     * nothing is spent, and starting a worker later drains them correctly.
     * Dispatching before starting a worker is a legitimate order of operations,
     * so refusing it would block a safe workflow to prevent an inconvenience.
     */
    public function test_an_empty_queue_warns_but_still_dispatches(): void
    {
        $notes = app(PreflightAssetDispatch::class)->handle(
            story: $this->story(),
            checkWorkers: true,
            checkAligner: false,
        );

        $this->assertSame('warn', $notes[0]['level']);
        $this->assertStringContainsString('Nothing is listening', $notes[0]['message']);
    }

    /**
     * The job-level backstop, for the window the preflight cannot cover: a
     * worker that goes stale AFTER the dispatch, while the batch is draining.
     */
    public function test_a_job_refuses_when_the_worker_disagrees_with_its_payload(): void
    {
        $story = $this->story();

        $dispatched = RunFingerprint::for($story);

        $job = new GenerateSceneNarrationJob($story->id, null, 'elevenlabs', $dispatched);

        // The worker moves out from under the queued job.
        //
        // DERIVED from the configured speed rather than hardcoded. It was
        // hardcoded to 1.0, which stopped being a mismatch the moment the
        // project's configured speed became 1.0 — a test that silently stops
        // testing anything is the same defect class this whole file is about.
        config([
            'providers.elevenlabs.tts.voice_settings.speed' => NarrationPace::configuredSpeed() + 0.5,
        ]);

        $diff = RunFingerprint::diff($job->expectedFingerprint, RunFingerprint::for($story));

        $this->assertArrayHasKey('narration_speed', $diff);

        $this->expectException(StaleWorkerException::class);

        throw new StaleWorkerException(RunFingerprint::explain($diff));
    }

    /**
     * A job queued before fingerprints existed carries none, and must still run.
     *
     * The same rule the provenance checks follow: never refuse on unknown, only
     * on demonstrably different. Refusing null would strand every job already
     * sitting on the queue at deploy time.
     */
    public function test_a_job_with_no_fingerprint_is_not_treated_as_a_mismatch(): void
    {
        $job = new GenerateSceneNarrationJob(1, null, 'elevenlabs');

        $this->assertNull($job->expectedFingerprint);
    }

    /**
     * The per-story half is not compared against workers.
     *
     * A worker serves every story on its queue and announces itself long before
     * any particular story is named. Comparing voice_id against it would flag
     * every healthy worker the moment a second story existed.
     */
    public function test_the_worker_comparison_ignores_per_story_fields(): void
    {
        $shared = RunFingerprint::shared();

        $this->assertArrayNotHasKey('voice_id', $shared);
        $this->assertArrayNotHasKey('expected_wpm', $shared);

        $story = $this->story();

        $this->assertArrayHasKey('voice_id', RunFingerprint::for($story));
        $this->assertArrayHasKey('expected_wpm', RunFingerprint::for($story));
    }

    /**
     * Register a worker whose fingerprint was taken under `$config`.
     *
     * @param  array<string, mixed>  $config
     */
    private function registerWorkerBelieving(array $config): void
    {
        $restore = [];

        foreach (array_keys($config) as $key) {
            $restore[$key] = config($key);
        }

        config($config);
        $fingerprint = RunFingerprint::shared();
        config($restore);

        $this->registerWorkerWithFingerprint($fingerprint);
    }

    /**
     * @param  array<string, scalar|null>  $fingerprint
     */
    private function registerWorkerWithFingerprint(array $fingerprint): void
    {
        WorkerRegistry::heartbeat('assets', $fingerprint, force: true);
    }

    private function story(): Story
    {
        return Story::factory()->create(['voice_id' => 'nPczCjzI2devNBz1zQrb']);
    }
}
