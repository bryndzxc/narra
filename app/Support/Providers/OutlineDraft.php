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
 * The eight spine fields are not metadata about the outline. They ARE the
 * outline, in the sense that matters: an aggrieved-narrator melodrama with a
 * vague grievance or a cartoon antagonist is not a weaker version of the genre,
 * it is a different one that nobody in this niche watches. They are required
 * from the generator and surfaced at Gate 1.
 *
 * Four of them describe the wrong and where it comes out. The other three —
 * departure, reversalBeats, refusal — describe the half of the arc story 21
 * did not have, where the narrator leaves, is searched for, and refuses. That
 * half is what the niche pays off on, and a spine without it produces a video
 * that escalates for forty minutes and hands the narrator one scene of power.
 *
 * The eighth is `hook`, and it arrived from a measurement rather than from a
 * gap in the arc: both shipped stories already contain four of the five beats a
 * hook needs and land every one of them two to seven minutes late. Nothing had
 * ever asked the outline what the first thirty seconds were, so the act 1 call
 * had nothing to be told, and a writer with no instruction about where the
 * opening starts writes the chronological beginning.
 *
 * The ninth is `narratorAtExposure`, and it is the sharpest of them. Story
 * 25's withheld information needed the narrator's body in the room — a trust
 * vote requiring the settlor present — and the writer brought him back for
 * the exposure. Stories 23 and 28 let a document and a third party produce
 * it, and the writer left the narrator 800 km away, so the public payoff
 * arrived as hearsay. Whether the search finds them or they choose the
 * moment, the narrator is in the room and the scene is theirs.
 *
 * The tenth is `betrayalScene`, and it is the other end of the same idea: the
 * payoff was hearsay because the narrator was not in the room, and the
 * betrayal was a discovery because nobody was. Seven stories found their
 * betrayal or heard it at a kitchen table; the reference stages it at 1:31,
 * in front of nine people, with the other man holding her hand.
 */
final class OutlineDraft
{
    /**
     * @param  array<int, ActOutline>  $acts
     * @param  string  $hook  The first thirty seconds, as five beats: one sentence of
     *                        setup, the betrayal inside ~20 seconds, evidence in exact
     *                        words, one small cold action, and a closing line promising
     *                        the DEPARTURE. Gate 1 checks the last beat by overlap.
     * @param  string  $narratorGrievance  Who wronged the narrator, and how. First person.
     * @param  string  $antagonistJustification  The antagonist's own account of why they
     *                                           were entitled to it. The engine of the format.
     * @param  string  $betrayalScene  The betrayal as a present-day scene in chapter one: the
     *                                 room, the named witnesses, the person it is done with
     *                                 standing there, the justification said aloud to the
     *                                 narrator's face, and the narrator's line back.
     * @param  string  $withheldInformation  What the narrator knows and the antagonist does not.
     * @param  string  $exposureMoment  Where it comes out, and in front of whom.
     * @param  string  $narratorAtExposure  How the narrator comes to be in the room —
     *                                      by their own choice, unexpected, the search
     *                                      having failed — and what only they produce
     *                                      there. Gate 1 checks it against the withheld
     *                                      information by overlap.
     * @param  string  $departure  How and when the narrator goes, and whether they
     *                             announce it. Not announcing is what makes the search
     *                             possible, so the announcement is the detail Gate 1 checks.
     * @param  string  $reversalBeats  What the antagonist does to find them and what each
     *                                 attempt costs her. The humiliation beats running the
     *                                 other way, and escalating the same.
     * @param  string  $refusal  What the narrator says when finally found, and which earlier
     *                           moment it answers. The private payoff, opposite the public one.
     */
    public function __construct(
        public readonly string $title,
        public readonly array $acts,
        public readonly ProviderUsage $usage,
        public readonly string $hook = '',
        public readonly string $narratorGrievance = '',
        public readonly string $antagonistJustification = '',
        public readonly string $withheldInformation = '',
        public readonly string $exposureMoment = '',
        public readonly string $narratorAtExposure = '',
        public readonly string $departure = '',
        public readonly string $reversalBeats = '',
        public readonly string $refusal = '',
        // At the END of the spine parameters rather than beside the
        // justification, so a caller passing them positionally is unchanged.
        // `spine()` below puts it in narrative order.
        public readonly string $betrayalScene = '',
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
        /**
         * Every person the story names, declared before the spine. Last, so a
         * caller passing the other parameters by position is unchanged.
         *
         * @var array<int, CastMember>
         */
        public readonly array $cast = [],
        /*
         * The accomplice as a person with a stake, and the narrator's running
         * thought. Last, so every positional caller is unchanged; `spine()`
         * puts them in narrative order. The three accomplice fields are empty
         * when the cast declares no accomplice. See CLAUDE.md 3g.
         */
        public readonly string $accompliceMotive = '',
        public readonly string $accomplicePerformance = '',
        public readonly string $accompliceFall = '',
        public readonly string $runningThought = '',
        /*
         * The last chance the antagonist was offered and threw away, and what
         * about a year later looks like, told in her own chapter at the end of
         * the refusal act. Last, so every positional caller is unchanged.
         */
        public readonly string $antagonistRegret = '',
    ) {}

    /** @return array<int, array{name: string, role: string, relationship: string}> */
    public function castRows(): array
    {
        return array_map(static fn (CastMember $m): array => $m->toRow(), $this->cast);
    }

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
            'hook' => $this->hook,
            'narrator_grievance' => $this->narratorGrievance,
            'antagonist_justification' => $this->antagonistJustification,
            'accomplice_motive' => $this->accompliceMotive,
            'accomplice_performance' => $this->accomplicePerformance,
            'betrayal_scene' => $this->betrayalScene,
            'withheld_information' => $this->withheldInformation,
            'exposure_moment' => $this->exposureMoment,
            'narrator_at_exposure' => $this->narratorAtExposure,
            'departure' => $this->departure,
            'reversal_beats' => $this->reversalBeats,
            'accomplice_fall' => $this->accompliceFall,
            'running_thought' => $this->runningThought,
            'refusal' => $this->refusal,
            'antagonist_regret' => $this->antagonistRegret,
        ];
    }

    /** Every piece of prose the outline contains, for a locale check. */
    public function proseForInspection(): string
    {
        return implode("\n", array_merge(
            [$this->title],
            // Names and relationships go through the locale check too: a name
            // is the first place a Filipino honorific gets in ("Lola").
            array_map(static fn (CastMember $m): string => $m->name.' — '.$m->relationship, $this->cast),
            array_values($this->spine()),
            array_map(
                fn (ActOutline $act): string => implode("\n", [$act->title, $act->summary, $act->escalationBeat]),
                $this->acts
            )
        ));
    }
}
