<?php

namespace Tests\Unit;

use App\Enums\Gate;
use App\Enums\StoryStatus;
use PHPUnit\Framework\TestCase;

/**
 * The gate machine, checked without a database.
 *
 * These are product invariants rather than convenience rules: four human gates,
 * no generate-and-upload path, and no paid asset generation before Gate 2. A
 * test that only exercises the happy path would let a future edit open a
 * shortcut past any of them, so the shortcuts are what is asserted here.
 */
class StoryStatusTest extends TestCase
{
    public function test_the_pipeline_runs_end_to_end_one_step_at_a_time(): void
    {
        $sequence = [
            StoryStatus::Draft,
            StoryStatus::Outlined,
            StoryStatus::Scripted,
            StoryStatus::ScenesDrafted,
            StoryStatus::ScenesApproved,
            StoryStatus::AssetsGenerating,
            StoryStatus::AssetsReady,
            StoryStatus::Rendering,
            StoryStatus::Rendered,
            StoryStatus::MetadataReady,
            StoryStatus::Published,
        ];

        foreach ($sequence as $i => $status) {
            if (! isset($sequence[$i + 1])) {
                break;
            }

            $this->assertTrue(
                $status->canTransitionTo($sequence[$i + 1]),
                "{$status->value} cannot reach {$sequence[$i + 1]->value}."
            );
        }
    }

    public function test_no_status_can_skip_ahead_more_than_one_step(): void
    {
        foreach (StoryStatus::cases() as $from) {
            foreach ($from->allowedTransitions() as $to) {
                $this->assertLessThanOrEqual(
                    1,
                    $to->rank() - $from->rank(),
                    "{$from->value} skips ahead to {$to->value}."
                );
            }
        }
    }

    public function test_nothing_can_jump_straight_to_published(): void
    {
        foreach (StoryStatus::cases() as $status) {
            if ($status === StoryStatus::MetadataReady) {
                continue;
            }

            $this->assertFalse(
                $status->canTransitionTo(StoryStatus::Published),
                "{$status->value} can publish without passing Gate 4."
            );
        }
    }

    public function test_published_is_terminal(): void
    {
        // The app produces a file and a metadata sheet. A human uploads it and
        // toggles the synthetic-content disclosure. There is nothing after this.
        $this->assertSame([], StoryStatus::Published->allowedTransitions());
    }

    public function test_every_gate_is_the_only_way_into_the_status_behind_it(): void
    {
        foreach (Gate::cases() as $gate) {
            $behind = $gate->opensTo();

            foreach (StoryStatus::cases() as $from) {
                // Backward moves land on these statuses too — scenes_drafted
                // back to scripted is a reopen, not a way past Gate 1. Only
                // FORWARD progress into a post-gate status is the concern.
                if (! $from->canTransitionTo($behind) || $from->rank() > $behind->rank()) {
                    continue;
                }

                // The gate's own crossing is a gate; anything else reaching the
                // same status would be a way around the operator.
                $this->assertSame(
                    $gate->waitsAt(),
                    $from,
                    "{$from->value} reaches {$behind->value} without passing {$gate->label()}."
                );

                $this->assertSame($gate, $from->gateFor($behind));
            }
        }
    }

    public function test_paid_assets_are_locked_until_gate_two_is_passed(): void
    {
        $locked = [
            StoryStatus::Draft,
            StoryStatus::Outlined,
            StoryStatus::Scripted,
            StoryStatus::ScenesDrafted,
        ];

        foreach ($locked as $status) {
            $this->assertFalse(
                $status->allowsPaidAssets(),
                "{$status->value} would allow paid asset generation before Gate 2."
            );
        }

        foreach (StoryStatus::cases() as $status) {
            if (in_array($status, $locked, true)) {
                continue;
            }

            $this->assertTrue($status->allowsPaidAssets(), "{$status->value} should allow paid assets.");
        }
    }

    public function test_reopening_gate_two_is_allowed_and_is_not_itself_a_gate(): void
    {
        // The operator spotted a bad scene after approving. Going back must be
        // possible — and going back is a reopen, not an approval.
        $this->assertTrue(StoryStatus::ScenesApproved->canTransitionTo(StoryStatus::ScenesDrafted));
        $this->assertNull(StoryStatus::ScenesApproved->gateFor(StoryStatus::ScenesDrafted));
    }

    public function test_the_reopen_predicate_and_the_transition_table_agree(): void
    {
        // The bug this pair replaces: the page decided for itself which
        // statuses could reopen, using `! editable()`, and the transition table
        // disagreed with it. Whenever those two expressions can drift, they
        // will — so there is now one predicate, and this asserts the table
        // matches it exactly in both directions.
        foreach (StoryStatus::cases() as $status) {
            $isBackwards = $status->rank() > StoryStatus::ScenesDrafted->rank();
            $hasEdge = $status->canTransitionTo(StoryStatus::ScenesDrafted);

            $this->assertSame(
                $status->canReopenScenesGate(),
                $hasEdge && $isBackwards,
                "{$status->value} offers a reopen the transition table does not have, or vice versa."
            );
        }
    }

    public function test_gate_two_can_be_reopened_from_every_status_that_can_hold_a_paid_asset(): void
    {
        // The reported bug, as an invariant. Once money has been spent there is
        // always a way back to the gate that authorised spending it — otherwise
        // "I watched the render and scene 147 is wrong" is a dead end.
        $reopenable = [
            StoryStatus::ScenesApproved,
            StoryStatus::AssetsGenerating,
            StoryStatus::AssetsReady,
            StoryStatus::Rendered,
            StoryStatus::MetadataReady,
        ];

        foreach ($reopenable as $status) {
            $this->assertTrue(
                $status->canReopenScenesGate(),
                "{$status->value} holds paid assets with no way back to Gate 2."
            );
            $this->assertTrue($status->canTransitionTo(StoryStatus::ScenesDrafted));
            $this->assertNull($status->reopenRefusalReason());
        }
    }

    public function test_a_render_in_flight_and_a_published_story_refuse_a_reopen(): void
    {
        // `rendering` means up to 250 clip jobs are on the queue. Reopening
        // would let the operator delete scenes the running jobs are encoding.
        $this->assertFalse(StoryStatus::Rendering->canReopenScenesGate());
        $this->assertStringContainsString('render:cancel', (string) StoryStatus::Rendering->reopenRefusalReason());

        // Terminal stays terminal. The file is on YouTube.
        $this->assertFalse(StoryStatus::Published->canReopenScenesGate());
        $this->assertSame([], StoryStatus::Published->allowedTransitions());
    }

    public function test_a_reopen_is_not_a_gate_crossing_from_any_status(): void
    {
        // Going back is a reopen, not an approval. If any of the new edges
        // registered as a gate, transitionTo() would refuse it and the operator
        // would be told to approve their way backwards.
        foreach (StoryStatus::cases() as $status) {
            if (! $status->canReopenScenesGate()) {
                continue;
            }

            $this->assertNull($status->gateFor(StoryStatus::ScenesDrafted));
        }
    }

    public function test_the_new_reopen_edges_open_no_shortcut_forward(): void
    {
        // Adding five backwards edges must not have widened anything forward.
        // Re-asserted here rather than trusted: this is the property the
        // reopen work was most likely to break.
        foreach (StoryStatus::cases() as $from) {
            foreach ($from->allowedTransitions() as $to) {
                $this->assertLessThanOrEqual(1, $to->rank() - $from->rank());
            }
        }

        foreach (StoryStatus::cases() as $status) {
            if ($status === StoryStatus::ScenesDrafted) {
                continue;
            }

            $this->assertFalse(
                $status->canTransitionTo(StoryStatus::ScenesApproved) && $status->rank() < StoryStatus::ScenesApproved->rank(),
                "{$status->value} reaches scenes_approved without passing Gate 2."
            );
        }
    }

    public function test_each_gate_waits_at_exactly_one_status(): void
    {
        foreach (Gate::cases() as $gate) {
            $this->assertSame($gate, $gate->waitsAt()->awaitingGate());
        }

        $waiting = array_filter(
            StoryStatus::cases(),
            fn (StoryStatus $status): bool => $status->awaitingGate() !== null
        );

        $this->assertCount(4, $waiting, 'There are four gates. Not three, not five.');
    }
}
