<?php

namespace App\Support;

use App\Models\Character;
use Illuminate\Support\Collection;

/**
 * What generating reference sheets is about to cost, itemised, before it does.
 *
 * "Cost is logged per video" is the rule after the fact; this is the rule
 * before it. The sheet is the first thing in the project that spends money on
 * an asset, and the operator clicking the button should be reading the bill
 * rather than discovering it in the ledger afterwards.
 *
 * Both numbers the operator asked for are here and they are different
 * questions: `usdPerSheet` is what one character costs — the unit of the
 * decision, since sheets are generated and regenerated one character at a time
 * — and `usdTotal` is what the outstanding cast costs together.
 *
 * `scenesUnblocked` is the third number and the one that makes the spend legible.
 * A sheet is not bought for its own sake; it is bought so that the stills of
 * the scenes that character appears in can be conditioned on a fixed face. Nine
 * dollars is an abstraction. Nine dollars that unblocks 199 scenes is a
 * decision.
 */
final class CharacterSheetEstimate
{
    /**
     * @param  Collection<int, Character>  $pending  Characters with no usable reference.
     */
    public function __construct(
        public readonly Collection $pending,
        public readonly int $castSize,
        public readonly int $candidatesEach,
        public readonly float $usdPerImage,
        public readonly int $scenesUnblocked,
        public readonly int $scenesTotal,
        public readonly string $provider,
        public readonly ?string $model,
        public readonly bool $rateIsDeclared,
    ) {}

    public function pendingCount(): int
    {
        return $this->pending->count();
    }

    public function readyCount(): int
    {
        return $this->castSize - $this->pendingCount();
    }

    /** Images one character's sheet will generate. */
    public function imagesPerSheet(): int
    {
        return $this->candidatesEach;
    }

    public function imagesTotal(): int
    {
        return $this->pendingCount() * $this->candidatesEach;
    }

    /** What one character costs. The unit of the decision. */
    public function usdPerSheet(): float
    {
        return round($this->candidatesEach * $this->usdPerImage, 4);
    }

    /** What the whole outstanding cast costs. */
    public function usdTotal(): float
    {
        return round($this->imagesTotal() * $this->usdPerImage, 4);
    }

    public function billsAnything(): bool
    {
        return $this->pendingCount() > 0;
    }

    /**
     * The itemised lines, for the confirmation the operator actually reads.
     *
     * Itemised rather than a single total for the same reason Gate 2's
     * confirmation is: an operator shown one aggregate number every time stops
     * reading it, and the number that matters after a partial run is "three
     * characters", not "the cast".
     *
     * @return array<int, string>
     */
    public function summary(): array
    {
        if (! $this->billsAnything()) {
            return [];
        }

        $lines = [];

        foreach ($this->pending as $character) {
            $lines[] = sprintf(
                '%s — %d candidates, $%s (appears in %d scene%s)',
                $character->name,
                $this->candidatesEach,
                number_format($this->usdPerSheet(), 4),
                $character->scenes_count ?? 0,
                ($character->scenes_count ?? 0) === 1 ? '' : 's',
            );
        }

        $lines[] = sprintf(
            'Total: %d images, $%s',
            $this->imagesTotal(),
            number_format($this->usdTotal(), 4),
        );

        return $lines;
    }
}
