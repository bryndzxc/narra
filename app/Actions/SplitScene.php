<?php

namespace App\Actions;

use App\Enums\SceneStatus;
use App\Enums\StoryStatus;
use App\Models\Scene;
use App\Support\ImagePromptBuilder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Cuts one scene into two, without changing a word of the narration.
 *
 * The fix for a scene nobody can look at for that long. Seventy-plus words is
 * about half a minute on one still: the Ken Burns move finishes with twenty
 * seconds to spare and the viewer is looking at a photograph.
 *
 * **The verbatim guarantee is checked, not trusted.** The caller supplies the
 * two halves and this refuses unless they recombine — character for character,
 * once whitespace is normalised — into exactly what the scene held before.
 * Narration is the act script word for word; a split is a decision about where
 * the picture changes, never an opportunity to reword. Anything that fails that
 * comparison is a paraphrase, and a paraphrase here silently changes what the
 * operator approved and what a paid TTS call will read aloud.
 *
 * That check is also what lets a split fall mid-sentence. Scene drafting works
 * in whole sentences because it is choosing ranges from a script; a 74-word
 * single sentence therefore cannot be divided by any re-draft, at any price.
 * Cutting it at a clause boundary is the only fix that does not rewrite the
 * prose — and the pause it introduces lands where the comma already was.
 */
class SplitScene
{
    public function __construct(
        private readonly ImagePromptBuilder $prompts,
    ) {}

    /**
     * @param  string  $newFrame  The second half's picture. It needs its own: no
     *                            existing prompt describes that part of the span.
     * @param  array<int, string>|null  $present  Who is in the new frame. Defaults to
     *                                            whoever was in the original.
     * @param  string|null  $firstHalfFrame  Replaces the original scene's frame too.
     *                                       Needed more often than it looks: a scene
     *                                       runs long because it covers two beats, and
     *                                       the picture it already has usually belongs
     *                                       to one of them — frequently the second.
     */
    public function handle(
        Scene $scene,
        string $firstHalf,
        string $secondHalf,
        string $newFrame,
        ?array $present = null,
        ?string $firstHalfFrame = null,
    ): Scene {
        $this->assertSplittable($scene);
        $this->assertVerbatim($scene, $firstHalf, $secondHalf);

        return DB::transaction(function () use ($scene, $firstHalf, $secondHalf, $newFrame, $present, $firstHalfFrame): Scene {
            $cast = $scene->story->characters()->get();
            $names = $present ?? $scene->characters()->pluck('name')->all();

            $keep = ['narration_text' => trim($firstHalf)];

            if ($firstHalfFrame !== null) {
                $keep['image_prompt'] = $this->prompts->build(
                    $firstHalfFrame,
                    $cast,
                    $scene->characters()->pluck('name')->all(),
                );
            }

            $scene->forceFill($keep)->save();

            // Make room first, from the back. (story_id, sequence) is unique,
            // so every scene after this one shifts up by one before the new row
            // can take the slot immediately behind its parent.
            //
            // Parking the new row high and renumbering afterwards does NOT
            // work: renumber() orders by sequence alone, so a parked row sorts
            // to the end and the second half of a scene lands at the end of the
            // video instead of after the first half.
            $scene->story->scenes()
                ->where('sequence', '>', $scene->sequence)
                // reorder() first, or the relation's own ascending order wins
                // and the shift runs front-to-back — which collides with the
                // row it is about to move into.
                ->reorder()
                ->orderByDesc('sequence')
                ->get()
                ->each(fn (Scene $later) => $later->forceFill(['sequence' => $later->sequence + 1])->save());

            $created = Scene::create([
                'story_id' => $scene->story_id,
                'act_id' => $scene->act_id,
                // The same chapter: a split is two halves of one scene's
                // narration, and narration does not change chapter mid-scene.
                'chapter_id' => $scene->chapter_id,
                'sequence' => $scene->sequence + 1,
                // Never. The hook is the first scene of the video and a split
                // produces a second half, which by definition is not first.
                'is_hook' => false,
                // Not inherited. A thumbnail nomination points at a specific
                // picture, and this is a different picture.
                'is_thumbnail_candidate' => false,
                'narration_text' => trim($secondHalf),
                'image_prompt' => $this->prompts->build($newFrame, $cast, $names),
                // The move that was chosen for the first half was chosen for
                // its frame. The second half gets the opposite direction so the
                // cut reads as a cut rather than one long continuous push.
                'motion_preset' => $scene->motion_preset->opposite(),
                'status' => SceneStatus::Drafted,
            ]);

            $created->characters()->sync(
                $cast->whereIn('name', $names)->pluck('id')->all()
            );

            return $created->refresh();
        });
    }

    private function assertSplittable(Scene $scene): void
    {
        if (! in_array($scene->story->status, [StoryStatus::Scripted, StoryStatus::ScenesDrafted], true)) {
            throw new RuntimeException(sprintf(
                "Scenes are locked at '%s'. Splitting rewrites narration_text and adds a scene that "
                .'will need its own still and its own narration, so it belongs behind Gate 2. '
                .'Reopen the gate first.',
                $scene->story->status->value,
            ));
        }
    }

    private function assertVerbatim(Scene $scene, string $firstHalf, string $secondHalf): void
    {
        $normalise = fn (string $text): string => (string) preg_replace('/\s+/u', ' ', trim($text));

        $original = $normalise((string) $scene->narration_text);
        $rejoined = $normalise($firstHalf.' '.$secondHalf);

        if ($original !== $rejoined) {
            throw new RuntimeException(sprintf(
                "The two halves do not add up to scene %d's narration, so this is a rewrite rather "
                ."than a split.\n\n  was: %s\n  now: %s\n\nNarration is the act script word for word. "
                .'A split decides where the picture changes; it does not get to change the words.',
                $scene->sequence,
                mb_substr($original, 0, 160),
                mb_substr($rejoined, 0, 160),
            ));
        }

        if (trim($firstHalf) === '' || trim($secondHalf) === '') {
            throw new RuntimeException('Both halves of a split have to contain narration.');
        }
    }
}
