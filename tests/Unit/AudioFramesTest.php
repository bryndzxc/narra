<?php

namespace Tests\Unit;

use App\Support\AudioFrames;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Frame counts computed from samples, not from rounded milliseconds.
 *
 * Built from the case that broke: story 21 scene 201 refused at concat with
 * "360002 samples at 24000 Hz (661504 at the 44100 Hz render rate) but only
 * 661500 fit in 450 frames. Padding would become a trim."
 *
 * The guard was right and the frame count was wrong. The whole render rests on
 * ceil() guaranteeing video >= audio for every scene, so that padding only ever
 * ADDS silence — and a rounding three steps upstream had quietly broken the
 * guarantee.
 */
class AudioFramesTest extends TestCase
{
    private const SOURCE_RATE = 24000;   // ElevenLabs pcm_24000

    private const RENDER_RATE = 44100;

    private const FPS = 30;

    /**
     * The exact failure, to the sample.
     *
     * 360002 @ 24 kHz = 15.0000833 s. The old path stored that as the integer
     * 15000 ms, and 15000/1000*30 is exactly 450.0 — a whole number, so ceil()
     * had no headroom left to absorb the discarded remainder and returned 450
     * where 451 was needed.
     */
    public function test_the_scene_that_broke_concat_gets_a_frame_that_can_hold_it(): void
    {
        $frames = AudioFrames::forSamples(360002, self::SOURCE_RATE, self::RENDER_RATE, self::FPS);

        $this->assertSame(451, $frames, 'four samples over is still over');

        // And the guarantee itself, stated the way PadSceneAudio checks it.
        $needed = AudioFrames::atRenderRate(360002, self::SOURCE_RATE, self::RENDER_RATE);
        $capacity = $frames * AudioFrames::samplesPerFrame(self::RENDER_RATE, self::FPS);

        $this->assertSame(661504, $needed);
        $this->assertGreaterThanOrEqual($needed, $capacity, 'padding would become a trim');
    }

    /** The old arithmetic, kept as a test so the regression is legible. */
    public function test_the_millisecond_path_is_the_one_that_was_short(): void
    {
        // What ffprobe stored, and what it yielded.
        $this->assertSame(450, AudioFrames::forMilliseconds(15000, self::FPS));

        $capacity = 450 * AudioFrames::samplesPerFrame(self::RENDER_RATE, self::FPS);
        $needed = AudioFrames::atRenderRate(360002, self::SOURCE_RATE, self::RENDER_RATE);

        $this->assertSame(661500, $capacity);
        $this->assertSame(4, $needed - $capacity, 'short by exactly four samples');
    }

    /**
     * Why it looked intermittent. At 30 fps the ceil() only runs out of
     * headroom when duration_ms lands exactly on a frame boundary, which needs
     * a multiple of 100 — about one scene in a hundred. Story 21 had one in
     * 270; story 9 had none in 186 and shipped on luck.
     */
    public function test_a_duration_off_the_frame_boundary_had_headroom_to_spare(): void
    {
        // 15.001 s of audio: 360026 samples, ms rounds to 15001, and
        // ceil(15001/1000*30) = 451 — enough, by accident rather than design.
        $this->assertSame(451, AudioFrames::forMilliseconds(15001, self::FPS));
        $this->assertSame(451, AudioFrames::forSamples(360026, self::SOURCE_RATE, self::RENDER_RATE, self::FPS));
    }

    /** Same rate in and out means no conversion at all, not a rounded one. */
    public function test_a_source_already_at_the_render_rate_is_untouched(): void
    {
        $this->assertSame(
            661504,
            AudioFrames::atRenderRate(661504, self::RENDER_RATE, self::RENDER_RATE)
        );
    }

    /** Exactly one frame of audio is exactly one frame of video. */
    public function test_an_exact_frame_of_audio_does_not_gain_a_frame(): void
    {
        $perFrame = AudioFrames::samplesPerFrame(self::RENDER_RATE, self::FPS);

        $this->assertSame(1470, $perFrame);
        $this->assertSame(1, AudioFrames::forSamples($perFrame, self::RENDER_RATE, self::RENDER_RATE, self::FPS));
        $this->assertSame(2, AudioFrames::forSamples($perFrame + 1, self::RENDER_RATE, self::RENDER_RATE, self::FPS));
    }

    /**
     * The ceil guarantee, swept across the whole boundary region rather than
     * asserted at one point. Every sample count in the range must fit.
     */
    public function test_video_is_never_shorter_than_its_audio_across_the_boundary(): void
    {
        $perFrame = AudioFrames::samplesPerFrame(self::RENDER_RATE, self::FPS);

        for ($samples = 359_980; $samples <= 360_100; $samples++) {
            $frames = AudioFrames::forSamples($samples, self::SOURCE_RATE, self::RENDER_RATE, self::FPS);
            $needed = AudioFrames::atRenderRate($samples, self::SOURCE_RATE, self::RENDER_RATE);

            $this->assertGreaterThanOrEqual(
                $needed,
                $frames * $perFrame,
                "{$samples} samples did not fit in {$frames} frames"
            );
        }
    }

    /**
     * A rate that does not divide by fps makes every offset a fraction of a
     * sample. Refused rather than rounded quietly.
     */
    public function test_a_rate_that_does_not_divide_by_fps_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        AudioFrames::samplesPerFrame(48000, 7);
    }

    public function test_a_zero_sample_count_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        AudioFrames::forSamples(0, self::SOURCE_RATE, self::RENDER_RATE, self::FPS);
    }
}
