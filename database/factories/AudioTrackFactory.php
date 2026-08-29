<?php

namespace Database\Factories;

use App\Enums\AssetStatus;
use App\Models\AudioTrack;
use App\Models\Story;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AudioTrack>
 */
class AudioTrackFactory extends Factory
{
    protected $model = AudioTrack::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'story_id' => Story::factory(),
            'language' => 'en-US',
            'voice_id' => 'narrator-us-01',
            'audio_path' => null,
            'timings_json' => null,
            'duration_ms' => null,
            'status' => AssetStatus::Pending,
        ];
    }

    public function ready(): static
    {
        return $this->state(fn (): array => [
            'status' => AssetStatus::Ready,
            'audio_path' => 'renders/'.$this->faker->slug(2).'/narration.wav',
            // A 30-40 minute video, which is the point of the format.
            'duration_ms' => $this->faker->numberBetween(1_800_000, 2_400_000),
        ]);
    }

    /** A dubbed track, for the Phase 3 shape the table already supports. */
    public function language(string $language, ?string $voiceId = null): static
    {
        return $this->state(fn (): array => [
            'language' => $language,
            'voice_id' => $voiceId ?? 'narrator-'.strtolower($language),
        ]);
    }
}
