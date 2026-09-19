<?php

namespace App\Enums;

/**
 * What the last chapter of the video is. Chosen by the OPERATOR, before the
 * outline, and read by everything after it.
 *
 * ---------------------------------------------------------------------------
 * WHY THIS EXISTS: EVERY ENDING CAME OUT THE SAME SHAPE
 * ---------------------------------------------------------------------------
 *
 * Measured 2026-09-19 on every story with a written refusal act (29-37). The
 * refusal act was asked for an optional narrator epilogue — "what the
 * narrator's life is now, and one concrete fact that shows her loss from the
 * outside" — and, whenever `antagonist_regret` was written, the antagonist's
 * chapter as well, stacked after it. `antagonist_regret` was REQUIRED in the
 * outline schema, so from story 37 on every outline asked for both.
 *
 * Every epilogue delivered the same list: a headcount and square metres, her
 * decline in three facts, and one object — stories 33 and 35 even send back
 * the same red envelope unopened. And story 37's two endings overlapped by
 * construction: the regret field asked for "one fact about where the narrator
 * is now", so her chapter restated the showroom, the daughter and the full
 * banquet the epilogue had just listed. Two endings asked for the same year.
 *
 * ---------------------------------------------------------------------------
 * TWO ENDINGS, EXCLUSIVE, AND EACH SAYS WHAT IT DOES NOT CARRY
 * ---------------------------------------------------------------------------
 *
 * The operator's decision, 2026-09-19. A third ("somebody tells him what
 * happened to her") was dropped: its content was already the second half of
 * every epilogue, and what made it different was only that it was a scene,
 * which the new life can be too. "Alone and fine" and "with the partner" are
 * one ending, not two: whether a partner is in it is a fact about the CAST.
 *
 * ---------------------------------------------------------------------------
 * WHY AN OPERATOR COLUMN, SET BEFORE THE OUTLINE
 * ---------------------------------------------------------------------------
 *
 * Not the outline's choice: a model left to choose converges — 16 of 72 cast
 * names were the setting's own examples, and every epilogue took one shape —
 * and it sees one story, so it cannot vary the channel. Not Gate 1 after the
 * outline: each ending needs fields written upstream (the regret for hers, a
 * partner in the premise and the cast for the new life). Not the premise: the
 * premise generator is optional. See `App\Support\RecentEndings` for the
 * history shown beside the picker.
 *
 * NULL is a story outlined before the choice existed, and gets exactly the
 * refusal act it had. A single narrative with no ending is refused at the
 * outline, before the call. An anthology has no ending: each act runs the
 * whole arc itself.
 *
 * Stored as a string column, not a MySQL ENUM, so a case added here needs no
 * migration and cannot drift from a column the way `CostUnit::TotalTokens`
 * did.
 */
enum StoryEnding: string
{
    /**
     * The narrator's life, about a year on, as one scene. The cast decides
     * whether a partner is in it.
     */
    case NewLife = 'new_life';

    /**
     * The antagonist's own closing chapter, about a year on: the chance they
     * threw away and what the year looks like from inside their life.
     */
    case AntagonistVoice = 'antagonist_voice';

    public function label(): string
    {
        return match ($this) {
            self::NewLife => 'The narrator\'s new life',
            self::AntagonistVoice => 'The antagonist\'s year, in their own voice',
        };
    }

    /** One sentence for the picker: what the last two or three minutes are. */
    public function description(): string
    {
        return match ($this) {
            self::NewLife => 'A year on, one scene of the narrator\'s life. With the partner on screen if '
                .'the cast has one; alone and fine if it does not.',
            self::AntagonistVoice => 'A year on, the antagonist\'s own chapter: the chance to put it right '
                .'that they threw away, and what the year looks like from inside their life.',
        };
    }

    /**
     * What this ending must NOT carry, stated in the prompt beside what it
     * does. The overlap on story 37 happened because two endings were asked
     * for the same year; exclusive endings with no statement of their edges
     * would drift back into each other's material.
     */
    public function doesNotCarry(): string
    {
        return match ($this) {
            self::NewLife => 'IT DOES NOT CARRY THE ANTAGONIST\'S YEAR. No report on their job, their company, '
                .'their family or their rooms, and no gesture back at them — nothing returned, refused or '
                .'sent back; the refusal already did that. At most one clause may touch them, as something '
                .'the narrator notices in passing and lets go. Their year belongs to the other ending, and '
                .'this video does not have it.',
            self::AntagonistVoice => 'IT DOES NOT CARRY THE NARRATOR\'S YEAR. The narrator\'s story ends when '
                .'they walk away from the last refusal: no time jump in the narrator\'s voice, no epilogue, '
                .'no account of their work or their new life. Inside the antagonist\'s chapter the narrator '
                .'is seen only from outside, in ONE fact the antagonist knows, never a list.',
        };
    }

    /** Whether the outline is asked for `antagonist_regret`. */
    public function asksForRegret(): bool
    {
        return $this === self::AntagonistVoice;
    }
}
