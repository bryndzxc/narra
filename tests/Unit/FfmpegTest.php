<?php

namespace Tests\Unit;

use App\Actions\RenderSceneClip;
use App\Services\Ffmpeg;
use PHPUnit\Framework\TestCase;

/**
 * escapeFilterPath() is the one platform-specific method in the render
 * pipeline, so it is tested both ways regardless of which platform the suite
 * happens to be running on. When production moves to Linux, this test is what
 * says the move was safe.
 */
class FfmpegTest extends TestCase
{
    public function test_windows_paths_get_two_backslashes_before_the_drive_colon(): void
    {
        // Two backslashes, because the colon has to survive both the
        // filtergraph parser and the filter-argument parser. Verified against
        // ffmpeg N-119331: one backslash fails, two work.
        $this->assertSame(
            'E\\\\:/narra/storage/app/renders/subs.ass',
            Ffmpeg::escapeFilterPath('E:/narra/storage/app/renders/subs.ass', windows: true)
        );
    }

    public function test_windows_backslash_separators_are_normalised_to_forward_slashes(): void
    {
        $this->assertSame(
            'E\\\\:/narra/storage/subs.ass',
            Ffmpeg::escapeFilterPath('E:\\narra\\storage\\subs.ass', windows: true)
        );
    }

    public function test_the_escaped_prefix_is_exactly_two_backslashes(): void
    {
        $escaped = Ffmpeg::escapeFilterPath('C:/tmp/subs.ass', windows: true);

        // Guards against a future refactor reintroducing preg_replace, whose
        // replacement string silently collapses \\ into a single backslash.
        $this->assertSame('C', $escaped[0]);
        $this->assertSame('\\', $escaped[1]);
        $this->assertSame('\\', $escaped[2]);
        $this->assertSame(':', $escaped[3]);
        $this->assertSame('/', $escaped[4]);
    }

    public function test_linux_paths_are_left_alone(): void
    {
        $this->assertSame(
            '/srv/narra/storage/app/renders/subs.ass',
            Ffmpeg::escapeFilterPath('/srv/narra/storage/app/renders/subs.ass', windows: false)
        );
    }

    public function test_linux_mode_never_escapes_even_when_given_a_drive_letter(): void
    {
        $this->assertSame(
            'E:/narra/subs.ass',
            Ffmpeg::escapeFilterPath('E:/narra/subs.ass', windows: false)
        );
    }

    public function test_relative_and_unc_paths_are_passed_through_on_windows(): void
    {
        $this->assertSame(
            'clips/scene-001.mp4',
            Ffmpeg::escapeFilterPath('clips\\scene-001.mp4', windows: true)
        );

        $this->assertSame(
            '//server/share/subs.ass',
            Ffmpeg::escapeFilterPath('\\\\server\\share\\subs.ass', windows: true)
        );
    }

    public function test_frame_count_rounds_up(): void
    {
        // 12.867s at 30fps is 386.01 frames. Truncating loses a frame and
        // stutters at the scene boundary.
        $this->assertSame(387, RenderSceneClip::framesFor(12867, 30));
    }

    public function test_frame_count_is_exact_on_whole_frames(): void
    {
        $this->assertSame(300, RenderSceneClip::framesFor(10000, 30));
        $this->assertSame(449, RenderSceneClip::framesFor(14950, 30));
    }

    public function test_frame_count_never_returns_zero_for_a_short_scene(): void
    {
        $this->assertSame(1, RenderSceneClip::framesFor(1, 30));
    }
}
