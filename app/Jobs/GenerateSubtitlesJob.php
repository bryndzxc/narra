<?php

namespace App\Jobs;

use App\Actions\GenerateAssSubtitles;
use App\Actions\VerifyAssSubtitles;
use App\Enums\RenderStage;
use App\Models\RenderJob;
use App\Models\Story;
use App\Support\RenderWorkspace;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Step 3a: write subs.ass from the word timings, then verify it.
 *
 * The inputs come from the database — narration and word timings from
 * scene_audio, offsets from the concat stage — rather than from the fixture
 * manifest. The verification is not optional: a subtitle file that drifts from
 * the audio is invisible until the video is watched, and by then a 40-minute
 * mux has already been paid for.
 */
class GenerateSubtitlesJob extends RenderStageJob
{
    protected function stage(): RenderStage
    {
        return RenderStage::Subtitles;
    }

    protected function run(Story $story, RenderWorkspace $workspace, RenderJob $job): array
    {
        $manifestPath = $workspace->slug.'/scene_audio.json';

        if (! Storage::disk('renders')->exists($manifestPath)) {
            throw new RuntimeException('No scene_audio.json. The concat stage has not run.');
        }

        $manifest = json_decode(Storage::disk('renders')->get($manifestPath), true);

        [$scenes, $sceneAudio] = $this->inputsFromDatabase($story);

        $outputPath = $workspace->path('subs.ass');

        $result = app(GenerateAssSubtitles::class)->handle(
            scenes: $scenes,
            sceneAudio: $sceneAudio,
            outputPath: $outputPath,
            title: $story->title,
        );

        $job->heartbeat();

        // The video is the authority on total length, from the concat stage.
        // Passed as frames so the check can compare at ASS's own centisecond
        // resolution rather than rounding a duration twice.
        $checks = app(VerifyAssSubtitles::class)->handle(
            $outputPath,
            $scenes,
            $sceneAudio,
            (int) $manifest['story']['total_frames'],
            (int) config('render.video.fps'),
        );

        $failures = [];

        foreach ($checks as $name => $check) {
            if (! $check['pass']) {
                $failures[] = $name.': '.$check['detail'].implode('', array_map(
                    fn (string $failure): string => "\n    - ".$failure,
                    array_slice($check['failures'], 0, 5)
                ));
            }
        }

        if ($failures !== []) {
            throw new RuntimeException(
                "Subtitle verification failed:\n".implode("\n", $failures)
            );
        }

        return [$outputPath, sprintf(
            '%d lines, %d words, %s bytes, %d checks passed',
            $result['lines'],
            $result['words'],
            number_format($result['bytes']),
            count($checks)
        )];
    }

    /**
     * Rebuild the two arrays the subtitle actions expect, from model rows.
     *
     * Same shape the fixture manifest had, because the actions are unchanged —
     * the source moved into the database, the contract did not.
     *
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, array<string, mixed>>}
     */
    private function inputsFromDatabase(Story $story): array
    {
        $scenes = [];
        $sceneAudio = [];

        // Derived rather than stored: samples per frame is exact (44100/30 =
        // 1470) and a second stored copy of a duration is how two answers to
        // one question get into the schema.
        $samplesPerFrame = intdiv(
            (int) config('render.audio.sample_rate'),
            (int) config('render.video.fps')
        );

        foreach ($story->scenes()->with(['sceneAudio', 'act'])->get() as $scene) {
            $audio = $scene->sceneAudio->first();

            if ($audio === null || $audio->offset_frames === null) {
                throw new RuntimeException(
                    "Scene {$scene->sequence} has no timeline offset. The concat stage has not run for it."
                );
            }

            $scenes[] = [
                'sequence' => $scene->sequence,
                'act_sequence' => $scene->act->sequence,
                'duration_ms' => $scene->duration_ms,
                'narration_text' => $scene->narration_text,
                'words' => $audio->timings_json ?? [],
            ];

            // Keyed by sequence so a reordered set cannot quietly misalign.
            $sceneAudio[$scene->sequence] = [
                'scene_sequence' => $scene->sequence,
                'duration_ms' => $scene->duration_ms,
                'padded_duration_ms' => $audio->padded_duration_ms,
                'offset_ms' => $audio->offset_ms,
                'frames' => $audio->frames,
                'offset_frames' => $audio->offset_frames,
                // What the subtitle shift actually reads. Never offset_ms.
                'offset_samples' => $audio->offset_samples,
                'padded_samples' => $audio->frames * $samplesPerFrame,
            ];
        }

        return [$scenes, $sceneAudio];
    }
}
