<?php

namespace Tests\Unit;

use App\Support\SceneTimeline;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * These offsets are what step 3 shifts every word timing by. A millisecond of
 * accumulated error here is a millisecond of subtitle desync that grows for
 * 35 minutes, so the arithmetic is pinned down rather than eyeballed.
 */
class SceneTimelineTest extends TestCase
{
    /** The 12 fixture scenes. */
    private const FIXTURE_FRAMES = [449, 374, 387, 424, 399, 399, 436, 374, 387, 374, 411, 449];

    public function test_offsets_match_the_rendered_fixture(): void
    {
        $entries = (new SceneTimeline(self::FIXTURE_FRAMES, 30, 44100))->entries();

        $this->assertSame(
            [0, 14967, 27433, 40333, 54467, 67767, 81067, 95600, 108067, 120967, 133433, 147133],
            array_column($entries, 'offset_ms')
        );

        $this->assertSame(
            [0, 449, 823, 1210, 1634, 2033, 2432, 2868, 3242, 3629, 4003, 4414],
            array_column($entries, 'offset_frames')
        );
    }

    public function test_offsets_are_derived_from_cumulative_frames_not_summed_milliseconds(): void
    {
        $timeline = new SceneTimeline(self::FIXTURE_FRAMES, 30, 44100);
        $entries = $timeline->entries();

        $naive = 0;
        $diverged = false;

        foreach ($entries as $entry) {
            // Every offset must be within half a millisecond of the true value,
            // no matter how many scenes precede it.
            $exact = $entry['offset_frames'] / 30 * 1000;
            $this->assertLessThanOrEqual(0.5, abs($entry['offset_ms'] - $exact));

            if ($naive !== $entry['offset_ms']) {
                $diverged = true;
            }

            $naive += $entry['padded_duration_ms'];
        }

        // If this ever stops diverging the test has lost its teeth: it means
        // the fixture no longer exercises the rounding case the rule exists for.
        $this->assertTrue(
            $diverged,
            'Summing rounded padded_duration_ms should diverge from the exact offsets on this fixture.'
        );
    }

    public function test_offsets_do_not_drift_over_two_hundred_scenes(): void
    {
        // 387 frames is 12900ms exactly; 449 is 14966.67 and rounds. Alternating
        // them is the adversarial case for accumulated rounding.
        $frames = [];
        for ($i = 0; $i < 200; $i++) {
            $frames[] = $i % 2 === 0 ? 449 : 374;
        }

        $entries = (new SceneTimeline($frames, 30, 44100))->entries();

        foreach ($entries as $entry) {
            $exact = $entry['offset_frames'] / 30 * 1000;

            $this->assertLessThanOrEqual(
                0.5,
                abs($entry['offset_ms'] - $exact),
                'Offset drifted; offsets must be derived from cumulative frames.'
            );
        }

        // The naive approach, for contrast: it is off by more than a frame by
        // the end, which is exactly the desync this class exists to prevent.
        $naive = 0;
        foreach ($entries as $entry) {
            $naive += $entry['padded_duration_ms'];
        }

        $last = end($entries);
        $trueEnd = ($last['offset_frames'] + $last['frames']) / 30 * 1000;

        $this->assertGreaterThan(33.0, abs($naive - $trueEnd));
    }

    public function test_totals_are_exact_and_agree_across_streams(): void
    {
        $timeline = new SceneTimeline(self::FIXTURE_FRAMES, 30, 44100);

        $this->assertSame(4863, $timeline->totalFrames());
        $this->assertSame(7148610, $timeline->totalSamples());
        $this->assertSame(1470, $timeline->samplesPerFrame());

        $this->assertTrue($timeline->durationsAgree(4863, 7148610));

        // One sample out must fail. No tolerance.
        $this->assertFalse($timeline->durationsAgree(4863, 7148611));
        $this->assertFalse($timeline->durationsAgree(4864, 7148610));
    }

    public function test_a_sample_rate_that_does_not_divide_by_fps_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('does not divide evenly');

        // 48000 / 30 is 1600 and fine; 48000 / 25 is 1920 and fine. 44100 / 24
        // is 1837.5, so a frame is not a whole number of samples.
        new SceneTimeline([100], 24, 44100);
    }
}
