<?php

namespace App\Actions;

use App\Contracts\ScriptWriter;
use App\Enums\MotionPreset;
use App\Enums\RenderStage;
use App\Enums\SceneStatus;
use App\Enums\StoryStatus;
use App\Models\Act;
use App\Models\Character;
use App\Models\RenderJob;
use App\Models\Scene;
use App\Models\Story;
use App\Support\ImagePromptBuilder;
use App\Support\LocaleGuard;
use App\Support\Providers\CharacterProfile;
use App\Support\Providers\SceneDraft;
use App\Support\SentenceSplitter;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Act scripts become 150-250 scenes, each with narration and an image prompt.
 *
 * The stage that fills Gate 2. Everything after Gate 2 bills, so this is the
 * last free moment — and the last place a bad image prompt costs a text edit
 * rather than a re-billed still.
 *
 * Two properties are load-bearing and neither is obvious from the output:
 *
 *  1. **The narration is verbatim by construction.** The generator returns
 *     sentence RANGES, and the narration is sliced out of the approved script
 *     in PHP. A model asked to echo 1,000 words back will occasionally
 *     paraphrase, drop a clause or fix a "typo", and none of that shows up
 *     anywhere — it just changes what an operator approved and what a paid TTS
 *     call reads aloud. The ranges are then checked to cover every sentence
 *     exactly once, so a bad split is loud instead of lossy.
 *
 *  2. **The image prompt is assembled, not generated.** The model writes only
 *     the frame. The art style comes from config, once, and each character's
 *     description is pasted verbatim from the `characters` table. See
 *     ImagePromptBuilder for why neither is left to the model.
 *
 * Idempotent per story: a story that already has scenes keeps them unless the
 * caller asks for a rebuild. Every act here is a billed call.
 */
class DraftScenes
{
    /**
     * Where a partial re-draft parks its new rows before renumbering.
     *
     * `(story_id, sequence)` is unique and the acts NOT being re-drafted still
     * hold the low numbers, so a partial run cannot count from 1. High rather
     * than negative: the column is an unsignedSmallInteger.
     */
    private const PARK_BASE = 30000;

    public function __construct(
        private readonly ScriptWriter $writer,
        private readonly LocaleGuard $locale,
        private readonly RecordProviderCost $costs,
        private readonly SentenceSplitter $splitter,
        private readonly ImagePromptBuilder $prompts,
        private readonly RecordSceneCast $cast,
    ) {}

    /**
     * @param  Closure|null  $progress  fn (Act $act, int $scenes, string $note): void
     * @param  array<int, int>  $onlyActs  Act sequences to re-draft, leaving the rest
     *                                     alone. Empty means the whole story.
     * @return array{scenes: int, acts: int, kept: bool}
     */
    public function handle(
        Story $story,
        bool $rebuild = false,
        ?Closure $progress = null,
        array $onlyActs = [],
    ): array {
        $this->assertReady($story);

        // A partial re-draft is always a rebuild of the acts it names — there
        // is no "keep what is there" reading of "draft act 6 again".
        $rebuild = $rebuild || $onlyActs !== [];

        if (! $rebuild && $story->scenes()->exists()) {
            // Deliberately outside the row. Nothing ran, nothing was billed, and
            // opening a `succeeded` render_jobs row for a no-op would put a
            // green stage on the progress page for work that did not happen —
            // which is the reporting failure this project keeps finding, not a
            // convenience.
            return ['scenes' => $story->scenes()->count(), 'acts' => 0, 'kept' => true];
        }

        return RenderJob::record(
            $story->id,
            RenderStage::DraftScenes,
            fn (RenderJob $job): array => $this->draft($story, $rebuild, $progress, $onlyActs, $job),
        );
    }

    /**
     * @param  array<int, int>  $onlyActs
     * @return array{scenes: int, acts: int, kept: bool}
     */
    private function draft(
        Story $story,
        bool $rebuild,
        ?Closure $progress,
        array $onlyActs,
        RenderJob $job,
    ): array {
        $cast = $story->characters()->get();

        if ($cast->isEmpty()) {
            throw new RuntimeException(
                'This story has no characters. The cast is extracted first and its descriptions are '
                .'pasted into every image prompt — drafting scenes without it would mean 150-250 '
                .'stills each inventing their own description of the same people, which is the '
                .'single biggest quality risk in this format.'
            );
        }

        $acts = $story->acts()->orderBy('sequence')->get();

        if ($onlyActs !== []) {
            $acts = $acts->whereIn('sequence', $onlyActs)->values();

            if ($acts->isEmpty()) {
                throw new RuntimeException(sprintf(
                    'This story has no act %s. It has acts 1-%d.',
                    implode(' or ', $onlyActs),
                    $story->acts()->max('sequence') ?? 0,
                ));
            }
        }

        $profiles = $this->profiles($cast);

        $drafted = [];

        foreach ($acts as $act) {
            $script = trim((string) $act->script);

            if ($script === '') {
                throw new RuntimeException("Act {$act->sequence} has no script to draft scenes from.");
            }

            $sentences = $this->splitter->split($script);
            $target = $this->targetScenesFor($script);

            $job->note(sprintf(
                'Act %d of %d — splitting %d sentences into ~%d scenes...',
                $act->sequence,
                $acts->count(),
                count($sentences),
                $target,
            ));

            $set = $this->writer->scenes($story, $act, $sentences, $profiles, $target);

            // Every attempt, not just the one that produced the scenes. When a
            // cheap model's sentence ranges fail to tile the act, the provider
            // re-runs it on the fallback — and that first call was still billed.
            // Recording only the winner would report a saving that did not
            // happen, which is the one thing the cost table must never do.
            foreach ($set->allUsages() as $usage) {
                $this->costs->handle($story, $usage);
            }

            $this->locale->assert(
                $set->proseForInspection(),
                (string) $story->locale_profile,
                "scene drafting for act {$act->sequence}"
            );

            $this->assertRangesCoverScript($act, $set->scenes, count($sentences));

            $drafted[$act->id] = ['act' => $act, 'sentences' => $sentences, 'scenes' => $set->scenes];

            $progress?->__invoke($act, $set->count(), sprintf('%d sentences', count($sentences)));

            $job->note(sprintf(
                'Act %d of %d — %d scenes%s.',
                $act->sequence,
                $acts->count(),
                $set->count(),
                // Named, because a fallback that fired is a second billed call
                // for the same act and reads in the ledger as a duplicate.
                $set->discardedAttempts !== [] ? ' (first attempt discarded, fell back)' : '',
            ));
        }

        $total = $this->persist($story, $cast, $drafted, $rebuild, $onlyActs);

        $job->note(sprintf('%d scenes written across %d act(s).', $total, $acts->count()));

        return ['scenes' => $total, 'acts' => $acts->count(), 'kept' => false];
    }

    /**
     * Write the scenes, numbering them across the whole story.
     *
     * `scenes.sequence` is story-wide, not act-wide: it is what names a clip on
     * disk (`scene-007.mp4`), what an operator says out loud, and what the
     * progress page shows when one of 200 fails.
     *
     * @param  Collection<int, Character>  $cast
     * @param  array<int, array{act: Act, sentences: array<int, string>, scenes: array<int, SceneDraft>}>  $drafted
     * @param  array<int, int>  $onlyActs
     */
    private function persist(Story $story, Collection $cast, array $drafted, bool $rebuild, array $onlyActs = []): int
    {
        return DB::transaction(function () use ($story, $cast, $drafted, $rebuild, $onlyActs): int {
            $partial = $onlyActs !== [];

            if ($partial) {
                // Only the acts being re-drafted. Everything else keeps its
                // scenes, its prompts and its operator edits — which is the
                // whole point: `--rebuild` throwing away five good acts to fix
                // one is what made this option necessary.
                $story->scenes()->whereIn('act_id', array_keys($drafted))->delete();
            } elseif ($rebuild) {
                $story->scenes()->delete();
            }

            // New rows are parked above the live range and renumbered at the
            // end. `(story_id, sequence)` is unique, so a partial re-draft
            // cannot number from 1 — those numbers belong to acts it is not
            // touching.
            $sequence = $partial ? self::PARK_BASE : 0;
            $fallback = (array) config('scenes.motion_fallback');
            $thumbnails = 0;
            $maxThumbnails = (int) config('scenes.thumbnail_candidates.max');

            foreach ($drafted as $entry) {
                foreach ($entry['scenes'] as $draft) {
                    $sequence++;

                    $narration = $this->splitter->join(array_slice(
                        $entry['sentences'],
                        $draft->firstSentence - 1,
                        $draft->sentenceCount()
                    ));

                    $present = $this->resolvePresent($draft, $cast);

                    $isThumbnail = $draft->isThumbnailCandidate && $thumbnails < $maxThumbnails;
                    $thumbnails += $isThumbnail ? 1 : 0;

                    $scene = Scene::create([
                        'story_id' => $story->id,
                        'act_id' => $entry['act']->id,
                        'sequence' => $sequence,
                        // Exactly one hook, and it is the first scene of the
                        // video by definition — not something the generator
                        // votes on. Two scenes claiming the opening is not a
                        // state the format has an answer for.
                        // On a partial re-draft the opening scene lives in an
                        // act this run may not have touched, so the hook is
                        // settled after renumbering rather than from a counter
                        // that no longer starts at 1.
                        'is_hook' => ! $partial && $sequence === 1,
                        'is_thumbnail_candidate' => $isThumbnail,
                        'narration_text' => $narration,
                        'image_prompt' => $this->prompts->build($draft->frame, $cast, $present),
                        'motion_preset' => $this->motionFor($draft, $sequence, $fallback),
                        'status' => SceneStatus::Drafted,
                    ]);

                    // Presence is written down, not just used and discarded.
                    // It was computed here already — it decides whose frozen
                    // descriptions go into the prompt — and the only record of
                    // it used to be the prompt's own prose. The reference rule
                    // ("a scene featuring a character with no approved face
                    // fails loudly") cannot rest on grepping an
                    // operator-editable text field for names, so the same
                    // resolution that built the prompt is persisted.
                    $this->cast->handle($scene, $present, $cast);
                }
            }

            // Gate 4 asks for a thumbnail still and an operator who has to hunt
            // for one at that point will pick whatever is nearest. The hook is
            // the honest default: it is the frame the video opens on.
            if ($partial) {
                // Back to 1..n across the whole story, in act order. A
                // re-drafted act almost never yields the same scene count, so
                // every act after it shifts.
                $this->renumberByAct($story);
            }

            $total = $partial ? $story->scenes()->count() : $sequence;

            if ($thumbnails === 0 && $total > 0 && ! $partial) {
                $story->scenes()->where('sequence', 1)->update(['is_thumbnail_candidate' => true]);
            }

            if ($partial) {
                // Exactly one hook, and it is whatever is first now.
                $story->scenes()->update(['is_hook' => false]);
                $story->scenes()->where('sequence', 1)->update(['is_hook' => true]);
            }

            if ($story->status === StoryStatus::Scripted) {
                $story->transitionTo(StoryStatus::ScenesDrafted);
            }

            return $total;
        });
    }

    /**
     * Back to 1..n across the story, in act order.
     *
     * Ordering by act first and sequence second is what makes a partial
     * re-draft safe: the re-drafted act's new rows are parked high, so they sort
     * after the untouched acts' rows numerically — but within their own act,
     * which is where they belong. Sorting on sequence alone would drop a
     * re-drafted act 3 at the end of the video.
     */
    private function renumberByAct(Story $story): void
    {
        // reorder() first: the scenes relation carries a default
        // `orderBy('sequence')`, and once `acts` is joined that column name
        // exists on both tables — MySQL rejects the query as ambiguous rather
        // than guessing.
        $ordered = $story->scenes()
            ->reorder()
            ->join('acts', 'acts.id', '=', 'scenes.act_id')
            ->orderBy('acts.sequence')
            ->orderBy('scenes.sequence')
            ->pluck('scenes.id');

        // Park everything clear of the target range first; 1..n overlaps the
        // numbers still in use and the unique index does not care that the end
        // state would have been fine.
        foreach ($ordered as $index => $id) {
            Scene::whereKey($id)->update(['sequence' => self::PARK_BASE + $index]);
        }

        foreach ($ordered as $index => $id) {
            Scene::whereKey($id)->update(['sequence' => $index + 1]);
        }
    }

    /**
     * Every sentence in exactly one scene, in order.
     *
     * The check that makes "narration is verbatim" true rather than hoped for.
     * A gap drops narration from the video; an overlap says the same line
     * twice; a range past the end throws. All three are silent in the output
     * and obvious here.
     *
     * @param  array<int, SceneDraft>  $scenes
     */
    private function assertRangesCoverScript(Act $act, array $scenes, int $sentenceCount): void
    {
        $expected = 1;

        foreach ($scenes as $index => $draft) {
            if ($draft->firstSentence !== $expected) {
                throw new RuntimeException(sprintf(
                    'Act %d scene %d starts at sentence %d, but sentence %d is where the previous '
                    .'scene ended. A gap drops narration out of the video and an overlap says a line '
                    .'twice, and neither shows up anywhere but here.',
                    $act->sequence,
                    $index + 1,
                    $draft->firstSentence,
                    $expected
                ));
            }

            if ($draft->lastSentence > $sentenceCount) {
                throw new RuntimeException(sprintf(
                    'Act %d scene %d runs to sentence %d; the act has %d.',
                    $act->sequence,
                    $index + 1,
                    $draft->lastSentence,
                    $sentenceCount
                ));
            }

            $expected = $draft->lastSentence + 1;
        }

        if ($expected !== $sentenceCount + 1) {
            throw new RuntimeException(sprintf(
                'Act %d scenes cover sentences 1-%d of %d. The tail of the act would be silently '
                .'dropped from the video.',
                $act->sequence,
                $expected - 1,
                $sentenceCount
            ));
        }
    }

    /**
     * @param  Collection<int, Character>  $cast
     * @return array<int, string>
     */
    private function resolvePresent(SceneDraft $draft, Collection $cast): array
    {
        $resolved = [];

        foreach ($draft->charactersPresent as $name) {
            // Resolved against the stored cast rather than trusted. A frame
            // naming somebody who is not in the cast is usually the generator
            // inventing a person, and pasting no description for them is
            // better than pasting one it made up.
            $character = $this->prompts->resolve($name, $cast);

            if ($character !== null && ! in_array($character->name, $resolved, true)) {
                $resolved[] = $character->name;
            }
        }

        return $resolved;
    }

    /**
     * @param  array<int, string>  $fallback
     */
    private function motionFor(SceneDraft $draft, int $sequence, array $fallback): MotionPreset
    {
        $preset = MotionPreset::tryFrom((string) $draft->motionPreset);

        if ($preset !== null) {
            return $preset;
        }

        // Rotate rather than default. An unusable response degrading into 200
        // identical zoom-ins is a video that looks mechanical, and nothing in
        // the data would say why.
        return MotionPreset::from($fallback[($sequence - 1) % count($fallback)]);
    }

    private function targetScenesFor(string $script): int
    {
        $words = max(1, str_word_count($script));

        return max(1, (int) round($words / (int) config('scenes.words_per_scene')));
    }

    /**
     * @param  Collection<int, Character>  $cast
     * @return array<int, CharacterProfile>
     */
    private function profiles(Collection $cast): array
    {
        return $cast->map(fn (Character $c): CharacterProfile => new CharacterProfile(
            name: (string) $c->name,
            description: (string) $c->description,
            styleNotes: (string) $c->style_notes,
        ))->all();
    }

    private function assertReady(Story $story): void
    {
        if (in_array($story->status, [StoryStatus::Scripted, StoryStatus::ScenesDrafted], true)) {
            return;
        }

        throw new RuntimeException(sprintf(
            "Cannot draft scenes for a story at '%s'. Scenes are drafted from approved act scripts "
            .'and reviewed at Gate 2, which is the last free moment before anything bills.',
            $story->status->value
        ));
    }
}
