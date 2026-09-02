<?php

namespace Tests\Unit;

use App\Enums\OperatorAction;
use App\Enums\StoryStatus;
use Tests\TestCase;

/**
 * Every operator capability, against every status, checked by machine.
 *
 * This test exists because the same bug shipped four times and was found four
 * separate times by an operator pressing a button:
 *
 *   - Gate 1's reopen was offered at nine statuses and legal at one.
 *   - `assets:generate` accepted any status while its own button correctly
 *     refused past `rendered` — the two disagreed with each other.
 *   - `render:dispatch` accepted any status and was illegal from
 *     `metadata_ready`.
 *   - `render:cancel` never touched the status at all, while an error message
 *     elsewhere promised it "lands on assets_ready" — leaving the story at
 *     `rendering`, which has no way back.
 *
 * Every one of those is the same shape: a guard and a transition table written
 * in two places and compared by eye. Each cost a round trip to find, one at a
 * time, in separate sessions.
 *
 * The mechanism is OperatorAction — one predicate per capability, consulted by
 * the button AND the command — and this is the test that keeps it honest. It is
 * a full cross product, 5 capabilities x 11 statuses, so a new status or a new
 * capability is audited the moment it is added rather than the first time
 * somebody presses it.
 *
 * The invariant, in one sentence: **if a capability says it is available, the
 * move it will attempt must be legal; if it says it is not, it must say why in
 * a sentence with a next action in it.**
 */
class StoryStatusAuditTest extends TestCase
{
    /**
     * The one that would have caught all four.
     *
     * Note the axis. It is easy to write a test that walks the transition table
     * and confirms it is internally consistent — the table is a match statement
     * and cannot be inconsistent with itself. The axis that actually broke is
     * the JOIN between what a caller offers and what the machine allows, and
     * that is the only thing asserted here.
     */
    public function test_every_permitted_capability_attempts_a_legal_move(): void
    {
        $broken = [];

        foreach (OperatorAction::cases() as $action) {
            foreach (StoryStatus::cases() as $status) {
                if (! $action->permittedAt($status)) {
                    continue;
                }

                $target = $action->targetStatus($status);

                // Null is legitimate: a retry that changes no status. What must
                // never happen is a capability offering a move the machine has
                // never been told about.
                if ($target === null || $status->canTransitionTo($target)) {
                    continue;
                }

                $broken[] = sprintf(
                    "%s is offered at '%s' but would attempt '%s' -> '%s', which the state machine does "
                    ."not define.\n     Allowed from '%s': %s.\n     Callers: %s.",
                    $action->label(),
                    $status->value,
                    $status->value,
                    $target->value,
                    $status->value,
                    implode(', ', array_column($status->allowedTransitions(), 'value')) ?: '(nothing)',
                    $action->callers(),
                );
            }
        }

        $this->assertSame([], $broken, "Capabilities offering moves the state machine forbids:\n  - "
            .implode("\n  - ", $broken));
    }

    /**
     * A refusal without a next action is how an operator ends up editing the
     * status column by hand.
     */
    public function test_every_refusal_explains_itself(): void
    {
        foreach (OperatorAction::cases() as $action) {
            foreach (StoryStatus::cases() as $status) {
                if ($action->permittedAt($status)) {
                    $this->assertNull(
                        $action->refusalReason($status),
                        "{$action->value} is permitted at {$status->value} but still gives a refusal."
                    );

                    continue;
                }

                $reason = $action->refusalReason($status);

                $this->assertIsString(
                    $reason,
                    "{$action->value} is refused at {$status->value} with no reason given."
                );

                $this->assertGreaterThan(
                    30,
                    strlen((string) $reason),
                    "{$action->value} at {$status->value} refuses with a message too short to be useful: "
                    ."\"{$reason}\""
                );
            }
        }
    }

    /**
     * The capability that motivated the whole audit.
     *
     * Replacing placeholder narration on a story that has already rendered is
     * not a redo and must not require reopening Gate 2 — reopening Gate 2 to fix
     * audio would put every paid still back in play. Spending is not a gate
     * crossing; that is a non-negotiable in the spec, and this is the edge that
     * makes it true past `rendered`.
     */
    public function test_assets_can_be_regenerated_on_a_story_that_already_rendered(): void
    {
        foreach ([StoryStatus::Rendered, StoryStatus::MetadataReady] as $status) {
            $this->assertTrue(
                OperatorAction::RegenerateAssets->permittedAt($status),
                "assets must be regenerable from {$status->value}"
            );

            $this->assertTrue(
                $status->canTransitionTo(StoryStatus::AssetsGenerating),
                "{$status->value} -> assets_generating must be a legal move"
            );
        }
    }

    /**
     * Regenerating assets drops the story BELOW Gate 3, so the Gate 3 approval
     * cannot survive it.
     *
     * There is no flag to invalidate: the approval IS the
     * `rendered -> metadata_ready` transition, so landing below `rendered` means
     * the only way back to `metadata_ready` is approveGate(Preview) again. A
     * render approved while it was silent said nothing about the narrated one.
     */
    public function test_regenerating_assets_forfeits_the_gate_three_approval(): void
    {
        $after = OperatorAction::RegenerateAssets->targetStatus(StoryStatus::MetadataReady);

        $this->assertSame(StoryStatus::AssetsGenerating, $after);
        $this->assertLessThan(StoryStatus::Rendered->rank(), $after->rank());

        // And getting back to metadata_ready is a gate crossing, not a move.
        $this->assertFalse(StoryStatus::Rendered->canTransitionTo(StoryStatus::MetadataReady)
            && StoryStatus::Rendered->gateFor(StoryStatus::MetadataReady) === null);
    }

    /**
     * The refusal that pointed into a dead end.
     *
     * `reopenRefusalReason()` tells an operator at `rendering` to run
     * `render:cancel`, promising it "lands on assets_ready, which can reopen".
     * That promise has to be true, or the app's own error message routes the
     * operator somewhere they cannot leave — `rendering` has no reopen edge.
     */
    public function test_cancelling_a_render_lands_where_the_error_message_promises(): void
    {
        $this->assertTrue(OperatorAction::CancelRender->permittedAt(StoryStatus::Rendering));

        $landing = OperatorAction::CancelRender->targetStatus(StoryStatus::Rendering);

        $this->assertSame(StoryStatus::AssetsReady, $landing);
        $this->assertTrue(StoryStatus::Rendering->canTransitionTo($landing));

        // The whole point of landing there: it can reopen, and `rendering` cannot.
        $this->assertTrue($landing->canReopenScenesGate());
        $this->assertFalse(StoryStatus::Rendering->canReopenScenesGate());

        // And the message really does name that command, so this test breaks if
        // the promise is reworded rather than silently diverging from it.
        $this->assertStringContainsString(
            'render:cancel',
            (string) StoryStatus::Rendering->reopenRefusalReason(),
        );
    }

    /**
     * Gate 1's reopen, which was offered at nine statuses and legal at one.
     */
    public function test_gate_one_reopen_is_offered_only_where_it_works(): void
    {
        foreach (StoryStatus::cases() as $status) {
            $permitted = OperatorAction::ReopenOutlineGate->permittedAt($status);

            $this->assertSame(
                $status === StoryStatus::Scripted,
                $permitted,
                "Gate 1 reopen availability is wrong at {$status->value}"
            );

            if ($permitted) {
                $this->assertTrue($status->canTransitionTo(StoryStatus::Outlined));
            }
        }
    }

    /**
     * Nothing may generate paid assets before Gate 2, at any status, through any
     * capability.
     *
     * The money invariant, re-asserted here rather than only where it is
     * enforced: this file is the cross product, so it is the place a new
     * capability that quietly skipped the check would show up.
     */
    public function test_no_capability_reaches_paid_assets_before_gate_two(): void
    {
        foreach (StoryStatus::cases() as $status) {
            if ($status->allowsPaidAssets()) {
                continue;
            }

            $this->assertFalse(
                OperatorAction::RegenerateAssets->permittedAt($status),
                "paid asset generation must be refused at {$status->value}"
            );

            $this->assertFalse(
                $status->canTransitionTo(StoryStatus::AssetsGenerating),
                "{$status->value} -> assets_generating must not be a legal move"
            );
        }
    }

    /**
     * A status that can go nowhere must offer nothing.
     */
    public function test_a_published_story_offers_no_capability_at_all(): void
    {
        $this->assertSame([], StoryStatus::Published->allowedTransitions());

        foreach (OperatorAction::cases() as $action) {
            $this->assertFalse(
                $action->permittedAt(StoryStatus::Published),
                "{$action->value} must not be offered on a published story"
            );
        }
    }

    /**
     * No capability starts a batch while another is in flight.
     *
     * Both in-flight statuses are represented, and the distinction between them
     * matters: `rendering` forbids asset regeneration (the render would encode
     * one file while the row named another), while `assets_generating` permits
     * it, because that IS the retry path for the scenes that failed.
     */
    public function test_capabilities_respect_work_already_in_flight(): void
    {
        $this->assertFalse(OperatorAction::RegenerateAssets->permittedAt(StoryStatus::Rendering));
        $this->assertFalse(OperatorAction::ReopenScenesGate->permittedAt(StoryStatus::Rendering));

        $this->assertTrue(OperatorAction::RegenerateAssets->permittedAt(StoryStatus::AssetsGenerating));
        $this->assertNull(OperatorAction::RegenerateAssets->targetStatus(StoryStatus::AssetsGenerating));
    }
}
