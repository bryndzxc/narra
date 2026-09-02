<?php

namespace App\Support\Providers;

/**
 * The act outline a story is built on, plus the genre spine that holds it up.
 *
 * Deliberately a plain structure rather than Eloquent models: a provider
 * returns a proposal, and nothing is written to the database until an Action
 * decides to accept it. That separation is what makes "nothing is regenerated
 * silently" possible — a re-run produces a new draft the caller can compare
 * against, not a mutation that has already happened.
 *
 * The four spine fields are not metadata about the outline. They ARE the
 * outline, in the sense that matters: an aggrieved-narrator melodrama with a
 * vague grievance or a cartoon antagonist is not a weaker version of the genre,
 * it is a different one that nobody in this niche watches. They are required
 * from the generator and surfaced at Gate 1.
 */
final class OutlineDraft
{
    /**
     * @param  array<int, ActOutline>  $acts
     * @param  string  $narratorGrievance  Who wronged the narrator, and how. First person.
     * @param  string  $antagonistJustification  The antagonist's own account of why they
     *                                           were entitled to it. The engine of the format.
     * @param  string  $withheldInformation  What the narrator knows and the antagonist does not.
     * @param  string  $exposureMoment  Where it comes out, and in front of whom.
     */
    public function __construct(
        public readonly string $title,
        public readonly array $acts,
        public readonly ProviderUsage $usage,
        public readonly string $narratorGrievance = '',
        public readonly string $antagonistJustification = '',
        public readonly string $withheldInformation = '',
        public readonly string $exposureMoment = '',
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

    /**
     * The spine, keyed the way it is stored and displayed.
     *
     * @return array<string, string>
     */
    public function spine(): array
    {
        return [
            'narrator_grievance' => $this->narratorGrievance,
            'antagonist_justification' => $this->antagonistJustification,
            'withheld_information' => $this->withheldInformation,
            'exposure_moment' => $this->exposureMoment,
        ];
    }

    /** Every piece of prose the outline contains, for a locale check. */
    public function proseForInspection(): string
    {
        return implode("\n", array_merge(
            [$this->title],
            array_values($this->spine()),
            array_map(
                fn (ActOutline $act): string => implode("\n", [$act->title, $act->summary, $act->escalationBeat]),
                $this->acts
            )
        ));
    }
}
