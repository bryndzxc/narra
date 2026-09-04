<?php

namespace App\Actions;

use App\Enums\MotionPreset;
use App\Services\Ffmpeg;
use App\Support\AudioFrames;
use App\Support\Directory;
use InvalidArgumentException;

/**
 * Step 1 of the render pipeline: one still plus Ken Burns motion becomes one
 * scene clip.
 *
 * Every clip this produces shares identical codec parameters, which is what
 * lets step 2 concatenate with the demuxer and `-c copy` rather than
 * re-encoding.
 */
class RenderSceneClip
{
    public function __construct(private readonly Ffmpeg $ffmpeg) {}

    /**
     * Frames a clip must contain for a given audio duration.
     *
     * ceil, deliberately. It guarantees video >= audio for every scene, so the
     * padding applied at mux only ever ADDS silence — at most one frame
     * (~33ms at 30fps), average ~16ms. round() would sometimes leave the video
     * shorter than its audio and force a trim that can clip the tail of the
     * last word. Do not "optimize" this to round().
     *
     * Off by one frame causes a visible stutter at every scene boundary, and
     * across 200 scenes a small drift becomes seconds of audio desync. Compute
     * it, never guess it.
     *
     * **This is the APPROXIMATE path and callers should prefer the exact one.**
     * A duration in milliseconds has already lost the sub-millisecond remainder
     * — 360002 samples at 24 kHz is 15.0000833 s and arrives here as 15000 —
     * and where that lands exactly on a frame boundary the ceil() has no
     * headroom left to absorb it, so the clip comes out one frame short of its
     * own audio. Anything that can reach the audio file should compute from the
     * sample count instead: `Scene::framesAt()` does, via AudioFrames.
     *
     * Kept because fixture stories carry a duration and no samples.
     */
    public static function framesFor(int $audioDurationMs, int $fps): int
    {
        return AudioFrames::forMilliseconds($audioDurationMs, $fps);
    }

    /**
     * @param  int  $audioDurationMs  The scene's RAW audio duration. Reported,
     *                                and used for the padding figure — but no
     *                                longer the source of the frame count.
     * @param  int|null  $frames  The exact frame count, computed from the audio's
     *                            sample count. Null falls back to the millisecond
     *                            derivation, which is correct only when the
     *                            samples are genuinely unavailable.
     * @return array{
     *     output_path: string,
     *     audio_duration_ms: int,
     *     frames: int,
     *     clip_duration_ms: float,
     *     padding_ms: float,
     *     motion_preset: string,
     *     filter: string,
     *     elapsed_seconds: float
     * }
     */
    public function handle(
        string $imagePath,
        string $outputPath,
        int $audioDurationMs,
        MotionPreset $motion,
        ?int $frames = null,
    ): array {
        if ($audioDurationMs <= 0) {
            throw new InvalidArgumentException("Scene duration must be positive, got {$audioDurationMs}ms.");
        }

        if (! is_readable($imagePath)) {
            throw new InvalidArgumentException("Source still not readable: {$imagePath}");
        }

        $video = config('render.video');
        $fps = (int) $video['fps'];

        // Given by the caller wherever the true sample count is known, which
        // is every production path. Derived from milliseconds only for fixture
        // stories, which carry a duration in JSON and nothing else — see
        // framesFor(), and AudioFrames for why that is the lossy option.
        $frames ??= self::framesFor($audioDurationMs, $fps);
        $filter = $this->filterGraph($motion, $frames);

        Directory::ensure($directory = dirname($outputPath));

        $startedAt = microtime(true);

        $this->ffmpeg->run([
            '-y',
            '-loglevel', 'error',
            '-loop', '1',
            '-i', $imagePath,
            '-filter_complex', $filter,
            // -frames:v, never -t. They disagree: -t yields
            // round(seconds * fps) while zoompan's d= takes the frame count
            // directly, and when they disagree -t wins — so the emitted clip
            // does not match d=, the zoom never completes, and the frame count
            // is unpredictable. -frames:v makes output frames exactly equal d=
            // by construction. Frame count is authoritative; the audio is
            // padded to it at mux.
            '-frames:v', (string) $frames,
            '-c:v', 'libx264',
            '-preset', (string) $video['preset'],
            '-crf', (string) $video['crf'],
            // The still carries no audio track; saying so keeps the stream
            // layout identical across every clip, which the concat demuxer
            // in step 2 requires.
            '-an',
            $outputPath,
        ], (int) config('render.timeouts.scene_clip'));

        // Clip duration is exact by construction: frames / fps. The silence
        // needed to bring this scene's audio up to it is reported here for the
        // mux stage, which is where the padding is actually applied.
        $clipDurationMs = $frames / $fps * 1000;

        return [
            'output_path' => $outputPath,
            'audio_duration_ms' => $audioDurationMs,
            'frames' => $frames,
            'clip_duration_ms' => $clipDurationMs,
            'padding_ms' => $clipDurationMs - $audioDurationMs,
            'motion_preset' => $motion->value,
            'filter' => $filter,
            'elapsed_seconds' => round(microtime(true) - $startedAt, 2),
        ];
    }

    /**
     * scale -> zoompan -> format.
     *
     * The upscale is not cosmetic: zoompan applied directly at output
     * resolution jitters, because it can only move the crop window in whole
     * source pixels. Zooming on a 2x source and downscaling to 1080p gives the
     * motion sub-pixel resolution.
     */
    private function filterGraph(MotionPreset $motion, int $frames): string
    {
        $video = config('render.video');

        return implode(',', [
            // -2 keeps the height even, which libx264 with yuv420p requires.
            sprintf('scale=%d:-2', (int) $video['upscale_width']),
            $this->zoompan($motion, $frames),
            'format=yuv420p',
        ]);
    }

    private function zoompan(MotionPreset $motion, int $frames): string
    {
        $video = config('render.video');
        $motionConfig = config('render.motion');

        $rate = (float) $motionConfig['zoom_rate'];
        $max = (float) $motionConfig['zoom_max'];
        $panZoom = (float) $motionConfig['pan_zoom'];

        // Guard the pan divisor: a one-frame clip would divide by zero.
        $travel = max(1, $frames - 1);

        $centreX = 'iw/2-(iw/zoom/2)';
        $centreY = 'ih/2-(ih/zoom/2)';

        [$z, $x, $y] = match ($motion) {
            // Spec form, verbatim. `zoom` is the previous frame's value and
            // initialises to 1.
            MotionPreset::ZoomIn => [
                sprintf('min(zoom+%s,%s)', $this->number($rate), $this->number($max)),
                $centreX,
                $centreY,
            ],

            // Must be written against `on` (the output frame number) rather
            // than mirrored from the zoom-in form. zoompan initialises `zoom`
            // to 1.0, so a decrementing max(zoom-rate,1.0) would clamp at 1.0
            // on the very first frame and never move at all.
            MotionPreset::ZoomOut => [
                sprintf('max(%s-%s*on,1.0)', $this->number($max), $this->number($rate)),
                $centreX,
                $centreY,
            ],

            // A pan needs somewhere to pan to, so it holds a constant zoom and
            // travels the crop window across the width the zoom freed up.
            MotionPreset::PanRight => [
                $this->number($panZoom),
                sprintf('(iw-iw/zoom)*on/%d', $travel),
                $centreY,
            ],

            MotionPreset::PanLeft => [
                $this->number($panZoom),
                sprintf('(iw-iw/zoom)*(1-on/%d)', $travel),
                $centreY,
            ],

            // Still held at 1:1. Kept on the zoompan path rather than a plain
            // scale so every clip leaves the same filter chain with the same
            // stream parameters.
            MotionPreset::Static => ['1', $centreX, $centreY],
        };

        // The single quotes are part of the filter graph, not shell quoting —
        // they protect the commas inside min()/max() from being read as filter
        // separators. Symfony Process passes this through as one argv element,
        // so no shell ever sees it.
        return sprintf(
            "zoompan=z='%s':d=%d:x='%s':y='%s':s=%dx%d:fps=%d",
            $z,
            $frames,
            $x,
            $y,
            (int) $video['width'],
            (int) $video['height'],
            (int) $video['fps'],
        );
    }

    /**
     * Format a float for a filter expression without locale decimal commas —
     * a comma here would silently split the filter graph.
     */
    private function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 6, '.', ''), '0'), '.');
    }
}
