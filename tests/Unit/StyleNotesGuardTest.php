<?php

namespace Tests\Unit;

use App\Support\Providers\CharacterProfile;
use App\Support\StyleNotesGuard;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The field is applied unconditionally, so anything conditional in it is a bug
 * by definition.
 *
 * These are not hypothetical strings. Both offenders below are what the
 * extractor actually wrote for the first real story, and the first one put a
 * handheld microphone into a parking-lot argument four acts before the speech
 * it belonged to — in all 36 of that character's prompts.
 */
class StyleNotesGuardTest extends TestCase
{
    private StyleNotesGuard $guard;

    protected function setUp(): void
    {
        parent::setUp();

        $this->guard = new StyleNotesGuard;
    }

    public function test_it_catches_the_microphone_that_started_this(): void
    {
        $notes = 'Untucked flannel shirts or polo shirts, work jeans, ball cap, brown boots; '
            .'often holding a phone or a handheld microphone';

        $violations = $this->guard->violations($notes);

        $this->assertNotEmpty($violations);
        $this->assertStringContainsString('often', implode(' ', $violations));
        $this->assertStringContainsString('microphone', implode(' ', $violations));
    }

    public function test_it_catches_the_lead_s_folder_too(): void
    {
        // The one nobody noticed, because a manila folder in a spreadsheet
        // story looks plausible in every frame it appears in — and she is in
        // 92 of them.
        $notes = 'Plain cardigans over simple blouses, dark jeans or work slacks, small stud '
            .'earrings; often carries a manila folder, printed spreadsheet pages, or a leather purse';

        $violations = $this->guard->violations($notes);

        $this->assertNotEmpty($violations);
        $this->assertStringContainsString('carries', implode(' ', $violations));
    }

    /**
     * @dataProvider conditionals
     */
    public function test_it_rejects_every_hedge(string $notes): void
    {
        $this->assertFalse($this->guard->isClean($notes));
    }

    public static function conditionals(): array
    {
        return [
            'often' => ['Grey work shirt, often rolled to the elbow'],
            'sometimes' => ['Denim jacket, sometimes a scarf'],
            'usually' => ['Usually a navy cardigan over a blouse'],
            'occasionally' => ['Chinos and boat shoes, occasionally a blazer'],
            'typically' => ['Typically a fleece vest over a polo'],
            'when' => ['A parka when the weather turns'],
        ];
    }

    public function test_clothing_alone_passes(): void
    {
        // What the field is for. Every one of these is on the person in every
        // frame, which is the only thing that survives being pasted 250 times.
        $clean = [
            'Plain cardigans over simple blouses, dark jeans or work slacks, small stud earrings',
            'Untucked flannel shirts, work jeans, ball cap, brown boots',
            'Navy scrubs and white sneakers',
            'A grey wool overcoat over a charcoal suit, wire-rimmed glasses',
        ];

        foreach ($clean as $notes) {
            $this->assertTrue(
                $this->guard->isClean($notes),
                "Rejected clothing-only notes: {$notes} — ".implode(', ', $this->guard->violations($notes))
            );
        }
    }

    public function test_worn_items_are_not_treated_as_props(): void
    {
        // Glasses, a watch and a ring are worn, not held. A guard that swept
        // them up would push real wardrobe out of the one field that carries it.
        $this->assertTrue($this->guard->isClean('Wire-rimmed glasses, a steel watch, a plain wedding band'));
    }

    public function test_empty_notes_are_fine(): void
    {
        // Not every character has habitual clothing worth fixing, and an empty
        // field is the correct way to say so.
        $this->assertTrue($this->guard->isClean(null));
        $this->assertTrue($this->guard->isClean(''));
        $this->assertTrue($this->guard->isClean('   '));
    }

    public function test_assert_names_the_character_and_refuses(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Kyle Ostergaard/');

        $this->guard->assert([
            new CharacterProfile('Erin Kessler', 'Forties, dark blonde.', 'Plain cardigans, dark jeans'),
            new CharacterProfile('Kyle Ostergaard', 'Late thirties, ruddy.', 'Flannel, often holding a microphone'),
        ], 'character extraction');
    }

    public function test_assert_passes_a_clean_cast(): void
    {
        $this->guard->assert([
            new CharacterProfile('Erin Kessler', 'Forties, dark blonde.', 'Plain cardigans, dark jeans'),
            new CharacterProfile('Kyle Ostergaard', 'Late thirties, ruddy.', 'Flannel shirts, work jeans, ball cap'),
        ], 'character extraction');

        $this->addToAssertionCount(1);
    }
}
