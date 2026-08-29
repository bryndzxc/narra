<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Turns a list of per-scene frame counts into the whole-video timeline:
 * padded durations, offsets, and totals.
 *
 * Pure arithmetic, no I/O, because these are the numbers step 3 shifts every
 * subtitle by and they need to be provable rather than observed.
 *
 * The rule that matters: offsets accumulate in the EXACT integer domain
 * (frames, then samples) and are converted to milliseconds only at the very
 * end. Summing already-rounded millisecond values instead lets the error
 * compound scene after scene, and every subtitle past the first drifts with it.
 */
final class SceneTimeline
{
    /**
     * @param  array<int, int>  $frameCounts  Per scene, in playback order.
     */
    public function __construct(
        private readonly array $frameCounts,
        private readonly int $fps,
        private readonly int $sampleRate,
    ) {
        if ($fps <= 0 || $sampleRate <= 0) {
            throw new InvalidArgumentException('fps and sampleRate must both be positive.');
        }

        if ($sampleRate % $fps !== 0) {
            throw new InvalidArgumentException(
                "Sample rate {$sampleRate} does not divide evenly by {$fps} fps, so a video "
                .'frame is not a whole number of samples.'
            );
        }

        foreach ($frameCounts as $frames) {
            if ($frames <= 0) {
                throw new InvalidArgumentException('Every scene must have a positive frame count.');
            }
        }
    }

    public function samplesPerFrame(): int
    {
        return intdiv($this->sampleRate, $this->fps);
    }

    /**
     * @return array<int, array{
     *     frames: int,
     *     padded_samples: int,
     *     padded_duration_ms: int,
     *     offset_frames: int,
     *     offset_samples: int,
     *     offset_ms: int
     * }>
     */
    public function entries(): array
    {
        $perFrame = $this->samplesPerFrame();
        $entries = [];
        $cumulativeFrames = 0;

        foreach ($this->frameCounts as $frames) {
            $entries[] = [
                'frames' => $frames,
                'padded_samples' => $frames * $perFrame,
                'padded_duration_ms' => $this->toMs($frames),
                'offset_frames' => $cumulativeFrames,
                'offset_samples' => $cumulativeFrames * $perFrame,
                // Rounded once, from the exact running frame total — never by
                // summing the rounded padded_duration_ms values above.
                'offset_ms' => $this->toMs($cumulativeFrames),
            ];

            $cumulativeFrames += $frames;
        }

        return $entries;
    }

    public function totalFrames(): int
    {
        return array_sum($this->frameCounts);
    }

    public function totalSamples(): int
    {
        return $this->totalFrames() * $this->samplesPerFrame();
    }

    /**
     * The exact cross-check: samples/rate == frames/fps, compared as integers
     * so no float ever enters the decision.
     */
    public function durationsAgree(int $actualFrames, int $actualSamples): bool
    {
        return $actualSamples * $this->fps === $actualFrames * $this->sampleRate;
    }

    private function toMs(int $frames): int
    {
        return (int) round($frames / $this->fps * 1000);
    }
}
