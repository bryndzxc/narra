<?php

namespace Tests\Feature;

use App\Actions\RenderSceneClip;
use App\Enums\MotionPreset;
use App\Services\Ffmpeg;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Frame count is authoritative. This renders every fixture scene and checks the
 * decoded frame count against ceil(audio_ms / 1000 * fps).
 *
 * This exists because the pipeline previously used `-t DURATION_SECONDS`, which
 * yields round(seconds * fps) and silently disagreed with zoompan's `d=` on 4 of
 * 12 fixture scenes. A one-frame error is invisible on a single clip and becomes
 * seconds of desync by minute 35.
 *
 * Tagged `render` so it can be excluded with --exclude-group render when a fast
 * loop matters. It runs by default, which is the point.
 */
#[Group('render')]
class SceneClipFrameCountTest extends TestCase
{
    private const FIXTURE = 'sample-story';

    private string $workDir;

    protected function setUp(): void
    {
        parent::setUp();

        if (! Storage::disk('fixtures')->exists(self::FIXTURE.'/timings.json')) {
            $this->markTestSkipped('Fixture set missing. Run `php artisan fixtures:make` first.');
        }

        $this->workDir = storage_path('app/testing/frame-count-'.getmypid());
    }

    protected function tearDown(): void
    {
        if (is_dir($this->workDir)) {
            foreach (glob($this->workDir.'/*') ?: [] as $file) {
                @unlink($file);
            }

            @rmdir($this->workDir);
        }

        parent::tearDown();
    }

    public function test_every_fixture_scene_renders_exactly_ceil_frames(): void
    {
        // Frame count is governed by -frames:v and is independent of the
        // encoder preset, so the fast preset keeps the assertion identical
        // while keeping the suite usable.
        config(['render.video.preset' => 'ultrafast']);

        $fixtures = Storage::disk('fixtures');
        $timings = json_decode($fixtures->get(self::FIXTURE.'/timings.json'), true);
        $sourceRoot = str_replace('\\', '/', $fixtures->path(self::FIXTURE));

        $fps = (int) config('render.video.fps');
        $renderer = app(RenderSceneClip::class);
        $ffmpeg = app(Ffmpeg::class);

        $this->assertNotEmpty($timings['scenes'], 'Fixture manifest has no scenes.');

        foreach ($timings['scenes'] as $scene) {
            $slug = sprintf('scene-%03d', $scene['sequence']);
            $audioMs = (int) $scene['duration_ms'];
            $output = $this->workDir.'/'.$slug.'.mp4';

            $expected = (int) ceil($audioMs / 1000 * $fps);

            $result = $renderer->handle(
                imagePath: $sourceRoot.'/'.$scene['image'],
                outputPath: $output,
                audioDurationMs: $audioMs,
                motion: MotionPreset::from($scene['motion_preset']),
            );

            $actual = $ffmpeg->frameCount($output);

            $this->assertSame(
                $expected,
                $actual,
                "{$slug} ({$scene['motion_preset']}, {$audioMs}ms audio): expected "
                ."{$expected} frames, got {$actual}. If this drifted by exactly one "
                .'frame, check that RenderSceneClip still passes -frames:v and not -t.'
            );

            // The Action must agree with the probe, not just with itself.
            $this->assertSame($expected, $result['frames'], "{$slug}: Action reported a different frame count than the clip contains.");

            // The ceil guarantee: video is never shorter than its audio, so the
            // mux only ever adds silence.
            $this->assertGreaterThanOrEqual(
                $audioMs,
                $result['clip_duration_ms'],
                "{$slug}: clip is shorter than its audio, which would force a trim at mux."
            );

            // ...and never longer than by a single frame.
            $this->assertLessThanOrEqual(
                1000 / $fps,
                $result['padding_ms'],
                "{$slug}: padding exceeds one frame, so the frame count is wrong."
            );
        }
    }
}
