<?php

namespace Tests\Unit;

use App\Actions\RenderSceneClip;
use App\Enums\MotionPreset;
use App\Services\Ffmpeg;
use Tests\TestCase;

/**
 * A millisecond-scale guard on the one argument that decides clip length.
 *
 * The render-level proof lives in SceneClipFrameCountTest, which actually
 * encodes every fixture scene. This one catches the same regression without
 * spawning FFmpeg, so it stays cheap enough to never be skipped.
 */
class SceneClipArgumentsTest extends TestCase
{
    public function test_the_clip_length_is_set_with_frames_v_and_never_with_t(): void
    {
        $spy = $this->spyFfmpeg();

        app(RenderSceneClip::class)->handle(
            imagePath: $this->anyStill(),
            outputPath: sys_get_temp_dir().'/unused.mp4',
            audioDurationMs: 12867,
            motion: MotionPreset::ZoomOut,
        );

        $arguments = $spy->captured;

        // 12867ms at 30fps is 386.01 frames, so ceil gives 387. `-t 12.867`
        // would have produced 386 — the exact bug this guards.
        $this->assertSame('387', $this->valueAfter($arguments, '-frames:v'));

        $this->assertNotContains(
            '-t',
            $arguments,
            '-t yields round(seconds * fps) and disagrees with zoompan d=. Use -frames:v.'
        );
    }

    public function test_zoompan_duration_matches_the_frames_v_argument(): void
    {
        $spy = $this->spyFfmpeg();

        app(RenderSceneClip::class)->handle(
            imagePath: $this->anyStill(),
            outputPath: sys_get_temp_dir().'/unused.mp4',
            audioDurationMs: 13283,
            motion: MotionPreset::PanLeft,
        );

        $arguments = $spy->captured;

        $filter = $this->valueAfter($arguments, '-filter_complex');
        $frames = $this->valueAfter($arguments, '-frames:v');

        // If these two ever diverge the zoom does not complete over the clip.
        $this->assertStringContainsString(":d={$frames}:", $filter);
        $this->assertSame('399', $frames);
    }

    /**
     * The argument following a flag, failing loudly when the flag is absent.
     *
     * array_search() returns false on a miss and `$arguments[false + 1]` reads
     * index 1 instead, which turns a missing flag into a confusing assertion
     * about an unrelated argument.
     *
     * @param  array<int, string>  $arguments
     */
    private function valueAfter(array $arguments, string $flag): string
    {
        $index = array_search($flag, $arguments, true);

        $this->assertNotFalse($index, "Expected {$flag} in the FFmpeg arguments.");
        $this->assertArrayHasKey($index + 1, $arguments, "{$flag} has no value after it.");

        return $arguments[$index + 1];
    }

    private function anyStill(): string
    {
        $still = storage_path('app/fixtures/sample-story/scene-001.png');

        if (! is_readable($still)) {
            $this->markTestSkipped('Fixture still missing. Run `php artisan fixtures:make` first.');
        }

        return $still;
    }

    /**
     * Swaps the container's Ffmpeg for one that records its arguments and never
     * spawns a process.
     */
    private function spyFfmpeg(): Ffmpeg
    {
        $spy = new class('ffmpeg', 'ffprobe', 60) extends Ffmpeg
        {
            /** @var array<int, string> */
            public array $captured = [];

            public function run(array $arguments, int $timeout, ?callable $onOutput = null): string
            {
                $this->captured = $arguments;

                return '';
            }
        };

        $this->app->instance(Ffmpeg::class, $spy);

        return $spy;
    }
}
