<?php

namespace Tests\Feature;

use App\Actions\RecordProviderCost;
use App\Enums\CostCategory;
use App\Enums\CostUnit;
use App\Enums\StoryStatus;
use App\Models\CostEntry;
use App\Models\Story;
use App\Support\Providers\ProviderUsage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Evaluation spend: billed, logged, and not part of any video.
 *
 * `style:preview`, `images:bakeoff` and `narration:bakeoff` all pay a real
 * vendor for a real file while making no video at all. They had no category, so
 * they borrowed one — two took `reference`, one took `asset` — and inherited
 * gates written for a decision they were not making.
 *
 * That bit for real. A style preview asks what a candidate look does to a cast,
 * so it wants the earliest story that HAS a cast, which is `scripted`.
 * `reference` unlocks at `scenes_drafted`, so a run generated an image, billed
 * for it, and then threw a gate violation while writing the row: spend with no
 * record. The workaround was to walk a scratch story through Gate 2 to satisfy
 * a guard about something it was not doing.
 *
 * The two properties below are what the category is FOR, and neither of them
 * was true when an operation-name prefix was the whole mechanism.
 */
class EvaluationSpendTest extends TestCase
{
    use RefreshDatabase;

    public function test_evaluation_spend_is_allowed_at_any_status(): void
    {
        // `draft` — below every gate in the ledger. A style preview wants the
        // earliest story that has a cast, and refusing it here is what forced a
        // story to be advanced through Gate 2 for no editorial reason.
        $story = Story::factory()->create(['status' => StoryStatus::Draft]);

        app(RecordProviderCost::class)->handle($story, $this->usage(CostCategory::Evaluation, 0.14));

        $this->assertSame(1, $story->costEntries()->count());
    }

    public function test_evaluation_spend_is_logged_but_left_out_of_the_video_total(): void
    {
        $story = Story::factory()->create(['status' => StoryStatus::Scripted]);

        app(RecordProviderCost::class)->handle($story, $this->usage(CostCategory::Text, 0.50));
        app(RecordProviderCost::class)->handle($story, $this->usage(CostCategory::Evaluation, 0.14));

        $story->refresh();

        $this->assertSame(
            '0.5000',
            $story->total_cost_usd,
            'A style preview inflated the story total. That column answers "what did this video '
                .'cost", and a preview borrows a cast the way a lens test borrows an actor.'
        );

        $this->assertSame(2, $story->costEntries()->count(), 'The row must still be written.');
        $this->assertEqualsWithDelta(0.14, $story->evaluationSpend(), 0.00001);
    }

    public function test_every_other_category_still_counts(): void
    {
        $story = Story::factory()->create(['status' => StoryStatus::ScenesApproved]);

        app(RecordProviderCost::class)->handle($story, $this->usage(CostCategory::Text, 0.10));
        app(RecordProviderCost::class)->handle($story, $this->usage(CostCategory::Reference, 0.20));
        app(RecordProviderCost::class)->handle($story, $this->usage(CostCategory::Asset, 0.30));

        $this->assertSame('0.6000', $story->refresh()->total_cost_usd);
        $this->assertSame(0.0, $story->evaluationSpend());
    }

    public function test_the_total_reconciles_against_the_rows_that_count(): void
    {
        // The invariant restated. It is still exact — the exception is a
        // method, not a habit.
        $story = Story::factory()->create(['status' => StoryStatus::ScenesApproved]);

        foreach ([
            [CostCategory::Text, 0.05],
            [CostCategory::Asset, 1.25],
            [CostCategory::Evaluation, 0.14],
            [CostCategory::Reference, 0.35],
            [CostCategory::Evaluation, 0.07],
        ] as [$category, $cost]) {
            app(RecordProviderCost::class)->handle($story, $this->usage($category, $cost));
        }

        $counted = $story->costEntries()->get()->filter(
            fn (CostEntry $e): bool => $e->category->countsTowardVideoCost()
        )->sum(fn (CostEntry $e): float => (float) $e->usd_cost);

        $this->assertEqualsWithDelta($counted, (float) $story->refresh()->total_cost_usd, 0.00001);
        $this->assertEqualsWithDelta(0.21, $story->evaluationSpend(), 0.00001);
    }

    public function test_a_new_category_has_to_state_which_side_it_is_on(): void
    {
        // countsTowardVideoCost() is an exhaustive match rather than a
        // `!== Evaluation`, so adding a case without deciding this is a
        // TypeError here rather than a silent default in the ledger.
        foreach (CostCategory::cases() as $case) {
            $this->assertIsBool($case->countsTowardVideoCost());
        }
    }

    private function usage(CostCategory $category, float $cost): ProviderUsage
    {
        return new ProviderUsage(
            provider: 'fal',
            operation: 'style_preview_trio',
            category: $category,
            quantity: 1,
            unit: CostUnit::Images,
            usdCost: $cost,
        );
    }
}
