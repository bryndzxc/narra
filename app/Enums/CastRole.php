<?php

namespace App\Enums;

/**
 * What a named person is FOR in this story.
 *
 * The outline declares every person the story names, each with one of these,
 * before a word of the spine is written. See `App\Support\OutlineCast` and
 * CLAUDE.md 3f for why the cast is decided at the outline and not harvested
 * from the scripts afterwards.
 *
 * Six roles and no "witness", deliberately. A cousin who asks one question at
 * the banquet and is never drawn is not a row: they stay "his cousin" in every
 * field and every act. A role for them would be an invitation to name them,
 * and naming them is how story 33's four tables became twelve characters.
 *
 * Stored inside `stories.outline_cast` as JSON, not as a MySQL ENUM column, so
 * adding a case here needs no migration and cannot drift from a column the way
 * `CostUnit::TotalTokens` did. `SchemaEnumDrift` has nothing to watch.
 */
enum CastRole: string
{
    /** The first person. Exactly one on a single narrative. */
    case Narrator = 'narrator';

    /** The partner who does it. Exactly one on a single narrative. */
    case Antagonist = 'antagonist';

    /**
     * Who it is done WITH or FOR, and who has a stake of their own in it.
     *
     * At most one. The spine gives this person a motive, a harmless act he
     * performs for the antagonist, and a fall across the last three acts —
     * see CLAUDE.md 3g. The first reference's accomplice was silent because
     * he was a prop with no stake; one with a stake talks.
     */
    case Accomplice = 'accomplice';

    /** Backs the antagonist's excuse: a mother, an in-law, a friend who takes her side. */
    case AntagonistSide = 'antagonist_side';

    /** On the narrator's side: friends, the narrator's own family, a colleague. */
    case NarratorSide = 'narrator_side';

    /**
     * The person the narrator ends up with.
     *
     * A role and not a column on the story, on purpose: it is a person, they
     * have a name, they get a face, and the extractor describes them like
     * anyone else. Whether this row alone carries them into the acts, or a
     * separate instruction is needed, is the measurement item 5 waits on.
     * Its first reading arrived on 2026-09-19 from a direction nobody
     * predicted: the premise generator declared this row in all three of
     * story 38's candidates ("married her best friend" was in the idea) and
     * none of the three premises named her. The row existed one stage
     * earlier than the outline and was dropped there. See CLAUDE.md 3i.
     *
     * Not "the woman". That was written for a man narrating, and story 33 is a
     * woman narrating a partner betrayal: the guidance told the outline writer
     * her future partner was a woman. The premise says who it is.
     * At most one.
     */
    case FuturePartner = 'future_partner';

    public function label(): string
    {
        return match ($this) {
            self::Narrator => 'Narrator',
            self::Antagonist => 'Antagonist',
            self::Accomplice => 'Accomplice',
            self::AntagonistSide => 'On her side',
            self::NarratorSide => 'On the narrator\'s side',
            self::FuturePartner => 'Future partner',
        };
    }

    /**
     * What the outline writer is told each role means, in the cast's role list.
     *
     * The narrator line is read only on an anthology. A single narrative asks
     * for its narrator as a property of its own ahead of the cast, and leaves
     * `narrator` out of the list — see OutlineCast::castArrayRoles().
     */
    public function guidance(): string
    {
        return match ($this) {
            self::Narrator => 'the first person telling one act\'s story: every act\'s narrator but act 1\'s, '
                .'which is given above',
            self::Antagonist => 'the partner who betrays the narrator. Exactly one',
            self::Accomplice => 'the person it is done with or for, in the room for the betrayal, with a '
                .'stake of their own that she does not know about. At most one',
            self::AntagonistSide => 'someone who backs her excuse — a parent, an in-law, a friend who takes her side',
            self::NarratorSide => 'someone on the narrator\'s side — a friend, the narrator\'s own family, a colleague',
            self::FuturePartner => 'the person the narrator ends up with, if the premise names one, as the premise '
                .'names them. At most one. Their relationship says who they arrive through',
        };
    }
}
