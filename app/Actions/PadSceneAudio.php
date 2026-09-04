<?php

namespace App\Actions;

use App\Exceptions\FfmpegException;
use App\Services\Ffmpeg;
use App\Support\AudioFrames;
use App\Support\Directory;
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
     * Kept as a name callers already use; the arithmetic lives in AudioFrames
     * so there is one copy of it. There were three — here, in SceneTimeline and
     * inline in the guard below — and three copies of an expression is three
     * chances for one of them to be corrected alone.
     */
    public static function samplesPerFrame(int $sampleRate, int $fps): int
    {
        return AudioFrames::samplesPerFrame($sampleRate, $fps);
    }

    /**
     * @return array{
     *     output_path: string,
     *     frames: int,
     *     source_samples: int,
     *     source_rate: int,
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

        $target = $frames * AudioFrames::samplesPerFrame($rate, (int) config('render.video.fps'));

        // Decoded, explicitly, not the container's declared length. This number
        // decides how much silence is appended and guards against apad,atrim
        // turning into a trim, so it has to be the samples that actually exist
        // — an MP3's declared duration and its decoded length are not the same
        // thing. Scene audio is seconds long, so the decode is cheap; the deep
        // check that is NOT affordable is the one on the full-length files.
        $source = $this->ffmpeg->sampleCount($sourceAudioPath, deep: true);

        // The source's OWN rate, which is not necessarily the render's.
        //
        // Every count below has to be compared in one rate domain, and for a
        // whole phase they were not — they only agreed because the fake
        // synthesizer writes at render.audio.sample_rate, so source and target
        // were the same number by construction. Real vendor audio arrives at
        // whatever the TTS output format says: ElevenLabs `pcm_24000` is 24 kHz
        // against a 44.1 kHz render.
        $sourceRate = $this->ffmpeg->sampleRate($sourceAudioPath);

        // Source length expressed at the RENDER rate, so the guard below
        // compares like with like. Without this a 48 kHz source would report
        // more samples than a 44.1 kHz target can hold and fail as "longer than
        // its frame count allows" while being nothing of the kind.
        //
        // Shared with AudioFrames::forSamples() rather than written out again.
        // The number that decides how many frames to allocate and the number
        // that decides whether padding would become a trim must be the same
        // number — two copies of one expression is how they come to disagree.
        $sourceAtRenderRate = AudioFrames::atRenderRate($source, $sourceRate, $rate);

        // apad,atrim would silently CUT a scene whose audio runs past its frame
        // count, clipping the tail of the last word. That must never happen —
        // ceil() guarantees it cannot, so if it does, the frame count is wrong.
        if ($sourceAtRenderRate > $target) {
            throw new FfmpegException(sprintf(
                'Scene audio is longer than its frame count allows: %s has %d samples at %d Hz '
                .'(%d at the %d Hz render rate) but only %d fit in %d frames. Padding would become '
                ."a trim.\n"
                .'Check that frames were computed with ceil() from this exact file.',
                basename($sourceAudioPath),
                $source,
                $sourceRate,
                $sourceAtRenderRate,
                $rate,
                $target,
                $frames
            ));
        }

        Directory::ensure(dirname($outputPath));

        $this->ffmpeg->run([
            '-y',
            '-loglevel', 'error',
            '-i', $sourceAudioPath,
            // `aresample` FIRST, and its position is the whole bug.
            //
            // `-af` filters run before the output resampling that `-ar` implies,
            // so `atrim=end_sample=` counts samples at the INPUT rate. With a
            // 24 kHz source and a 44.1 kHz render that trimmed to 784,980
            // samples of 24 kHz audio — 32.7 seconds — which `-ar` then
            // resampled up to 1,442,401 samples. Exactly target × 44100/24000,
            // and a scene running 84% too long.
            //
            // It never showed while the only audio reaching here came from the
            // fake synthesizer, which writes at render.audio.sample_rate: input
            // and output rates were equal, so the domains coincided and the
            // filter was accidentally right. Resampling inside the chain makes
            // `end_sample` mean samples at the render rate, which is what every
            // number downstream already assumes it means.
            '-af', 'aresample='.$rate.',apad,atrim=end_sample='.$target,
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
            'source_rate' => $sourceRate,
            'target_samples' => $target,
            // Both expressed at the RENDER rate. Subtracting a source-rate count
            // from a render-rate one reported 24 kHz scenes as gaining ~358,000
            // samples of "padding" when the real figure is under one frame.
            'padding_samples' => $target - $sourceAtRenderRate,
            'padding_ms' => ($target - $sourceAtRenderRate) / ($rate / 1000),
        ];
    }
}
