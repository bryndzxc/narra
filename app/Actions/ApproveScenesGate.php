<?php

namespace App\Actions;

use App\Enums\AssetStatus;
use App\Enums\Gate;
use App\Enums\SceneStatus;
use App\Exceptions\MissingCharacterReferenceException;
use App\Models\Scene;
use App\Models\SceneAudio;
use App\Models\Story;
use App\Support\SceneChangeSet;
use Illuminate\Support\Facades\DB;

/**
 * Cross Gate 2, and — on a re-approval after a reopen — discard exactly what
 * went stale and nothing else.
 *
 * This is the only place in the app that discards a paid asset, and the rule it
 * follows is the one that matters: reopening Gate 2 preserves what was already
 * paid for. A scene the operator only read keeps its still, its narration and
 * its word timings, is not re-billed, and does not even re-render its clip. A
 * scene whose narration or image prompt actually changed loses precisely the
 * assets that depended on the field that changed — not the other ones.
 *
 * Deletion happens here rather than at reopen on purpose. Reopening is entered
 * speculatively; an operator who opens scene 147, reads it and closes the page
 * again should not have paid an hour of re-render for the look. Doing it here
 * also means there is exactly one moment where the destruction is known, so it
 * can be shown before the second press rather than discovered afterwards.
 *
 * The gate still opens to `scenes_approved`, always — Gate::opensTo() is a
 * constant and no shortcut forward is added. Unchanged scenes cost nothing on
 * the way back through the pipeline because every stage is already idempotent:
 * with its image_path and audio_path intact and its fingerprints matching, an
 * untouched scene is skipped by the asset jobs, and RenderSceneClipJob already
 * keeps a clip that holds the right number of frames.
 */
class ApproveScenesGate
{
    /** Rebuilt from scratch whenever anything at all changed. Free — CPU only. */
    public function __construct(
        private readonly ValidateCharacterSheets $sheets,
        // Shared with DispatchAssetGeneration. Two copies of "what is stale"
        // would eventually disagree about it, and the disagreement would be a
        // finished-looking video that no longer matches its assets.
        private readonly DiscardRenderArtifacts $artifacts,
    ) {}

    /**
     * @return SceneChangeSet What was applied, so the caller can report it.
     */
    public function handle(Story $story): SceneChangeSet
    {
        // Before anything else, and before the transaction: crossing this gate
        // authorises 150-250 paid stills, and a still cannot be generated for a
        // scene whose characters have no approved face. That failure has to
        // happen HERE, where nothing has been spent and the operator is on the
        // screen that fixes it — not inside the image batch, ninety images
        // deep, where the same check runs as a backstop and is a far worse
        // place to learn it.
        $this->assertCastIsReferenced($story);

        $changes = SceneChangeSet::for($story);

        DB::transaction(function () use ($story, $changes): void {
            $this->clearStalePaidAssets($changes);
            $this->markScenes($changes);

            foreach ($story->scenes()->get() as $scene) {
                $scene->recordGateTwoApproval();
            }

            $story->forceFill([
                'approved_scene_digest' => $story->sceneDigest(),
                // The reopen is closed out. Leaving it set would make a later,
                // clean approval look like it was still recovering from one.
                'reopened_from' => null,
            ])->save();

            $story->approveGate(Gate::Scenes);
        });

        // Outside the transaction, deliberately: an unlink cannot be rolled
        // back, so doing it inside would let a late failure leave the database
        // claiming assets that are no longer on disk. Committing the row state
        // first means the worst case is an orphaned file, which the next render
        // overwrites, rather than a row pointing at nothing.
        $this->deleteStaleFiles($story, $changes);

        return $changes;
    }

    /**
     * Null the pointers for assets that must be paid for again — and only those.
     *
     * The two fields bill separately and so are cleared separately. Editing the
     * narration of scene 147 must not throw away the still that scene 147's
     * unchanged image prompt already paid for, and editing the image prompt
     * must not throw away the audio or the word timings.
     */
    private function clearStalePaidAssets(SceneChangeSet $changes): void
    {
        // `changed`, not `needs`. needsImage() is "absent OR stale" and is true
        // on a first approval, when nothing has been recorded yet — clearing on
        // that would throw away an asset whose provenance is merely unknown. An
        // asset is discarded because it demonstrably changed, never because we
        // cannot prove it did not.
        foreach ($changes->needsImage->filter(fn (Scene $scene): bool => $scene->imagePromptChanged()) as $scene) {
            /** @var Scene $scene */
            $scene->forceFill(['image_path' => null])->save();
        }

        foreach ($changes->needsNarration->filter(fn (Scene $scene): bool => $scene->narrationChanged()) as $scene) {
            /** @var Scene $scene */
            // duration_ms goes with the audio: it IS the audio's length, and a
            // frame count derived from the old narration would size the new
            // clip wrongly and put every offset after it out.
            $scene->forceFill(['duration_ms' => null])->save();

            $scene->sceneAudio()->get()->each(function (SceneAudio $audio): void {
                $audio->forceFill([
                    'audio_path' => null,
                    'timings_json' => null,
                    'duration_ms' => null,
                    'padded_duration_ms' => null,
                    'frames' => null,
                    // Offsets are cumulative, so one scene changing length puts
                    // every offset after it out. They are recomputed wholesale
                    // at concat rather than patched.
                    'offset_frames' => null,
                    'offset_samples' => null,
                    'offset_ms' => null,
                    'status' => AssetStatus::Pending,
                ])->save();
            });
        }

        // Transcription going stale without the narration changing only happens
        // when the timings are missing outright, which the asset stage fills in.
        // Nothing to clear in that case — but a row holding timings for audio
        // that is about to be replaced is handled above.
    }

    /**
     * Approved means "cleared to spend on". A scene that still has everything
     * it needs stays Ready, because telling the progress page it needs work
     * again is how an operator ends up regenerating what they already own.
     */
    private function markScenes(SceneChangeSet $changes): void
    {
        foreach ($changes->story->scenes()->with('sceneAudio')->get() as $scene) {
            if ($scene->status === SceneStatus::Ready && $scene->paidAssetsStillValid()) {
                continue;
            }

            $scene->forceFill(['status' => SceneStatus::Approved])->save();
        }
    }

    /**
     * Delete the derived files that no longer describe the story.
     *
     * Nothing here costs money to rebuild — every one of these is CPU applied
     * to assets that stay on disk. The reason to delete rather than leave them
     * is correctness: a final.mp4 that no longer matches the scene list is a
     * finished video an operator can sit down and approve at Gate 3.
     */
    private function deleteStaleFiles(Story $story, SceneChangeSet $changes): void
    {
        if ($changes->isEmpty()) {
            return;
        }

        $this->artifacts->handle(
            story: $story,
            scenes: $changes->needsClip,
            wholeVideo: $changes->videoIsStale(),
            // A reorder refiles every clip under a different number, so the
            // survivors belong to scenes that no longer sit at those sequences.
            sweepDirectories: $changes->sceneSetChanged,
        );
    }

    /**
     * Refuse the gate while a character in a frame has no face on file.
     *
     * The alternative is not "generate it from text" — that option does not
     * exist anywhere in this feature, by design. A face drawn from a
     * description looks correct in isolation and drifts across the video, and
     * the drift is not visible until every still has been paid for.
     *
     * @throws MissingCharacterReferenceException
     */
    private function assertCastIsReferenced(Story $story): void
    {
        $state = $this->sheets->handle($story);

        if ($state['ready']) {
            return;
        }

        throw new MissingCharacterReferenceException(sprintf(
            'Gate 2 cannot be approved: %d character%s in this story appear in scenes but have no '
            .'reference image picked (%s), which blocks %d of %d scenes. Approving would authorise '
            .'paid stills that cannot be generated. Generate and pick their character sheets first '
            .'— that step is on this page and costs a fraction of the stills it protects.',
            $state['missing']->count(),
            $state['missing']->count() === 1 ? '' : 's',
            $state['missing']->pluck('name')->implode(', '),
            $state['scenes_blocked'],
            $story->scenes()->count(),
        ));
    }
}
