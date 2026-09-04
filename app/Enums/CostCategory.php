<?php

namespace App\Enums;

/**
 * What kind of spend a cost entry records.
 *
 * This exists because two of the project's rules collided the moment a real
 * provider was wired up, and only one of them was being enforced:
 *
 *   "Cost is logged per video. Every paid API call writes a row."
 *   "No paid asset generation may begin before scenes_approved."
 *
 * CostEntry enforced the second by refusing EVERY row before Gate 2 — which
 * silently made the first impossible for the text stages. Script generation
 * runs at `draft` through `scripted`, all of them below `scenes_approved`, and
 * it costs real money in tokens. Under the old guard it could either skip its
 * cost row or crash; neither is acceptable.
 *
 * The resolution is that the Gate 2 line was never about money in general. It
 * is about ASSETS: 150-250 stills at ~70% of a video's cost, plus per-scene TTS
 * and transcription, all of it committed before a human has read a single
 * scene. That is the unrecoverable mistake the gate prevents. A few dollars of
 * outline tokens spent to produce the very text the operator reviews at Gate 1
 * is not that mistake — it is the thing the gates exist to review.
 *
 * So the guard keys off this, not off status alone. `Asset` is gated; `Text`
 * is logged from the first call. Both are logged.
 *
 * `Reference` was added when character sheets landed and sits between them:
 * real image spend, but unlocked one status early, at `scenes_drafted`. See
 * the case for why that is a sharpening of the Gate 2 rule rather than a hole
 * in it.
 *
 * `Evaluation` sits outside that axis entirely. It is spend on the channel —
 * a style preview, a bake-off — rather than on a video, so it is ungated and
 * it is the one category kept out of `stories.total_cost_usd`. It exists
 * because three commands were borrowing a category whose gate they had to work
 * around and whose total they had to be filtered out of by hand.
 */
enum CostCategory: string
{
    /**
     * Tokens spent producing text a human will read at a gate.
     *
     * Outline, act scripts, scene drafts, metadata copy. Permitted at any
     * status, because refusing it would mean the four gates had nothing to
     * review. Still logged, and still counted in the story's total.
     */
    case Text = 'text';

    /**
     * Money spent producing a file: an image, narration audio, word timings.
     *
     * Gated behind Gate 2 without exception. This is the money line.
     */
    case Asset = 'asset';

    /**
     * Candidate reference images for the cast, generated AT Gate 2.
     *
     * Paid image generation, and it deliberately runs before the gate is
     * approved. The reason is the same one that exempts `Text`, applied to the
     * one asset the operator has to see in order to make the Gate 2 decision
     * at all.
     *
     * The Gate 2 rule was never "no image may ever be billed before
     * scenes_approved" — it is that 150-250 stills plus per-scene TTS must not
     * be committed against scenes no human has read. Every clause of that
     * fails to describe a character sheet: it is about three dozen images, not
     * two hundred and fifty; it is fired by an explicit operator click with the
     * cost on screen, not by a batch; and its output is the thing being
     * reviewed, since a face that is wrong is cheaper to find here than at
     * scene 90.
     *
     * Generating the cast's faces first is also what makes the expensive stage
     * cheaper: every scene still is conditioned on an approved reference, which
     * is the mechanism against the drift the spec names as the single biggest
     * quality risk in this format.
     *
     * Unlocked at `scenes_drafted` and not one status earlier. A story that has
     * not drafted its scenes has nothing for the operator to be standing in
     * front of, and this must not become a way to spend money at `draft`.
     */
    case Reference = 'reference';

    /**
     * Spend on deciding HOW to make videos, rather than on making one.
     *
     * A style preview, an image bake-off, a narration bake-off. Each bills a
     * real vendor for a real file, and each of them borrowed a category that
     * did not fit — `style:preview` and `images:bakeoff` took `Reference`,
     * `narration:bakeoff` took `Asset` — because there was nothing else to
     * take. Both borrowings were wrong in the same two ways.
     *
     * **The gate.** A borrowed category carries a borrowed gate. A style
     * preview wants the EARLIEST story it can get: the question it asks is what
     * a look does to a cast, and a cast exists from `scripted` onward. Pointed
     * at one, it generated an image, billed for it, and then threw a gate
     * violation while writing the row — spend with no record, which is the one
     * outcome this ledger exists to make impossible. The workaround was to
     * advance a scratch story through Gate 2 to satisfy a guard about a
     * decision it was not making, which is a measurement moved until it passes.
     *
     * The gate does not apply here, and that is not a hole in it. Gate 2 stops
     * 150-250 stills and per-scene TTS being committed against scenes no human
     * has read. Evaluation spend is a handful of files fired by an explicit
     * operator confirmation with the price on screen, written to a preview
     * directory no pipeline stage reads, and committed against no scenes at
     * all — every clause of the rule fails to describe it.
     *
     * **The total.** `stories.total_cost_usd` answers "what did this video
     * cost", and a style test is not part of any video; it borrows a story's
     * cast the way a lens test borrows an actor. Three docblocks already
     * promised "a per-video total can exclude them in one predicate" and left
     * the excluding to whoever remembered the operation-name prefix — a promise
     * with no mechanism, which this project has learned to read as absent
     * rather than as covered. So these rows are logged in full, counted in
     * Story::evaluationSpend(), and do not touch the story total. See
     * countsTowardVideoCost().
     */
    case Evaluation = 'evaluation';

    /** Whether Gate 2 must have been passed before this may be spent. */
    public function requiresPaidAssetsUnlocked(): bool
    {
        return $this === self::Asset;
    }

    /**
     * Whether the operator must at least be standing at Gate 2 to spend this.
     *
     * The weaker of the two guards, and the only category that uses it. Gate 2
     * has to be reachable — scenes drafted — but not yet crossed.
     */
    public function requiresScenesDrafted(): bool
    {
        return $this === self::Reference;
    }

    /**
     * Whether this row belongs in the story's denormalised total.
     *
     * The one predicate the per-video total is allowed to ask, so that
     * "what did this video cost" and "what was billed against this story" can
     * be different questions without either of them being a guess.
     *
     * Everything a video is made of counts: the tokens that wrote it, the
     * reference sheets its stills are conditioned on, the stills, the
     * narration, the alignment. Only Evaluation does not, because it is spend
     * on the channel rather than on the video whose cast it happened to
     * borrow — see the case.
     *
     * Written as an explicit list rather than `!== self::Evaluation` so a
     * future category has to state which side it is on. Silently defaulting a
     * new kind of spend into or out of a video's cost is precisely how a
     * ledger stops being worth reading.
     */
    public function countsTowardVideoCost(): bool
    {
        return match ($this) {
            self::Text, self::Asset, self::Reference => true,
            self::Evaluation => false,
        };
    }

    /** Whether this category buys a file rather than tokens. */
    public function isSpendOnAssets(): bool
    {
        return $this !== self::Text;
    }

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
