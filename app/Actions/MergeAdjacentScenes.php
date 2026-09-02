<?php

namespace App\Actions;

use App\Enums\StoryStatus;
use App\Models\Scene;
use App\Support\SentenceSplitter;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Folds one scene into the one before it, keeping the narration verbatim.
 *
 * The fix for a scene too short to look at. Three or four words is about a
 * second and a half on screen: the Ken Burns move never completes, and the cut
 * reads as a flicker rather than a beat.
 *
 * **Merging, not rewriting.** The narration is the act script, word for word,
 * and it stays that way — the two scenes' sentences are re-split and re-joined
 * so the result is byte-identical to what a single scene covering that span
 * would have held. Nothing is paraphrased, nothing is dropped, and the running
 * order does not move. That is what keeps this free: `narration_text` changes
 * only in where the scene boundaries fall, and no sentence is added or lost.
 *
 * **Which frame survives is a real choice, so it is a parameter.** The earlier
 * scene keeps its position in the video, but not necessarily its picture. When
 * the absorbed scene carries the stronger image — a close-up of the document
 * being read, a room after four years of not being opened — that is the frame
 * the merged span should hold, and the pivot of who is in it travels with the
 * frame rather than with the narration. The frame decides which faces get a
 * reference attached; the narration can name people it never shows.
 *
 * Refuses on a story whose scenes are locked. This edits `narration_text`,
 * which is a paid input, and the gate is where changing one is authorised —
 * even when, as now, nothing has been generated yet for it to invalidate.
 */
class MergeAdjacentScenes
{
    public function __construct(
        private readonly SentenceSplitter $splitter,
        private readonly ReorderScenes $reorder,
    ) {}

    /**
     * @param  Scene  $into  The earlier scene. Keeps its place in the running order.
     * @param  bool  $keepAbsorbedFrame  Take the picture, motion and cast from the later scene.
     */
    public function handle(Scene $into, Scene $absorbed, bool $keepAbsorbedFrame = false): Scene
    {
        $this->assertMergeable($into, $absorbed);

        return DB::transaction(function () use ($into, $absorbed, $keepAbsorbedFrame): Scene {
            // Re-split and re-join rather than concatenating the two strings.
            // DraftScenes builds narration by joining sentences, so going
            // through the same splitter is what makes a merged scene
            // indistinguishable from a natively drafted one — no doubled
            // spaces, no lost sentence boundary.
            $sentences = array_merge(
                $this->splitter->split((string) $into->narration_text),
                $this->splitter->split((string) $absorbed->narration_text),
            );

            $attributes = ['narration_text' => $this->splitter->join($sentences)];

            if ($keepAbsorbedFrame) {
                $attributes['image_prompt'] = $absorbed->image_prompt;
                $attributes['motion_preset'] = $absorbed->motion_preset;
            }

            // Either scene having been nominated is enough. Gate 4 needs a
            // still to recommend and the merged scene now contains that moment.
            $attributes['is_thumbnail_candidate'] = $into->is_thumbnail_candidate
                || $absorbed->is_thumbnail_candidate;

            $into->forceFill($attributes)->save();

            if ($keepAbsorbedFrame) {
                // Presence travels with the picture, not with the words. A
                // reference is attached because a face is IN the frame; a
                // narration that merely names somebody must not pull their
                // reference into a shot they do not appear in.
                $into->characters()->sync($absorbed->characters()->pluck('characters.id')->all());
            }

            $absorbed->delete();

            $this->reorder->renumber($into->story);

            return $into->refresh();
        });
    }

    private function assertMergeable(Scene $into, Scene $absorbed): void
    {
        if ($into->story_id !== $absorbed->story_id) {
            throw new RuntimeException('Those scenes belong to different stories.');
        }

        if (! in_array($into->story->status, [StoryStatus::Scripted, StoryStatus::ScenesDrafted], true)) {
            throw new RuntimeException(sprintf(
                "Scenes are locked at '%s'. Merging rewrites narration_text, which is what the "
                .'narration and its word timings are billed against, so it belongs behind Gate 2 '
                .'like every other change to a paid input. Reopen the gate first.',
                $into->story->status->value,
            ));
        }

        if ($into->act_id !== $absorbed->act_id) {
            // Acts are the unit the script is written and chaptered in, and a
            // scene's sentence range is an offset into ONE act's script. A
            // scene spanning two of them has no coherent range, and it would
            // also straddle a YouTube chapter boundary.
            throw new RuntimeException(sprintf(
                'Scene %d is in act %d and scene %d is in act %d. A scene cannot span two acts: its '
                .'narration is a range within one act script, and acts are the chapter boundaries.',
                $into->sequence,
                $into->act->sequence,
                $absorbed->sequence,
                $absorbed->act->sequence,
            ));
        }

        if ($absorbed->sequence !== $into->sequence + 1) {
            throw new RuntimeException(sprintf(
                'Scenes %d and %d are not adjacent. Merging non-adjacent scenes would reorder the '
                .'narration, and the narration is the act script in its own order.',
                $into->sequence,
                $absorbed->sequence,
            ));
        }
    }
}
