<?php

namespace App\Support;

/**
 * How a line of speech reached the page: quoted, unquoted, or reported.
 *
 * ---------------------------------------------------------------------------
 * WHY THIS EXISTS
 * ---------------------------------------------------------------------------
 *
 * Story 37 is published and twelve minutes of its dialogue is punctuated as
 * narration. Its act 3 carries ZERO quoted lines and twelve constructions of
 * this shape:
 *
 *     He said, take these back, we are not people who keep things.
 *     He said, Uncle, don't trouble Brother Owen with the running around.
 *
 * Three people are arguing and none of it is in quotation marks. Acts 1, 2
 * and 5 of the same story carry 18, 9 and 27 quoted lines, so the writer did
 * not lack the convention — it dropped it for one act, and nothing in this
 * application had an opinion about quotation marks. The act system prompt
 * says "Dialogue is quoted plainly"; that was the request with no invariant
 * behind it, which this file's own rule says reads as a guard while doing
 * nothing.
 *
 * It matters because ONE VOICE READS THE WHOLE SCRIPT. An unquoted line is
 * not a typographic preference — the narrator reads it in the narrator's
 * voice, as narration, and the viewer never learns somebody else was
 * speaking. It is the same failure as an untagged thought, which
 * `genreGuidance()` already refuses for the same reason.
 *
 * ---------------------------------------------------------------------------
 * THE DISCRIMINATOR WAS DESIGNED AGAINST THE DATA, NOT GUESSED
 * ---------------------------------------------------------------------------
 *
 * All 230 `TAG,` constructions in the five rendered stories were printed with
 * what follows them before this was written. Reported speech carries `that`
 * or `whether`, usually after an adverbial — "said, quietly, that my parents
 * had given it freely", "said, loud enough to carry, that the girl had a
 * lucky face". Direct speech does not. So the rule is:
 *
 *   a quotation mark in the window        -> QUOTED     (what we want)
 *   `that` / `whether` early in it        -> REPORTED   (legal, summary)
 *   neither                               -> UNQUOTED   (the defect)
 *
 * IT UNDER-REPORTS ON PURPOSE. "She said, if anybody asks, you don't know
 * where I went" is direct speech opening on `if`, and it is not counted.
 * A check that fires on good output is the one that retires a detector, and
 * this one reports an ABSENCE of quotation marks on an operator's page, so a
 * miss costs one unflagged line and a false fire costs the whole check.
 */
final class SpokenLines
{
    /**
     * Speech verbs that can take a comma and then the words themselves.
     *
     * `say`/`says` are deliberately absent: "I did not say a word of it out
     * loud" is a sentence with nobody speaking in it, and the first version
     * of this list scored it as dialogue.
     */
    private const TAGS = [
        'said', 'asked', 'told', 'replied', 'answered', 'added', 'shouted',
        'whispered', 'repeated', 'announced', 'explained', 'called',
    ];

    /** How far past the tag to look for the words themselves. */
    private const WINDOW = 110;

    /** Within this much of the tag, `that` means the speech is being summarised. */
    private const REPORTING_WINDOW = 60;

    /** Complete quoted utterances in the script. */
    public static function quoted(string $script): int
    {
        return preg_match_all('/"[^"]{4,}"/u', $script);
    }

    /**
     * Direct speech written without quotation marks.
     *
     * @return array<int, string> the offending fragments, for the operator
     */
    public static function unquoted(string $script): array
    {
        // An optional short object between the verb and the comma: "I told
        // HER, go home" is the same construction as "she said, go home", and
        // the first version matched only the second. Anything longer than one
        // object is left alone — a long adverbial before the comma is where
        // reported speech lives, and that is handled below.
        $object = '(?:\s+(?:him|her|them|me|us|everyone|anybody|nobody|the\s+\w+|my\s+\w+|his\s+\w+))?';

        // THE TAG IS MATCHED WITHOUT ITS WINDOW, and the window is taken by
        // offset afterwards. Writing this as `tag,(.{0,110})` makes the window
        // part of the match, so the next tag is consumed inside it and
        // overlapping speech is never seen: on story 37's act 3 that was 7
        // findings where there are 10, and on the test fixture it was 2 where
        // there are 3 — a check under-reporting the exact defect it exists
        // for. Found by the fixture assertion, not by reading it.
        $pattern = '/\b(?:'.implode('|', self::TAGS).')'.$object.'\s*,\s*/u';

        if (! preg_match_all($pattern, $script, $matches, PREG_OFFSET_CAPTURE)) {
            return [];
        }

        $found = [];

        foreach ($matches[0] as [$tag, $offset]) {
            $window = mb_strcut($script, $offset + strlen($tag), self::WINDOW);

            // The words are right there, marked. Nothing to report.
            if (str_contains($window, '"') || preg_match('/[\x{201C}\x{201D}]/u', $window)) {
                continue;
            }

            // "said, quietly, that ..." — summary, which is legal prose and
            // is the subject of a different rule (the biggest beat is staged).
            if (preg_match('/^.{0,'.self::REPORTING_WINDOW.'}?\b(?:that|whether)\b/u', $window)) {
                continue;
            }

            $found[] = trim(preg_replace('/\s+/u', ' ', $window));
        }

        return $found;
    }

    /**
     * Whether this act's dialogue is punctuated as narration.
     *
     * TWO conditions, and the second is what keeps it quiet on good acts. An
     * act with three unquoted lines and twenty-five quoted ones is an act
     * with three slips in it, not an act written in the wrong register; the
     * defect this exists for is a whole act that stopped using the marks.
     * Measured over the 25 acts of the five rendered stories, exactly one
     * fires — story 37's act 3 — and it is the true positive.
     */
    public static function readsAsNarration(string $script): bool
    {
        $unquoted = count(self::unquoted($script));

        return $unquoted >= 3 && $unquoted > self::quoted($script);
    }
}
