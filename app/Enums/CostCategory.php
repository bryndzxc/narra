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

    /** Whether Gate 2 must have been passed before this may be spent. */
    public function requiresPaidAssetsUnlocked(): bool
    {
        return $this === self::Asset;
    }

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
