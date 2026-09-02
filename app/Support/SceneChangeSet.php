<?php

namespace App\Support;

use App\Contracts\SpeechSynthesizer;
use App\Contracts\Transcriber;
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
        /**
         * The subset the operator restricted this run to, or null for all.
         *
         * Carried on the object rather than applied by the caller so that the
         * quote, the dispatch and the transcription batch cannot disagree about
         * what "this run" means — which is the same reason the whole class
         * exists.
         */
        public readonly ?SceneSelection $selection = null,
        /**
         * Scenes that need work and are NOT in this run because the selection
         * excluded them.
         *
         * Distinct from preserved(), and the distinction is the whole honesty
         * of a limited run: a preserved scene is finished, a deferred scene is
         * outstanding and simply not being paid for yet. Reporting the second
         * as the first would let a five-scene run read as a finished story.
         */
        public readonly int $deferred = 0,
    ) {}

    /**
     * @param  SceneSelection|null  $only  Restrict the PAID stages to this subset.
     */
    public static function for(Story $story, ?SceneSelection $only = null): self
    {
        // Eager, not lazy. This runs over every scene in the story and the
        // narration checks read scene_audio; at 250 scenes a lazy relation here
        // is 250 extra queries on a page the operator is waiting on.
        $scenes = $story->scenes()->with('sceneAudio')->get();

        // Resolved once, from the container rather than from config, and folded
        // into the staleness checks below.
        //
        // Without this, "already generated" means only "the text has not
        // changed" — so a story narrated end to end by a stand-in reports
        // nothing outstanding, and binding a real provider then pressing
        // Generate assets does nothing, silently. The text-based check is right
        // about money and blind about provenance; this supplies the other half.
        $speech = app(SpeechSynthesizer::class);
        $transcriber = app(Transcriber::class);
        $voiceId = $story->voice_id;

        // A digest that was never recorded means the story has never passed
        // Gate 2, so there is no previous scene set for this one to differ
        // from. Reported as unchanged: it is a first approval, and the
        // per-scene checks below already say everything needs generating.
        $sceneSetChanged = $story->approved_scene_digest !== null
            && $story->approved_scene_digest !== $story->sceneDigest();

        $needsImage = $scenes->filter(fn (Scene $scene): bool => $scene->needsImage())->values();

        $needsNarration = $scenes->filter(fn (Scene $scene): bool => $scene->needsNarration()
            || $scene->narrationProvenanceStale($speech->providerName(), $voiceId))->values();

        // New audio always means new timings, so a scene whose narration is
        // being regenerated is transcribed again regardless of who timed it
        // last — otherwise the karaoke line would describe audio that no longer
        // exists.
        $needsTranscription = $scenes->filter(fn (Scene $scene): bool => $scene->needsTranscription()
            || $scene->timingsProvenanceStale($transcriber->providerName())
            || $scene->narrationProvenanceStale($speech->providerName(), $voiceId))->values();

        // The selection narrows the three PAID stages and nothing else.
        //
        // needsClip and sceneSetChanged are deliberately left whole-story: they
        // are free, they describe the finished video rather than a scene, and a
        // clip list narrowed to five scenes would silently describe a render
        // that cannot be assembled.
        $outstanding = $needsImage->merge($needsNarration)->merge($needsTranscription)
            ->pluck('id')->unique()->count();

        if ($only !== null) {
            $needsImage = $needsImage->filter(fn (Scene $s): bool => $only->matches($s))->values();
            $needsNarration = $needsNarration->filter(fn (Scene $s): bool => $only->matches($s))->values();
            $needsTranscription = $needsTranscription->filter(fn (Scene $s): bool => $only->matches($s))->values();
        }

        $inRun = $needsImage->merge($needsNarration)->merge($needsTranscription)
            ->pluck('id')->unique()->count();

        return new self(
            story: $story,
            needsImage: $needsImage,
            needsNarration: $needsNarration,
            needsTranscription: $needsTranscription,
            // A reorder renames every clip: they are filed as `scene-%03d` from
            // `sequence`, so swapping scenes 2 and 3 leaves each one's contents
            // under the other's number. Cheap to rebuild, silently wrong to keep.
            needsClip: $sceneSetChanged
                ? $scenes->values()
                : $scenes->filter(fn (Scene $scene): bool => $scene->needsClip())->values(),
            sceneSetChanged: $sceneSetChanged,
            sceneCount: $scenes->count(),
            selection: $only,
            deferred: $outstanding - $inRun,
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

        // Deferred scenes are subtracted as well as regenerating ones. They are
        // outstanding work that this run is simply not paying for yet, and
        // counting them as preserved is how a five-scene run would report
        // itself as a finished story.
        return $this->sceneCount - $regenerating - $this->deferred;
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

        if ($this->deferred > 0) {
            $lines[] = sprintf(
                'LIMITED RUN — scenes %s only. %d other scene(s) still need work and are NOT in this '
                .'run; the story stays parked until they are generated too.',
                $this->selection?->describe() ?? '?',
                $this->deferred,
            );
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
