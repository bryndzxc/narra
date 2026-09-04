<?php

namespace Tests\Feature;

use App\Enums\CostCategory;
use App\Enums\StoryStatus;
use App\Livewire\Dashboard;
use App\Models\CostEntry;
use App\Models\Story;
use App\Support\SpendSummary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The landing page, asserted on the two axes it was built for.
 *
 * The first is reachability: it must render at all, with providers resolved
 * from the container rather than from config. The second is the one this page
 * could most easily get wrong — it summarises money, and a summary is the most
 * tempting place in an application to write a second copy of a figure that
 * already exists.
 */
class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_dashboard_renders(): void
    {
        $this->get(route('dashboard'))->assertOk()->assertSee('Waiting on you');
    }

    /**
     * A story at a gate is named, with the gate it is standing at.
     */
    public function test_a_story_waiting_on_the_operator_is_listed_under_its_gate(): void
    {
        $story = Story::factory()->create([
            'title' => 'The House in Ohio',
            'status' => StoryStatus::ScenesDrafted,
        ]);

        Livewire::test(Dashboard::class)
            ->assertSee('The House in Ohio')
            ->assertSee('Gate 2');

        $this->assertSame(StoryStatus::ScenesDrafted, $story->fresh()->status);
    }

    /**
     * Published is terminal. Carrying it would make this page longer every
     * time a video ships, which is the opposite of what a summary is for.
     */
    public function test_a_published_story_is_not_carried(): void
    {
        Story::factory()->create(['title' => 'Already On YouTube', 'status' => StoryStatus::Published]);

        Livewire::test(Dashboard::class)->assertDontSee('Already On YouTube');
    }

    /**
     * The month total and the per-story totals must be built from ONE
     * predicate.
     *
     * `stories.total_cost_usd` is maintained by `CostEntry::booted()` through
     * `CostCategory::countsTowardVideoCost()`. This asserts the roll-up asks
     * the same question rather than restating it — the failure it guards
     * against is a fifth category being added and counted in one place and not
     * the other, which is exactly how one narration multiplier ended up
     * applied at three different prices.
     */
    public function test_the_month_roll_up_agrees_with_the_denormalised_story_totals(): void
    {
        $story = Story::factory()->create(['status' => StoryStatus::ScenesApproved]);

        CostEntry::create([
            'story_id' => $story->id,
            'provider' => 'anthropic',
            'operation' => 'generate_outline',
            'category' => CostCategory::Text,
            'quantity' => 1000,
            'unit' => \App\Enums\CostUnit::OutputTokens,
            'usd_cost' => 1.2500,
        ]);

        CostEntry::create([
            'story_id' => $story->id,
            'provider' => 'fal',
            'operation' => 'generate_image',
            'category' => CostCategory::Asset,
            'quantity' => 1,
            'unit' => \App\Enums\CostUnit::Images,
            'usd_cost' => 0.7500,
        ]);

        // Evaluation is real spend and deliberately outside the per-video
        // total. It must be outside the roll-up's video figure too, and
        // visible separately in both — logged and nowhere on screen is the
        // same defect one level up.
        CostEntry::create([
            'story_id' => $story->id,
            'provider' => 'fal',
            'operation' => 'style_preview',
            'category' => CostCategory::Evaluation,
            'quantity' => 1,
            'unit' => \App\Enums\CostUnit::Images,
            'usd_cost' => 0.3000,
        ]);

        $spend = SpendSummary::forCurrentMonth();

        $this->assertSame(2.0, round($spend->monthVideoSpend, 4));
        $this->assertSame(0.3, round($spend->monthEvaluationSpend, 4));

        // The identity that matters: the roll-up and the column agree.
        $this->assertSame(
            round((float) $story->fresh()->total_cost_usd, 4),
            round($spend->monthVideoSpend, 4),
        );
        $this->assertSame(0.3, round($story->fresh()->evaluationSpend(), 4));
    }
}
