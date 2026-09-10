<?php

namespace Tests\Unit;

use App\Support\ModelText;
use PHPUnit\Framework\TestCase;

/**
 * The boundary that undoes escapes the model doubled.
 *
 * Every offender here is real. The em dash is story 25's Amy Sun narration and
 * story 22's whole outline; the doubled quote is story 21's act summaries, seven
 * of them, which were not noticed until the repair swept for backslashes rather
 * than for the `\u` sequence alone.
 *
 * ---------------------------------------------------------------------------
 * WHY EVERY OFFENDING STRING IS BUILT FROM chr(92)
 * ---------------------------------------------------------------------------
 *
 * The first draft of this file wrote the inputs as literal text and four cases
 * failed with a count of zero: the em dashes had gone in as real em dashes
 * (`E2 80 94`), so there was nothing to undouble and the assertions were about
 * a defect the fixture could not contain. That is this project's oldest fixture
 * lesson — the detector was right and the input it was handed could not hold the
 * failure — arriving inside the test written for the escaping bug itself.
 *
 * So the backslash is constructed, never typed. CLAUDE.md already names this as
 * the remedy for the 0x08 family, and it applies with the arrow reversed here:
 * an escape that must SURVIVE into the fixture is as fragile as one that must
 * not.
 */
class ModelTextTest extends TestCase
{
    /** One backslash, built rather than typed. See the class docblock. */
    private const BS = "\x5c";

    /** The six characters a doubled `\uXXXX` actually decodes to. */
    private function esc(string $hex): string
    {
        return self::BS.'u'.$hex;
    }

    /**
     * IDENTITY. A response with nothing wrong with it comes back byte-identical.
     *
     * Deliberately first. This file's own rule for anything transformational is
     * that the case to write before any other is the one asserting clean input
     * survives untouched — a "repair" that rewrites correct text is worse than
     * the defect it fixes, and it is the failure a normaliser reaches for first.
     *
     * The curly quotes are story 25's `refusal` and are WANTED; straightening
     * them would be an editorial change wearing a transport repair's clothes.
     */
    public function test_clean_json_passes_through_byte_identical(): void
    {
        $clean = [
            'refusal' => 'First: “You don\'t need to explain yourself to me like a supplier, Amy.”',
            'script' => 'Not refused — stopped. The number rang and rang.',
            'summary' => 'She said "he\'s the driver, he can wait downstairs" in front of everyone.',
            'title' => 'Two Rooms, Four Nights',
            'nested' => ['beat' => 'He loses the account — and says nothing.', 'n' => 7],
            'accented' => 'a café on Zhongshan Road',
            'empty' => '',
        ];

        [$out, $count] = ModelText::undouble($clean);

        $this->assertSame($clean, $out, 'A clean response must not be rewritten.');
        $this->assertSame(0, $count);
    }

    /** The live instance: story 25's scene 180 narration, verbatim. */
    public function test_it_undoubles_the_escape_that_reached_paid_narration(): void
    {
        [$out, $count] = ModelText::undouble([
            'narration_text' => 'Not refused '.$this->esc('2014').' stopped. The number rang and rang.',
        ]);

        $this->assertSame('Not refused — stopped. The number rang and rang.', $out['narration_text']);
        $this->assertSame(1, $count);
    }

    /** Curly quotes and an accent: the other three sequences the data held. */
    public function test_it_undoubles_every_sequence_the_database_actually_held(): void
    {
        [$out, $count] = ModelText::undouble([
            'refusal' => 'First: '.$this->esc('201c').'You don\'t need to explain yourself'.$this->esc('201d').' and I meant it.',
            'setting' => 'a caf'.$this->esc('00e9').' on Zhongshan Road',
        ]);

        $this->assertSame('First: “You don\'t need to explain yourself” and I meant it.', $out['refusal']);
        $this->assertSame('a café on Zhongshan Road', $out['setting']);
        $this->assertSame(3, $count);
    }

    /** Story 21's act summaries, seven of them; the form missed on the first sweep. */
    public function test_it_undoubles_a_doubled_quote(): void
    {
        [$out, $count] = ModelText::undouble([
            'summary' => 'removed from the list entirely '.self::BS.'"guardian one Song Yiran'
                .self::BS.'"; he stood outside.',
        ]);

        $this->assertSame(
            'removed from the list entirely "guardian one Song Yiran"; he stood outside.',
            $out['summary']
        );
        $this->assertSame(2, $count);
    }

    /**
     * Surrogate pairs, and the reason they are handled before single escapes.
     *
     * None exist in the database. That is exactly why this has to be right
     * before the first one arrives: fed to the single rule each half is a lone
     * surrogate, and mb_chr() on one produces bytes no reader can use, from
     * input that was perfectly recoverable.
     */
    public function test_a_doubled_surrogate_pair_becomes_one_character(): void
    {
        [$out, $count] = ModelText::undouble([
            't' => 'she typed '.$this->esc('d83d').$this->esc('de00').' and hit send',
        ]);

        $this->assertSame('she typed 😀 and hit send', $out['t']);
        $this->assertSame(1, $count, 'A pair is one character, so it is one repair.');
    }

    /**
     * A LONE surrogate is left exactly as found, and so is `\u` with no hex.
     *
     * Neither is a character, so there is nothing to decode them to, and
     * inventing one would be a guess. Same decision that left story 22's
     * "for the audience only \u Ray Petrosky" alone in the stored-text repair:
     * an em dash reads naturally in that sentence, and "reads naturally" is not
     * a decode.
     */
    public function test_it_refuses_to_guess_at_what_it_cannot_decode(): void
    {
        $input = [
            'lone' => 'a lone '.$this->esc('d83d').' high surrogate',
            'story22' => 'laid out for the audience only '.self::BS.'u Ray Petrosky drew a deed',
        ];

        [$out, $count] = ModelText::undouble($input);

        $this->assertSame($input, $out);
        $this->assertSame(0, $count);
    }

    /** It reaches every depth, because a response is a tree and scenes are nested. */
    public function test_it_walks_nested_structures_and_leaves_keys_alone(): void
    {
        [$out, $count] = ModelText::undouble([
            'scenes' => [
                ['narration' => 'first '.$this->esc('2014').' second', 'frame' => 'a desk'],
                ['narration' => 'clean', 'frame' => 'a '.$this->esc('2014').' window'],
            ],
        ]);

        $this->assertSame('first — second', $out['scenes'][0]['narration']);
        $this->assertSame('a — window', $out['scenes'][1]['frame']);
        $this->assertSame('a desk', $out['scenes'][0]['frame']);
        $this->assertSame(['scenes'], array_keys($out));
        $this->assertSame(2, $count);
    }

    /**
     * The STRONG identity case: repair only what is broken, inside one string.
     *
     * The plain identity test above is weaker than it reads, and a drill is what
     * showed it. `clean()` returns early on any string with no backslash in it,
     * so every value in that fixture is protected by the cheap reject rather
     * than by the transform being careful — an over-eager rule added AFTER the
     * early return leaves it completely green.
     *
     * This value has a backslash, so it goes the whole way through, and it
     * carries legitimate curly quotes and a real em dash alongside the one thing
     * that is actually wrong. Exactly one repair may happen.
     */
    public function test_it_repairs_only_the_broken_part_of_a_string(): void
    {
        $subject = 'She said “no” '.$this->esc('2014').' twice — and meant it.';

        [$out, $count] = ModelText::undouble(['refusal' => $subject]);

        $this->assertSame('She said “no” — twice — and meant it.', $out['refusal']);
        $this->assertSame(1, $count);
        $this->assertStringContainsString('“no”', $out['refusal'], 'Curly quotes are wanted here.');
    }

    /** Non-strings are untouched: token counts and flags travel in the same tree. */
    public function test_it_leaves_non_strings_alone(): void
    {
        $input = ['n' => 42, 'ok' => true, 'nothing' => null, 'f' => 1.5];

        [$out, $count] = ModelText::undouble($input);

        $this->assertSame($input, $out);
        $this->assertSame(0, $count);
    }

    /**
     * The fixture can express the defect at all.
     *
     * Asserted rather than assumed, because the first draft of this file could
     * not: the inputs went in as real em dashes and four cases passed a count of
     * zero. A fixture that cannot hold the failure makes every assertion built
     * on it vacuous however carefully it is written.
     */
    public function test_the_fixture_actually_contains_a_doubled_escape(): void
    {
        $subject = 'Not refused '.$this->esc('2014').' stopped.';

        $this->assertStringContainsString(self::BS.'u2014', $subject);
        $this->assertStringNotContainsString('—', $subject, 'The input must not already be decoded.');
        $this->assertSame(1, substr_count($subject, self::BS));
    }
}
