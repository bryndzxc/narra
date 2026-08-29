<?php

namespace Tests\Feature;

use App\Enums\Gate;
use App\Enums\StoryStatus;
use App\Exceptions\GateViolationException;
use App\Models\CostEntry;
use App\Models\Story;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The gates as enforced on the model, where a job, a command or a controller
 * all have to pass through them.
 */
class StoryGateTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_legal_move_persists(): void
    {
        $story = Story::factory()->create();

        $story->transitionTo(StoryStatus::Outlined);

        $this->assertSame(StoryStatus::Outlined, $story->fresh()->status);
    }

    public function test_an_illegal_move_is_refused_and_says_what_is_allowed(): void
    {
        $story = Story::factory()->create();

        $this->expectException(GateViolationException::class);
        $this->expectExceptionMessage("Cannot move a story from 'draft' to 'rendering'");

        $story->transitionTo(StoryStatus::Rendering);
    }

    public function test_a_gate_cannot_be_crossed_by_an_ordinary_transition(): void
    {
        $story = Story::factory()->awaitingGate(Gate::Scenes)->create();

        try {
            $story->transitionTo(StoryStatus::ScenesApproved);
            $this->fail('A gate was crossed without an operator.');
        } catch (GateViolationException $e) {
            $this->assertStringContainsString('Gate 2 — Scenes', $e->getMessage());
            $this->assertStringContainsString('approveGate', $e->getMessage());
        }

        // And nothing moved.
        $this->assertSame(StoryStatus::ScenesDrafted, $story->fresh()->status);
    }

    public function test_approving_a_gate_moves_the_story(): void
    {
        $story = Story::factory()->awaitingGate(Gate::Scenes)->create();

        $story->approveGate(Gate::Scenes);

        $this->assertSame(StoryStatus::ScenesApproved, $story->fresh()->status);
        $this->assertTrue($story->hasPassedGate(Gate::Scenes));
    }

    public function test_a_gate_can_only_be_approved_from_the_status_it_waits_at(): void
    {
        $story = Story::factory()->create();

        $this->expectException(GateViolationException::class);
        $this->expectExceptionMessage("Gate 2 — Scenes can only be approved from 'scenes_drafted'");

        $story->approveGate(Gate::Scenes);
    }

    public function test_a_story_reports_which_gate_is_waiting_on_the_operator(): void
    {
        $this->assertNull(Story::factory()->create()->awaitingGate());

        foreach (Gate::cases() as $gate) {
            $story = Story::factory()->awaitingGate($gate)->create();

            $this->assertSame($gate, $story->awaitingGate());
        }
    }

    public function test_status_is_not_mass_assignable(): void
    {
        // The one attribute that must never be set by a form request or an
        // ill-considered update() call.
        $story = Story::factory()->create();

        $story->update(['status' => StoryStatus::Published->value, 'title' => 'Renamed']);

        $story->refresh();

        $this->assertSame(StoryStatus::Draft, $story->status);
        $this->assertSame('Renamed', $story->title);
    }

    // -- The money line ------------------------------------------------------

    public function test_paid_assets_are_locked_before_gate_two(): void
    {
        $story = Story::factory()->awaitingGate(Gate::Scenes)->create();

        $this->assertFalse($story->canGeneratePaidAssets());

        $this->expectException(GateViolationException::class);
        $this->expectExceptionMessage("Paid operation 'generate_image' is blocked");

        $story->assertPaidAssetsUnlocked('generate_image');
    }

    public function test_paid_assets_unlock_once_the_operator_approves_the_scenes(): void
    {
        $story = Story::factory()->awaitingGate(Gate::Scenes)->create();

        $story->approveGate(Gate::Scenes);

        $this->assertTrue($story->canGeneratePaidAssets());
        $story->assertPaidAssetsUnlocked('generate_image');
    }

    public function test_a_cost_cannot_be_recorded_against_a_story_that_may_not_spend(): void
    {
        // The invariant made structural: 150-250 images at ~70% of the video's
        // cost sit behind Gate 2, so a bill for a story that has not passed it
        // is refused by the data layer rather than by a comment in a job.
        $story = Story::factory()->awaitingGate(Gate::Scenes)->create();

        $this->expectException(GateViolationException::class);

        CostEntry::factory()->for($story)->image()->create();

        $this->assertDatabaseCount('cost_entries', 0);
    }

    public function test_recorded_costs_roll_up_onto_the_story(): void
    {
        $story = Story::factory()->paidAssetsUnlocked()->create();

        CostEntry::factory()->for($story)->image(0.0400)->count(3)->create();
        CostEntry::factory()->for($story)->narration(900, 0.0150)->create();

        $story->refresh();

        // "What did this video cost" in one query, and the denormalised total
        // agreeing with the rows it summarises.
        $this->assertSame('0.1350', $story->total_cost_usd);
        $this->assertEqualsWithDelta(0.1350, (float) $story->costEntries()->sum('usd_cost'), 0.00001);
    }

    public function test_cost_entries_are_write_once(): void
    {
        $story = Story::factory()->paidAssetsUnlocked()->create();

        $entry = CostEntry::factory()->for($story)->image()->create();

        $this->assertNull($entry->updated_at);
        $this->assertNotNull($entry->created_at);
    }
}
