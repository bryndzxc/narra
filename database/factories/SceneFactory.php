<?php

namespace Database\Factories;

use App\Enums\MotionPreset;
use App\Enums\SceneStatus;
use App\Models\Act;
use App\Models\Scene;
use App\Models\Story;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Scene>
 */
class SceneFactory extends Factory
{
    protected $model = Scene::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $story = Story::factory();

        return [
            'story_id' => $story,
            'act_id' => Act::factory()->for($story),
            'sequence' => $this->faker->unique()->numberBetween(1, 250),
            'is_hook' => false,
            'is_thumbnail_candidate' => false,
            'narration_text' => $this->faker->sentence(18),
            'image_prompt' => $this->faker->sentence(20),
            'image_path' => null,
            // Scene audio in this format runs roughly 8-16 seconds.
            'duration_ms' => $this->faker->numberBetween(8000, 16000),
            'motion_preset' => $this->faker->randomElement(MotionPreset::cases()),
            'status' => SceneStatus::Drafted,
        ];
    }

    /**
     * Attach to an existing act, keeping story_id consistent with it.
     *
     * A scene whose act belongs to a different story is nonsense the schema
     * cannot catch on its own, so the factory refuses to produce it.
     */
    public function forAct(Act $act): static
    {
        return $this->state(fn (): array => [
            'act_id' => $act->id,
            'story_id' => $act->story_id,
        ]);
    }

    /**
     * Not named sequence(): Factory::sequence() is Laravel's own state-cycling
     * helper, and overriding it breaks every caller that expects that.
     */
    public function atSequence(int $sequence): static
    {
        return $this->state(fn (): array => ['sequence' => $sequence]);
    }

    /** The 15-second opening. */
    public function hook(): static
    {
        return $this->state(fn (): array => [
            'is_hook' => true,
            'sequence' => 1,
        ]);
    }

    public function thumbnailCandidate(): static
    {
        return $this->state(fn (): array => ['is_thumbnail_candidate' => true]);
    }

    /** Approved at Gate 2 — assets may now be generated for it. */
    public function approved(): static
    {
        return $this->state(fn (): array => ['status' => SceneStatus::Approved]);
    }

    /** Image and audio present, ready to render a clip. */
    public function ready(): static
    {
        return $this->state(fn (): array => [
            'status' => SceneStatus::Ready,
            'image_path' => 'scenes/'.$this->faker->uuid().'.png',
        ]);
    }
}
