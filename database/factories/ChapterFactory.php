<?php

namespace Database\Factories;

use App\Models\Act;
use App\Models\Chapter;
use App\Models\Story;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Chapter>
 */
class ChapterFactory extends Factory
{
    protected $model = Chapter::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $story = Story::factory();

        return [
            'story_id' => $story,
            'act_id' => Act::factory()->for($story),
            'sequence' => 1,
            'title' => rtrim($this->faker->sentence(3), '.'),
            'rehook_line' => $this->faker->sentence(12),
            'first_sentence' => 1,
            'start_ms' => null,
            'duration_ms' => null,
        ];
    }

    /** Attach to an existing act, keeping story_id consistent with it. */
    public function forAct(Act $act): static
    {
        return $this->state(fn (): array => [
            'act_id' => $act->id,
            'story_id' => $act->story_id,
        ]);
    }

    public function atSequence(int $sequence, int $firstSentence = 1): static
    {
        return $this->state(fn (): array => [
            'sequence' => $sequence,
            'first_sentence' => $firstSentence,
        ]);
    }

    /** Chapter timings as they exist after a render. */
    public function timed(int $startMs, int $durationMs): static
    {
        return $this->state(fn (): array => [
            'start_ms' => $startMs,
            'duration_ms' => $durationMs,
        ]);
    }

    public function withoutRehook(): static
    {
        return $this->state(fn (): array => ['rehook_line' => null]);
    }
}
