<?php

namespace App\Support;

use App\Enums\CastRole;
use App\Models\Story;

/**
 * The narrator's own possessive, in the narrator's own voice.
 *
 * ---------------------------------------------------------------------------
 * THE INSTANCE
 * ---------------------------------------------------------------------------
 *
 * Story 36, at 10:19 of a 39:15 published video, in the narration and in the
 * burned-in subtitles:
 *
 *     "...Nie Guifang stood up at table one, sixty years old that day, in red,
 *      and said to the whole room that the apartment money MY HUSBAND'S parents
 *      put in back in 2019..."
 *
 * Aaron Cui is the husband. The sentence reports what his mother-in-law said
 * and the possessive slipped into HER seat halfway through: "my husband's
 * parents" are his own parents. A listener at 10:19 hears a first-person
 * narrator call himself somebody's wife, four minutes after he has been
 * established as a man whose wife is the antagonist.
 *
 * One instance in four rendered stories, ~160,000 characters of narration.
 * Nothing in the pipeline could see it: `LocaleGuard` reads a denylist,
 * `CharacterTextGuard` reads cast descriptions, and no check in the app has
 * ever had an opinion about who "I" is.
 *
 * ---------------------------------------------------------------------------
 * WHAT IT CHECKS, AND WHY IT IS DERIVED FROM THE STORY RATHER THAN FROM THE
 * NARRATOR'S GENDER
 * ---------------------------------------------------------------------------
 *
 * The obvious rule is "a man does not say 'my husband'". That encodes an
 * assumption about who is married to whom, and this contract deliberately
 * excludes reasoning of that kind (see the accomplice exclusion in 3g).
 *
 * So the rule is INTERNAL to the story: the outline cast says what the
 * narrator calls the antagonist — "my wife", "his wife", "the husband" — and
 * the MIRROR of that term, in the narrator's own narration, is somebody
 * else's possessive in the narrator's mouth. A story that calls her his wife
 * has no sentence in which "my husband" belongs to the narrator.
 *
 * ---------------------------------------------------------------------------
 * `voice_id` WAS THE FALLBACK FOR TWO HOURS AND THE MEASUREMENT KILLED IT
 * ---------------------------------------------------------------------------
 *
 * The first version fell back to `NarratorVoice::genderOf($story->voice_id)`
 * when a story had no cast, on the reasoning that the voice is the record of
 * the narrator choice. Run across every story in the database it produced
 * **13 false fires against 1 true one** — every act of stories 30, 31 and 32,
 * which are narrated by a daughter-in-law and correctly say "my husband" on
 * every page.
 *
 * The cause is a category error in the question, not a bad threshold.
 * `genderOf()` answers "which narrator gender is this VOICE the channel's
 * voice for". It does not answer "what gender is this story's narrator", and
 * on stories 29-32 the two disagree: they are women's first person carrying
 * Brian, because they were created before the new-story form asked who
 * narrates and `default_voice_id` was the only answer. Story 33 is the first
 * story where the voice was set deliberately.
 *
 * So there is no fallback. The CAST is the only source, because the cast is
 * the story saying what it is; a story with no cast is silent. That is one
 * true hit and zero false ones across every story in the database, measured
 * both ways before this sentence was written.
 *
 * **Seen and not fixed, because it is a different change:**
 * `ClaudeScriptWriter::premisePrompt()` reads the narrator's gender back from
 * `voice_id` through the same method, to decide whose first person it writes.
 * On a story whose voice was never set deliberately that is the same wrong
 * answer. It is safe for every story created since the form gained the
 * narrator radio, and that is the whole of why it has not bitten.
 *
 * A story that the cast does not answer for is silent. Absence is not
 * agreement here any more than anywhere else, and an unknown narrator accused
 * of a slip is the over-report that retires a detector.
 *
 * ---------------------------------------------------------------------------
 * QUOTED SPEECH IS STRIPPED FIRST, AND THAT IS THE WHOLE PRECISION STORY
 * ---------------------------------------------------------------------------
 *
 * Every character in these stories has a spouse and says so. Nie Guifang says
 * "my husband" correctly, in quotes, and so does every aunt at every banquet.
 * The defect is the term in NARRATION. Stripping quoted spans — straight and
 * curly, both directions — is what makes a two-word match safe, and it is the
 * same move `AccompliceArc::quotesALine()` had to learn in the other
 * direction.
 *
 * Measured over the four published stories before this existed: 1 hit, and it
 * is the real one. Zero on 33, 37 and 38.
 *
 * **Unchecked, said so it is not read as covered:** every other possessive a
 * narrator can borrow — "my mother" used of the antagonist's mother, "our"
 * used of the antagonist's family, a sentence that changes seat without a
 * spouse word in it. Those need sentence parsing, and a word list that tried
 * would fire on the correct case constantly. This catches one shape, the one
 * shape that has actually happened.
 */
final class NarratorPointOfView
{
    /** The narrator's spouse word, and the one that is therefore not theirs. */
    private const MIRROR = ['wife' => 'husband', 'husband' => 'wife'];

    /**
     * The term the narrator may NOT use of their own spouse, or null when the
     * story does not say.
     */
    public static function borrowedTermFor(Story $story): ?string
    {
        $own = self::ownTermFor($story);

        return $own === null ? null : self::MIRROR[$own];
    }

    /**
     * What the narrator calls their spouse, from the cast and nothing else.
     * Null when the cast does not say, which is every story outlined before
     * the cast existed.
     */
    public static function ownTermFor(Story $story): ?string
    {
        foreach ((array) ($story->outline_cast ?? []) as $row) {
            if (($row['role'] ?? null) !== CastRole::Antagonist->value) {
                continue;
            }

            $line = mb_strtolower((string) ($row['relationship'] ?? ''));

            // "his wife of six years" / "my wife, CEO of the company" — the
            // antagonist's own row, in the narrator's frame. A row naming
            // neither is not a spouse story and answers nothing.
            foreach (['wife', 'husband'] as $term) {
                if (preg_match('/\b(?:my|his|her|the)\s+'.$term.'\b/u', $line) === 1) {
                    return $term;
                }
            }
        }

        return null;
    }

    /**
     * Slips in one piece of narration.
     *
     * @return array<int, array{term: string, context: string}>
     */
    public static function slips(?string $text, string $borrowed): array
    {
        $narration = self::withoutQuotedSpeech((string) $text);

        if (trim($narration) === '') {
            return [];
        }

        $found = [];

        // The possessive form only: "my husband", "my husband's". "The
        // husband" is how the cast row describes the narrator and is not a
        // first-person claim.
        if (preg_match_all('/\bmy\s+'.$borrowed.'(?:\'s)?\b/iu', $narration, $m, PREG_OFFSET_CAPTURE) === 0) {
            return [];
        }

        foreach ($m[0] as $hit) {
            $found[] = [
                'term' => trim((string) $hit[0]),
                'context' => self::contextAround($narration, (int) $hit[1], mb_strlen((string) $hit[0])),
            ];
        }

        return $found;
    }

    /**
     * Quoted speech blanked to spaces, so every offset still points where it
     * did and every line number in a longer text survives.
     *
     * Straight and curly pairs. An unpaired quote closes at the end of the
     * text rather than swallowing nothing, because a stray opening mark is
     * far more common in this prose than a deliberate one and the safe
     * failure here is to check LESS.
     */
    public static function withoutQuotedSpeech(string $text): string
    {
        foreach (['"([^"]*)"', '\x{201C}([^\x{201D}]*)\x{201D}'] as $pattern) {
            $text = (string) preg_replace_callback(
                '/'.$pattern.'/u',
                static fn (array $m): string => str_repeat(' ', mb_strlen($m[0])),
                $text
            );
        }

        return $text;
    }

    private static function contextAround(string $text, int $byteOffset, int $length): string
    {
        $before = mb_strlen(substr($text, 0, $byteOffset));
        $start = max(0, $before - 60);
        $slice = trim((string) preg_replace('/\s+/u', ' ', mb_substr($text, $start, $length + 120)));

        return $slice;
    }
}
