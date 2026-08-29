<?php

namespace Database\Factories;

use App\Enums\MetadataStatus;
use App\Models\Story;
use App\Models\YoutubeMetadata;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<YoutubeMetadata>
 */
class YoutubeMetadataFactory extends Factory
{
    protected $model = YoutubeMetadata::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'story_id' => Story::factory()->rendered(),
            'title_options' => null,
            'title_selected' => null,
            'description' => null,
            'tags' => null,
            'thumbnail_text_options' => null,
            'thumbnail_scene_id' => null,
            'pinned_comment' => null,
            'checklist_state' => null,
            'status' => MetadataStatus::Pending,
        ];
    }

    /**
     * A generated sheet waiting at Gate 4.
     *
     * Five titles, because the operator picks one and the other four become
     * data on what actually performs.
     */
    public function generated(): static
    {
        return $this->state(fn (): array => [
            'status' => MetadataStatus::Generated,
            'title_options' => array_map(
                fn (): string => rtrim($this->faker->sentence(8), '.'),
                range(1, 5)
            ),
            'description' => $this->faker->paragraphs(3, true),
            'tags' => $this->faker->words(12),
            'thumbnail_text_options' => array_map(
                fn (): string => rtrim($this->faker->sentence(4), '.'),
                range(1, 4)
            ),
            'pinned_comment' => $this->faker->sentence(14),
            'checklist_state' => [
                'synthetic_content_disclosed' => false,
                'not_made_for_kids' => false,
                'category_set' => false,
                'languages_set' => false,
                'scheduled_time_confirmed_et' => false,
                'pinned_comment_drafted' => false,
            ],
        ]);
    }

    /**
     * Tags deliberately over the 500-character budget, for testing that the
     * budget is enforced rather than silently truncated.
     */
    public function overTagBudget(): static
    {
        return $this->state(fn (): array => [
            'tags' => array_map(
                fn (int $i): string => 'long-tag-phrase-number-'.str_pad((string) $i, 3, '0', STR_PAD_LEFT),
                range(1, 25)
            ),
        ]);
    }
}
