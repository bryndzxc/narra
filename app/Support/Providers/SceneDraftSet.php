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
     * @param  string|null  $fallbackReason
     *                                       Which quality axis the discarded attempt failed,
     *                                       and by how much. Null when nothing was discarded.
     *
     *                                                        Carried because a gate that fires without saying
     *                                                        why costs the diagnosis every time. Story 21 fell
     *                                                        back on all seven acts, logged only "fell back",
     *                                                        and the failing axis — 34.4% static against a 15%
     *                                                        ceiling — had to be recovered by measuring the
     *                                                        finished draft against all three thresholds.
     */
    public function __construct(
        public readonly array $scenes,
        public readonly ProviderUsage $usage,
        public readonly array $discardedAttempts = [],
        public readonly ?string $fallbackReason = null,
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
