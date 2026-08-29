<?php

namespace App\Support;

use App\Models\Scene;
use App\Models\Story;
use Illuminate\Support\Collection;

/**
 * What a Gate 2 re-approval would actually have to redo, and what it must not.
 *
 * The rule this exists to enforce: reopening Gate 2 preserves what was already
 * paid for. Images and narration for untouched scenes stay on disk, are not
 * re-billed, and their clips are not re-rendered. Only a scene whose narration
 * or image prompt actually changed is regenerated.
 *
 * Which means the question "did this scene change" cannot be answered with
 * `updated_at` or Eloquent's isDirty(). Both move when the operator ticks
 * `is_thumbnail_candidate`, switches a motion preset, or has a scene renumbered
 * out from under them by a reorder — and none of those cost a cent to redo. At
 * 250 stills and ~70% of a video's cost, answering that question loosely is the
 * most expensive mistake in the app.
 *
 * So the comparison is against a snapshot taken at approval, field by field,
 * separated by what each field bills for:
 *
 *   narration_text -> TTS + transcription. Also duration_ms, so also the clip.
 *   image_prompt   -> image generation. Also the clip. Nothing else.
 *   motion_preset  -> the clip. Free.
 *   scene order    -> every clip filename, and the whole-video artifacts. Free.
 *
 * Everything here is a pure read. Nothing is deleted, nulled or transitioned —
 * ApproveScenesGate does that, once, after the operator has seen this.
 */
class SceneChangeSet
{
    /**
     * @param  Collection<int, Scene>  $needsImage
     * @param  Collection<int, Scene>  $needsNarration
     * @param  Collection<int, Scene>  $needsTranscription
     * @param  Collection<int, Scene>  $needsClip
     */
    private function __construct(
        public readonly Story $story,
        public readonly Collection $needsImage,
        public readonly Collection $needsNarration,
        public readonly Collection $needsTranscription,
        public readonly Collection $needsClip,
        public readonly bool $sceneSetChanged,
        public readonly int $sceneCount,
    ) {}

    public static function for(Story $story): self
    {
        // Eager, not lazy. This runs over every scene in the story and the
        // narration checks read scene_audio; at 250 scenes a lazy relation here
        // is 250 extra queries on a page the operator is waiting on.
        $scenes = $story->scenes()->with('sceneAudio')->get();

        // A digest that was never recorded means the story has never passed
        // Gate 2, so there is no previous scene set for this one to differ
        // from. Reported as unchanged: it is a first approval, and the
        // per-scene checks below already say everything needs generating.
        $sceneSetChanged = $story->approved_scene_digest !== null
            && $story->approved_scene_digest !== $story->sceneDigest();

        return new self(
            story: $story,
            needsImage: $scenes->filter(fn (Scene $scene): bool => $scene->needsImage())->values(),
            needsNarration: $scenes->filter(fn (Scene $scene): bool => $scene->needsNarration())->values(),
            needsTranscription: $scenes->filter(fn (Scene $scene): bool => $scene->needsTranscription())->values(),
            // A reorder renames every clip: they are filed as `scene-%03d` from
            // `sequence`, so swapping scenes 2 and 3 leaves each one's contents
            // under the other's number. Cheap to rebuild, silently wrong to keep.
            needsClip: $sceneSetChanged
                ? $scenes->values()
                : $scenes->filter(fn (Scene $scene): bool => $scene->needsClip())->values(),
            sceneSetChanged: $sceneSetChanged,
            sceneCount: $scenes->count(),
        );
    }

    /**
     * Scenes whose paid assets survive untouched.
     *
     * The number that matters at the confirmation: it is what the operator is
     * NOT paying for again.
     */
    public function preserved(): int
    {
        $regenerating = $this->needsImage
            ->merge($this->needsNarration)
            ->merge($this->needsTranscription)
            ->pluck('id')
            ->unique()
            ->count();

        return $this->sceneCount - $regenerating;
    }

    /** Whether re-approving would bill anything at all. */
    public function billsAnything(): bool
    {
        return $this->needsImage->isNotEmpty()
            || $this->needsNarration->isNotEmpty()
            || $this->needsTranscription->isNotEmpty();
    }

    /**
     * Whether the whole-video artifacts still describe this story.
     *
     * silent.mp4, narration.wav, subs.ass and final.mp4 each encode the entire
     * scene sequence and its timeline, so any scene changing, or the scene set
     * changing at all, invalidates all of them together. Free to rebuild — but
     * a stale final.mp4 left in place is a finished video that no longer matches
     * the scene list, and Gate 3 exists to be watched.
     */
    public function videoIsStale(): bool
    {
        return $this->sceneSetChanged || $this->needsClip->isNotEmpty();
    }

    /** Nothing changed. The reopen was a look, and it cost nothing. */
    public function isEmpty(): bool
    {
        return ! $this->videoIsStale() && ! $this->billsAnything();
    }

    /**
     * One line per kind of work, for the confirmation the operator reads before
     * the second press. Empty when re-approving would change nothing.
     *
     * @return array<int, string>
     */
    public function summary(): array
    {
        $lines = [];

        foreach ([
            ['images', $this->needsImage->count()],
            ['narrations', $this->needsNarration->count()],
            ['transcriptions', $this->needsTranscription->count()],
        ] as [$label, $count]) {
            if ($count > 0) {
                $lines[] = sprintf('%d %s will be generated and billed.', $count, $label);
            }
        }

        // Clips that have to be re-encoded even though nothing about them was
        // paid for: a motion preset change, or a reorder. Worth its own line —
        // it is the difference between "this costs an hour" and "this costs
        // money", and the operator is deciding about both.
        $repaid = $this->needsImage->merge($this->needsNarration)->pluck('id')->unique();
        $clipsOnly = $this->needsClip->reject(fn (Scene $scene): bool => $repaid->contains($scene->id))->count();

        if ($clipsOnly > 0) {
            $lines[] = sprintf(
                '%d scene clip(s) will be re-rendered from assets already paid for. CPU only, no charge.',
                $clipsOnly
            );
        }

        if ($this->sceneSetChanged) {
            $lines[] = 'The scene list changed order or length, so every clip is refiled and re-rendered. '
                .'No charge — the stills and narration are reused.';
        }

        if ($this->videoIsStale()) {
            $lines[] = 'The concatenated video, its narration track, its subtitles and final.mp4 are '
                .'discarded and rebuilt. No charge, but it is a full re-render.';
        }

        if ($this->preserved() > 0) {
            $lines[] = sprintf(
                '%d scene(s) keep their existing image, narration and timings. Nothing is re-billed for them.',
                $this->preserved()
            );
        }

        return $lines;
    }
}
