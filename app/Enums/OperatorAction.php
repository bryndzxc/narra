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
    /**
     * Write the outline and then every act script, in order.
     *
     * Free of gate ceremony and not free of money: a six-act story is seven
     * billed calls. It is a capability rather than a bare button because it was
     * `story:write` and nothing else for the whole of Phase 2 — the one stage
     * of the pipeline with no way into it except a terminal.
     */
    case WriteScript = 'write_script';

    /**
     * Write the outline AGAIN, on a story that already has one.
     *
     * A separate capability from WriteScript rather than a flag on it, and the
     * reason is that at `outlined` the two press buttons that spend different
     * money on different things: WriteScript there means "write the acts this
     * outline is missing", and this means "throw the outline away and buy
     * another one". One capability answering for both is how a button and a
     * command come to disagree about what they are permitted to do, which this
     * enum exists to prevent.
     *
     * It exists because the repair for a bad outline was terminal-only. A
     * story with no scripts and a wrong outline is a $0.28 fix and there was
     * no press for it — the second Gate 1 finding whose repair lived in a
     * command, after the locale term in an act script.
     *
     * POSITION ONLY, and the precondition is elsewhere on purpose: `outlined`
     * is also where a story with every script written waits for approval, and
     * deleting those is what GenerateOutline::WRITTEN_ACTS refuses. A status
     * cannot see it.
     */
    case ReOutline = 're_outline';

    /**
     * Write three premise candidates from the operator's idea.
     *
     * Only at `draft`, before the outline exists: a premise is what the
     * outline is written from, and once there is an outline a new premise
     * would describe a story nobody wrote. One billed Sonnet call, and a
     * separate button from Write because choosing a premise is its own
     * decision — the outline is the second press.
     */
    case WritePremises = 'write_premises';

    /** Back to Gate 1 to edit the act outline. */
    case ReopenOutlineGate = 'reopen_outline_gate';

    /**
     * Extract the cast, then cut the act scripts into scenes.
     *
     * Fills Gate 2. Deliberately a separate capability from WriteScript rather
     * than the tail of it: Gate 1 sits between them, and a single "write the
     * story" action that ran through to scenes would cross a gate on the
     * operator's behalf, which is the one thing this app does not do.
     */
    case DraftSceneList = 'draft_scene_list';

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

    /**
     * Re-run word alignment, and only word alignment.
     *
     * Separate from RegenerateAssets because the two want opposite things from
     * the same computation: `needsTranscription` and `needsNarration` overlap
     * heavily, so "retry the failed alignments" through the asset path would
     * have re-billed 69 narrations on the story this was written for. Free, and
     * structurally incapable of billing — see AssetsTimings.
     */
    case AlignTimings = 'align_timings';

    /** Render, or re-render, the scene clips through to the final mux. */
    case DispatchRender = 'dispatch_render';

    /** Call off an in-flight batch. */
    case CancelRender = 'cancel_render';

    /**
     * Write the YouTube publish sheet.
     *
     * After the render, never alongside the script: chapters are derived from
     * act timings and act timings are written by the mux.
     */
    case WriteMetadata = 'write_metadata';

    public function label(): string
    {
        return match ($this) {
            self::WriteScript => 'Write the outline and act scripts',
            self::ReOutline => 'Write the outline again',
            self::WritePremises => 'Write three premises from an idea',
            self::ReopenOutlineGate => 'Reopen Gate 1',
            self::DraftSceneList => 'Extract the cast and draft the scenes',
            self::ReopenScenesGate => 'Reopen Gate 2',
            self::RegenerateAssets => 'Generate scene assets',
            self::AlignTimings => 'Re-run word timings only',
            self::DispatchRender => 'Dispatch the render',
            self::CancelRender => 'Cancel the in-flight batch',
            self::WriteMetadata => 'Write the publish sheet',
        };
    }

    /** The entry points that must consult this, named so the audit can say so. */
    public function callers(): string
    {
        return match ($this) {
            self::WriteScript => 'story:write, NewStory::create(), OutlineGate::write(), DispatchTextStage',
            self::ReOutline => 'story:write --re-outline, OutlineGate::reOutline(), DispatchTextStage::writeScript()',
            self::WritePremises => 'OutlineGate::writePremises(), DispatchTextStage::writePremises()',
            self::ReopenOutlineGate => 'OutlineGate::reopen() and its blade',
            self::DraftSceneList => 'story:scenes, ScenesGate::draftScenes(), DispatchTextStage',
            self::ReopenScenesGate => 'ScenesGate::reopen() and its blade',
            self::RegenerateAssets => 'assets:generate, ScenesGate::canGenerateAssets(), DispatchAssetGeneration',
            self::AlignTimings => 'assets:timings, ScenesGate::alignTimings()',
            self::DispatchRender => 'render:dispatch, PreviewGate::dispatchRender(), '
                .'PreviewGate::reject(), DispatchRenderPipeline',
            self::CancelRender => 'render:cancel, PreviewGate::cancelRender(), CancelRenderBatch',
            self::WriteMetadata => 'metadata:generate, MetadataGate::draft()',
        };
    }

    public function permittedAt(StoryStatus $status): bool
    {
        return match ($this) {
            // The two statuses GenerateOutline itself accepts: `draft` is the
            // first run and `outlined` is the operator asking for a different
            // structure at Gate 1. Refused from `scripted` onward, because by
            // then the acts carry scripts written against this outline and
            // replacing it would orphan every one of them.
            self::WriteScript => match ($status) {
                StoryStatus::Draft,
                StoryStatus::Outlined => true,
                default => false,
            },

            // `outlined` and nothing else. At `draft` there is no outline to
            // replace and WriteScript is the press; past `outlined` the acts
            // carry scripts and Gate 1 has been approved, which is the same
            // wall WriteScript hits and for the same reason.
            self::ReOutline => $status === StoryStatus::Outlined,

            // Only from `scripted`. Walking further back than one step is a
            // walk, not a jump: scenes drafted against this outline exist, and
            // the operator returns through Gate 2 first.
            // Draft and nothing else. See the case.
            self::WritePremises => $status === StoryStatus::Draft,

            self::ReopenOutlineGate => $status === StoryStatus::Scripted,

            // `scripted` is the first draft; `scenes_drafted` is a re-draft the
            // operator asked for at Gate 2. The same pair DraftScenes accepts,
            // and it stops at `scenes_approved` for the reason everything stops
            // there — paid assets are attached to the scene list by then.
            self::DraftSceneList => match ($status) {
                StoryStatus::Scripted,
                StoryStatus::ScenesDrafted => true,
                default => false,
            },

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

            // Exactly the RegenerateAssets set, and that is not laziness. This
            // aligns audio that only exists after Gate 2, and it is excluded at
            // `rendering` for the same reason: the subtitle stage reads these
            // timings, so rewriting them under a running clip batch would burn
            // one thing while the row named another. Free is not the same as
            // harmless.
            self::AlignTimings => self::RegenerateAssets->permittedAt($status),

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

            // After the render and not before it. Chapters are derived from act
            // timings and act timings are written by the mux, so a sheet drafted
            // earlier would carry timestamps for a video that does not exist.
            self::WriteMetadata => match ($status) {
                StoryStatus::Rendered,
                StoryStatus::MetadataReady => true,
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
            // Null at both statuses, and deliberately so. Dispatching is not
            // writing: GenerateOutline moves `draft` -> `outlined` inside the
            // job, at the moment an outline actually lands, and a dispatcher
            // that moved the status first would leave a story reading
            // `outlined` with no acts in it if the provider refused.
            self::WriteScript => null,

            // Candidates are written beside the story; nothing moves until
            // the operator writes the outline from one of them.
            self::WritePremises => null,

            // Moves nothing. The story is already at `outlined` and stays
            // there: a replaced outline is the same decision to make again,
            // not progress through the gate.
            self::ReOutline => null,

            self::ReopenOutlineGate => StoryStatus::Outlined,

            // Same reasoning. DraftScenes moves `scripted` -> `scenes_drafted`
            // once scenes exist to be reviewed.
            self::DraftSceneList => null,

            self::ReopenScenesGate => StoryStatus::ScenesDrafted,

            self::RegenerateAssets => $status === StoryStatus::AssetsGenerating
                ? null
                : StoryStatus::AssetsGenerating,

            // Never moves anything. Alignment is a repair on assets that
            // already exist, and a repair that changed the story's status would
            // be reporting progress it did not make.
            self::AlignTimings => null,

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

            // `rendered -> metadata_ready` is Gate 3's crossing and stays Gate
            // 3's. Drafting the sheet is not a substitute for watching the
            // render, so writing it moves nothing.
            self::WriteMetadata => null,
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
            // Refused from `scripted` onward, and the way back is a walk rather
            // than a jump — the same walk ReopenOutlineGate describes, because
            // it is the same walk.
            self::WriteScript => match (true) {
                $status === StoryStatus::Scripted => 'Gate 1 has been approved and every act carries a '
                    .'script written against this outline. Reopen Gate 1 — that returns the story to '
                    .'"outlined", where the outline and the scripts can be written again.',

                default => sprintf(
                    'the story is at "%s", well past the outline. Scenes, and probably paid assets, '
                    .'were built on the scripts as they stand. Walk back one gate at a time: reopen '
                    .'Gate 2 first, then Gate 1, and the script can be rewritten from there.',
                    $status->value,
                ),
            },

            // Deliberately says nothing about what the acts CONTAIN. The
            // WriteScript refusal above states "every act carries a script"
            // having checked only a status, which is the figure-axis defect
            // this file records; a second copy of it would be a second one.
            self::ReOutline => match (true) {
                $status->rank() < StoryStatus::Outlined->rank() => 'there is no outline to replace yet. '
                    .'Write the first one — that is the same press, at "draft".',

                default => sprintf(
                    'Gate 1 has been approved (the story is at "%s"), so the outline has work built on '
                    .'it. Reopen Gate 1 — that returns the story to "outlined", where it can be '
                    .'written again.',
                    $status->value,
                ),
            },

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

            self::DraftSceneList => match (true) {
                $status->rank() < StoryStatus::Scripted->rank() => 'Gate 1 has not been approved yet. '
                    .'Scenes are cut from the act scripts, so the outline has to be settled first — '
                    .'approve Gate 1, which moves the story to "scripted".',

                default => sprintf(
                    'Gate 2 has already been approved (the story is at "%s"), and paid assets are '
                    .'attached to the scene list as it stands. Re-drafting would orphan them. Reopen '
                    .'Gate 2 first, which returns the story to "scenes_drafted".',
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

            self::AlignTimings => match (true) {
                $status === StoryStatus::Rendering => 'a clip batch is in flight, and the subtitle stage '
                    .'reads these timings. Rewriting them now would burn one thing while the row named '
                    .'another. Cancel the batch first — that lands on assets_ready.',

                default => 'no scene has narration audio to align yet. Alignment is free, but it runs on '
                    .'audio that only exists after Gate 2 is approved and the narration has been '
                    .'generated. Approve Gate 2 and generate scene assets first.',
            },

            self::DispatchRender => 'the assets are not ready. Every scene needs its still, its narration '
                .'and its word timings before a clip can be encoded — run `php artisan assets:generate '
                .'<story>` and let it reach assets_ready.',

            self::CancelRender => 'nothing is in flight. There is a batch to cancel only at '
                .'assets_generating or rendering.',

            self::WritePremises => sprintf(
                'the story has an outline (it is at "%s"). A premise is what the outline is written '
                .'from, so a new one now would describe a story nobody wrote. Premises are written '
                .'at "draft", before the outline.',
                $status->value,
            ),

            self::WriteMetadata => 'there is no finished render. Chapters are derived from act timings '
                .'and act timings are written by the mux, so a sheet written now would carry timestamps '
                .'for a video that does not exist. Render first and approve Gate 3.',
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
