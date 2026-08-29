<?php

namespace App\Jobs;

use App\Actions\RenderSceneClip;
use App\Enums\RenderStage;
use App\Exceptions\FfmpegException;
use App\Models\RenderJob;
use App\Models\Scene;
use App\Models\Story;
use App\Services\Ffmpeg;
use App\Support\RenderWorkspace;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * One scene's Ken Burns clip. The fan-out stage.
 *
 * 200 sequential 10-second renders is an overnight job, so these go out as a
 * batch and are picked up by however many `render` workers are running. The
 * work itself is RenderSceneClip, untouched.
 */
class RenderSceneClipJob extends RenderStageJob
{
    protected function stage(): RenderStage
    {
        return RenderStage::SceneClips;
    }

    protected function run(Story $story, RenderWorkspace $workspace, RenderJob $job): array
    {
        /** @var Scene $scene */
        $scene = Scene::query()->findOrFail($this->sceneId);

        if ($scene->duration_ms === null) {
            throw new RuntimeException("Scene {$scene->sequence} has no audio duration, so its frame count is unknowable.");
        }

        $output = $workspace->clipPath($scene);
        $expected = $scene->framesAt();

        $ffmpeg = app(Ffmpeg::class);

        // Idempotent, exactly as the CLI is: a clip that already holds the right
        // number of frames is not re-encoded. Re-running a completed stage must
        // not redo the work — at 200 scenes that is the difference between a
        // resumed batch and a repeated one.
        if ($this->existingClipIsComplete($ffmpeg, $output, $expected)) {
            $this->recordFrames($scene, $expected);

            return [$output, sprintf('kept existing clip, %d frames', $expected)];
        }

        $this->assertStillDecodes($ffmpeg, $workspace->sourcePath((string) $scene->image_path), $scene);

        $result = app(RenderSceneClip::class)->handle(
            imagePath: $workspace->sourcePath((string) $scene->image_path),
            outputPath: $output,
            audioDurationMs: $scene->duration_ms,
            motion: $scene->motion_preset,
        );

        // Decoded, not declared. A scene clip is seconds long so the decode is
        // cheap, and this is the assertion that catches zoompan's `d=`
        // disagreeing with the emitted frame count — the bug that made
        // `-frames:v` mandatory in the first place.
        $actual = $ffmpeg->frameCount($output, deep: true);

        if ($actual !== $result['frames']) {
            throw new RuntimeException(sprintf(
                'Scene %d rendered %d frames, expected %d. The clip does not match its audio, '
                .'and every offset after it would be wrong.',
                $scene->sequence,
                $actual,
                $result['frames']
            ));
        }

        $this->recordFrames($scene, $actual);

        return [$output, sprintf(
            '%d frames, %s, %.1fs',
            $actual,
            $scene->motion_preset->value,
            $result['elapsed_seconds']
        )];
    }

    /**
     * Whether an existing clip can be kept.
     *
     * The probe is wrapped because a half-written MP4 is a completely normal
     * thing to find here: a worker killed mid-encode, a timeout, a machine
     * reboot. ffprobe exits non-zero on such a file, and letting that surface
     * as the job's failure would leave the scene permanently stuck — every
     * retry failing on the wreckage of the previous one instead of replacing
     * it. An unreadable clip is not an error, it is an absent clip.
     */
    private function existingClipIsComplete(Ffmpeg $ffmpeg, string $output, int $expected): bool
    {
        if (! is_readable($output)) {
            return false;
        }

        try {
            return $ffmpeg->frameCount($output) === $expected;
        } catch (FfmpegException) {
            return false;
        }
    }

    /**
     * Refuse a still FFmpeg cannot decode, before FFmpeg gets to try.
     *
     * `-loop 1` does not fail on a corrupt image. It loops: FFmpeg prints
     * "Invalid PNG signature" over and over, emits no frames, and never exits.
     * The Symfony Process timeout does eventually kill it, but that is 15
     * minutes per bad still by default — three of them in a 200-scene batch
     * would stall the render for the better part of an hour before reporting
     * anything, and the operator page would show three jobs "running" the whole
     * time.
     *
     * One ffprobe call costs about 40 ms and turns that into an immediate,
     * readable failure naming the scene. The timeout stays as the backstop for
     * a file that probes clean and then hangs anyway.
     */
    private function assertStillDecodes(Ffmpeg $ffmpeg, string $path, Scene $scene): void
    {
        try {
            $stream = $ffmpeg->inspect($path)['stream'] ?? [];
        } catch (FfmpegException $e) {
            throw new RuntimeException(sprintf(
                "Scene %d's still is not a decodable image: %s
%s",
                $scene->sequence,
                $scene->image_path,
                Str::limit($e->getMessage(), 400)
            ), 0, $e);
        }

        // Zero, not absent. ffprobe exits 0 on a file whose PNG signature is
        // wrong and reports a png stream of 0x0 — the corruption shows up as
        // dimensions, not as an error code. Checking for the keys alone would
        // wave exactly the file this guard exists for straight through.
        if ((int) ($stream['width'] ?? 0) < 1 || (int) ($stream['height'] ?? 0) < 1) {
            throw new RuntimeException(sprintf(
                "Scene %d's still probes as %sx%s, so it holds no decodable image: %s",
                $scene->sequence,
                $stream['width'] ?? '?',
                $stream['height'] ?? '?',
                $scene->image_path
            ));
        }
    }

    /**
     * The frame count belongs on the scene's audio row: it is what the padding
     * target and every downstream offset are derived from.
     */
    private function recordFrames(Scene $scene, int $frames): void
    {
        $scene->sceneAudio()->update(['frames' => $frames]);
    }
}
