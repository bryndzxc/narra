<?php

namespace App\Support\Providers;

/**
 * The act outline a story is built on. Gate 1's subject.
 *
 * Deliberately a plain structure rather than Eloquent models: a provider
 * returns a proposal, and nothing is written to the database until an Action
 * decides to accept it. That separation is what makes "nothing is regenerated
 * silently" possible — a re-run produces a new draft the caller can compare
 * against, not a mutation that has already happened.
 */
final class OutlineDraft
{
    /**
     * @param  array<int, ActOutline>  $acts
     */
    public function __construct(
        public readonly string $title,
        public readonly array $acts,
        public readonly ProviderUsage $usage,
        /**
         * What was asked for, carried alongside what came back.
         *
         * The exact count cannot be a schema constraint — structured outputs
         * reject any minItems other than 0 or 1 — so it has to be verified
         * against the response. Verifying it inside the provider would mean
         * throwing after the call was billed and before its usage was handed
         * back, losing the cost row for a call that cost money. So the draft
         * carries both numbers and the Action checks them, after recording.
         */
        public readonly ?int $requestedActCount = null,
    ) {}

    /** Whether the provider returned the number of acts it was asked for. */
    public function actCountMatches(): bool
    {
        return $this->requestedActCount === null || $this->actCount() === $this->requestedActCount;
    }

    public function actCount(): int
    {
        return count($this->acts);
    }

    /** Every piece of prose the outline contains, for a locale check. */
    public function proseForInspection(): string
    {
        return implode("\n", array_merge(
            [$this->title],
            array_map(fn (ActOutline $act): string => $act->title."\n".$act->summary, $this->acts)
        ));
    }
}
