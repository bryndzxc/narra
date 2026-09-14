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
            self::Escalation => 'Costs the narrator more than the act before it. Nothing is recovered, '
                .'no apology sticks — and the narrator answers back in every scene the antagonist '
                .'is in, one line that lands and changes nothing about the cost. Set in the '
                .'story\'s PRESENT: a prior incident is cited in one sentence inside it, never '
                .'staged as the act.',
            self::Departure => 'The narrator goes. It has to be the act where staying stops being '
                .'possible, and where they went is not announced — no note, no address, and the '
                .'people who know are asked not to tell her.',
            self::Search => 'The antagonist looks for them and REACHES them: she and the narrator '
                .'are in the same scene at least once in this act, and the meeting costs her — '
                .'money, standing, the people who backed her excuse, her face in public — more than '
                .'the last one did.',
            self::Refusal => 'The narrator is in the room for the exposure and says no. Each refusal '
                .'answers one specific earlier humiliation by name — this is the private payoff, '
                .'and it is what the audience has been waiting forty minutes for.',
        };
    }

    /**
     * Whether the act's LEDGER ends against the narrator. False past the
     * departure.
     *
     * This is about the cost, not the exchange. Since 2026-09-13 an escalation
     * act has the narrator answering back in every scene the antagonist is in
     * — a line that lands — and it still ends worse off for them on money,
     * standing and the people around them. The reference video this was
     * measured against runs exactly that sawtooth: "a breakup dinner or a date
     * with your new boy toy", said aloud inside the betrayal (3:30), and the
     * wedding still cancelled. (Not "marry you my ass" at 2:56, which is what
     * the narrator THINKS — see genreGuidance().) A method named for the
     * round rather than the ledger would say the wrong thing about it.
     */
    public function endsWorseForNarrator(): bool
    {
        return $this === self::Escalation || $this === self::Departure;
    }

    /**
     * The act structure, keyed by sequence.
     *
     * Escalation through roughly the first two thirds, the departure at the
     * boundary, the remainder search and refusal. At FIVE acts — the default
     * for a single narrative since 2026-09-13 — that is escalation 1-2,
     * departure 3, search 4, refusal 5: a reversal that occupies three of
     * five acts rather than the last two minutes of the last one.
     *
     * The departure is clamped to leave at least two acts behind it, because
     * a search with nowhere to run and a refusal in the same act as the
     * leaving is the compressed ending this whole structure exists to replace.
     *
     * **THAT CLAMP IS NOW THE BINDING TERM AT THE DEFAULT COUNT, AND IT WAS
     * INERT AT EVERY COUNT THIS PROJECT HAD USED BEFORE.** The two-thirds
     * point of five acts is act 4; a departure there makes act 5 the refusal
     * and leaves NO SEARCH ACT AT ALL. At six and seven the two-thirds point
     * already lands on `$actCount - 2` and the clamp changes nothing, so it
     * spent two act-count moves as a guarantee nobody could see working.
     * Five is where it starts doing the work, which means five is the count
     * that breaks first if `departureActFor()` is ever loosened.
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
