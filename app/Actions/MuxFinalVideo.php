<?php

namespace App\Actions;

use App\Exceptions\FfmpegException;
use App\Services\Ffmpeg;
use InvalidArgumentException;

/**
 * Step 3b: silent video + padded narration + burned-in subtitles -> final.mp4.
 *
 * This re-encodes the whole video and is the longest single operation in the
 * pipeline, hence the multi-hour timeout.
 *
 * Subtitles are burned in rather than muxed as a soft track: the animated
 * word-by-word highlight is the format's visual signature and cannot survive as
 * a selectable subtitle stream.
 *
 * Audio comes from the padded PCM, not from an MP3. Its sample count is exactly
 * frames x samples-per-frame, so the A/V relationship established in step 2
 * carries through.
 *
 * It is delivered as AAC (see render.audio.master_codec). The frame count is
 * asserted exactly and must be; the audio is asserted to within one AAC frame,
 * because AAC encodes in 1024-sample blocks with a priming delay and cannot
 * carry an arbitrary sample count. That residual is a single terminal boundary
 * artifact of one lossy encode, not accumulation — per-scene sync was fixed
 * exactly, in PCM, at concat.
 *
 * Verification reads the container's declared lengths rather than decoding the
 * finished file, which measured 404 s at 58 minutes. Pass $deep to decode.
 */
class MuxFinalVideo
{
    public function __construct(private readonly Ffmpeg $ffmpeg) {}

    /**
     * @param  bool|null  $deep  Verify by decoding rather than by reading the
     *                           container. Null follows render.verify.deep.
     * @return array{
     *     output_path: string,
     *     skipped_encode: bool,
     *     frames: int,
     *     expected_frames: int,
     *     frames_exact: bool,
     *     audio_samples: int,
     *     expected_samples: int,
     *     audio_delta_samples: int,
     *     audio_delta_ms: float,
     *     audio_exact: bool,
     *     audio_within_one_aac_frame: bool,
     *     decoded_samples: int|null,
     *     aac_padding_samples: int|null,
     *     codec: string,
     *     verified_by: string,
     *     video_duration_seconds: float,
     *     audio_duration_seconds: float,
     *     elapsed_seconds: float
     * }
     */
    public function handle(
        string $silentPath,
        string $audioPath,
        string $assPath,
        string $outputPath,
        int $expectedFrames,
        bool $force = false,
        ?bool $deep = null,
    ): array {
        foreach (['video' => $silentPath, 'audio' => $audioPath, 'subtitles' => $assPath] as $label => $path) {
            if (! is_readable($path)) {
                throw new InvalidArgumentException("Cannot read {$label} input: {$path}");
            }
        }

        $video = config('render.video');
        $audio = config('render.audio');

        $fps = (int) $video['fps'];
        $rate = (int) $audio['sample_rate'];

        if (! is_dir($directory = dirname($outputPath))) {
            mkdir($directory, 0775, true);
        }

        $startedAt = microtime(true);

        // Idempotent: a finished encode is not redone. At 35-40 minutes this is
        // a multi-hour operation in the worst case, so re-running to re-check
        // the output must not mean re-encoding it.
        if (! $force && is_readable($outputPath) && $this->ffmpeg->frameCount($outputPath, $deep) === $expectedFrames) {
            return $this->inspect($outputPath, $expectedFrames, $fps, $rate, 0.0, true, $deep);
        }

        $this->ffmpeg->run([
            '-y',
            '-loglevel', 'error',
            '-i', $silentPath,
            '-i', $audioPath,
            // Explicit mapping. Default stream selection would happen to pick
            // these two, but only because each input carries exactly one
            // stream — that is a coincidence, not a guarantee.
            '-map', '0:v:0',
            '-map', '1:a:0',
            // The one place a filesystem path enters a filter graph, and the
            // only reason escapeFilterPath() exists.
            '-vf', 'ass='.Ffmpeg::escapeFilterPath($assPath),
            '-c:v', 'libx264',
            '-preset', (string) $video['preset'],
            '-crf', (string) $video['crf'],
            '-pix_fmt', 'yuv420p',
            ...$this->audioCodecArguments($audio),
            '-ar', (string) $rate,
            '-ac', (string) (int) $audio['channels'],
            // Harmless here: both inputs are the same exact length by
            // construction, so there is nothing for this to cut.
            '-shortest',
            '-movflags', '+faststart',
            $outputPath,
        ], (int) config('render.timeouts.mux'));

        return $this->inspect(
            $outputPath,
            $expectedFrames,
            $fps,
            $rate,
            round(microtime(true) - $startedAt, 1),
            false,
            $deep,
        );
    }

    /**
     * Delivery codec for the final file.
     *
     * AAC ships. 192k mono is transparent for narration, YouTube re-encodes on
     * ingest regardless, and PCM would roughly double the upload for no audible
     * gain — the price is a sub-millisecond sample delta at the very end of the
     * file, which does not accumulate because sync was already fixed exactly in
     * PCM at concat. PCM stays available behind the config flag for the case
     * where a sample-exact master is genuinely wanted.
     *
     * @param  array<string, mixed>  $audio
     * @return array<int, string>
     */
    private function audioCodecArguments(array $audio): array
    {
        if ($this->codec() === 'pcm') {
            return ['-c:a', (string) $audio['pcm_codec']];
        }

        return ['-c:a', 'aac', '-b:a', (string) ($audio['aac_bitrate'] ?? $audio['mp3_bitrate'])];
    }

    private function codec(): string
    {
        $codec = strtolower((string) config('render.audio.master_codec', 'aac'));

        if (! in_array($codec, ['aac', 'pcm'], true)) {
            throw new InvalidArgumentException(
                "Unknown render.audio.master_codec '{$codec}'. Expected 'aac' or 'pcm'."
            );
        }

        return $codec;
    }

    private function deep(?bool $deep): bool
    {
        return $deep ?? (bool) config('render.verify.deep', false);
    }

    /**
     * @return array<string, mixed>
     */
    private function inspect(
        string $outputPath,
        int $expectedFrames,
        int $fps,
        int $rate,
        float $elapsed,
        bool $skippedEncode,
        ?bool $deep,
    ): array {
        // Declared, not decoded. Fully decoding a finished 58-minute file to
        // count frames and samples measured 404 s — a third of the mux stage
        // itself, paid on every render. The container's own numbers are what a
        // player and YouTube's ingest honour, and they agreed with the decoded
        // counts in every measured case. --deep re-checks by decoding when a
        // render is actually under suspicion.
        $frames = $this->ffmpeg->frameCount($outputPath, $deep);

        if ($frames === 0) {
            throw new FfmpegException("Muxed output has no video frames: {$outputPath}");
        }

        // The presented length, after the edit list — what a player renders.
        // Always declared: this IS the container's number, and there is no
        // decoded equivalent of it.
        $samples = $this->ffmpeg->presentedSampleCount($outputPath);

        // Raw decoded samples, including AAC priming and frame padding that no
        // player renders. Interesting when explaining the delta below, not worth
        // minutes of decode to print on a healthy render.
        $decoded = $this->deep($deep) ? $this->ffmpeg->decodedSampleCount($outputPath) : null;

        // What the exact PCM upstream guarantees.
        $samplesPerFrame = intdiv($rate, $fps);
        $expectedSamples = $expectedFrames * $samplesPerFrame;

        $delta = $samples - $expectedSamples;

        return [
            'output_path' => $outputPath,
            'skipped_encode' => $skippedEncode,

            // Video is lossless through the mux in the only sense that matters:
            // the frame count is preserved exactly. This is a hard requirement.
            'frames' => $frames,
            'expected_frames' => $expectedFrames,
            'frames_exact' => $frames === $expectedFrames,

            'audio_samples' => $samples,
            'expected_samples' => $expectedSamples,
            'audio_delta_samples' => $delta,
            'audio_delta_ms' => $delta / ($rate / 1000),

            // AAC cannot be relied on to preserve a sample count: it encodes in
            // 1024-sample frames with a priming delay, and the container's edit
            // list lands 0, 15 or 29 samples short depending on how the total
            // divides. Measured, not assumed. This is a terminal boundary
            // artifact of the final single encode — it does not accumulate,
            // because per-scene sync was already fixed in PCM at concat, where
            // the assertion IS exact.
            'audio_exact' => $delta === 0,
            // Only a lossy codec earns this allowance. A PCM master carries the
            // sample count verbatim, so under PCM anything but exact is a bug.
            'audio_within_one_aac_frame' => $this->codec() === 'aac' && abs($delta) <= 1024,

            'decoded_samples' => $decoded,
            'aac_padding_samples' => $decoded === null ? null : $decoded - $samples,
            'codec' => $this->codec(),
            'verified_by' => $this->ffmpeg->verificationDepth($deep),
            'video_duration_seconds' => $frames / $fps,
            'audio_duration_seconds' => $samples / $rate,
            'elapsed_seconds' => $elapsed,
        ];
    }
}
