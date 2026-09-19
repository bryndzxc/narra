<?php

namespace App\Support;

use App\Enums\ActPhase;
use App\Enums\CastRole;
use App\Models\Act;
use App\Models\Story;

/**
 * Whether a story's refusal act ends on the antagonist's own chapter, and
 * whose name that chapter carries.
 *
 * ONE OWNER, because four places ask the same question — the act prompt, the
 * act's structural checks after the cost row, Gate 1's review and the scene
 * call — and a question answered four times is four chances to disagree. The
 * extraction retry note and CharacterTextGuard disagreed on exactly that shape.
 *
 * The ending chosen for the story decides first (App\Enums\StoryEnding); on a
 * story that chose hers, or one outlined before the choice existed, the answer
 * is yes only when BOTH are true: the outline carries an
 * antagonist_regret (so there is a planned reveal to tell), and its cast names
 * an antagonist (so the chapter has a name to announce). A story outlined
 * before the field existed has neither half and gets exactly the refusal act
 * it had. See the migration that added `antagonist_regret`.
 */
final class AntagonistPointOfView
{
    /**
     * The antagonist's cast name when her chapter is asked for, else null.
     *
     * THE ENDING DECIDES FIRST (2026-09-19). A story whose operator chose the
     * narrator's new life never gets her chapter, whatever the regret field
     * holds: the two endings are exclusive, and a regret typed in at Gate 1 on
     * that story is reported there rather than obeyed. A story with NO ending
     * was outlined before the choice existed and keeps the old rule — the
     * regret's presence — so it gets exactly the refusal act it had.
     */
    public static function nameFor(Story $story): ?string
    {
        if ($story->ending !== null && ! $story->ending->asksForRegret()) {
            return null;
        }

        if (trim((string) $story->antagonist_regret) === '') {
            return null;
        }

        foreach (OutlineCast::members($story->outline_cast) as $member) {
            if ($member->role === CastRole::Antagonist && trim($member->name) !== '') {
                return trim($member->name);
            }
        }

        return null;
    }

    /** Whether THIS act is the one that ends on her chapter. */
    public static function endsAct(Story $story, ?ActPhase $phase): bool
    {
        return $phase === ActPhase::Refusal && self::nameFor($story) !== null;
    }

    /** Convenience for a stored act. */
    public static function endsStoredAct(Story $story, Act $act): bool
    {
        return self::endsAct($story, $act->phase);
    }
}
