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
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

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

        $output = $workspace->clipPath($scene);
        $ffmpeg = app(Ffmpeg::class);

        // Fill in the true length for any row written before the sample columns
        // existed, once, at the only point that already has both the file and a
        // probe to hand. Without it framesAt() falls back to milliseconds and a
        // scene whose duration lands on a frame boundary comes out one frame
        // short of its own audio — story 21 scene 201, four samples over.
        //
        // Ahead of the guard below, deliberately. It can answer the question
        // the guard asks, from the file, whenever a path is present — so
        // running it second meant rejecting rows this stage could have
        // repaired itself.
        $this->backfillSampleCount($ffmpeg, $workspace, $scene);

        $expected = $scene->framesAt();

        /*
         * ASK THE QUESTION THE MESSAGE STATES.
         *
         * This used to be `$scene->duration_ms === null`, checked before the
         * backfill above. That is ONE of the two inputs a frame count can come
         * from, and it is the FALLBACK one: `framesAt()` prefers
         * `scene_audio.samples` and only reaches `scenes.duration_ms` when the
         * sample count is absent, because a millisecond cannot represent where
         * audio ends.
         *
         * So the guard rejected on a field the arithmetic would not have read.
         * Story 25 scenes 169, 180, 193 and 201 had 587,372 / 235,172 / 353,315
         * / 672,078 samples at 24 kHz sitting in the row — 735, 294, 442 and
         * 841 frames, computable exactly — and were refused because a repair
         * had restored `scene_audio.duration_ms` and not the copy on `scenes`.
         *
         * `framesAt()` returns `?int` and is null precisely when the count is
         * unknowable, which is what the sentence below has always claimed to
         * be about. A guard whose message names a question its check never asks
         * is the proxy-for-the-real-thing shape this project keeps finding.
         */
        if ($expected === null) {
            throw new RuntimeException(
                "Scene {$scene->sequence} has no audio duration, so its frame count is unknowable."
            );
        }

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
            // Exact, from the sample count. Passing the duration and letting the
            // renderer re-derive would reintroduce the rounding this job just
            // avoided, and the two would disagree by one frame on about one
            // scene in a hundred.
            frames: $expected,
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
     * Record the audio's true length on a row that predates the sample columns.
     *
     * A one-off per scene, and deliberately lazy rather than a migration or a
     * backfill command: this is the only place in the pipeline that already
     * holds both the audio file and an ffprobe, so filling it here costs one
     * probe on a row that has never been probed and nothing at all afterwards.
     *
     * Never fatal. This is an optimisation of accuracy, not a precondition:
     * a file ffprobe cannot count samples in — a fixture stub, a format it
     * does not decode — leaves the columns null and framesAt() falls back to
     * milliseconds, which is exactly the behaviour that existed before these
     * columns. And the fallback is not unguarded: PadSceneAudio refuses at
     * concat if a frame count cannot hold its audio, which is the check that
     * found this bug in the first place.
     *
     * Logged rather than swallowed, at debug, because on fixture stories this
     * is the normal case and a warning per scene would be noise.
     */
    private function backfillSampleCount(Ffmpeg $ffmpeg, RenderWorkspace $workspace, Scene $scene): void
    {
        $audio = $scene->sceneAudio->first();

        if ($audio === null || $audio->audio_path === null) {
            return;
        }

        if ($audio->samples !== null && $audio->sample_rate !== null) {
            return;
        }

        $path = $workspace->sourcePath((string) $audio->audio_path);

        if (! is_readable($path)) {
            return;
        }

        try {
            $samples = $ffmpeg->sampleCount($path, deep: true);
            $sampleRate = $ffmpeg->sampleRate($path);
        } catch (Throwable $e) {
            Log::debug('could not read a sample count; frames fall back to milliseconds for this scene', [
                'scene' => $scene->sequence,
                'path' => $audio->audio_path,
                'reason' => $e->getMessage(),
            ]);

            return;
        }

        // A non-positive reading is not a length. Storing one would make
        // framesAt() throw instead of falling back, turning a probe that
        // could not answer into a failed render.
        if ($samples <= 0 || $sampleRate <= 0) {
            return;
        }

        $audio->forceFill([
            'samples' => $samples,
            'sample_rate' => $sampleRate,
        ])->save();

        $scene->load('sceneAudio');
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
