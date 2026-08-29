<?php

namespace App\Actions;

use App\Exceptions\FfmpegException;
use App\Services\Ffmpeg;
use InvalidArgumentException;

/**
 * Step 2, video half: the scene clips become silent.mp4.
 *
 * Concat demuxer, not the concat filter — every clip left step 1 with identical
 * codec parameters, so this is a stream copy and is effectively instant
 * regardless of runtime.
 */
class ConcatVideoClips
{
    public function __construct(private readonly Ffmpeg $ffmpeg) {}

    /**
     * @param  array<int, string>  $clipPaths  In playback order.
     * @param  array<int, int>  $expectedFrames  Per clip, same order.
     * @return array{
     *     output_path: string,
     *     clips: int,
     *     expected_frames: int,
     *     actual_frames: int,
     *     duration_seconds: float,
     *     verified_by: string
     * }
     */
    public function handle(
        array $clipPaths,
        array $expectedFrames,
        string $outputPath,
        string $listPath,
        ?bool $deep = null,
    ): array {
        if (count($clipPaths) !== count($expectedFrames)) {
            throw new InvalidArgumentException('Clip list and frame list are different lengths.');
        }

        if ($clipPaths === []) {
            throw new InvalidArgumentException('Nothing to concatenate.');
        }

        $fps = (int) config('render.video.fps');
        $expected = array_sum($expectedFrames);

        $this->ffmpeg->writeConcatList($clipPaths, $listPath);
        $this->ffmpeg->concatCopy($listPath, $outputPath, (int) config('render.timeouts.concat'));

        // Declared by default. The concat is a stream copy into MP4, so the
        // sample table this reads was written from the same packets the copy
        // moved — counting them again by decoding 40 minutes proves the same
        // thing, slowly. --deep does that when a render is under suspicion.
        $actual = $this->ffmpeg->frameCount($outputPath, $deep);

        // A stream copy should never lose or gain a frame. If it did, one of the
        // inputs did not carry the frame count step 1 recorded for it.
        if ($actual !== $expected) {
            throw new FfmpegException(sprintf(
                "Concatenated video has %d frames, expected %d (%+d).\n"
                .'The sum of the scene clips does not match the joined file, so a clip is '
                .'not what step 1 recorded.',
                $actual,
                $expected,
                $actual - $expected
            ));
        }

        return [
            'output_path' => $outputPath,
            'clips' => count($clipPaths),
            'expected_frames' => $expected,
            'actual_frames' => $actual,
            'duration_seconds' => $actual / $fps,
            'verified_by' => $this->ffmpeg->verificationDepth($deep),
        ];
    }
}
