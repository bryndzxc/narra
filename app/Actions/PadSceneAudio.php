<?php

namespace App\Actions;

use App\Exceptions\FfmpegException;
use App\Services\Ffmpeg;
use InvalidArgumentException;

/**
 * Pads one scene's narration with silence to exactly frames / fps.
 *
 * The frame count is authoritative and the audio is fitted to it, never the
 * other way around. Because the frame count is a ceil of the audio duration,
 * this only ever ADDS silence — at most one frame.
 *
 * Output is PCM, deliberately. MP3 carries per-file encoder delay and padding,
 * so a stream-copy concat of padded MP3s gains roughly 1685 samples per file
 * and the exact-duration guarantee is lost.
 */
class PadSceneAudio
{
    public function __construct(private readonly Ffmpeg $ffmpeg) {}

    /**
     * Samples occupied by one video frame.
     *
     * Whole numbers only. If the sample rate does not divide by the frame rate,
     * a frame is a fractional number of samples and nothing downstream can be
     * exact — so this refuses rather than rounding quietly.
     */
    public static function samplesPerFrame(int $sampleRate, int $fps): int
    {
        if ($fps <= 0 || $sampleRate <= 0 || $sampleRate % $fps !== 0) {
            throw new InvalidArgumentException(
                "Sample rate {$sampleRate} does not divide evenly by {$fps} fps, so a video "
                .'frame is not a whole number of samples. Exact A/V duration is impossible.'
            );
        }

        return intdiv($sampleRate, $fps);
    }

    /**
     * @return array{
     *     output_path: string,
     *     frames: int,
     *     source_samples: int,
     *     target_samples: int,
     *     padding_samples: int,
     *     padding_ms: float
     * }
     */
    public function handle(string $sourceAudioPath, string $outputPath, int $frames): array
    {
        if (! is_readable($sourceAudioPath)) {
            throw new InvalidArgumentException("Scene audio not readable: {$sourceAudioPath}");
        }

        if ($frames <= 0) {
            throw new InvalidArgumentException("Frame count must be positive, got {$frames}.");
        }

        $audio = config('render.audio');
        $rate = (int) $audio['sample_rate'];

        $target = $frames * self::samplesPerFrame($rate, (int) config('render.video.fps'));

        // Decoded, explicitly, not the container's declared length. This number
        // decides how much silence is appended and guards against apad,atrim
        // turning into a trim, so it has to be the samples that actually exist
        // — an MP3's declared duration and its decoded length are not the same
        // thing. Scene audio is seconds long, so the decode is cheap; the deep
        // check that is NOT affordable is the one on the full-length files.
        $source = $this->ffmpeg->sampleCount($sourceAudioPath, deep: true);

        // apad,atrim would silently CUT a scene whose audio runs past its frame
        // count, clipping the tail of the last word. That must never happen —
        // ceil() guarantees it cannot, so if it does, the frame count is wrong.
        if ($source > $target) {
            throw new FfmpegException(sprintf(
                'Scene audio is longer than its frame count allows: %s has %d samples but '
                ."only %d fit in %d frames. Padding would become a trim.\n"
                .'Check that frames were computed with ceil() from this exact file.',
                basename($sourceAudioPath),
                $source,
                $target,
                $frames
            ));
        }

        if (! is_dir($directory = dirname($outputPath))) {
            mkdir($directory, 0775, true);
        }

        $this->ffmpeg->run([
            '-y',
            '-loglevel', 'error',
            '-i', $sourceAudioPath,
            // apad appends silence indefinitely; atrim cuts at the exact sample.
            // Together they land on target_samples regardless of source length.
            '-af', 'apad,atrim=end_sample='.$target,
            '-ar', (string) $rate,
            '-ac', (string) (int) $audio['channels'],
            '-c:a', (string) $audio['pcm_codec'],
            $outputPath,
        ], (int) config('render.timeouts.audio_pad'));

        $written = $this->ffmpeg->sampleCount($outputPath, deep: true);

        if ($written !== $target) {
            throw new FfmpegException(sprintf(
                'Padded audio is %d samples, expected exactly %d (%s).',
                $written,
                $target,
                basename($outputPath)
            ));
        }

        return [
            'output_path' => $outputPath,
            'frames' => $frames,
            'source_samples' => $source,
            'target_samples' => $target,
            'padding_samples' => $target - $source,
            'padding_ms' => ($target - $source) / ($rate / 1000),
        ];
    }
}
