<?php

namespace App\Support;

use App\Enums\PartnerEndState;
use App\Enums\StoryEnding;
use App\Enums\StoryFormat;
use App\Models\Story;
use App\Support\Providers\CastMember;

/**
 * Whether a story's end state is read at all, and which one it is.
 *
 * ONE OWNER, for `AntagonistPointOfView`'s reason: six places ask the same
 * question — the outline prompt, the act arc, the last chapter, the scene
 * call, the metadata brief and Gate 1 — and a question answered six times is
 * six chances to disagree.
 *
 * ---------------------------------------------------------------------------
 * THE SCOPE, WHICH IS NARROWER THAN THE COLUMN
 * ---------------------------------------------------------------------------
 *
 * `stories.partner_end_state` is on every story and is READ only when:
 *
 *   - the story is a single narrative (an anthology has no ending at all), and
 *   - the chosen ending is the narrator's new life — on the antagonist's
 *     chapter the narrator's year is not shown and the partner is not on
 *     screen at the end, so there is no end state to state, and
 *   - the cast names a future partner — with nobody to be anything to, the
 *     question has no subject.
 *
 * NULL IS "NOT CHOSEN" and never a default. A story outlined before this
 * column existed reads null, and so does one whose partner the OUTLINE
 * invented rather than the premise: the question can only be required where
 * its answer is knowable before the call, which is a cast chosen with the
 * premise. Both get the widened vocabulary and neither gets a warning about
 * a column that did not exist when they were written. Gate 1's readout says
 * which.
 */
final class PartnerEnding
{
    /** The partner, when this story's ending actually puts them on screen. */
    public static function partnerFor(Story $story): ?CastMember
    {
        if ($story->format !== StoryFormat::Single || $story->ending !== StoryEnding::NewLife) {
            return null;
        }

        return OutlineCast::futurePartner($story->outline_cast);
    }

    /**
     * The chosen end state, only where it is read. Null means the operator
     * did not choose one, and every writer gets the widened list instead.
     */
    public static function stateFor(Story $story): ?PartnerEndState
    {
        return self::partnerFor($story) === null ? null : $story->partner_end_state;
    }

    /**
     * Whether the outline must be refused for want of one.
     *
     * Only when the answer is knowable before the call, which is exactly:
     * THE STORY ALREADY CARRIES A CAST NAMING A FUTURE PARTNER. That is a
     * cast picked with the premise, or one a previous outline wrote — a
     * re-outline is the second case, and story 39 is in it. On a typed
     * premise's first outline there is no cast at all, so nothing is refused
     * and nothing could be: asking would be a question about a person who
     * does not exist yet.
     *
     * The first version of this read `OutlineCast::chosenBeforeOutline()`,
     * which is empty once a story has acts — so the one story the column was
     * built for would have re-outlined with no end state and no refusal.
     * Caught by asking what would happen to story 39 next, not by a test.
     */
    public static function requiredBeforeOutline(Story $story): bool
    {
        if ($story->format !== StoryFormat::Single || $story->ending !== StoryEnding::NewLife) {
            return false;
        }

        if ($story->partner_end_state !== null) {
            return false;
        }

        return OutlineCast::futurePartner($story->outline_cast) !== null;
    }

    /**
     * Whether a WRITE PRESS — the outline or the act scripts — is refused for
     * want of one.
     *
     * Wider than the outline, because the acts are where the words actually
     * land: every act summary is written to this, the last chapter is written
     * to this, and six act calls bought against "partner" are six act calls.
     *
     * AND NARROWER THAN THE REQUIREMENT, which is the load-bearing half. Once
     * any act carries a script the operator can no longer choose, so refusing
     * would be a guard nobody can satisfy — and the press it would kill is the
     * resume after a run that died on act 4, which is the one press this
     * pipeline most needs to keep working.
     */
    public static function blocksWriting(Story $story): bool
    {
        return self::requiredBeforeOutline($story) && self::stillChoosable($story);
    }

    /**
     * Whether the operator may still choose it.
     *
     * NOT `canChooseEnding()`, and the difference is the point. The ending is
     * fixed once acts exist, because the outline SCHEMA branches on it. This
     * is read by the act writer as well, and `GenerateActScripts` REPLACES
     * each act's summary with the one the act writer returns — so a state
     * chosen after the outline still reaches the artifact Gate 1 checks. The
     * line is drawn where the scripts are: once an act carries one, the words
     * are in the prose and a radio button cannot move them.
     */
    public static function stillChoosable(Story $story): bool
    {
        return $story->format === StoryFormat::Single
            && ! $story->hasWrittenActs();
    }
}
