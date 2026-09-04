<?php

namespace App\Enums;

/**
 * Where an act sits in the arc. The part of this genre story 21 was missing.
 *
 * Story 21 ran escalation -> escalation -> exposure -> end, and the narrator
 * held power for exactly one scene out of two hundred and seventy. That reads
 * as a competent version of the format and it is not the format: the thing the
 * niche actually pays off on is a REVERSAL PHASE, and a phase is not a scene.
 *
 *     escalation -> the narrator LEAVES -> the antagonist SEARCHES ->
 *     the narrator REFUSES -> end
 *
 * The reference channel's own framing — "never expecting to see me and our son
 * 5 years later" — is that gap named out loud. Everything before the departure
 * is what the refusals are owed against; everything after it is the audience
 * being paid what it waited forty minutes for.
 *
 * This is an enum rather than a derived "is the last act" test because the act
 * SCRIPT generator needs it. The previous shape branched on `sequence === last`
 * and told every other act to "end worse off than it started" — which is
 * exactly correct for an escalation act and exactly wrong for a search act,
 * where things get worse for the ANTAGONIST. A prompt cannot make that
 * distinction from a sequence number.
 *
 * Anthology stories carry no phase: each act is a self-contained story that
 * runs the whole arc internally, so a per-act phase would be a lie about five
 * different narrators. Null is the honest value there and the checks skip it.
 */
enum ActPhase: string
{
    case Escalation = 'escalation';
    case Departure = 'departure';
    case Search = 'search';
    case Refusal = 'refusal';

    public function label(): string
    {
        return match ($this) {
            self::Escalation => 'Escalation',
            self::Departure => 'Departure',
            self::Search => 'Search',
            self::Refusal => 'Refusal',
        };
    }

    /**
     * What `acts.escalation_beat` means in this phase.
     *
     * One column, two directions. In the escalation phase the beat names what
     * the act costs the NARRATOR; after the departure it names what the
     * attempt costs the ANTAGONIST. Splitting it into two columns would leave
     * one of them null on every act and give the duplicate-beat check two
     * lists to walk — the beat is the same idea running the other way, and the
     * phase is what says which way.
     */
    public function beatLabel(): string
    {
        return match ($this) {
            self::Escalation => 'Escalation beat — what this act costs the narrator',
            self::Departure => 'Departure beat — what finally makes staying impossible',
            self::Search => 'Reversal beat — what this attempt costs the antagonist',
            self::Refusal => 'Refusal beat — which earlier humiliation this answers',
        };
    }

    /** Written into the outline prompt beside the slot, and shown at Gate 1. */
    public function guidance(): string
    {
        return match ($this) {
            self::Escalation => 'Costs the narrator more than the act before it. Nothing is resolved, '
                .'no round is won, no apology sticks.',
            self::Departure => 'The narrator goes. It has to be the act where staying stops being '
                .'possible, and they must not announce it — an announced departure cannot be '
                .'searched for, and the search is the next third of the video.',
            self::Search => 'The antagonist looks for them. Each attempt costs her something real '
                .'and more than the last: money, standing, the people who backed her excuse.',
            self::Refusal => 'The narrator is found and says no. Each refusal answers one specific '
                .'earlier humiliation by name — this is the private payoff, and it is what the '
                .'audience has been waiting forty minutes for.',
        };
    }

    /** Whether the act ends with the narrator worse off. False past the departure. */
    public function endsWorseForNarrator(): bool
    {
        return $this === self::Escalation || $this === self::Departure;
    }

    /**
     * The act structure, keyed by sequence.
     *
     * Escalation through roughly the first two thirds, the departure at the
     * boundary, the remainder search and refusal. At seven acts — the default
     * for a single narrative since this phase was added — that is escalation
     * 1-4, departure 5, search 6, refusal 7: a reversal that occupies three of
     * seven acts rather than the last two minutes of the last one.
     *
     * The departure is clamped to leave at least two acts behind it, because
     * a search with nowhere to run and a refusal in the same act as the
     * leaving is the compressed ending this whole structure exists to replace.
     *
     * @return array<int, self> sequence => phase
     */
    public static function planFor(int $actCount): array
    {
        $departure = self::departureActFor($actCount);

        $plan = [];

        for ($sequence = 1; $sequence <= $actCount; $sequence++) {
            $plan[$sequence] = match (true) {
                $sequence < $departure => self::Escalation,
                $sequence === $departure => self::Departure,
                $sequence === $actCount => self::Refusal,
                default => self::Search,
            };
        }

        return $plan;
    }

    /** Which act the narrator leaves in. */
    public static function departureActFor(int $actCount): int
    {
        return max(2, min((int) ceil($actCount * 2 / 3), $actCount - 2));
    }
}
