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

        foreach ($changes->needsNarration->filter(fn (Scene $scene): bool => $this->narrationIsStale($scene)) as $scene) {
            /** @var Scene $scene */
            // duration_ms goes with the audio: it IS the audio's length, and a
            // frame count derived from the old narration would size the new
            // clip wrongly and put every offset after it out.
            $scene->forceFill(['duration_ms' => null])->save();

            $scene->sceneAudio()->get()->each(function (SceneAudio $audio): void {
                /*
                 * CLEAR ALL, not some — and this used to clear some.
                 *
                 * It nulled the pointer, the timings and the derived lengths
                 * and left `samples`, `sample_rate` and the narration
                 * provenance standing. That is a row describing a file it no
                 * longer claims to have, and `samples` is not decorative:
                 * `AudioFrames` treats it as the AUTHORITATIVE input to frame
                 * arithmetic, over `duration_ms`, precisely because a
                 * millisecond cannot represent where audio ends. So a
                 * half-cleared row is one another reader can compute a frame
                 * count from.
                 *
                 * The surviving `samples` is what let the four discarded files
                 * be identified and re-pointed for nothing, which is a real
                 * argument and still the wrong instrument: evidence belongs in
                 * a backup, not in a live row that other code reads as fact. A
                 * row that can be half-believed is worse than one that is
                 * plainly empty.
                 *
                 * The list mirrors what `GenerateSceneNarration` WRITES, so the
                 * two stay symmetric: everything generation sets is cleared
                 * here, and everything generation already nulls stays null.
                 */
                $audio->forceFill([
                    'audio_path' => null,
                    'timings_json' => null,
                    'timings_provider' => null,
                    'timings_simulated' => null,
                    'duration_ms' => null,
                    'samples' => null,
                    'sample_rate' => null,
                    // Provenance of a file that no longer exists describes
                    // nothing. Generation rewrites every one of these.
                    'narration_provider' => null,
                    'narration_voice_id' => null,
                    'narration_speed' => null,
                    'narration_simulated' => null,
                    'narration_text_hash' => null,
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
     * Does this scene's audio provably disagree with its current narration?
     *
     * -----------------------------------------------------------------------
     * ASK THE ARTIFACT, NOT THE RECORD
     * -----------------------------------------------------------------------
     *
     * `narrationChanged()` compares `approved_narration_hash` against the text.
     * That answers "has the text changed since the operator last approved it",
     * which is a fact about the RECORD, and it was standing in for a fact about
     * the ARTIFACT: "was this audio made from this text".
     *
     * They agree until the two go out of order. Story 25: the narration text
     * was repaired, the four affected scenes were re-narrated from the repaired
     * text for $0.1274, and Gate 2 was re-approved afterwards. At that moment
     * the approval record still described the PRE-repair text, so
     * `narrationChanged()` was true and four files that were exactly right were
     * discarded for being stale. The audio was current; the approval was not.
     *
     * `scene_audio.narration_text_hash` is written by GenerateSceneNarration at
     * the moment of synthesis, so it can answer the artifact question directly
     * and the ORDER STOPS MATTERING. Repair-then-narrate-then-approve and
     * repair-then-approve-then-narrate now both keep the audio, because both
     * end with a file made from the current text.
     *
     * **UNKNOWN IS NOT DISCARD, AND IT IS NOT KEEP EITHER.** A null hash is
     * audio made before this column existed. It falls through to exactly the
     * predicate that governed it before, so adding the column discards nothing
     * that would not already have been discarded and keeps nothing that would
     * not already have been kept — the backfill's absence changes no behaviour
     * at all. Reading null as "matches" would make every legacy edit at Gate 2
     * silently keep audio for text that no longer exists, which is a false
     * success traded for a false purge.
     *
     * The trap therefore closes for all future audio the first time a scene is
     * narrated, and stays open for pre-column rows until they are.
     */
    private function narrationIsStale(Scene $scene): bool
    {
        $audio = $scene->sceneAudio->first(
            fn (SceneAudio $a): bool => $a->narration_text_hash !== null
        );

        if ($audio !== null) {
            // A positive reading: this file was made from words we can name,
            // and they are not these words.
            return $audio->narration_text_hash !== $scene->narrationFingerprint();
        }

        return $scene->narrationChanged();
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
