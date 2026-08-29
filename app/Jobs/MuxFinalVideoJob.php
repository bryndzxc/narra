<?php

namespace App\Jobs;

use App\Actions\MuxFinalVideo;
use App\Enums\RenderStage;
use App\Enums\StoryStatus;
use App\Models\RenderJob;
use App\Models\Story;
use App\Support\RenderWorkspace;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Step 3b: the final encode, and Gate 3.
 *
 * The longest single operation in the pipeline — it re-encodes the whole video
 * to burn in the subtitles — so this is the job the heartbeat exists for. A
 * 40-minute render is tens of minutes of silence from the worker's point of
 * view, and `queue:work --timeout` cannot tell hung from busy on this platform.
 *
 * On success the story lands on `rendered`, where Gate 3 waits for a human to
 * watch it. Nothing here advances past that gate.
 */
class MuxFinalVideoJob extends RenderStageJob
{
    protected function stage(): RenderStage
    {
        return RenderStage::Mux;
    }

    protected function run(Story $story, RenderWorkspace $workspace, RenderJob $job): array
    {
        $manifestPath = $workspace->slug.'/scene_audio.json';

        if (! Storage::disk('renders')->exists($manifestPath)) {
            throw new RuntimeException('No scene_audio.json. The concat stage has not run.');
        }

        $manifest = json_decode(Storage::disk('renders')->get($manifestPath), true);
        $expectedFrames = (int) $manifest['story']['total_frames'];

        $result = app(MuxFinalVideo::class)->handle(
            silentPath: $workspace->path('silent.mp4'),
            audioPath: $workspace->path('narration.wav'),
            assPath: $workspace->path('subs.ass'),
            outputPath: $workspace->path('final.mp4'),
            expectedFrames: $expectedFrames,
        );

        // Video: exact, and required to be. A frame lost here is a frame of
        // desync that grows on nothing but is wrong from the moment it happens.
        if (! $result['frames_exact']) {
            throw new RuntimeException(sprintf(
                'Muxed video holds %d frames, expected %d (%+d).',
                $result['frames'],
                $result['expected_frames'],
                $result['frames'] - $result['expected_frames']
            ));
        }

        // Audio: bounded, not exact, and deliberately so. AAC encodes in
        // 1024-sample frames with a priming delay and cannot carry an arbitrary
        // sample count; the measured residual is 0, 15 or 29 samples. Sync was
        // already fixed exactly in PCM at concat, so this is one terminal
        // boundary artifact rather than accumulating drift.
        if (! $result['audio_exact'] && ! $result['audio_within_one_aac_frame']) {
            throw new RuntimeException(sprintf(
                'Muxed audio is %+d samples (%+.4f ms) from the exact PCM, beyond one AAC frame. '
                .'That is not codec quantisation.',
                $result['audio_delta_samples'],
                $result['audio_delta_ms']
            ));
        }

        // Gate 3 now waits on the operator.
        if ($story->status === StoryStatus::Rendering) {
            $story->transitionTo(StoryStatus::Rendered);
        }

        return [$result['output_path'], sprintf(
            '%d frames, %s master, audio %+d samples (%+.4f ms), %s, %.1fs',
            $result['frames'],
            $result['codec'],
            $result['audio_delta_samples'],
            $result['audio_delta_ms'],
            $result['skipped_encode'] ? 'existing encode verified' : 'encoded',
            $result['elapsed_seconds']
        )];
    }
}
