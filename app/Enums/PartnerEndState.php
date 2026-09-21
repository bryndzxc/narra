<?php

namespace App\Enums;

/**
 * What the narrator and the future partner ARE to each other by the end.
 * Chosen by the OPERATOR, before the outline, and read by every writer after
 * it. See App\Support\PartnerEnding for when it is read at all.
 *
 * ---------------------------------------------------------------------------
 * WHY THIS EXISTS: NO STAGE COULD ASK FOR A MARRIAGE
 * ---------------------------------------------------------------------------
 *
 * Story 39, 2026-09-20. The operator's idea said "i am married to her older
 * sister". The premise writer read it; `usePremise()` kept the prose and the
 * cast and nothing else; and the outline came back with an act-5 summary
 * reading "Nancy introduces me to a room as her partner".
 *
 * That is not a writer ignoring an instruction. It is a writer obeying the
 * only instruction there was. Measured across every sentence in this app that
 * says what the two of them become, the entire vocabulary on offer was "a
 * couple", "together" and "a year on":
 *
 *   - `partnerOutlineInstruction()`: "a couple, together, a year on ... The
 *     last act's summary says they are together."
 *   - `partnerArcFor(Refusal)`: "the narrator and X are together".
 *   - `newLifeEnding()`: "THE TWO OF THEM ARE A COUPLE NOW".
 *   - `sceneContext()`: "the two of them are a couple".
 *
 * "Her partner" is a faithful rendering of every one of those. Nothing was
 * wrong; nothing could have produced a marriage, because nothing asked for
 * one, and the one place that had the word — the operator's idea — reaches
 * the premise writer and no stage after it.
 *
 * ---------------------------------------------------------------------------
 * WHY AN OPERATOR COLUMN RATHER THAN A WIDER PROMPT
 * ---------------------------------------------------------------------------
 *
 * Widening the words is the other half of the fix and it is NOT a substitute
 * (the operator, 2026-09-20): a writer offered four end states and no
 * instruction picks the weakest one it can defend, which is what "partner"
 * already is. The StoryEnding argument transfers exactly — a model left to
 * choose converges, and it sees ONE story where the channel sees a run of
 * them — so the four states sit beside the ending picker with the last few
 * videos' choices printed under them.
 *
 * ---------------------------------------------------------------------------
 * THE WORDS ARE OWNED HERE, IN BOTH DIRECTIONS
 * ---------------------------------------------------------------------------
 *
 * `instruction()` is what the writers are asked for and `words()` is what
 * Gate 1 reads back. One owner, for `ChapterAnnouncement`'s reason: a prompt
 * that started asking for "wed" while the check looked for "married" is not a
 * state this can reach.
 *
 * Stored as a string column, not a MySQL ENUM, so a fifth state needs no
 * migration and cannot drift from the column the way `CostUnit::TotalTokens`
 * did.
 */
enum PartnerEndState: string
{
    case Married = 'married';

    case Engaged = 'engaged';

    case LivingTogether = 'living_together';

    case Together = 'together';

    public function label(): string
    {
        return match ($this) {
            self::Married => 'Married',
            self::Engaged => 'Engaged',
            self::LivingTogether => 'Living together',
            self::Together => 'Together',
        };
    }

    /** One sentence for the picker: what the last chapter shows. */
    public function description(): string
    {
        return match ($this) {
            self::Married => 'A year on they are married, and the last chapter says so in those words. '
                .'The idea that says "I married her sister" wants this one.',
            self::Engaged => 'A year on they are engaged: it has been asked and answered, and the last '
                .'chapter says so.',
            self::LivingTogether => 'A year on they live together — one home, not a wedding. The last '
                .'chapter says so.',
            self::Together => 'A year on they are together, and nothing further is claimed. This is what '
                .'a writer with no instruction already produces, so pick it because you want it.',
        };
    }

    /**
     * What the writers are asked for, as the clause that names the state.
     *
     * Every one of them ends by refusing the weaker wordings, because the
     * weaker wordings are the default: story 39 came back as "her partner"
     * from a prompt whose only word was "together".
     */
    public function instruction(): string
    {
        return match ($this) {
            self::Married => 'MARRIED. They are married to each other by the end, and it is said in those '
                .'words — "we married", "my wife", "my husband" — never as "my partner", "a couple" or '
                .'"together", which are all true of a married pair and none of which says they married.',
            self::Engaged => 'ENGAGED. By the end it has been asked and answered and they are engaged, and '
                .'it is said in those words — "engaged", "my fiancee" — never as "my partner", "a couple" '
                .'or "together". They are not married: no wedding has happened.',
            self::LivingTogether => 'LIVING TOGETHER. By the end they share one home, and it is said in '
                .'those words — "we live together", "we moved in", "our place". They are not married and '
                .'not engaged: no wedding and no proposal.',
            self::Together => 'TOGETHER. By the end they are together — a couple — and nothing more than '
                .'that is claimed: no wedding, no engagement, no shared home unless the story put them '
                .'there. Say it plainly, the way a person says it about their own life.',
        };
    }

    /**
     * The words that COUNT as stating this end state, matched whole-word in
     * the same sentence as the partner's name.
     *
     * DELIBERATELY GENEROUS, and the direction is the reason. This is a
     * POSITIVE check — it reports an ABSENCE — so a generous list misses a
     * weak summary and a mean list fires on a good one, which is the opposite
     * of the discovery check's exposure and the opposite trade. CLAUDE.md's
     * standing finding is that a check firing on good output is the one that
     * retires a detector.
     *
     * TOGETHER IS THE FLOOR and takes every other state's words as well:
     * married, engaged and living together each entail being together, so a
     * summary that says any of them has said this one. The other three are
     * exact and do not entail each other — an engaged pair is not married and
     * a married pair need not have been asked.
     *
     * @return array<int, string>
     */
    public function words(): array
    {
        $married = ['married', 'marry', 'marries', 'marrying', 'marriage', 'wed', 'wedding', 'wife', 'husband', 'spouse'];
        $engaged = ['engaged', 'engagement', 'fiance', 'fiancee', 'fiancé', 'fiancée', 'propose', 'proposed', 'proposal'];
        // "our flat" is deliberately NOT here, and `PromptLocaleTest` is what
        // caught it — this file is reachable from the outline prompt, so a
        // British term in it is a term the writers are taught. It would also
        // be a check looking for a word the pipeline already discourages: the
        // shared warn list flags "flat" on every profile, so an act script
        // saying "our flat" has a locale finding of its own at Gate 1. The 3g
        // instance of exactly this ("apologise" and "licence" in the genre
        // contract) was caught only by accident; this one by the guard.
        $living = ['living together', 'live together', 'lives with', 'moved in', 'moving in', 'our apartment', 'our home', 'our place'];
        $together = ['together', 'couple', 'partner', 'partners'];

        return match ($this) {
            self::Married => $married,
            self::Engaged => $engaged,
            self::LivingTogether => $living,
            self::Together => [...$together, ...$married, ...$engaged, ...$living],
        };
    }

    /**
     * The words a Gate 1 finding quotes back, so the operator is told what to
     * look for rather than handed the whole matcher. The floor state quotes
     * its own four and not the union.
     */
    public function quotedWords(): string
    {
        $shown = $this === self::Together
            ? ['together', 'a couple']
            : array_slice($this->words(), 0, 3);

        return '"'.implode('", "', $shown).'"';
    }
}
