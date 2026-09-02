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
