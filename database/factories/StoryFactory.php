<?php

namespace Database\Factories;

use App\Enums\Gate;
use App\Enums\StoryFormat;
use App\Enums\StoryStatus;
use App\Models\Story;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Story>
 */
class StoryFactory extends Factory
{
    protected $model = Story::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $title = rtrim($this->faker->sentence(6), '.');

        return [
            'title' => $title,
            'slug' => Story::slugFor($title, (string) $this->faker->unique()->numberBetween(1000, 9999)),
            'premise' => $this->faker->paragraph(3),
            'format' => StoryFormat::Single,
            'locale_profile' => 'en-US',
            'voice_id' => 'narrator-us-01',
            'target_duration_min' => 30,
            'target_duration_max' => 40,
            'status' => StoryStatus::Draft,
            'target_publish_at' => null,
            'total_cost_usd' => 0,
        ];
    }

    /**
     * Park the story at a status directly.
     *
     * Factories set state; they do not walk the gate machine. Tests that care
     * about the gates should use transitionTo() and approveGate() from a Draft
     * story, which is the only path production code has.
     */
    public function status(StoryStatus $status): static
    {
        return $this->state(fn (): array => ['status' => $status]);
    }

    /** Waiting on the operator at a given gate. */
    public function awaitingGate(Gate $gate): static
    {
        return $this->status($gate->waitsAt());
    }

    /**
     * Past Gate 2, so paid asset generation is permitted.
     *
     * The state most asset-stage tests need, named after the reason it exists
     * rather than after the status it sets.
     */
    public function paidAssetsUnlocked(): static
    {
        return $this->status(StoryStatus::ScenesApproved);
    }

    /**
     * The default shape for this genre: one narrator, one grievance, escalating.
     */
    public function single(): static
    {
        return $this->state(fn (): array => ['format' => StoryFormat::Single]);
    }

    public function anthology(): static
    {
        return $this->state(fn (): array => ['format' => StoryFormat::Anthology]);
    }

    /** Rendered and waiting at Gate 3. */
    public function rendered(): static
    {
        return $this->status(StoryStatus::Rendered);
    }
}
