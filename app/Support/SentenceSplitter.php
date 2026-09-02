<?php

namespace App\Support;

/**
 * Splits an act script into sentences, losslessly.
 *
 * This is the unit scenes are addressed in, which makes "losslessly" a hard
 * requirement rather than a nicety: the script is what an operator approved at
 * Gate 1 and what a paid TTS call will read aloud, so a splitter that drops a
 * clause silently shortens the video and nobody finds out until the mux.
 *
 * `join(split($text)) === normalise($text)` is asserted by test in both
 * directions, and it is why the splitter keeps punctuation and trims only
 * whitespace.
 *
 * The abbreviation list is not decoration. This genre is full of the exact
 * constructions that break a naive `(?<=[.!?])\s+` split — "Mr. Ostrander",
 * "$71,000.", "St. Luke's", "Jan. 4th", "U.S." — and every false split becomes
 * a scene boundary in the middle of a sentence, which is a still that changes
 * halfway through a clause.
 */
class SentenceSplitter
{
    /**
     * Tokens that end in a period without ending a sentence.
     *
     * Titles, months, states and the common US-address abbreviations, because
     * the stories are American by design and these turn up constantly.
     */
    private const ABBREVIATIONS = [
        'mr', 'mrs', 'ms', 'dr', 'prof', 'rev', 'fr', 'sr', 'jr', 'st', 'mt',
        'gen', 'gov', 'sen', 'rep', 'capt', 'lt', 'sgt', 'det', 'atty', 'hon',
        'jan', 'feb', 'mar', 'apr', 'jun', 'jul', 'aug', 'sept', 'sep', 'oct',
        'nov', 'dec',
        'ave', 'blvd', 'rd', 'ln', 'ct', 'dr', 'apt', 'ste', 'no',
        'inc', 'llc', 'ltd', 'co', 'corp', 'dept', 'est', 'approx',
        'vs', 'etc', 'eg', 'ie', 'al', 'ca', 'cf',
        'am', 'pm', 'us', 'usa', 'ok',
    ];

    /**
     * @return array<int, string> 1-indexed by position in the returned array
     *                            plus one; callers number them for the model.
     */
    public function split(string $text): array
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        if ($text === '') {
            return [];
        }

        $sentences = [];
        $current = '';
        $length = mb_strlen($text);

        for ($i = 0; $i < $length; $i++) {
            $char = mb_substr($text, $i, 1);
            $current .= $char;

            if (! in_array($char, ['.', '!', '?'], true)) {
                continue;
            }

            // Consume a run of terminators and any closing quote or bracket, so
            // `she said "no!"` and `(really?)` end with their punctuation
            // attached rather than starting the next sentence with it.
            while ($i + 1 < $length && in_array(mb_substr($text, $i + 1, 1), ['.', '!', '?', '"', "'", ')', ']', '\u{201D}', '\u{2019}'], true)) {
                $i++;
                $current .= mb_substr($text, $i, 1);
            }

            if ($i + 1 >= $length) {
                break;
            }

            // A sentence only ends if a space follows. "3.5" and "example.com"
            // are one token.
            if (mb_substr($text, $i + 1, 1) !== ' ') {
                continue;
            }

            if ($this->isAbbreviation($current) || $this->isInitial($current)) {
                continue;
            }

            // A lowercase word after the space means the period was not a
            // terminator — "the U.S. attorney", "$4,000. and then".
            $next = mb_substr($text, $i + 2, 1);

            if ($next !== '' && $next === mb_strtolower($next) && preg_match('/\p{L}/u', $next) === 1) {
                continue;
            }

            $sentences[] = trim($current);
            $current = '';
            $i++; // skip the space
        }

        if (trim($current) !== '') {
            $sentences[] = trim($current);
        }

        return $sentences;
    }

    /**
     * Rejoin sentences into the text they came from.
     *
     * The inverse of split(), and the reason narration can be sliced by index
     * without ever being retyped by a model.
     *
     * @param  array<int, string>  $sentences
     */
    public function join(array $sentences): string
    {
        return trim(implode(' ', array_map('trim', $sentences)));
    }

    /** The normalised form split() and join() round-trip against. */
    public function normalise(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    private function isAbbreviation(string $sentenceSoFar): bool
    {
        if (! str_ends_with($sentenceSoFar, '.')) {
            return false;
        }

        $words = preg_split('/\s+/u', trim($sentenceSoFar)) ?: [];
        $last = mb_strtolower(rtrim((string) end($words), '.'));

        return in_array($last, self::ABBREVIATIONS, true);
    }

    /**
     * A single initial: "J. Ostrander", "Harry S. Truman".
     *
     * One letter followed by a period is never the end of a sentence in prose
     * of this kind.
     */
    private function isInitial(string $sentenceSoFar): bool
    {
        return preg_match('/(?:^|\s)\p{L}\.$/u', $sentenceSoFar) === 1;
    }
}
