<?php

namespace Database\Factories;

use App\Models\Act;
use App\Models\Story;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Act>
 */
class ActFactory extends Factory
{
    protected $model = Act::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'story_id' => Story::factory(),
            'sequence' => $this->faker->unique()->numberBetween(1, 8),
            'title' => rtrim($this->faker->sentence(4), '.'),
            'summary' => $this->faker->paragraph(2),
            'script' => null,
            'is_rehook_written' => false,
            'start_ms' => null,
            'duration_ms' => null,
        ];
    }

    /**
     * Not named sequence(): Factory::sequence() is Laravel's own state-cycling
     * helper, and overriding it breaks every caller that expects that.
     */
    public function atSequence(int $sequence): static
    {
        return $this->state(fn (): array => ['sequence' => $sequence]);
    }

    public function scripted(): static
    {
        return $this->state(fn (): array => [
            'script' => $this->faker->paragraphs(8, true),
            'is_rehook_written' => true,
        ]);
    }

    /**
     * Act timings as they exist after a render — the state chapters need.
     */
    public function timed(int $startMs, int $durationMs): static
    {
        return $this->state(fn (): array => [
            'start_ms' => $startMs,
            'duration_ms' => $durationMs,
        ]);
    }
}
