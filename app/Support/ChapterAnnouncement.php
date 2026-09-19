<?php

namespace App\Support;

/**
 * The spoken chapter number: the sentence the writer is asked for, and the
 * only thing that knows what it looks like when it comes back.
 *
 * ---------------------------------------------------------------------------
 * WHY THIS IS A CLASS AND NOT TWO PRIVATE METHODS
 * ---------------------------------------------------------------------------
 *
 * The announcement is WRITTEN by `ClaudeScriptWriter::chapterInstruction()`
 * and READ by `ValidateOutlineSpine::checkChapterAnnouncements()`. Those are
 * a request and its invariant, which this project has already paid for
 * keeping in two places: the extraction retry prompt restated
 * `CharacterTextGuard`'s rules by hand, the copies disagreed on the word
 * `weathered`, and six billed calls were refused for a term the rejection
 * note never mentioned.
 *
 * So the number word, the sentence and the match are one file. A check that
 * looks for "Chapter five." while the prompt has started asking for
 * "Chapter 5." is a check that reports every chapter in the story.
 *
 * ---------------------------------------------------------------------------
 * THE MATCH IS DELIBERATELY LENIENT, AND THAT IS NOT LAZINESS
 * ---------------------------------------------------------------------------
 *
 * The prompt asks for the number as its own sentence and the writer produced
 * exactly that on eight chapters of twelve. The defect the check exists for
 * is TOTAL ABSENCE — acts 1 and 4 of story 30, the two acts with a special
 * opening instruction, announced nothing at all — so matching on the opening
 * of the sentence catches it while a "Chapter five, and this is where…" that
 * still says the number out loud is not reported.
 *
 * That direction is chosen on this file's own rule about over-reporting: a
 * false positive in a category the operator acts on retires the detector,
 * and a miss here costs one unannounced chapter rather than a page of
 * findings nobody can act on.
 */
final class ChapterAnnouncement
{
    /**
     * Whether chapters are announced in the narration at all.
     *
     * OFF, the prose carries no marker and the check must not look for one —
     * a guard that cannot be turned off with the thing it guards is a guard
     * that reports the whole story the day somebody flips the config.
     */
    public static function enabled(): bool
    {
        return (bool) config('chapters.announce', false);
    }

    /** A chapter number as the narrator says it. */
    public static function numberWord(int $n): string
    {
        $words = [
            1 => 'one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine', 'ten',
            'eleven', 'twelve', 'thirteen', 'fourteen', 'fifteen', 'sixteen', 'seventeen',
            'eighteen', 'nineteen', 'twenty',
        ];

        if (isset($words[$n])) {
            return $words[$n];
        }

        if ($n > 20 && $n < 30) {
            return 'twenty-'.$words[$n - 20];
        }

        return (string) $n;
    }

    /** The sentence the writer is asked to open the chapter with. */
    public static function sentenceFor(int $n): string
    {
        return 'Chapter '.self::numberWord($n).'.';
    }

    /**
     * The sentence that opens the antagonist's point-of-view chapter.
     *
     * Not numbered, the way the reference's is not: chapters 1-14 are spoken
     * as "chapter N" and the one after them as "Extra 1 — Sophia's POV". It is
     * also the ONLY marker that the "I" has changed hands — one narrator voice
     * reads both, on purpose — so it names her, and it is spoken rather than
     * left to the chapter list. "POV" is written out because a TTS voice reads
     * letters. A dash, not a full stop, after "Extra": the reference's own form,
     * and one sentence to SentenceSplitter, so Gate 1 finds the name beside it.
     */
    public static function pointOfViewSentence(string $name): string
    {
        return 'Extra — '.trim($name)."'s point of view.";
    }

    /**
     * Whether this sentence is the point-of-view announcement for `$name`,
     * spelled the way the prompt asks or the way a writer reaches for anyway.
     */
    public static function announcesPointOfView(string $sentence, string $name): bool
    {
        $text = mb_strtolower(trim($sentence));
        $name = mb_strtolower(trim($name));

        return $name !== ''
            && str_starts_with($text, 'extra')
            && str_contains($text, $name)
            && (str_contains($text, 'point of view') || str_contains($text, 'pov'));
    }

    /** Whether this sentence announces this particular chapter number. */
    public static function matches(string $sentence, int $n): bool
    {
        return self::numberIn($sentence) === $n;
    }

    /**
     * The chapter number this sentence announces, or null if it announces
     * none.
     *
     * Both spellings are accepted — the word, which is what the prompt asks
     * for, and the digit, which is what a writer reaches for anyway — because
     * the question this answers is "did the narrator say the number out
     * loud", and both do.
     *
     * Returning the number rather than a boolean is what lets the caller tell
     * "announced as chapter four when it is chapter five" from "announced
     * nothing". Those are different repairs: one is the numbering shifting
     * under a rewritten act, the other is the instruction being dropped.
     */
    public static function numberIn(string $sentence): ?int
    {
        $text = mb_strtolower(trim($sentence));

        if (! preg_match('/^chapter\s+([a-z0-9-]+)/u', $text, $found)) {
            return null;
        }

        $token = $found[1];

        if (ctype_digit($token)) {
            return (int) $token > 0 ? (int) $token : null;
        }

        for ($n = 1; $n <= 29; $n++) {
            if (self::numberWord($n) === $token) {
                return $n;
            }
        }

        return null;
    }
}
