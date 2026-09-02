<?php

namespace App\Exceptions;

use App\Enums\Gate;
use App\Enums\OperatorAction;
use App\Enums\StoryStatus;
use DomainException;

/**
 * Thrown when something tries to move a story somewhere it may not go, or to
 * spend money before Gate 2 has been passed.
 *
 * A DomainException rather than a validation error on purpose: this is not a
 * form the operator filled in wrong, it is code attempting an illegal move.
 * The four gates and the paid-asset line are invariants of the product, so the
 * failure is loud and it happens at the model, where every caller — controller,
 * Livewire component, queued job, Artisan command — has to pass through it.
 */
class GateViolationException extends DomainException
{
    public static function illegalTransition(StoryStatus $from, StoryStatus $to): self
    {
        $allowed = array_map(
            fn (StoryStatus $status): string => $status->value,
            $from->allowedTransitions()
        );

        return new self(sprintf(
            "Cannot move a story from '%s' to '%s'. Allowed from here: %s.",
            $from->value,
            $to->value,
            $allowed === [] ? 'nothing, this status is terminal' : implode(', ', $allowed)
        ));
    }

    public static function gateNotApproved(StoryStatus $from, StoryStatus $to, Gate $gate): self
    {
        return new self(sprintf(
            "Moving from '%s' to '%s' crosses %s, which needs an operator. "
            .'Call approveGate(Gate::%s) instead of transitionTo() — a gate is never crossed as a side effect.',
            $from->value,
            $to->value,
            $gate->label(),
            $gate->name
        ));
    }

    public static function notWaitingAtGate(Gate $gate, StoryStatus $actual): self
    {
        return new self(sprintf(
            "%s can only be approved from '%s'; this story is '%s'.",
            $gate->label(),
            $gate->waitsAt()->value,
            $actual->value
        ));
    }

    /**
     * A reopen asked for from a status that has no way back to Gate 2.
     *
     * Distinct from illegalTransition() because "Cannot move a story from
     * 'rendered' to 'scenes_drafted'" describes the machine rather than the
     * situation. Both refusals have a specific next action behind them and the
     * message says which.
     */
    /**
     * An operator capability refused because it is not available at this status.
     *
     * The general form of cannotReopenScenesGate(), for every other capability
     * on OperatorAction. It exists so that a refusal decided by the shared
     * predicate throws the same domain exception a refusal decided by the
     * transition table does — a caller that had to distinguish "refused because
     * the guard said no" from "refused because the machine said no" would be
     * back to two sources of truth for one rule.
     */
    public static function actionUnavailable(OperatorAction $action, StoryStatus $status): self
    {
        return new self((string) $action->refusal($status));
    }

    public static function cannotReopenScenesGate(StoryStatus $from): self
    {
        return new self(sprintf(
            "Gate 2 cannot be reopened from '%s': %s",
            $from->value,
            $from->reopenRefusalReason() ?? 'no reason given, which is itself a bug.'
        ));
    }

    /**
     * A character sheet asked for before the story has any scenes to stand in
     * front of.
     *
     * Separate from paidAssetsLocked() because it names a different line. Both
     * are money guards; this one unlocks at `scenes_drafted` rather than
     * `scenes_approved`, and an operator told the wrong threshold will go
     * looking for the wrong button.
     */
    public static function referenceSpendLocked(StoryStatus $status, ?string $operation = null): self
    {
        return new self(sprintf(
            '%s is blocked: the story is at \'%s\' and character reference sheets cannot be '
            .'generated before \'%s\'. The sheet is a Gate 2 decision, so the scenes it will be '
            .'used on have to exist before it is worth paying for a face.',
            $operation === null ? 'Reference generation' : "Reference operation '{$operation}'",
            $status->value,
            StoryStatus::ScenesDrafted->value
        ));
    }

    public static function paidAssetsLocked(StoryStatus $status, ?string $operation = null): self
    {
        return new self(sprintf(
            '%s is blocked: the story is at \'%s\' and no paid asset generation may begin '
            .'before \'%s\'. Gate 2 is where an operator reviews every scene, and it is the '
            .'last free moment — images alone are ~70%% of a video\'s cost across 150-250 stills.',
            $operation === null ? 'Paid asset generation' : "Paid operation '{$operation}'",
            $status->value,
            StoryStatus::ScenesApproved->value
        ));
    }
}
