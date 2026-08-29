<?php

namespace Tests\Feature;

use App\Enums\RenderJobStatus;
use App\Enums\RenderStage;
use App\Enums\StoryStatus;
use App\Exceptions\FfmpegException;
use App\Jobs\RenderSceneClipJob;
use App\Models\Act;
use App\Models\AudioTrack;
use App\Models\RenderJob;
use App\Models\Scene;
use App\Models\SceneAudio;
use App\Models\Story;
use App\Services\Ffmpeg;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use RuntimeException;
use Tests\TestCase;

/**
 * The bookkeeping a queued stage adds around an Action.
 *
 * The Actions themselves are proven elsewhere and at full length; what is new in
 * Phase 1 is that a stage leaves a trace an operator can read, and that a long
 * one leaves a heartbeat. On this platform the heartbeat is not a nicety: a
 * hung FFmpeg holds a worker forever and `queue:work --timeout` cannot see it,
 * so a quiet `updated_at` is the only evidence that ever exists.
 */
class RenderStageJobTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        foreach (['app/fixtures/stage-test', 'app/renders/stage-test'] as $directory) {
            $this->deleteDirectory(storage_path($directory));
        }

        parent::tearDown();
    }

    private function deleteDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        foreach (glob($path.'/*') ?: [] as $entry) {
            is_dir($entry) ? $this->deleteDirectory($entry) : @unlink($entry);
        }

        @rmdir($path);
    }

    public function test_a_stage_records_a_render_job_row_and_marks_it_succeeded(): void
    {
        [$story, $scene] = $this->readyScene();

        $this->fakeFfmpeg(frames: $scene->framesAt());

        (new RenderSceneClipJob($story->id, $scene->id))->handle(app(Ffmpeg::class));

        $job = RenderJob::query()->firstOrFail();

        $this->assertSame(RenderStage::SceneClips, $job->stage);
        $this->assertSame(RenderJobStatus::Succeeded, $job->status);
        $this->assertSame($scene->id, $job->scene_id);
        $this->assertNotNull($job->started_at);
        $this->assertNotNull($job->finished_at);
        $this->assertStringContainsString('frames', (string) $job->log);
    }

    public function test_a_completed_stage_is_not_redone(): void
    {
        // Idempotence is the whole reason a batch can be resumed. At 200 scenes
        // the difference between skipping finished clips and redoing them is an
        // afternoon.
        [$story, $scene] = $this->readyScene();

        $ffmpeg = $this->fakeFfmpeg(frames: $scene->framesAt());

        (new RenderSceneClipJob($story->id, $scene->id))->handle($ffmpeg);

        $this->assertSame(0, $ffmpeg->encodes, 'An existing, correct clip was re-encoded.');
        $this->assertStringContainsString('kept existing clip', (string) RenderJob::query()->first()?->log);
    }

    public function test_one_row_per_stage_and_scene_no_matter_how_often_it_reruns(): void
    {
        [$story, $scene] = $this->readyScene();
        $ffmpeg = $this->fakeFfmpeg(frames: $scene->framesAt());

        (new RenderSceneClipJob($story->id, $scene->id))->handle($ffmpeg);
        (new RenderSceneClipJob($story->id, $scene->id))->handle($ffmpeg);
        (new RenderSceneClipJob($story->id, $scene->id))->handle($ffmpeg);

        // Three runs, one row. A 200-scene batch retried twice should not leave
        // 600 rows for the progress page to collapse.
        $this->assertDatabaseCount('render_jobs', 1);
    }

    public function test_a_failure_lands_on_the_row_before_it_is_rethrown(): void
    {
        [$story, $scene] = $this->readyScene(clipExists: false);

        $this->fakeFfmpeg(frames: 0, failWith: 'ffmpeg exited with code 1: No such filter');

        try {
            (new RenderSceneClipJob($story->id, $scene->id))->handle(app(Ffmpeg::class));
            $this->fail('The job should have rethrown so the batch sees the failure.');
        } catch (FfmpegException) {
            // Expected: the queue needs the exception, the operator needs the row.
        }

        $job = RenderJob::query()->firstOrFail();

        $this->assertSame(RenderJobStatus::Failed, $job->status);
        $this->assertStringContainsString('No such filter', (string) $job->error);
        $this->assertSame($scene->id, $job->scene_id, 'A failure must name the scene it belongs to.');
    }

    public function test_a_corrupt_still_fails_immediately_instead_of_hanging_the_worker(): void
    {
        // Found by drilling it: `-loop 1` does NOT fail on a corrupt image. It
        // loops on "Invalid PNG signature" forever, emits no frames and never
        // exits, so the job sits at `running` until the 15-minute Process
        // timeout kills it. Three bad stills in a 200-scene batch would stall
        // the render for the better part of an hour before saying anything.
        //
        // Worse, ffprobe EXITS ZERO on such a file — it reports a png stream of
        // 0x0. The guard has to look at the dimensions, not the exit code.
        [$story, $scene] = $this->readyScene(clipExists: false);

        $ffmpeg = $this->fakeFfmpeg(frames: 0, stillDimensions: [0, 0]);

        try {
            (new RenderSceneClipJob($story->id, $scene->id))->handle($ffmpeg);
            $this->fail('A still with no decodable image was handed to FFmpeg.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('probes as 0x0', $e->getMessage());
            $this->assertStringContainsString("Scene {$scene->sequence}", $e->getMessage());
        }

        // Nothing was encoded, and the operator gets the scene number.
        $this->assertSame(0, $ffmpeg->encodes);
        $this->assertStringContainsString('0x0', (string) RenderJob::query()->first()?->error);
    }

    public function test_a_half_written_clip_is_replaced_rather_than_failing_forever(): void
    {
        // The wreckage a killed worker leaves behind. ffprobe exits non-zero on
        // a truncated MP4, and letting that surface as the job's own failure
        // would leave the scene permanently stuck — every retry failing on the
        // remains of the last one. An unreadable clip is an absent clip.
        [$story, $scene] = $this->readyScene();

        $ffmpeg = $this->fakeFfmpeg(frames: $scene->framesAt(), probeExistingThrows: true);

        (new RenderSceneClipJob($story->id, $scene->id))->handle($ffmpeg);

        $this->assertSame(1, $ffmpeg->encodes, 'The damaged clip should have been re-encoded.');
        $this->assertSame(RenderJobStatus::Succeeded, RenderJob::query()->firstOrFail()->status);
    }

    public function test_a_running_stage_registers_a_heartbeat_with_the_ffmpeg_wrapper(): void
    {
        [$story, $scene] = $this->readyScene();

        $ffmpeg = $this->fakeFfmpeg(frames: $scene->framesAt());

        (new RenderSceneClipJob($story->id, $scene->id))->handle($ffmpeg);

        $this->assertTrue($ffmpeg->heartbeatRegistered, 'Nothing would report a hung encode.');

        // And it is unregistered afterwards, so the next job on this worker
        // cannot touch the previous job's row.
        $this->assertNull($ffmpeg->currentHeartbeat);
    }

    public function test_the_heartbeat_actually_moves_the_row(): void
    {
        [$story, $scene] = $this->readyScene();

        $job = RenderJob::factory()->for($story)->stale()->create([
            'stage' => RenderStage::Mux,
        ]);

        $this->assertTrue($job->isStale());

        Carbon::setTestNow(Carbon::now());
        $job->heartbeat();
        $this->assertFalse($job->fresh()->isStale());
        Carbon::setTestNow();
    }

    /**
     * @return array{0: Story, 1: Scene}
     */
    private function readyScene(bool $clipExists = true): array
    {
        $story = Story::factory()->status(StoryStatus::Rendering)->create(['slug' => 'stage-test']);
        $act = Act::factory()->for($story)->atSequence(1)->create();
        $scene = Scene::factory()->forAct($act)->atSequence(1)->ready()
            ->create(['duration_ms' => 12867, 'image_path' => 'stage-test/scene-001.png']);

        SceneAudio::factory()
            ->for($scene)
            ->for(AudioTrack::factory()->for($story))
            ->create(['audio_path' => 'stage-test/scene-001.mp3', 'duration_ms' => 12867]);

        // A source still and narration have to exist on the fixtures disk:
        // the Action refuses to run without them, and this test is about what
        // happens further in.
        foreach (['scene-001.png', 'scene-001.mp3'] as $asset) {
            $path = storage_path('app/fixtures/stage-test/'.$asset);

            if (! is_dir(dirname($path))) {
                mkdir(dirname($path), 0775, true);
            }

            file_put_contents($path, 'fixture stand-in; the fake ffmpeg does the answering');
        }

        $clip = storage_path('app/renders/stage-test/clips/scene-001.mp4');

        if (! is_dir(dirname($clip))) {
            mkdir(dirname($clip), 0775, true);
        }

        is_file($clip) && unlink($clip);

        if ($clipExists) {
            file_put_contents($clip, 'not really an mp4, the fake ffmpeg does the answering');
        }

        return [$story, $scene];
    }

    /**
     * An Ffmpeg that answers questions without spawning anything.
     */
    /**
     * @param  array{0: int, 1: int}  $stillDimensions  What ffprobe reports for
     *                                                  the source still.
     */
    private function fakeFfmpeg(
        int $frames,
        ?string $failWith = null,
        array $stillDimensions = [1920, 1080],
        bool $probeExistingThrows = false,
    ): Ffmpeg {
        $fake = new class('ffmpeg', 'ffprobe', 60, $frames, $failWith, $stillDimensions, $probeExistingThrows) extends Ffmpeg
        {
            public int $encodes = 0;

            public bool $heartbeatRegistered = false;

            /** @var (callable():void)|null */
            public $currentHeartbeat = null;

            public function __construct(
                string $ffmpeg,
                string $ffprobe,
                int $probeTimeout,
                private int $frames,
                private ?string $failWith,
                private array $stillDimensions = [1920, 1080],
                private bool $probeExistingThrows = false,
            ) {
                parent::__construct($ffmpeg, $ffprobe, $probeTimeout);
            }

            public function inspect(string $file): array
            {
                return ['stream' => [
                    'width' => $this->stillDimensions[0],
                    'height' => $this->stillDimensions[1],
                ], 'format' => []];
            }

            public function run(array $arguments, int $timeout, ?callable $onOutput = null): string
            {
                $this->encodes++;

                if ($this->failWith !== null) {
                    throw new FfmpegException($this->failWith);
                }

                return '';
            }

            public function frameCount(string $file, ?bool $deep = null): int
            {
                if ($this->probeExistingThrows && $this->encodes === 0) {
                    throw new FfmpegException('ffprobe exited 1: moov atom not found');
                }

                return $this->frames;
            }

            public function heartbeatUsing(?callable $callback, ?float $intervalSeconds = null): void
            {
                $this->heartbeatRegistered = $this->heartbeatRegistered || $callback !== null;
                $this->currentHeartbeat = $callback;
            }
        };

        $this->app->instance(Ffmpeg::class, $fake);

        return $fake;
    }
}
