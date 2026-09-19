<?php

namespace App\Contracts;

use App\Models\Act;
use App\Models\Story;
use App\Support\Providers\ActOutline;
use App\Support\Providers\ActScriptDraft;
use App\Support\Providers\CharacterCast;
use App\Support\Providers\CharacterProfile;
use App\Support\Providers\OutlineDraft;
use App\Support\Providers\SceneDraftSet;

/**
 * Writes the story: the act outline first, then each act in turn.
 *
 * Two methods, not one, and that is the defining constraint of this format
 * rather than a stylistic choice. A 30-40 minute video is 5,500-8,000 words of
 * narration, and a single call cannot hold that much coherent narrative — it
 * drifts, repeats itself, and contradicts what it said twenty minutes earlier.
 * So the shape is fixed:
 *
 *     premise -> act outline (5-8 acts) -> per-act script, each call given the
 *     outline plus a running summary of the acts already written
 *
 * `actScript()` therefore takes `$priorSummaries` and is called SEQUENTIALLY.
 * It cannot fan out: act 4 needs to know what happened in acts 1-3. This is the
 * one stage in the whole pipeline that must stay serial, and an implementation
 * that quietly parallelised it would produce a script that reads like five
 * people wrote it.
 *
 * There is no one-shot `script(Story)` method here, deliberately. Adding one
 * would be the first thing that had to be rewritten.
 *
 * Scene drafting lives here too rather than behind its own interface: it is the
 * same provider, the same model and the same rate card, and it reads the script
 * this interface wrote. Splitting it out would mean two implementations that
 * have to agree about the story.
 */
interface ScriptWriter
{
    /**
     * Propose an act outline for a premise.
     *
     * Returns a proposal. Nothing is written to the database by the provider —
     * an Action decides whether to accept it, because a re-run costs money and
     * must never silently replace what an operator has already reviewed.
     *
     * @param  int  $actCount  5-8. For an anthology this is the number of
     *                         self-contained stories; for a single narrative it
     *                         is the act structure.
     */
    public function outline(Story $story, int $actCount): OutlineDraft;

    /**
     * Write premise candidates from an operator's idea.
     *
     * Before the outline, while the story is a draft. Each candidate carries
     * the prose the outline will be written from AND the spine answers the
     * Gate 1 checks read, so the checks can run before the operator picks.
     * Nothing is written to the database by the provider.
     *
     * @param  int  $count  how many candidates to ask for; the provider returns
     *                      what it got, and the Action reports a shortfall
     *                      rather than refusing a billed call.
     */
    public function premises(Story $story, string $idea, int $count): \App\Support\Providers\PremiseDraftSet;

    /**
     * Write one act's narration.
     *
     * @param  ActOutline  $act  The act to write, from the approved outline.
     * @param  array<int, ActOutline>  $fullOutline
     *                                               Every act, so this one knows where it sits and what it is
     *                                               setting up — not just its own entry.
     * @param  array<int, string>  $priorSummaries
     *                                              Summaries of the acts already written, in order. Empty for act 1.
     *                                              This is what keeps a 7,000-word script coherent, and it is why
     *                                              this method cannot be called in parallel.
     * @param  int  $targetWords  Per-act share of the story's word budget.
     */
    public function actScript(
        Story $story,
        ActOutline $act,
        array $fullOutline,
        array $priorSummaries,
        int $targetWords,
    ): ActScriptDraft;

    /**
     * Extract every recurring character, with a fixed physical description each.
     *
     * Runs BEFORE any scene is drafted, and that order is the whole mechanism.
     * Character consistency across 150-250 stills is the single biggest quality
     * risk in this format; the fix is that one description is written once and
     * then pasted verbatim into every prompt the character appears in. A scene
     * drafted before the cast exists has to invent a description for whoever is
     * in it, and two scenes inventing separately is precisely the drift.
     *
     * Each description must be physical and unchanging — age, build, hair,
     * face, habitual clothing. Never mood, posture or action: those belong to
     * the frame and change every scene.
     *
     * @param  array<int, string>  $scripts  Every act's script, in order.
     */
    /**
     * @param  array<int, string>  $rejectionNotes
     *                                              What was wrong with a previous attempt, fed back verbatim.
     *                                              Empty on the first try. It exists because style_notes has a
     *                                              hard invariant a prompt alone did not hold: the field is
     *                                              pasted into every prompt its character appears in, so a prop
     *                                              in it is a prop in all 36 of their scenes — and re-asking
     *                                              without saying what failed just re-rolls the same mistake.
     */
    public function characters(Story $story, array $scripts, array $rejectionNotes = []): CharacterCast;

    /**
     * Split one act's script into scenes, with a composed frame for each.
     *
     * Scenes are returned as SENTENCE RANGES into `$sentences`, never as
     * narration text. The script was approved at Gate 1 and a paid TTS call
     * will read it aloud; a model asked to echo 1,000 words verbatim will
     * sometimes paraphrase, and that is invisible. Indices make the narration
     * verbatim by construction.
     *
     * The `frame` on each draft describes what is IN the picture. It must not
     * restate the narration: a prompt that transcribes its line produces a
     * literal illustration of a sentence, and two hundred of those in a row
     * read as a slideshow of captions rather than a film.
     *
     * @param  array<int, string>  $sentences  1-indexed act script sentences.
     * @param  array<int, CharacterProfile>  $cast  Names the frames may use.
     * @param  int  $targetScenes  Derived from the act's word count.
     */
    public function scenes(
        Story $story,
        Act $act,
        array $sentences,
        array $cast,
        int $targetScenes,
    ): SceneDraftSet;
}
