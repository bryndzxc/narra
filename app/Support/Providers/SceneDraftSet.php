<?php

namespace App\Support\Providers;

/**
 * One act's worth of proposed scenes.
 *
 * @see SceneDraft for why these carry sentence ranges rather than narration.
 */
final class SceneDraftSet
{
    /**
     * @param  array<int, SceneDraft>  $scenes
     * @param  array<int, ProviderUsage>  $discardedAttempts
     *                                                        Calls that were made, billed, and whose output was
     *                                                        thrown away — a cheap model whose sentence ranges
     *                                                        did not tile the act before the fallback re-ran it.
     *                                                        They are carried rather than dropped because the
     *                                                        rule is that every paid call writes a cost row, and
     *                                                        a fallback that hid its first attempt would report
     *                                                        a saving it did not make.
     */
    public function __construct(
        public readonly array $scenes,
        public readonly ProviderUsage $usage,
        public readonly array $discardedAttempts = [],
    ) {}

    /**
     * Every usage this act's scene drafting owes a cost row for, in order.
     *
     * @return array<int, ProviderUsage>
     */
    public function allUsages(): array
    {
        return [...$this->discardedAttempts, $this->usage];
    }

    public function count(): int
    {
        return count($this->scenes);
    }

    /** Every prompt fragment, for a locale check. */
    public function proseForInspection(): string
    {
        return implode("\n", array_map(fn (SceneDraft $s): string => $s->frame, $this->scenes));
    }
}
