<?php

namespace App\Support;

/**
 * Undo escapes the model doubled on the way out.
 *
 * ---------------------------------------------------------------------------
 * WHAT THIS IS FOR, AND WHAT IT IS NOT
 * ---------------------------------------------------------------------------
 *
 * On some calls the model emits a DOUBLED backslash inside its structured-output
 * JSON, so `json_decode` faithfully produces the six literal characters `—`
 * where an em dash was meant. The app decodes exactly once and correctly; this
 * is not a missing decode. The proof is that both forms coexist — story 25 held
 * 42 literal escapes against 1,512 real em dashes, and story 23 had zero literal
 * against 1,553 real. A skipped decode would have produced no real ones at all.
 *
 * It is per CALL rather than per field: no single stored value has ever held
 * both forms, and an act's `script` and `summary`, which come back from one
 * call, always agree with each other.
 *
 * **This is a TRANSPORT repair, not an editorial one.** It undoes damage done
 * between the model and the string, and it is deliberately narrow:
 *
 *   - `\uXXXX`  -> the character, surrogate pairs first.
 *   - `\"`      -> `"`.
 *
 * Nothing else. In particular it does NOT normalise Unicode (which changes
 * stored text invisibly and has no observed instance here), does NOT straighten
 * smart quotes (story 25's `refusal` legitimately contains curly quotes and
 * wants them), and does NOT trim whitespace (a script's leading newline and a
 * title's mean different things, and that belongs to whoever reads them).
 *
 * The line is: anything about the TRANSPORT belongs here; anything about a
 * DESTINATION FORMAT belongs to its reader. `GenerateAssSubtitles::assertPlain()`
 * is the other side of that line and stays exactly as it is — it is a statement
 * about what ASS can express, not a text-integrity check. After this it should
 * never fire on model text again, and if it ever does, that is a NEW defect and
 * that guard is the detector. It was the only thing that noticed this one.
 *
 * **It reports rather than silently repairing**, which is the whole point. This
 * defect ran for six days, through six stages and $16.64 of paid calls, without
 * anything saying a word. A boundary that quietly fixed it would mean nobody
 * ever learns the model is doing this, and the next variant would arrive with
 * the pipeline looking healthy.
 */
final class ModelText
{
    /** `\uXXXX` `\uXXXX` where the pair is a UTF-16 surrogate pair. */
    private const SURROGATE_PAIR = '/\\\\u([dD][89abAB][0-9a-fA-F]{2})\\\\u([dD][c-fC-F][0-9a-fA-F]{2})/';

    /** A single `\uXXXX`. */
    private const SINGLE = '/\\\\u([0-9a-fA-F]{4})/';

    /** A doubled quote escape: backslash, then a quote. */
    private const QUOTE = '/\\\\"/';

    /**
     * Normalise every string in a decoded response.
     *
     * @param  array<mixed>  $decoded
     * @return array{0: array<mixed>, 1: int} the tree, and how many escapes were undone
     */
    public static function undouble(array $decoded): array
    {
        $count = 0;

        return [self::walk($decoded, $count), $count];
    }

    /**
     * Keys are left alone: they are schema field names, fixed by us and ASCII
     * by construction, so touching them could only ever rename a field.
     */
    private static function walk(mixed $value, int &$count): mixed
    {
        if (is_array($value)) {
            $out = [];

            foreach ($value as $k => $v) {
                $out[$k] = self::walk($v, $count);
            }

            return $out;
        }

        return is_string($value) ? self::clean($value, $count) : $value;
    }

    private static function clean(string $text, int &$count): string
    {
        // Cheap reject. Every shape below needs a backslash, and the
        // overwhelming majority of values have none.
        if (! str_contains($text, '\\')) {
            return $text;
        }

        /*
         * Pairs BEFORE singles, and this ordering is the only part of the
         * function that can silently corrupt.
         *
         * A doubled emoji arrives as `😀`. Fed to the single rule
         * each half is a lone surrogate, and mb_chr() on a lone surrogate does
         * not round-trip to anything valid — it produces bytes no reader can
         * use, from input that was recoverable. None exist in the database
         * today, which is exactly why this has to be right before the first one
         * appears rather than after.
         */
        $text = preg_replace_callback(
            self::SURROGATE_PAIR,
            static function (array $m) use (&$count): string {
                $count++;

                return mb_chr(
                    0x10000 + ((hexdec($m[1]) - 0xD800) << 10) + (hexdec($m[2]) - 0xDC00),
                    'UTF-8'
                );
            },
            $text
        ) ?? $text;

        $text = preg_replace_callback(
            self::SINGLE,
            static function (array $m) use (&$count): string {
                $cp = hexdec($m[1]);

                /*
                 * A lone surrogate is left exactly as it was found.
                 *
                 * It is not a character, so there is nothing to decode it to,
                 * and inventing one would be a guess. The same reasoning left
                 * story 22's `\u ` — a backslash-u with no hex digits at all —
                 * untouched during the stored-text repair: an em dash reads
                 * naturally in that sentence, and "reads naturally" is not a
                 * decode.
                 */
                if ($cp >= 0xD800 && $cp <= 0xDFFF) {
                    return $m[0];
                }

                $count++;

                return mb_chr($cp, 'UTF-8');
            },
            $text
        ) ?? $text;

        $text = preg_replace(self::QUOTE, '"', $text, -1, $quotes) ?? $text;
        $count += $quotes;

        return $text;
    }
}
