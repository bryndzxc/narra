<?php

namespace App\Support;

use App\Enums\CastRole;
use App\Enums\StoryFormat;
use App\Models\Character;
use App\Models\Story;
use App\Support\Providers\CastMember;

/**
 * The people a story names, decided at the outline.
 *
 * ---------------------------------------------------------------------------
 * WHY THE CAST IS DECIDED HERE AND NOT HARVESTED LATER
 * ---------------------------------------------------------------------------
 *
 * Measured on 2026-09-15 across the seven stories with a cast (72 entries):
 * 55 were already named in the outline's spine fields, 14 were added by the
 * act writer, and 3 were invented by the extractor from a role ("Shen's
 * Father", zero script mentions). Nothing counted them at any stage. The only
 * per-story cast text, `cast_age_profile`, reached exactly one reader — the
 * extractor — which runs after every act is written and every name is already
 * in the narration.
 *
 * So the decision moved to where it is made: the outline returns `cast` as a
 * schema field before the spine, Gate 1 shows and edits it, the act writer is
 * handed it as the people who exist, and the extractor describes those people
 * and nobody else. A schema field and not a prompt line, for the reason
 * `expression` is one: its surface measured 24 times a prompt rule's.
 *
 * ---------------------------------------------------------------------------
 * AND THE NAMES ARE CHECKED AGAINST THE CHANNEL
 * ---------------------------------------------------------------------------
 *
 * Grace Zhou was in four of the last five casts and Wang Suhua in four. Both
 * are example names in the en-CN locale guidance, which gives eight examples,
 * and 16 of the 72 cast entries were those eight verbatim. The guidance meant
 * them as illustrations of a FORM; the writer read them as a list to draw
 * from. An illustration became a source.
 *
 * The fix is a check against the outcome rather than an edit to the source.
 * Removing or rotating the examples treats one supplier of repeated names and
 * leaves the model's own favourites free to repeat; what a viewer notices is
 * the same full name in two videos, whatever put it there. `recentNames()` is
 * what the outline prompt lists as unavailable and what `GenerateOutline`
 * refuses a cast for reusing.
 *
 * Checked on FULL names only. A given name repeated with a different family
 * name — Amy Sun, Amy Tang, Amy Shen — is not checked, and that is a known
 * gap rather than a covered case: a token match would refuse every shared
 * Chinese family name, and Zhou and Wang are common for a reason.
 */
final class OutlineCast
{
    /**
     * First words that make a stored character name a ROLE LABEL rather than a
     * name. Legacy casts carry "Second Uncle", "Elder Chen" and "Sophie's
     * Father"; treating those as taken names would refuse an outline for
     * containing an uncle.
     */
    private const LABEL_WORDS = [
        'first', 'second', 'third', 'fourth', 'eldest', 'elder', 'younger', 'little', 'big', 'old',
        'aunt', 'auntie', 'uncle', 'grandma', 'grandpa', 'grandmother', 'grandfather',
        'mother', 'father', 'mom', 'dad', 'master', 'director', 'manager', 'doctor', 'teacher',
    ];

    public static function maxNamed(): int
    {
        return max(1, (int) config('cast.max_named', 8));
    }

    /**
     * The roles the outline's `cast` ARRAY may carry.
     *
     * Not the narrator on a single narrative: the narrator is a required
     * property of their own, ahead of the array, and `outlineDraftFrom()`
     * puts them back as the first row. Story 37's outline returned eight
     * rows, the whole budget, every role filled but the narrator, with every
     * relationship written as "my ..." — the model wrote the cast FROM the
     * narrator's seat and left them off it. A cast with no narrator or two was
     * checked for and refused after a billed call; now it cannot be returned,
     * which is rule 2 in CLAUDE.md. The refusal in `structuralProblems()`
     * stays as the invariant behind the schema.
     *
     * An anthology keeps `narrator` in the array: each act is its own story
     * with its own first person, and the property carries only act 1's.
     *
     * One owner, because the schema's enum and the prompt's role list are two
     * readers of one answer.
     *
     * @return array<int, CastRole>
     */
    public static function castArrayRoles(StoryFormat $format): array
    {
        return $format === StoryFormat::Anthology
            ? CastRole::cases()
            : array_values(array_filter(
                CastRole::cases(),
                static fn (CastRole $role): bool => $role !== CastRole::Narrator,
            ));
    }

    /**
     * How many rows count against `maxNamed()`: everyone but the narrator.
     *
     * The prompt states the budget as people BESIDES the narrator, since the
     * narrator is asked for separately and is drawn in every story whatever
     * the premise. Counting them here as well would warn at Gate 1 about a
     * budget the outline was never told included them.
     *
     * @param  array<int, CastMember>  $members
     */
    public static function budgetCount(array $members): int
    {
        return count(array_filter(
            $members,
            static fn (CastMember $m): bool => $m->role !== CastRole::Narrator,
        ));
    }

    /**
     * @param  array<int, array<string, mixed>>|null  $rows
     * @return array<int, CastMember>
     */
    public static function members(?array $rows): array
    {
        return array_values(array_map(
            static fn (array $row): CastMember => CastMember::fromRow($row),
            array_filter($rows ?? [], 'is_array'),
        ));
    }

    /**
     * @param  array<int, CastMember>  $members
     * @return array<int, array{name: string, role: string, relationship: string}>
     */
    public static function rows(array $members): array
    {
        return array_map(static fn (CastMember $m): array => $m->toRow(), array_values($members));
    }

    public static function normalise(string $name): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $name)));
    }

    /**
     * What makes a cast unusable, as sentences.
     *
     * These are refusals at the outline and problems at Gate 1: a cast with no
     * narrator or two antagonists is not a weak cast, it is one the act writer
     * and the extractor cannot be handed. The COUNT is not here — over the
     * budget is a warning, because deleting a row at Gate 1 is free.
     *
     * The narrator and antagonist rules do not apply to an anthology, where
     * each act is its own story with its own narrator.
     *
     * @param  array<int, CastMember>  $members
     * @return array<int, string>
     */
    public static function structuralProblems(array $members, StoryFormat $format): array
    {
        if ($members === []) {
            return ['The cast is empty. Every person this story names is declared here, before the spine, '
                .'and the act writer and the extractor are both handed this list.'];
        }

        $problems = [];
        $seen = [];

        foreach ($members as $index => $member) {
            $row = $index + 1;

            if ($member->name === '') {
                $problems[] = "Cast row {$row} has no name.";

                continue;
            }

            if ($member->role === null) {
                $problems[] = "{$member->name} has no role.";
            }

            $key = self::normalise($member->name);

            if (isset($seen[$key])) {
                $problems[] = "{$member->name} is in the cast twice. Names are how a character is found in "
                    .'every prompt, so two rows with one name are one face drawn for two people.';
            }

            $seen[$key] = true;
        }

        if ($format === StoryFormat::Anthology) {
            return $problems;
        }

        $count = static fn (CastRole $role): int => count(array_filter(
            $members,
            static fn (CastMember $m): bool => $m->role === $role,
        ));

        if ($count(CastRole::Narrator) !== 1) {
            $problems[] = sprintf(
                'The cast has %d narrators. A single narrative has exactly one first person.',
                $count(CastRole::Narrator),
            );
        }

        if ($count(CastRole::Antagonist) !== 1) {
            $problems[] = sprintf(
                'The cast has %d antagonists. A single narrative has exactly one person who does it.',
                $count(CastRole::Antagonist),
            );
        }

        // At most one, because the spine describes ONE: `accomplice_motive`,
        // `accomplice_performance` and `accomplice_fall` are each one person's
        // stake, act and losses. Two accomplices is two arcs asked of three
        // fields, and the act writer would be handed a fall that fits neither.
        if ($count(CastRole::Accomplice) > 1) {
            $problems[] = sprintf(
                'The cast has %d accomplices. The spine gives the accomplice one motive, one act and '
                .'one fall; a second person on her side is "on her side", not a second accomplice.',
                $count(CastRole::Accomplice),
            );
        }

        if ($count(CastRole::FuturePartner) > 1) {
            $problems[] = sprintf(
                'The cast has %d future partners. The narrator ends up with one person.',
                $count(CastRole::FuturePartner),
            );
        }

        return $problems;
    }

    /**
     * The person the narrator ends up with, if the cast names one.
     *
     * @param  array<int, array<string, mixed>>|null  $rows
     */
    public static function futurePartner(?array $rows): ?CastMember
    {
        foreach (self::members($rows) as $member) {
            if ($member->role === CastRole::FuturePartner && $member->name !== '') {
                return $member;
            }
        }

        return null;
    }

    /**
     * The cast the next outline must honour: the story's, while no act carries
     * a script.
     *
     * -----------------------------------------------------------------------
     * IT READ "WHILE THE STORY HAS NO ACTS", AND THAT COST STORY 39 ITS PARTNER
     * -----------------------------------------------------------------------
     *
     * The narrower scope was deliberate and its reasoning is worth keeping,
     * because it is half right: *holding the cast fixed would make re-outlining
     * unable to repair a bad cast*. True. What it missed is that the SAME
     * condition also decides whether a re-outline may destroy a GOOD one, and
     * nothing asked which case a story was in.
     *
     * Measured, 2026-09-20. Story 39 was re-outlined at `outlined` with five
     * acts and no scripts, to put a chosen end state into its last summary. It
     * had acts, so this returned nothing, so the prompt never said the cast was
     * fixed and `chosenCastChanges()` had nothing to compare. The outline came
     * back having renamed the narrator, demoted Nancy Kong — the older sister
     * the operator's idea named, and the reason `married` had been chosen at
     * all — to `narrator_side`, and invented a stranger for the partner row.
     * $0.2566, and the guard written for exactly that swap (story 38) was inert
     * for the whole call. Restoring the cast and re-outlining with this scope
     * returned Nancy Kong, married, at 0 problems and 0 warnings.
     *
     * So the line is drawn where the words are, which is where this project
     * draws it everywhere else: once an act carries a SCRIPT the cast is in the
     * prose and an outline cannot be replaced at all (GenerateOutline::
     * assertReady refuses past `outlined`). Before that, a cast on the story is
     * one the operator has seen at Gate 1 and can edit there, so honouring it
     * is honouring them. Same line as `PartnerEnding::stillChoosable()`, from
     * the same predicate.
     *
     * **The repair path did not go away; it stopped being the default.**
     * `$keepCast = false` is the operator saying, on the re-outline confirm,
     * that this cast is the thing that is wrong. That is a press-level fact and
     * not a story-level one, which is why it is a parameter rather than a
     * column: the same story is repairable on one press and pinned on the next.
     * The outline prompt and GenerateOutline both ask this, with the same flag,
     * so they cannot disagree about it.
     *
     * @return array<int, CastMember>
     */
    public static function chosenBeforeOutline(Story $story, bool $keepCast = true): array
    {
        if (! $keepCast || $story->hasWrittenActs()) {
            return [];
        }

        return self::members($story->outline_cast);
    }

    /**
     * What the outline did to a cast that was chosen before it, as sentences.
     *
     * A cast on the story before the outline is written was picked with a
     * premise (Gate 1's "Use this premise"), or typed there by the operator.
     * Story 38's roll declared Chloe Rong as the future partner in all three
     * candidates; the pick kept only the prose, the prose never said what she
     * was to the narrator, and the outline made her a friend and invented a
     * stranger for the row. The outline is now handed the chosen cast, and
     * this is the invariant behind the request: every chosen person comes back
     * under the same name in the same role. The outline may add people (the
     * restraint rule still bounds that) and may rewrite a relationship line.
     *
     * @param  array<int, CastMember>  $chosen
     * @param  array<int, CastMember>  $returned
     * @return array<int, string>
     */
    public static function chosenCastChanges(array $chosen, array $returned): array
    {
        $byName = [];

        foreach ($returned as $member) {
            $byName[self::normalise($member->name)] = $member;
        }

        $changes = [];

        foreach ($chosen as $member) {
            if ($member->name === '') {
                continue;
            }

            $back = $byName[self::normalise($member->name)] ?? null;
            $role = $member->role?->label() ?? 'no role';

            if ($back === null) {
                $changes[] = "{$member->name} ({$role}) is not in the outline's cast.";
            } elseif ($back->role !== $member->role) {
                $changes[] = sprintf(
                    '%s came back as %s instead of %s.',
                    $member->name,
                    $back->role?->label() ?? 'no role',
                    $role,
                );
            }
        }

        return $changes;
    }

    /**
     * Full names used by the most recent other stories, keyed by normalised name.
     *
     * Read from BOTH the outline cast and the extracted characters, because
     * every story before the cast existed has only the second — and those are
     * the published videos whose names a viewer has heard. Fixtures are
     * excluded: nobody watches them.
     *
     * @return array<string, array{name: string, story: string}>
     */
    public static function recentNames(Story $story): array
    {
        $stories = Story::query()
            ->whereKeyNot($story->getKey())
            ->where('is_fixture', false)
            ->orderByDesc('id')
            ->limit(max(0, (int) config('cast.recent_story_window', 10)))
            ->get(['id', 'slug', 'outline_cast']);

        $names = [];

        foreach ($stories as $other) {
            $candidates = array_map(
                static fn (CastMember $m): string => $m->name,
                self::members($other->outline_cast),
            );

            $candidates = array_merge(
                $candidates,
                Character::query()->where('story_id', $other->id)->pluck('name')->all(),
            );

            foreach ($candidates as $name) {
                if (! self::isAName((string) $name)) {
                    continue;
                }

                $names[self::normalise((string) $name)] ??= ['name' => trim((string) $name), 'story' => (string) $other->slug];
            }
        }

        return $names;
    }

    /**
     * The cast members whose full name a recent story already used.
     *
     * @param  array<int, CastMember>  $members
     * @return array<int, array{name: string, story: string}>
     */
    public static function reused(array $members, Story $story): array
    {
        $recent = self::recentNames($story);
        $hits = [];

        foreach ($members as $member) {
            $key = self::normalise($member->name);

            if ($key !== '' && isset($recent[$key])) {
                $hits[] = ['name' => $member->name, 'story' => $recent[$key]['story']];
            }
        }

        return $hits;
    }

    /** A person's name rather than a role label: two words or more, no possessive, no kinship title first. */
    private static function isAName(string $name): bool
    {
        $name = trim($name);

        if ($name === '' || str_contains($name, "'") || str_contains($name, '’')) {
            return false;
        }

        $tokens = preg_split('/\s+/u', $name) ?: [];

        return count($tokens) >= 2 && ! in_array(mb_strtolower($tokens[0]), self::LABEL_WORDS, true);
    }
}
