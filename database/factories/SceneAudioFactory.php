<?php

namespace Database\Factories;

use App\Enums\AssetStatus;
use App\Models\AudioTrack;
use App\Models\Scene;
use App\Models\SceneAudio;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SceneAudio>
 */
class SceneAudioFactory extends Factory
{
    protected $model = SceneAudio::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'scene_id' => Scene::factory(),
            'audio_track_id' => AudioTrack::factory(),
            'audio_path' => null,
            'timings_json' => null,
            'duration_ms' => null,
            'padded_duration_ms' => null,
            'frames' => null,
            'offset_frames' => null,
            'offset_samples' => null,
            'offset_ms' => null,
            'status' => AssetStatus::Pending,
        ];
    }

    /**
     * A generated scene, placed on the timeline with the real arithmetic.
     *
     * frames = ceil(audio_ms / 1000 * fps), padded duration = frames / fps, and
     * offsets accumulate in integer frames and samples. Building a fixture any
     * other way — summing rounded milliseconds, say — would let a test pass
     * against numbers the pipeline would never produce.
     */
    public function placedAt(int $durationMs, int $offsetFrames, int $fps = 30, int $sampleRate = 44100): static
    {
        $frames = (int) ceil($durationMs / 1000 * $fps);

        return $this->state(fn (): array => [
            'status' => AssetStatus::Ready,
            'audio_path' => 'renders/scene-'.$this->faker->numberBetween(1, 250).'.wav',
            'duration_ms' => $durationMs,
            'frames' => $frames,
            'padded_duration_ms' => (int) round($frames / $fps * 1000),
            'offset_frames' => $offsetFrames,
            'offset_samples' => $offsetFrames * intdiv($sampleRate, $fps),
            // Derived from offset_frames, for display. Never used for timing.
            'offset_ms' => (int) round($offsetFrames / $fps * 1000),
        ]);
    }
}
