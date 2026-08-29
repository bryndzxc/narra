<?php

namespace App\Contracts;

use App\Models\Story;
use App\Support\Providers\ActOutline;
use App\Support\Providers\ActScriptDraft;
use App\Support\Providers\OutlineDraft;

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
}
