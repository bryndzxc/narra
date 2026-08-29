<?php

namespace App\Jobs;

use App\Actions\ConcatSceneAudio;
use App\Actions\ConcatVideoClips;
use App\Actions\PadSceneAudio;
use App\Enums\RenderStage;
use App\Models\Act;
use App\Models\RenderJob;
use App\Models\Scene;
use App\Models\SceneAudio;
use App\Models\Story;
use App\Services\Ffmpeg;
use App\Support\RenderWorkspace;
use App\Support\SceneAudioManifest;
use App\Support\SceneTimeline;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Step 2: pad every scene's audio to its frame boundary, join both streams, and
 * write the timeline the rest of the pipeline reads.
 *
 * Single job, not a fan-out. The padding is per scene but it is seconds of work
 * in total, and the concat itself is a stream copy — splitting it would buy
 * nothing and would put a barrier between two halves of one assertion.
 *
 * This is where the exact-duration guarantee is established:
 *
 *     audio_samples * fps == video_frames * sample_rate
 *
 * Cross-multiplied integers, no tolerance, on PCM where exactness is
 * achievable. If this passes, the video cannot drift; if it fails, nothing
 * downstream is worth rendering.
 */
class ConcatRenderJob extends RenderStageJob
{
    /** Scenes between heartbeats while padding. */
    private const HEARTBEAT_EVERY = 25;

    protected function stage(): RenderStage
    {
        return RenderStage::Concat;
    }

    protected function run(Story $story, RenderWorkspace $workspace, RenderJob $job): array
    {
        $ffmpeg = app(Ffmpeg::class);

        $fps = (int) config('render.video.fps');
        $rate = (int) config('render.audio.sample_rate');
        $samplesPerFrame = PadSceneAudio::samplesPerFrame($rate, $fps);

        $plan = $this->buildPlan($story, $workspace, $ffmpeg, $fps, $samplesPerFrame);

        $this->padEveryScene($plan, $ffmpeg, $job);

        $video = app(ConcatVideoClips::class)->handle(
            clipPaths: array_column($plan, 'clip_path'),
            expectedFrames: array_column($plan, 'frames'),
            outputPath: $workspace->path('silent.mp4'),
            listPath: $workspace->path('clips.txt'),
        );

        $audio = app(ConcatSceneAudio::class)->handle(
            paddedPaths: array_column($plan, 'padded_path'),
            expectedSamples: array_column($plan, 'padded_samples'),
            wavPath: $workspace->path('narration.wav'),
            mp3Path: $workspace->path('narration.mp3'),
            listPath: $workspace->path('narration.txt'),
        );

        $this->assertExactDurations($audio['actual_samples'], $video['actual_frames'], $fps, $rate);

        $this->persistTimeline($story, $plan);

        Storage::disk('renders')->put(
            $workspace->slug.'/scene_audio.json',
            SceneAudioManifest::encode($plan, $fps, $rate, $video['actual_frames'], $audio['actual_samples'])
        );

        return [$workspace->path('silent.mp4'), sprintf(
            '%d scenes, %d frames, %d samples, %.3f s, verified by %s',
            count($plan),
            $video['actual_frames'],
            $audio['actual_samples'],
            $video['actual_frames'] / $fps,
            $video['verified_by']
        )];
    }

    /**
     * Resolve every scene into the numbers this stage works from, and refuse to
     * continue if step 1's output disagrees with them.
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildPlan(
        Story $story,
        RenderWorkspace $workspace,
        Ffmpeg $ffmpeg,
        int $fps,
        int $samplesPerFrame,
    ): array {
        $scenes = $story->scenes()->with(['sceneAudio', 'act'])->get();

        if ($scenes->isEmpty()) {
            throw new RuntimeException("Story {$story->slug} has no scenes.");
        }

        $resolved = [];

        foreach ($scenes as $scene) {
            /** @var Scene $scene */
            $audio = $scene->sceneAudio->first();

            if ($audio === null || $audio->audio_path === null) {
                throw new RuntimeException("Scene {$scene->sequence} has no narration audio.");
            }

            $clip = $workspace->clipPath($scene);

            if (! is_readable($clip)) {
                throw new RuntimeException("Missing clip for scene {$scene->sequence}. Re-run the scene-clip stage.");
            }

            $frames = $scene->framesAt($fps);
            $actual = $ffmpeg->frameCount($clip);

            // The padding target comes from the frame count, so a stale clip
            // would pad this scene's audio to the wrong length and desync every
            // scene after it — silently.
            if ($actual !== $frames) {
                throw new RuntimeException(sprintf(
                    'Scene %d holds %d frames but its audio needs %d. The clip is stale.',
                    $scene->sequence,
                    $actual,
                    $frames
                ));
            }

            $resolved[] = [
                'scene_id' => $scene->id,
                'scene_audio_id' => $audio->id,
                'act_id' => $scene->act_id,
                'sequence' => $scene->sequence,
                'act_sequence' => $scene->act->sequence,
                'slug' => $workspace->sceneSlug($scene),
                'clip_path' => $clip,
                'source_audio' => $workspace->sourcePath((string) $audio->audio_path),
                'padded_path' => $workspace->paddedAudioPath($scene),
                'audio_duration_ms' => (int) $scene->duration_ms,
                'frames' => $frames,
            ];
        }

        // Offsets, padded durations and totals all come from one tested place
        // rather than being accumulated inline. They accumulate in integer
        // frames and samples; offset_ms is derived from frames for display and
        // is never used for timing.
        $timeline = new SceneTimeline(
            array_column($resolved, 'frames'),
            $fps,
            $samplesPerFrame * $fps,
        );

        foreach ($timeline->entries() as $i => $entry) {
            $resolved[$i] = array_merge($resolved[$i], $entry);
        }

        return $resolved;
    }

    /**
     * @param  array<int, array<string, mixed>>  $plan
     */
    private function padEveryScene(array $plan, Ffmpeg $ffmpeg, RenderJob $job): void
    {
        $padder = app(PadSceneAudio::class);

        foreach ($plan as $i => $scene) {
            // Idempotent: correctly padded audio is left alone. Re-running this
            // stage after a failure further down must not redo 200 encodes.
            if (is_readable($scene['padded_path'])
                && $ffmpeg->sampleCount($scene['padded_path']) === $scene['padded_samples']) {
                continue;
            }

            $padder->handle($scene['source_audio'], $scene['padded_path'], $scene['frames']);

            // Each pad is sub-second, so the wrapper's own time-based heartbeat
            // may never fire inside one. At 200 scenes the loop itself is long
            // enough to look hung without this.
            if ($i % self::HEARTBEAT_EVERY === 0) {
                $job->heartbeat();
            }
        }
    }

    private function assertExactDurations(int $samples, int $frames, int $fps, int $rate): void
    {
        // Integers, cross-multiplied, so no float enters the decision:
        // samples/rate == frames/fps  <=>  samples*fps == frames*rate.
        if ($samples * $fps !== $frames * $rate) {
            throw new RuntimeException(sprintf(
                'A/V duration mismatch: %d samples * %d fps = %d, %d frames * %d Hz = %d (%+.4f ms). '
                .'With padding this is a by-construction guarantee, so it is a bug, not tolerance.',
                $samples, $fps, $samples * $fps,
                $frames, $rate, $frames * $rate,
                ($samples / $rate - $frames / $fps) * 1000
            ));
        }
    }

    /**
     * Write the computed timeline back onto scene_audio and acts.
     *
     * The act timings are what YouTube chapters are built from, and this is the
     * first moment they can exist: they are the render's numbers, derived from
     * real frame counts, not an estimate made when the script was written.
     *
     * @param  array<int, array<string, mixed>>  $plan
     */
    private function persistTimeline(Story $story, array $plan): void
    {
        $fps = (int) config('render.video.fps');
        $acts = [];

        foreach ($plan as $scene) {
            SceneAudio::query()->whereKey($scene['scene_audio_id'])->update([
                'frames' => $scene['frames'],
                'padded_duration_ms' => $scene['padded_duration_ms'],
                // Authoritative, integer, no rounding anywhere in the chain.
                'offset_frames' => $scene['offset_frames'],
                'offset_samples' => $scene['offset_samples'],
                // Derived from offset_frames. Display only.
                'offset_ms' => $scene['offset_ms'],
            ]);

            $actId = $scene['act_id'];

            $acts[$actId] ??= ['start_frames' => $scene['offset_frames'], 'frames' => 0];
            $acts[$actId]['start_frames'] = min($acts[$actId]['start_frames'], $scene['offset_frames']);
            $acts[$actId]['frames'] += $scene['frames'];
        }

        foreach ($acts as $actId => $timing) {
            Act::query()->whereKey($actId)->update([
                'start_ms' => (int) round($timing['start_frames'] / $fps * 1000),
                'duration_ms' => (int) round($timing['frames'] / $fps * 1000),
            ]);
        }
    }
}
