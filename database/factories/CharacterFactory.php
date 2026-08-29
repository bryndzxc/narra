<?php

namespace Database\Factories;

use App\Models\Character;
use App\Models\Story;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Character>
 */
class CharacterFactory extends Factory
{
    protected $model = Character::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'story_id' => Story::factory(),
            // American names: the channel is written for a US audience, and the
            // fixtures should not quietly drift away from that.
            'name' => $this->faker->unique()->firstName().' '.$this->faker->lastName(),
            'description' => $this->faker->sentence(12),
            'seed' => null,
            'reference_image_path' => null,
            'style_notes' => null,
        ];
    }

    /** Seed and reference image both set — the consistency mechanism, complete. */
    public function locked(): static
    {
        return $this->state(fn (): array => [
            'seed' => $this->faker->numberBetween(1, 4294967295),
            'reference_image_path' => 'characters/'.$this->faker->uuid().'.png',
        ]);
    }
}
