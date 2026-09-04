<?php

namespace Tests\Unit;

use App\Support\CharacterTextGuard;
use App\Support\Providers\CharacterProfile;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Both fields are applied unconditionally, so anything conditional in either is
 * a bug by definition.
 *
 * These are not hypothetical strings. Every offender below is what the extractor
 * actually wrote for a real story: the microphone put a handheld mic into a
 * parking-lot argument four acts before the speech it belonged to, in all 36 of
 * that character's prompts; the cane sat in `style_notes` past the first version
 * of this guard entirely; and the two hedges and the gait sat in `description`,
 * a field the guard did not look at for two phases.
 */
class CharacterTextGuardTest extends TestCase
{
    private CharacterTextGuard $guard;

    protected function setUp(): void
    {
        parent::setUp();

        $this->guard = new CharacterTextGuard;
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

    /**
     * The one that got through.
     *
     * "Plain short-sleeved button shirts, suspenders, and a wooden cane" is live
     * data from story 9. It named no carrying verb the guard knew and no listed
     * object, so it passed — and that man holds a cane in all thirty-odd of his
     * scenes, including the ones where he is sitting at a kitchen table.
     *
     * A guard that only tests the axis the component is already strong on will
     * always pass. This is the failure mode named, and fired against the real
     * instance of it.
     */
    public function test_it_catches_the_cane_that_named_no_verb(): void
    {
        $notes = 'Plain short-sleeved button shirts, suspenders, and a wooden cane.';

        $violations = $this->guard->violations($notes);

        $this->assertNotEmpty($violations, 'The cane passed again.');
        $this->assertStringContainsString('cane', implode(' ', $violations));
    }

    /**
     * Whole-word matching, which is what makes the expanded object list safe.
     *
     * The first version used str_contains and carried trailing-space hacks to
     * stop `mic` firing on "microphone". Those hacks failed at a line end. A
     * word boundary is what lets `stick` sit in the list next to "lipstick" and
     * `card` next to "cardigan" — both of which are real wardrobe.
     */
    public function test_the_object_list_does_not_fire_on_words_that_merely_contain_it(): void
    {
        foreach ([
            'Dark lipstick, a silk scarf, gold hoop earrings',
            'Soft cardigans in cream and dusty pink',
            'Thin-framed rectangular glasses and a plain wedding band',
            'A letterman jacket over a plain t-shirt',
        ] as $notes) {
            $this->assertTrue(
                $this->guard->isClean($notes),
                "False positive on: {$notes} — ".implode(', ', $this->guard->violations($notes))
            );
        }
    }

    // -- description: the field this guard could not see for two phases -------

    /**
     * Both hedges are live data, from two leads of story 9.
     *
     * "Hair usually pulled back" means hair pulled back in all 92 of her scenes,
     * which is fine — and it is fine by accident. The model wrote a hedge into a
     * field that cannot express one, and nothing checked, because the guard was
     * pointed at the field beside it.
     */
    public function test_it_catches_the_hedges_that_sat_in_description(): void
    {
        foreach ([
            'Woman in her mid-forties, shoulder-length straight light brown hair usually pulled back.',
            'Woman in her early thirties, long straight blonde hair usually worn down.',
        ] as $description) {
            $violations = $this->guard->descriptionViolations($description);

            $this->assertNotEmpty($violations);
            $this->assertStringContainsString('usually', implode(' ', $violations));
        }
    }

    /**
     * A gait asks for a man mid-stride in the frames where he is sitting down.
     */
    public function test_it_catches_the_gait_that_sat_in_description(): void
    {
        $description = 'Man in his late seventies, heavyset, balding with a fringe of white hair, '
            .'walks with a noticeable stiffness in one hip.';

        $violations = $this->guard->descriptionViolations($description);

        $this->assertNotEmpty($violations);
        $this->assertStringContainsString('walks', implode(' ', $violations));
    }

    /**
     * The distinction the first version of this list got wrong.
     *
     * "Stoops" is something a person does in one frame. "Stooped" is the shape
     * their shoulders hold in every frame — a permanent property of an
     * eighty-year-old, and one of the few age markers that survives a wide shot
     * in an anime style, which is precisely what the extraction prompt now asks
     * for. Banning both would forbid the most useful word available for the job.
     */
    public function test_a_permanent_stoop_is_build_and_a_stooping_motion_is_not(): void
    {
        $this->assertTrue($this->guard->isDescriptionClean(
            'Man in his late sixties, tall and stooped with narrow shoulders, swept-back white hair.'
        ));

        $this->assertFalse($this->guard->isDescriptionClean(
            'Man in his late sixties, stooping over a workbench, swept-back white hair.'
        ));
    }

    /**
     * A description in the register the retuned prompt asks for passes cleanly.
     *
     * Silhouette first, age structural, no hedge, no gait, no expression. If
     * this ever starts failing, the guard has grown past what the prompt can
     * satisfy — which is a guard nobody can obey rather than a bug caught.
     */
    public function test_the_register_the_prompt_asks_for_is_accepted(): void
    {
        foreach ([
            'Woman in her early forties, tall and square-shouldered, dark brown hair in a blunt '
                .'jaw-length bob with a hard side part, long oval face, heavy straight brows.',
            'Man in his late sixties, tall and stooped with narrow shoulders, deeply receding white '
                .'hairline above a long gaunt face, heavy grey eyebrows, square rimless glasses.',
            'Woman in her late seventies, short and stout, tightly permed silver-grey hair with '
                .'volume at the sides, round jowled face, bifocal glasses.',
        ] as $description) {
            $this->assertTrue(
                $this->guard->isDescriptionClean($description),
                "Rejected a compliant description: {$description} — "
                .implode(', ', $this->guard->descriptionViolations($description))
            );
        }
    }

    public function test_assert_reports_a_bad_description_and_names_the_field(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/description/');

        $this->guard->assert([
            new CharacterProfile('Dale Ostergaard', 'Late seventies, walks with a stiff hip.', 'Plain shirts'),
        ], 'character extraction');
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

    // -- Ageing texture -------------------------------------------------------

    /**
     * The rule that had to move upstream because a style line kept losing.
     *
     * `scenes.art_style` has said "never by wrinkles, creases, liver spots,
     * sagging or any other photoreal ageing texture" through two style
     * previews, and both times the same description beat it — the cast block is
     * assembled ahead of the style block and is scoped to one person, so a
     * per-character instruction outranks a house rule sitting behind it. This
     * is the live text, verbatim.
     */
    public function test_it_refuses_the_ageing_texture_that_beat_the_style_constant(): void
    {
        $violations = $this->guard->descriptionViolations(
            'Late sixties to early seventies, small and frail with rounded stooped shoulders, '
            .'thinning white hair pulled into a soft low bun, deeply lined round face, pale watery '
            .'blue eyes, soft sagging jawline.'
        );

        $this->assertNotEmpty($violations);
        $this->assertStringContainsString('deeply lined', implode(' ', $violations));
        $this->assertStringContainsString('sagging', implode(' ', $violations));
    }

    /**
     * The distinction the whole list turns on, and the same one `stooped` sits
     * on: face SHAPE survives a wide shot and is what the prompt asks for, skin
     * does not and is what an anime generator draws literally.
     */
    public function test_face_shape_is_not_ageing_texture(): void
    {
        foreach ([
            'Man in his late sixties, tall and stooped, long gaunt face, heavy grey eyebrows.',
            'Woman in her seventies, broad jowled face, small sharp eyes, steel-grey hair.',
            'Man in his sixties, angular face with sunken cheeks and a high hollow temple.',
            'Woman in her sixties, softly rounded face, white hair in a low knot.',
        ] as $description) {
            $this->assertSame(
                [],
                $this->guard->descriptionViolations($description),
                "Refused a face SHAPE, which is what the extraction prompt asks for: {$description}"
            );
        }
    }

    public function test_the_model_cannot_smuggle_a_banned_term_by_negating_it(): void
    {
        // Live from the first real extraction after the rule was added: asked
        // not to write ageing texture, the model wrote "faint smile lines
        // absent". The phrase is still in the description, still reaches the
        // generator, and a negation is not something an image model honours.
        $this->assertNotEmpty($this->guard->descriptionViolations(
            'Woman in her seventies, round face, pale blue eyes, faint smile lines absent, soft jaw.'
        ));
    }

    public function test_ageing_texture_is_a_description_rule_only(): void
    {
        // style_notes is clothing. "A creased linen jacket" is wardrobe, and
        // failing an extraction over it would be the guard reaching into a
        // field the rule was never about.
        $this->assertSame([], $this->guard->violations('A creased linen jacket over a plain shirt.'));
    }

    // -- Headwear: advisory, never a refusal ----------------------------------

    /**
     * The third kind of finding, and the reason it could not be a row in the
     * lists above.
     *
     * A hat is genuinely clothing and a script can legitimately require one, so
     * refusing it would make "keep it if the story needs it" impossible — the
     * extraction would retry until the model dropped a hat the plot depends on.
     * But it also replaces the hair silhouette the entire cast-distinctness
     * mechanism is built on, so it cannot be silent either.
     */
    public function test_headwear_is_reported_but_never_refused(): void
    {
        // Live from story 9 and story 12, where four characters were issued a
        // cap between them.
        $notes = 'Wears fitted polo shirts and jeans with a ball cap pushed back.';

        $this->assertNotEmpty($this->guard->advisories($notes));
        $this->assertSame([], $this->guard->violations($notes), 'Headwear must not be a violation.');

        // And assert(), which is what actually stops an extraction, lets it by.
        $this->guard->assert(
            [new CharacterProfile('Kyle Ostergaard', 'Late thirties, ruddy, square jaw.', $notes)],
            'character extraction',
        );

        $this->addToAssertionCount(1);
    }

    public function test_one_hat_is_reported_once(): void
    {
        // 'cap' matches inside "ball cap" on a word boundary, so listing the
        // compounds as well reported the same hat three times on the Gate 2
        // panel.
        $this->assertCount(1, $this->guard->advisories('Jeans and a baseball cap worn backwards.'));
    }

    public function test_the_advisory_does_not_fire_on_words_that_merely_contain_it(): void
    {
        foreach ([
            'Capri trousers and a linen blouse.',
            'A chatty print scarf knotted at the neck.',
            'Wears that same grey coat and low heels.',
        ] as $notes) {
            $this->assertSame([], $this->guard->advisories($notes), "False positive on: {$notes}");
        }
    }

    public function test_clean_clothing_raises_no_advisory(): void
    {
        $this->assertSame([], $this->guard->advisories('Plain button-front blouses and dark cardigans.'));
    }
}
