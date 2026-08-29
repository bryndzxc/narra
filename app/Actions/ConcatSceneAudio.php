<?php

namespace App\Actions;

use App\Exceptions\FfmpegException;
use App\Services\Ffmpeg;
use InvalidArgumentException;

/**
 * Step 2, audio half: the padded per-scene PCM becomes narration.wav, and one
 * single encode produces narration.mp3 for the mux stage to read.
 *
 * The concat is PCM because MP3 cannot survive it — see config/render.php.
 */
class ConcatSceneAudio
{
    public function __construct(private readonly Ffmpeg $ffmpeg) {}

    /**
     * @param  array<int, string>  $paddedPaths  In playback order.
     * @param  array<int, int>  $expectedSamples  Per scene, same order.
     * @return array{
     *     wav_path: string,
     *     mp3_path: string,
     *     scenes: int,
     *     expected_samples: int,
     *     actual_samples: int,
     *     duration_seconds: float,
     *     mp3_samples: int,
     *     mp3_delta_samples: int,
     *     verified_by: string
     * }
     */
    public function handle(
        array $paddedPaths,
        array $expectedSamples,
        string $wavPath,
        string $mp3Path,
        string $listPath,
        ?bool $deep = null,
    ): array {
        if (count($paddedPaths) !== count($expectedSamples)) {
            throw new InvalidArgumentException('Padded audio list and sample list are different lengths.');
        }

        if ($paddedPaths === []) {
            throw new InvalidArgumentException('Nothing to concatenate.');
        }

        $audio = config('render.audio');
        $rate = (int) $audio['sample_rate'];
        $expected = array_sum($expectedSamples);

        $this->ffmpeg->writeConcatList($paddedPaths, $listPath);
        $this->ffmpeg->concatCopy($listPath, $wavPath, (int) config('render.timeouts.concat'));

        // WAV declares its length in sample units — duration_ts IS the sample
        // count — so the declared read is exact here, not an approximation, and
        // the assertion below stays as exact as it was when it decoded.
        $actual = $this->ffmpeg->sampleCount($wavPath, $deep);

        if ($actual !== $expected) {
            throw new FfmpegException(sprintf(
                "Concatenated audio has %d samples, expected exactly %d (%+d, %.1f ms).\n"
                .'Padded PCM concatenation is exact by construction, so this is a real bug, '
                .'not tolerance.',
                $actual,
                $expected,
                $actual - $expected,
                ($actual - $expected) / ($rate / 1000)
            ));
        }

        // One encode, from the finished exact PCM. Never a concat of MP3s.
        $this->ffmpeg->run([
            '-y',
            '-loglevel', 'error',
            '-i', $wavPath,
            '-c:a', 'libmp3lame',
            '-b:a', (string) $audio['mp3_bitrate'],
            '-ar', (string) $rate,
            '-ac', (string) (int) $audio['channels'],
            $mp3Path,
        ], (int) config('render.timeouts.encode_audio'));

        // Diagnostic only — nothing downstream reads narration.mp3, the mux
        // reads the WAV. Declared, because a full decode of a 40-minute MP3 to
        // print one informational delta is not worth minutes of render time.
        $mp3Samples = $this->ffmpeg->sampleCount($mp3Path, $deep);

        return [
            'wav_path' => $wavPath,
            'mp3_path' => $mp3Path,
            'scenes' => count($paddedPaths),
            'expected_samples' => $expected,
            'actual_samples' => $actual,
            'duration_seconds' => $actual / $rate,
            'mp3_samples' => $mp3Samples,
            'mp3_delta_samples' => $mp3Samples - $expected,
            'verified_by' => $this->ffmpeg->verificationDepth($deep),
        ];
    }
}
