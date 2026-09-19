<?php

namespace App\Support;

use App\Enums\CastRole;
use App\Support\Providers\CastMember;

/**
 * The accomplice as a person with a stake, and the one thing his act may
 * never be built on.
 *
 * ---------------------------------------------------------------------------
 * THE FINDING
 * ---------------------------------------------------------------------------
 *
 * The first reference (docs/refence/transcript-3446.txt) has a silent
 * accomplice, and the prompt turned that into "they do not need a line" in
 * three places. Story 33's summaries then carried "has not spoken a single
 * quoted word" from act to act as a fact. The transcript explains its own
 * silence at 29:29: the man is "a younger guy from school who had a crush on
 * me", asked to pretend. He has no stake, so he has nothing to say.
 *
 * The second reference (docs/refence/transcript-lydia.txt) runs the other
 * way. Gerald first speaks to the narrator at 1:28, changes his justification
 * four times (harmless, contrite, victim of family pressure, and then — the
 * act dropped at 11:21 — the man in charge), loses about twelve times across
 * the four scenes he shares with the narrator, is exposed at 23:06 as having
 * schemed for the company shares, is silent exactly once — the moment he is
 * caught — and ends in prison "for even longer" than anyone else. He talks
 * because he wants something of his own.
 *
 * So silence was one variant, and what decides it is whether the accomplice
 * has a stake. The spine now gives him one: `accomplice_motive`,
 * `accomplice_performance`, `accomplice_fall`. See CLAUDE.md 3g.
 *
 * ---------------------------------------------------------------------------
 * THE EXCLUSION
 * ---------------------------------------------------------------------------
 *
 * Gerald's act is built on orientation-coded mockery — a "sissy-sounding"
 * voice, "I'm not into women", a staged kiss with another man as the lie.
 * The device underneath is an accomplice whose harmless act the narrator sees
 * through, and it needs none of that. The operator excluded it entirely.
 *
 * The prompt asks for a ROLE instead (the old friend, the loyal colleague,
 * the considerate relative) and says what not to use. That is the request.
 * `codedTerms()` is the invariant: `GenerateOutline` refuses an outline whose
 * accomplice fields carry one of these words, after the cost row, and Gate 1
 * reports one an operator typed in as a PROBLEM. One list, two readers.
 *
 * Matched whole-word and deliberately WITHOUT a negation window, unlike the
 * departure markers. "He is not into women" is not the good case of this
 * check — it is Gerald's exact line, the tell the act was built on.
 *
 * UNCHECKED, said so it is not read as covered: the act scripts. Words in
 * prose need a reading this list cannot give, and a story can have a gay
 * character who is not a joke. The exclusion is about the accomplice's act,
 * which lives in these three fields and reaches the act writer from here.
 */
final class AccompliceArc
{
    /** The three spine columns, in narrative order. */
    public const FIELDS = ['accomplice_motive', 'accomplice_performance', 'accomplice_fall'];

    /**
     * Words that mean the act is being built on orientation or on a manner
     * mocked as unmanly.
     *
     * Phrases rather than single words where a single word is common in
     * innocent prose: "into men" is a phrase, "men" is not.
     */
    private const CODED_TERMS = [
        'gay', 'gays', 'sissy', 'sissies', 'sissified', 'effeminate', 'homosexual', 'lesbian',
        'queer', 'closeted', 'flamboyant', 'limp-wristed', 'girly', 'pansy', 'swishy',
        'not into women', 'not into men', 'into men', 'likes men', 'into guys',
        "doesn't like women", 'does not like women', 'sexual orientation', 'his orientation',
        'her orientation', 'not straight',
    ];

    /** Whether the cast declares an accomplice at all. */
    public static function declared(?array $castRows): bool
    {
        foreach (OutlineCast::members($castRows) as $member) {
            if ($member->role === CastRole::Accomplice) {
                return true;
            }
        }

        return false;
    }

    /**
     * The same question for a draft, which carries members rather than rows.
     *
     * @param  array<int, CastMember>  $members
     */
    public static function declaredIn(array $members): bool
    {
        foreach ($members as $member) {
            if ($member->role === CastRole::Accomplice) {
                return true;
            }
        }

        return false;
    }

    /**
     * Coded terms present in each field, keyed by field.
     *
     * @param  array<string, ?string>  $fields
     * @return array<string, array<int, string>>
     */
    public static function codedTerms(array $fields): array
    {
        $hits = [];

        foreach ($fields as $key => $value) {
            $text = mb_strtolower((string) $value);

            if (trim($text) === '') {
                continue;
            }

            $found = array_values(array_filter(
                self::CODED_TERMS,
                static fn (string $term): bool => (bool) preg_match('/\b'.preg_quote($term, '/').'\b/u', $text),
            ));

            if ($found !== []) {
                $hits[$key] = $found;
            }
        }

        return $hits;
    }

    /**
     * Whether the text quotes at least one line: double or SINGLE quotes,
     * straight or curly, around a few words.
     *
     * Single quotes because the model uses them. Story 38's first premise roll
     * (2026-09-19) quoted the accomplice in all three candidates, in both the
     * field and the prose, as 'I only ever tried to carry what she couldn't
     * carry alone...' — single quotes, the way a writer avoids escaping a
     * double quote inside a JSON string — and this check, which knew only
     * double quotes, reported all three as "described and never spoken". The
     * operator read the warning and reported a silent accomplice. The line was
     * there; the detector could not see it.
     *
     * A single quote is also an apostrophe, so the opening mark may not follow
     * a letter or digit (the boys' coats) and the closing one may not be
     * followed by one (couldn't): 'He's the one' is one quoted line, not a
     * quote closed after "He".
     */
    public static function quotesALine(string $text): bool
    {
        return (bool) preg_match(
            '/"[^"]{4,}"|“[^”]{4,}”'
            ."|(?<![\\p{L}\\p{N}])'(?=\\S).{4,}?(?<=\\S)'(?![\\p{L}\\p{N}])"
            .'|(?<![\p{L}\p{N}])‘(?=\S).{4,}?(?<=\S)’(?![\p{L}\p{N}])/u',
            $text,
        );
    }
}
