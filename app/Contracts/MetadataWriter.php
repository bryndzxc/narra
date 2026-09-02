<?php

namespace App\Contracts;

use App\Models\Story;
use App\Support\Providers\MetadataCopyDraft;
use App\Support\Providers\TagDraft;
use App\Support\Providers\TitleDraft;

/**
 * Writes the publish sheet: the copy a human pastes into YouTube.
 *
 * Separate from ScriptWriter, and the separation is a phase boundary rather
 * than a taxonomy. ScriptWriter runs at `draft` through `scripted` and produces
 * the video. This runs after `rendered` and produces the thing that decides
 * whether anybody watches it — and it cannot run earlier, because chapters need
 * real timestamps and those only exist once the render has filled in act
 * timings.
 *
 * **Three calls, not one, and the split is the same reasoning the script
 * pipeline already uses.** Those four text calls stopped being one model the
 * moment they were priced separately, because they are not the same kind of
 * work. Neither are these:
 *
 *   titles()   The click surface. The title is the single highest-leverage
 *              sentence in the product and, in this genre, it states the
 *              ending — the promise IS the hook. The description's opening two
 *              or three sentences make the same promise in the search snippet,
 *              so they are written in the SAME call: split across two models
 *              they drift, and a description that pitches a different video
 *              than the title is worse than either alone.
 *
 *   copy()     Thumbnail overlay phrases and the pinned comment. Short copy,
 *              written against a promise that already exists rather than
 *              inventing one. Read by humans, so not mechanical; not the thing
 *              that decides the click, so not the top of the roster.
 *
 *   tags()     A keyword list against a hard character budget. Genuinely
 *              mechanical and structurally checkable, exactly like scene
 *              drafting's sentence ranges — the budget either fits or it does
 *              not, and that is arithmetic rather than judgement.
 *
 * Which model each of those runs on is config, not code: see
 * `providers.anthropic.operations`. This interface only says they are three
 * different questions.
 *
 * Nothing here writes to the database. Each method returns a proposal carrying
 * its own cost, and GenerateMetadata decides whether to accept it — the same
 * rule as ScriptWriter, for the same reason: a re-run costs money and must
 * never silently replace what an operator has already edited.
 */
interface MetadataWriter extends ProviderIdentity
{
    /**
     * Title variants, plus the description's opening hook.
     *
     * @param  int  $variants  How many titles to propose. Five, so the four
     *                         that are not picked become data on what performs.
     */
    public function titles(Story $story, int $variants): TitleDraft;

    /**
     * Thumbnail overlay phrases and the pinned comment.
     *
     * @param  array<int, string>  $titleOptions
     *                                            The titles already proposed, so the overlay text promises the
     *                                            same video. A thumbnail that contradicts its title is the one
     *                                            way to lose a click that the title already won.
     */
    public function copy(Story $story, array $titleOptions): MetadataCopyDraft;

    /**
     * Tags, asked for inside the budget rather than trimmed afterwards.
     *
     * @param  array<int, string>  $titleOptions
     * @param  int  $charBudget  YouTube's 500 across all tags, separators
     *                           included. Asked for here AND enforced by the
     *                           caller: a model told a budget is not a model
     *                           that respects one.
     */
    public function tags(Story $story, array $titleOptions, int $charBudget): TagDraft;
}
