<?php

namespace App\Actions;

use App\Models\Scene;
use App\Models\Story;
use App\Support\RenderWorkspace;
use Illuminate\Support\Collection;

/**
 * Delete the derived files that no longer describe the story.
 *
 * Extracted from ApproveScenesGate because a second caller arrived — assets
 * being regenerated on a story that has already rendered — and a second copy of
 * this logic is exactly how the two paths would come to disagree about what
 * "stale" means. Nothing here is a new rule; it is the same rule with one home.
 *
 * **Nothing here costs money to rebuild, and nothing here can reach a paid
 * asset.** That is structural rather than careful: stills and narration live on
 * the `assets` disk and every file this touches lives on `renders`. The two were
 * separated for exactly this reason — a re-render must never re-bill for a
 * still — so this action could not delete one if it tried.
 *
 * The reason to delete rather than leave them is correctness, not tidiness. A
 * `final.mp4` that no longer matches the assets is a finished video an operator
 * can sit down and approve at Gate 3. And a stale clip left on disk is worse
 * than an absent one: frame counts derive from audio duration, so a clip built
 * against different audio is wrong in a way that only surfaces at the concat
 * assertion, tens of minutes into a render.
 */
class DiscardRenderArtifacts
{
    /**
     * Files that encode the whole story rather than one scene.
     *
     * Each one carries the entire scene sequence and its timeline, so any scene
     * changing invalidates all of them together.
     */
    public const WHOLE_VIDEO_ARTIFACTS = [
        'silent.mp4',
        'narration.wav',
        'narration.mp3',
        'narration.txt',
        'clips.txt',
        'subs.ass',
        'scene_audio.json',
        'final.mp4',
    ];

    /**
     * @param  Collection<int, Scene>  $scenes  Scenes whose clip and padded audio are stale.
     * @param  bool  $wholeVideo  Also discard the whole-video artifacts.
     * @param  bool  $sweepDirectories
     *                                  Empty the clip and padded directories entirely. For a REORDER, where
     *                                  clips are filed as `scene-%03d` from sequence and the survivors are
     *                                  misfiled rather than merely stale — sweeping is simpler than reasoning
     *                                  about which ones moved, and costs only CPU to rebuild.
     * @return array{clips: int, whole_video: int}
     */
    public function handle(
        Story $story,
        Collection $scenes,
        bool $wholeVideo,
        bool $sweepDirectories = false,
    ): array {
        if ($story->slug === null) {
            return ['clips' => 0, 'whole_video' => 0];
        }

        $workspace = RenderWorkspace::for($story);
        $clips = 0;

        foreach ($scenes as $scene) {
            // BOTH counted. The padded WAV used to be unlinked without being
            // added to the total, so a sweep that removed 362 files reported
            // 181 — which is a small lie in a codebase whose recurring defect is
            // reporting success it has not earned. A count nobody can trust is
            // worse than no count.
            $clips += (int) @unlink($workspace->clipPath($scene));
            $clips += (int) @unlink($workspace->paddedAudioPath($scene));
        }

        if ($sweepDirectories) {
            foreach (['clips', 'padded'] as $directory) {
                foreach (glob($workspace->path($directory).'/*') ?: [] as $file) {
                    $clips += (int) @unlink($file);
                }
            }
        }

        $removed = 0;

        if ($wholeVideo) {
            foreach (self::WHOLE_VIDEO_ARTIFACTS as $artifact) {
                $removed += (int) @unlink($workspace->path($artifact));
            }
        }

        return ['clips' => $clips, 'whole_video' => $removed];
    }
}
