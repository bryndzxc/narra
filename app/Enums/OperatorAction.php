<?php

namespace App\Enums;

/**
 * Every operator capability that moves a story, and the one place that decides
 * when it is available.
 *
 * This exists because the same bug happened four times.
 *
 * A button decides for itself whether to show. A command decides for itself
 * whether to run. The transition table decides, separately, whether the move is
 * legal. Three expressions of one rule, compared only by hand — so they drift,
 * and the drift is invisible until an operator presses the thing and gets
 * "Cannot move a story from 'rendered' to 'assets_generating'". By then it has
 * cost a round trip, and the next one costs another.
 *
 * The count, when it was finally audited: the Gate 1 reopen button was offered
 * at nine statuses and legal at one. `assets:generate` accepted any status while
 * its own button correctly refused past `rendered`. `render:dispatch` accepted
 * any status and was illegal from `metadata_ready`. And `render:cancel` — which
 * an error message elsewhere in the app explicitly recommends, promising it
 * "lands on assets_ready" — never touched the status at all, stranding the
 * story at `rendering`, which has no way back.
 *
 * `canReopenScenesGate()` had already solved this for exactly one button. This
 * generalises it: a capability is a case here, its availability is
 * `permittedAt()`, its refusal is a sentence with a next action in it, and the
 * move it makes is `targetStatus()`. Buttons ask it, commands ask it, and
 * StoryStatusAuditTest walks every case against all eleven statuses and fails if
 * a permitted capability would attempt an illegal move — so the fifth one of
 * these is found by CI rather than by the operator.
 */
enum OperatorAction: string
{
    /** Back to Gate 1 to edit the act outline. */
    case ReopenOutlineGate = 'reopen_outline_gate';

    /** Back to Gate 2 to edit scenes. */
    case ReopenScenesGate = 'reopen_scenes_gate';

    /**
     * Generate or regenerate paid scene assets.
     *
     * A spend, never a gate crossing — which is the whole reason it reaches
     * past `rendered`. Replacing placeholder narration on a story that has
     * already rendered must not require reopening Gate 2, because reopening
     * Gate 2 to fix audio would put all 186 paid stills back in play.
     */
    case RegenerateAssets = 'regenerate_assets';

    /** Render, or re-render, the scene clips through to the final mux. */
    case DispatchRender = 'dispatch_render';

    /** Call off an in-flight batch. */
    case CancelRender = 'cancel_render';

    public function label(): string
    {
        return match ($this) {
            self::ReopenOutlineGate => 'Reopen Gate 1',
            self::ReopenScenesGate => 'Reopen Gate 2',
            self::RegenerateAssets => 'Generate scene assets',
            self::DispatchRender => 'Dispatch the render',
            self::CancelRender => 'Cancel the in-flight batch',
        };
    }

    /** The entry points that must consult this, named so the audit can say so. */
    public function callers(): string
    {
        return match ($this) {
            self::ReopenOutlineGate => 'OutlineGate::reopen() and its blade',
            self::ReopenScenesGate => 'ScenesGate::reopen() and its blade',
            self::RegenerateAssets => 'assets:generate, ScenesGate::canGenerateAssets(), DispatchAssetGeneration',
            self::DispatchRender => 'render:dispatch, PreviewGate::reject(), DispatchRenderPipeline',
            self::CancelRender => 'render:cancel',
        };
    }

    public function permittedAt(StoryStatus $status): bool
    {
        return match ($this) {
            // Only from `scripted`. Walking further back than one step is a
            // walk, not a jump: scenes drafted against this outline exist, and
            // the operator returns through Gate 2 first.
            self::ReopenOutlineGate => $status === StoryStatus::Scripted,

            self::ReopenScenesGate => $status->canReopenScenesGate(),

            // From Gate 2 approval onward, which is where paid assets start
            // existing, and all the way through `metadata_ready`. Excluded:
            // `rendering`, because a clip batch is in flight and regenerating
            // an asset out from under it would have the render encode one file
            // while the row names another; and `published`, which is terminal.
            self::RegenerateAssets => match ($status) {
                StoryStatus::ScenesApproved,
                StoryStatus::AssetsGenerating,
                StoryStatus::AssetsReady,
                StoryStatus::Rendered,
                StoryStatus::MetadataReady => true,
                default => false,
            },

            // `rendering` is permitted and is a no-op move: re-dispatching a
            // render that is already running is how a partially-failed clip
            // batch is resumed.
            self::DispatchRender => match ($status) {
                StoryStatus::AssetsReady,
                StoryStatus::Rendering,
                StoryStatus::Rendered,
                StoryStatus::MetadataReady => true,
                default => false,
            },

            // The two statuses that mean "jobs are in flight". Both are
            // cancellable; they land in different places, see targetStatus().
            self::CancelRender => match ($status) {
                StoryStatus::AssetsGenerating,
                StoryStatus::Rendering => true,
                default => false,
            },
        };
    }

    /**
     * Where this lands the story from `$status`, or null when it makes no move.
     *
     * Null is a real answer and not an absence. Re-dispatching a render that is
     * already `rendering`, or regenerating assets on a story already at
     * `assets_generating`, is a legitimate retry that changes no status — and a
     * caller that transitioned anyway would be asking the machine for a
     * self-edge that does not exist.
     */
    public function targetStatus(StoryStatus $status): ?StoryStatus
    {
        if (! $this->permittedAt($status)) {
            return null;
        }

        return match ($this) {
            self::ReopenOutlineGate => StoryStatus::Outlined,
            self::ReopenScenesGate => StoryStatus::ScenesDrafted,

            self::RegenerateAssets => $status === StoryStatus::AssetsGenerating
                ? null
                : StoryStatus::AssetsGenerating,

            self::DispatchRender => $status === StoryStatus::Rendering
                ? null
                : StoryStatus::Rendering,

            // Cancelling a render lands on the status a re-run starts from.
            // Cancelling an ASSET batch lands nowhere by itself: reconcile
            // decides, because whether the story is finished depends on which
            // scenes got their assets before the batch was called off.
            self::CancelRender => $status === StoryStatus::Rendering
                ? StoryStatus::AssetsReady
                : null,
        };
    }

    /**
     * Why this is refused from here, phrased for the operator.
     *
     * Null when permitted. A bare "illegal transition" is true and useless —
     * every refusal here names the next action, because an operator who is
     * refused without one is an operator who files a bug or, worse, edits the
     * status column.
     */
    public function refusalReason(StoryStatus $status): ?string
    {
        if ($this->permittedAt($status)) {
            return null;
        }

        if ($status === StoryStatus::Published) {
            return 'the story is published. That is terminal: the file is on YouTube and this app does '
                .'not reach it.';
        }

        return match ($this) {
            self::ReopenOutlineGate => match (true) {
                $status->rank() <= StoryStatus::Outlined->rank() => 'Gate 1 has not been approved yet, '
                    .'so the outline is still editable and there is nothing to reopen.',

                default => sprintf(
                    'scenes have already been drafted against this outline (the story is at "%s"). Walk '
                    .'back one gate at a time: reopen Gate 2 first, which returns the story to '
                    .'scenes_drafted, then to scripted — and Gate 1 reopens from there.',
                    $status->value,
                ),
            },

            self::ReopenScenesGate => $status->reopenRefusalReason(),

            self::RegenerateAssets => match (true) {
                $status === StoryStatus::Rendering => 'a clip batch is in flight. Regenerating an asset '
                    .'out from under it would have the render encode one file while the row names '
                    .'another. Cancel it first (php artisan render:cancel <story>), which lands on '
                    .'assets_ready.',

                default => 'Gate 2 has not been approved, and no paid asset generation may begin before '
                    .'scenes_approved. Images alone are ~70% of a video across 150-250 stills, so '
                    .'"generate first, review later" is not a recoverable mistake.',
            },

            self::DispatchRender => 'the assets are not ready. Every scene needs its still, its narration '
                .'and its word timings before a clip can be encoded — run `php artisan assets:generate '
                .'<story>` and let it reach assets_ready.',

            self::CancelRender => 'nothing is in flight. There is a batch to cancel only at '
                .'assets_generating or rendering.',
        };
    }

    /**
     * The refusal, prefixed with the action, ready to print or throw.
     */
    public function refusal(StoryStatus $status): ?string
    {
        $reason = $this->refusalReason($status);

        return $reason === null ? null : sprintf('%s is not available: %s', $this->label(), $reason);
    }
}
