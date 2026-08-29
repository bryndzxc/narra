<?php

namespace App\Support;

/**
 * Writes `scene_audio.json` — the timeline handoff between the concat stage and
 * everything downstream of it.
 *
 * Extracted from the CLI command so the queued job and the command produce the
 * same bytes by construction rather than by two people being careful. The
 * subtitle stage reads offsets from this file, and a manifest that differs
 * between the two entry points would be a silent desync.
 */
class SceneAudioManifest
{
    /**
     * @param  array<int, array<string, mixed>>  $plan  One entry per scene, in
     *                                                  playback order, carrying
     *                                                  the keys read below.
     */
    public static function encode(array $plan, int $fps, int $rate, int $totalFrames, int $totalSamples): string
    {
        $scenes = array_map(fn (array $s): array => [
            'scene_sequence' => $s['sequence'],
            'act_sequence' => $s['act_sequence'],
            'audio_path' => 'padded/'.$s['slug'].'.wav',
            // Raw audio. The clip duration is derived, never stored twice.
            'duration_ms' => $s['audio_duration_ms'],
            'padded_duration_ms' => $s['padded_duration_ms'],
            'offset_ms' => $s['offset_ms'],
            // Exact integers. offset_ms is these rounded; step 3 should prefer
            // these when shifting word timings, since they carry no rounding.
            'frames' => $s['frames'],
            'offset_frames' => $s['offset_frames'],
            'offset_samples' => $s['offset_samples'],
            'padded_samples' => $s['padded_samples'],
        ], $plan);

        return json_encode([
            'story' => [
                'fps' => $fps,
                'sample_rate' => $rate,
                'scene_count' => count($plan),
                'total_frames' => $totalFrames,
                'total_samples' => $totalSamples,
                'video_duration_ms' => $totalFrames / $fps * 1000,
                'audio_duration_ms' => $totalSamples / $rate * 1000,
            ],
            'scenes' => $scenes,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
    }
}
