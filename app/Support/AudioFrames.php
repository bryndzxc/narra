<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Frame counts, computed from the samples that exist rather than from a
 * duration in milliseconds.
 *
 * **Milliseconds are a lossy intermediate and must never appear in this
 * calculation.** The failure, exactly as it happened on story 21 scene 201:
 *
 *     ElevenLabs returns pcm_24000; the render runs at 44100.
 *     360002 samples @ 24000 Hz  = 15.0000833 s
 *     duration_ms stores that as  15000        <- the remainder is gone
 *     frames = ceil(15000/1000 * 30) = 450     <- exactly 15.000 s
 *     450 frames @ 44100          = 661500 samples
 *     the audio actually needs      661504 samples
 *
 * Four samples over, so `apad` became `atrim` and PadSceneAudio refused. The
 * guard was right; the frame count was wrong. And the ceil() guarantee the
 * whole pipeline rests on — video >= audio for every scene, so padding only
 * ever ADDS silence — was broken by a rounding that happened three steps
 * earlier.
 *
 * It is intermittent by construction, which is what makes it dangerous. ceil()
 * normally leaves up to a full frame of headroom, so the loss is absorbed. It
 * only bites when `duration_ms * fps / 1000` lands exactly on an integer and
 * the true length is a hair past it — at 30 fps that means a duration_ms which
 * is a multiple of 100, roughly 1 scene in 100. Story 21 had one in 270; story
 * 9 had none in 186 and shipped on luck rather than on correctness.
 *
 * Same class as the centisecond-vs-millisecond rule in CLAUDE.md: a comparison
 * made in a resolution that cannot represent the thing being compared.
 */
final class AudioFrames
{
    /**
     * A source sample count expressed at the render's sample rate.
     *
     * Every count in the pipeline has to be compared in ONE rate domain. This
     * is the only conversion; PadSceneAudio's guard and the frame count below
     * both call it, so the number that decides how many frames to allocate and
     * the number that decides whether padding would become a trim cannot
     * disagree. They previously did not disagree only because the same
     * expression was written twice.
     */
    public static function atRenderRate(int $samples, int $sourceRate, int $renderRate): int
    {
        self::assertPositive($samples, 'sample count');
        self::assertPositive($sourceRate, 'source sample rate');
        self::assertPositive($renderRate, 'render sample rate');

        if ($sourceRate === $renderRate) {
            return $samples;
        }

        // round, not floor: this must match what the resampler actually emits,
        // and it is the value the guard compares against.
        return (int) round($samples * $renderRate / $sourceRate);
    }

    /**
     * Samples in one video frame at the render rate.
     *
     * Must divide exactly. 44100/30 = 1470; a rate and fps that do not divide
     * would make every offset in the pipeline a fraction of a sample, which is
     * the drift this design exists to make impossible.
     */
    public static function samplesPerFrame(int $renderRate, int $fps): int
    {
        self::assertPositive($renderRate, 'render sample rate');
        self::assertPositive($fps, 'fps');

        if ($renderRate % $fps !== 0) {
            throw new InvalidArgumentException(
                "A frame is not a whole number of samples at {$renderRate} Hz and {$fps} fps."
            );
        }

        return intdiv($renderRate, $fps);
    }

    /**
     * Frames a clip must hold for audio of this many samples.
     *
     * ceil, deliberately and for the same reason as always: padding may only
     * ever add silence. The change is WHAT is being ceilinged — the true sample
     * count rather than a duration already rounded to the millisecond.
     *
     * Integer arithmetic throughout after the rate conversion, so there is no
     * second place for a fraction to be lost.
     */
    public static function forSamples(int $samples, int $sourceRate, int $renderRate, int $fps): int
    {
        $atRenderRate = self::atRenderRate($samples, $sourceRate, $renderRate);
        $perFrame = self::samplesPerFrame($renderRate, $fps);

        return intdiv($atRenderRate + $perFrame - 1, $perFrame);
    }

    /**
     * Frames from a millisecond duration — the OLD path, kept only for audio
     * whose true sample count is not known.
     *
     * Fixture stories carry a duration in JSON and nothing else, and a legacy
     * `scene_audio` row predates the sample columns. Neither can be computed
     * exactly, so they get this, and it is named so that a call site using it
     * is visible rather than implied.
     *
     * Anything that CAN reach the file must use forSamples(). PadSceneAudio's
     * refusal is the backstop for whatever still lands here.
     */
    public static function forMilliseconds(int $durationMs, int $fps): int
    {
        self::assertPositive($durationMs, 'duration');
        self::assertPositive($fps, 'fps');

        return (int) ceil($durationMs / 1000 * $fps);
    }

    private static function assertPositive(int $value, string $what): void
    {
        if ($value <= 0) {
            throw new InvalidArgumentException(ucfirst($what)." must be positive, got {$value}.");
        }
    }
}
