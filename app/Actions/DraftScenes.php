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
use App\Support\ThumbnailFraming;
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
     * Frames whose named characters could not be resolved to the stored cast.
     *
     * Collected during `persist()` and written to the draft's own `render_jobs`
     * row afterwards, NOT inside the transaction — `RenderJob::note()` saves a
     * different model, so a note written inside would be rolled back by the
     * very failure it was describing.
     *
     * A property rather than a return value because `persist()` already returns
     * the scene count through a transaction closure, and threading a second
     * value out of it would mean changing that shape for a report. Cleared at
     * the top of every draft so a reused instance cannot carry a previous run's
     * findings into this one's log.
     *
     * @var array<int, string>
     */
    private array $nameProblems = [];

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
        private readonly ReassignMotionPresets $motion,
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
     * The static share of a distribution, as a phrase for the log.
     *
     * Written next to the re-cut rather than derived later, because the
     * BEFORE figure is the only record of what the generator picked — the
     * column has been overwritten by the time anyone reads the row.
     *
     * @param  array<string, int>  $distribution
     */
    private function staticShare(array $distribution, int $total): string
    {
        if ($total < 1) {
            return 'n/a';
        }

        return sprintf('%.1f%%', 100 * (($distribution[MotionPreset::Static->value] ?? 0) / $total));
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
        // A previous run's findings must not appear in this run's log. The
        // container may hand back the same instance, and a stale finding
        // attached to the wrong draft is worse than none.
        $this->nameProblems = [];

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
                // for the same act and reads in the ledger as a duplicate — and
                // named WITH ITS REASON, because "fell back" on seven acts out of
                // seven told nobody which of the three axes had failed. That cost
                // $0.16 of discarded calls and a diagnosis that had to measure the
                // finished draft to recover something the check already knew.
                $set->discardedAttempts !== []
                    ? sprintf(' (fell back: %s)', $set->fallbackReason ?? 'reason not recorded')
                    : '',
            ));
        }

        $total = $this->persist($story, $cast, $drafted, $rebuild, $onlyActs);

        $job->note(sprintf('%d scenes written across %d act(s).', $total, $acts->count()));

        /*
         * NAMES THE FRAME USED THAT THE CAST COULD NOT ANSWER.
         *
         * Written after `persist()` rather than inside it, because `note()`
         * saves a row and a note written inside the transaction would be rolled
         * back by exactly the failure worth recording.
         *
         * This used to be nothing at all: an unresolvable name was dropped in
         * silence, and on `en-CN` that was every given name in the story. The
         * count leads so the line is scannable at a glance on a 270-scene
         * draft, and each finding is printed in full underneath because
         * "3 unresolved names" is not something an operator can act on and
         * "Song could be any of five Songs" is.
         */
        if ($this->nameProblems !== []) {
            $job->note(sprintf(
                '%d frame(s) named a character the cast could not answer for. Their descriptions are '
                .'missing from those prompts, which is a still generated without the reference it '
                .'should have had:',
                count($this->nameProblems),
            ));

            foreach ($this->nameProblems as $problem) {
                $job->note('  '.$problem);
            }
        }

        // The camera, assigned by code from the frame text rather than asked
        // for per scene.
        //
        // ReassignMotionPresets existed, worked, and was reachable only from
        // `scenes:recut` — so every story drafted through this Action kept
        // whatever the generator picked. Measured across three stories the
        // generator picks static 11.8%, 23.2% and 34.4% of the time against a
        // 15% ceiling: not a bad model, an unreliable one, which is exactly
        // the case for a mechanism instead of a request.
        //
        // Free by construction — it writes one column, and motion appears in
        // neither needsImage() nor needsNarration(), so nothing paid for is
        // invalidated. The generator is still asked, and its answer is still
        // recorded as the `before` distribution: that is the only measurement
        // of how good its picks were on any given story.
        $motion = $this->motion->handle($story->refresh());

        $job->note(sprintf(
            'Camera re-cut: %d of %d scene(s) reassigned. static %s -> %s.',
            $motion['changed'],
            $total,
            $this->staticShare($motion['before'], $total),
            $this->staticShare($motion['after'], $total),
        ));

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
                foreach ($entry['scenes'] as $index => $draft) {
                    $sequence++;

                    $narration = $this->splitter->join(array_slice(
                        $entry['sentences'],
                        $draft->firstSentence - 1,
                        $draft->sentenceCount()
                    ));

                    /*
                     * Located by ACT and position within it, never by
                     * `$sequence`. On a partial re-draft that counter starts at
                     * PARK_BASE and is renumbered afterwards, so quoting it
                     * here would report "scene 30001" — a number that is true
                     * for a few milliseconds inside a transaction and matches
                     * nothing the operator can look at.
                     */
                    $present = $this->resolvePresent(
                        $draft,
                        $cast,
                        sprintf('act %d, scene %d of that act', $entry['act']->sequence, $index + 1),
                    );

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
                        'image_prompt' => $this->prompts->build(
                            $draft->frame,
                            $cast,
                            $present,
                            $this->expressionFor($draft, $present),
                        ),
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
        //
        // ABOVE EVERY LIVE SEQUENCE, not at PARK_BASE — and that difference is
        // the whole of a bug that made `--acts=` fail on every real story.
        //
        // persist() parks the NEW rows at PARK_BASE+1 .. PARK_BASE+N. Parking
        // here at PARK_BASE+$index walks straight back through that band: the
        // scenes of the untouched earlier acts get indices 1..N and are moved
        // onto numbers the new rows are still sitting on. Story 12 died on
        // exactly this — `Duplicate entry '12-30001'` — after billing two calls
        // for the act it then rolled back.
        //
        // It survived a test file written for this method because that
        // fixture's three acts produce ONE scene each, so the two bands are a
        // single number wide and the only row assigned 30001 is the row already
        // there. Passing on a fixture that cannot express the failure is the
        // shape this project keeps paying for; see the multi-scene case in
        // PartialSceneRedraftTest.
        $base = ((int) $story->scenes()->max('sequence')) + 1;

        // unsignedSmallInteger. A story long enough to overflow this is not a
        // story, but a silent wrap here would renumber a video at random.
        if ($base + $ordered->count() - 1 > 65535) {
            throw new RuntimeException(sprintf(
                'Cannot renumber %d scenes from %d without overflowing scenes.sequence. Something '
                .'has left this story numbered far above its scene count.',
                $ordered->count(),
                $base,
            ));
        }

        foreach ($ordered as $index => $id) {
            Scene::whereKey($id)->update(['sequence' => $base + $index]);
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
     * Which stored characters a frame's name list actually refers to.
     *
     * **The drop is reported now instead of being silent, and that is the whole
     * change.** This method used to resolve each name and quietly discard
     * anything that came back null, on the reasoning — correct as far as it
     * went — that a frame naming somebody outside the cast is the generator
     * inventing a person, and pasting no description beats pasting an invented
     * one.
     *
     * What it could not see is that `resolve()` answered the same null for a
     * REAL cast member it simply could not parse. On `en-CN`, where the family
     * name comes first, that was every given name in the story: "Yiran" matched
     * nothing, and Song Yiran's description silently went missing from a frame
     * about to be paid for. The matcher is fixed, and a name that still cannot
     * be resolved is now recorded rather than assumed to be an invention.
     *
     * An AMBIGUOUS name is the sharper case and it is new. It used to be
     * answered with whichever character came first — "Lu" returned Lu Jianguo
     * on a story whose Lu Wenbin carries 105 scenes — so the failure was not a
     * missing description but a confidently wrong one. It refuses now, which
     * means a frame loses a description it should have had, and that is
     * strictly better than a still bought with the wrong face conditioning it
     * — but ONLY if somebody is told. Hence the collection.
     *
     * @param  Collection<int, Character>  $cast
     * @param  string  $where  Act and position, for the report. Never a sequence — see the call site.
     * @return array<int, string>
     */
    private function resolvePresent(SceneDraft $draft, Collection $cast, string $where): array
    {
        $resolved = [];

        foreach ($draft->charactersPresent as $name) {
            $match = $this->prompts->explain($name, $cast);

            if ($match->character !== null) {
                if (! in_array($match->character->name, $resolved, true)) {
                    $resolved[] = $match->character->name;
                }

                continue;
            }

            $problem = $match->problem();

            if ($problem !== null) {
                $this->nameProblems[] = sprintf('%s: %s', $where, $problem);
            }
        }

        return $resolved;
    }

    /**
     * The expression, or nothing, and there are two ways to earn nothing.
     *
     * **Nobody in the frame.** Roughly a quarter of a real story is cutaways —
     * an envelope on a doormat, a driveway at dusk — and an expression there
     * describes a face the picture does not contain.
     *
     * **A wide establishing shot with no face named in it.** The frame budget is
     * 25-45 words and an expression on a house seen from the street is spent on
     * something no viewer can resolve. Story 12 produced exactly that: *"A
     * modest single-story house on Ridgeline Drive seen from the street … brows
     * drawn together"*.
     *
     * **The second condition is deliberately narrower than "the shot is wide",
     * and the reason is that the same method answers a different question for
     * ThumbnailFraming.** Its precedence tests wide FIRST and is documented as
     * erring that way on purpose, because there a false `wide` costs a ranking
     * and a false `close` costs a thumbnail. Here a false `wide` DELETES a real
     * expression from a real close-up, so the cost function is reversed and the
     * marker list cannot be trusted alone: 'empty' matched a shot of a print
     * shop counter, and 'the street' matched a frame whose subject is standing
     * in it.
     *
     * So the frame must be wide AND say nothing about a face. A frame that names
     * a face keeps its expression however the shot was marked — which is the
     * conservative direction, and the only one where a wrong answer costs
     * nothing worse than the words it was already spending.
     *
     * Read off `$draft->frame`, the model's own field, before assembly — so it
     * cannot see the expression it is deciding about. That ordering is
     * load-bearing: "eyes wide" inside a frame classifies the frame as wide.
     *
     * @param  array<int, string>  $present
     */
    private function expressionFor(SceneDraft $draft, array $present): string
    {
        $expression = trim($draft->expression);

        if ($expression === '' || $present === []) {
            return '';
        }

        $frame = mb_strtolower($draft->frame);

        if (app(ThumbnailFraming::class)->framing($frame)['shot'] !== 'wide') {
            return $expression;
        }

        foreach (self::FACE_IN_FRAME as $cue) {
            if (preg_match('/\b'.preg_quote($cue, '/').'\b/u', $frame)) {
                return $expression;
            }
        }

        return '';
    }

    /**
     * Words that say a face is actually in the picture.
     *
     * Kept here rather than shared with ValidateSceneDrafts::FACE_CUES, and that
     * is a decision rather than an oversight. That list answers "is this frame
     * ABOUT a face" for an advisory an operator reads; this one answers "is a
     * face visible enough to be worth describing" for a suppression that
     * silently drops text. Two questions, two costs, and merging them would make
     * one of them wrong the next time either is tuned.
     */
    private const FACE_IN_FRAME = [
        'face', 'faces', 'eyes', 'expression', 'mouth', 'jaw', 'brow', 'brows',
        'cheek', 'cheeks', 'chin', 'smile', 'staring', 'stares', 'gaze', 'lips',
        'looking', 'watching', 'glancing', 'turns to', 'close on', 'close-up',
    ];

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
