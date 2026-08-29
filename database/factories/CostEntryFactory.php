<?php

namespace Database\Factories;

use App\Enums\CostCategory;
use App\Enums\CostUnit;
use App\Enums\StoryStatus;
use App\Models\CostEntry;
use App\Models\Story;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CostEntry>
 */
class CostEntryFactory extends Factory
{
    protected $model = CostEntry::class;

    /**
     * A cost entry can only exist for a story that was allowed to spend money,
     * so the default story here is one past Gate 2. Anything else would be
     * rejected by CostEntry's own guard — correctly.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'story_id' => Story::factory()->status(StoryStatus::ScenesApproved),
            'provider' => 'fake',
            'operation' => 'generate_image',
            'category' => CostCategory::Asset,
            'quantity' => 1,
            'unit' => CostUnit::Images,
            'usd_cost' => 0.0400,
        ];
    }

    /**
     * Token spend on text - outline, act scripts, scene drafts, metadata copy.
     *
     * Not gated behind Gate 2: this is spend that happens BEFORE the operator
     * has approved anything, because it produces the thing they approve. Pair
     * it with ->for(a draft story) to exercise that.
     *
     * Deliberately does not set story_id. A state that did would silently
     * override an explicit ->for($story) and send the rows somewhere else.
     */
    public function text(int $tokens = 4000, float $usd = 0.1000, string $operation = 'generate_act_script'): static
    {
        return $this->state(fn (): array => [
            'operation' => $operation,
            'category' => CostCategory::Text,
            'quantity' => $tokens,
            'unit' => CostUnit::OutputTokens,
            'usd_cost' => $usd,
        ]);
    }

    /**
     * The dominant line item: 150-250 stills, roughly 70% of a video's cost.
     */
    public function image(float $usd = 0.0400): static
    {
        return $this->state(fn (): array => [
            'operation' => 'generate_image',
            'category' => CostCategory::Asset,
            'quantity' => 1,
            'unit' => CostUnit::Images,
            'usd_cost' => $usd,
        ]);
    }

    public function narration(int $characters = 900, float $usd = 0.0150): static
    {
        return $this->state(fn (): array => [
            'operation' => 'synthesize_speech',
            'category' => CostCategory::Asset,
            'quantity' => $characters,
            'unit' => CostUnit::Characters,
            'usd_cost' => $usd,
        ]);
    }

    public function transcription(int $seconds = 12, float $usd = 0.0012): static
    {
        return $this->state(fn (): array => [
            'operation' => 'transcribe',
            'category' => CostCategory::Asset,
            'quantity' => $seconds,
            'unit' => CostUnit::AudioSeconds,
            'usd_cost' => $usd,
        ]);
    }
}
