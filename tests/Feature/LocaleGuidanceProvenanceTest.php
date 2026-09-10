<?php

namespace Tests\Feature;

use App\Models\Story;
use App\Support\LocaleGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Which locale guidance a story was generated against.
 *
 * ---------------------------------------------------------------------------
 * WHY THE COLUMN EXISTS
 * ---------------------------------------------------------------------------
 *
 * `LocaleGuard::guidanceFor()` reads config live, which answers "what do we
 * believe now". Nothing answered "what was THIS story written against", and the
 * guidance is a string in a config file rather than a value on a row — so an
 * edit to it left **no trace whatsoever**. Stories 21 and 23 were about to
 * become the "before" of the en-CN naming change with nothing in the database
 * marking them as such.
 *
 * Same shape as `sized_against_wpm`, and recorded in the same order for the
 * same reason: the column first, the backfill second, the guidance third.
 * Steps one and two are recoverable and step three is not.
 */
class LocaleGuidanceProvenanceTest extends TestCase
{
    use RefreshDatabase;

    private LocaleGuard $locale;

    protected function setUp(): void
    {
        parent::setUp();

        $this->locale = app(LocaleGuard::class);
    }

    /**
     * The digest follows the guidance, and an edit to one profile leaves the
     * other alone.
     */
    public function test_the_digest_follows_the_guidance_and_leaves_other_profiles_alone(): void
    {
        $before = $this->locale->fingerprintFor('en-CN');
        $unrelated = $this->locale->fingerprintFor('en-US');

        config(['locale.profiles.en-CN.guidance' => 'Something else entirely.']);

        $this->assertNotSame($before, $this->locale->fingerprintFor('en-CN'));
        $this->assertSame($unrelated, $this->locale->fingerprintFor('en-US'), 'Editing one profile moved another.');
    }

    /**
     * TWO PROFILES WITH IDENTICAL GUIDANCE STILL GET DIFFERENT DIGESTS.
     *
     * -------------------------------------------------------------------
     * THIS CASE EXISTS BECAUSE A DRILL PASSED
     * -------------------------------------------------------------------
     *
     * The case above was written as "and is scoped to one profile" and it did
     * not test that at all. Removing the profile key from the hash entirely —
     * the exact defect — left it GREEN, because en-US and en-CN carry different
     * guidance TEXT, so their digests differ whether or not the key is in
     * there. The assertion was true for a reason other than the one it named.
     *
     * The state that makes the key load-bearing is two profiles whose guidance
     * happens to read the same, which is a real possibility the moment a
     * profile is forked from another and edited later. A fixture that cannot
     * express the failing state makes the assertion vacuous however carefully
     * it is worded — so this one builds that state on purpose.
     *
     * Suspect the drill first, and then suspect the fixture.
     */
    public function test_two_profiles_with_the_same_guidance_do_not_share_a_digest(): void
    {
        $shared = 'Identical guidance in both profiles.';

        config([
            'locale.profiles.en-US.guidance' => $shared,
            'locale.profiles.en-CN.guidance' => $shared,
        ]);

        $this->assertSame(
            $this->locale->guidanceFor('en-US'),
            $this->locale->guidanceFor('en-CN'),
            'The fixture must actually give both profiles the same text.',
        );

        $this->assertNotSame(
            $this->locale->fingerprintFor('en-US'),
            $this->locale->fingerprintFor('en-CN'),
            'Two profiles collided on one digest, so the profile is not part of the provenance.',
        );
    }

    /**
     * A reflow is not a change.
     *
     * The guidance is prose and wraps differently every time it is touched. A
     * digest that moved on a re-wrap would report every story as written
     * against something else after any edit at all, which is the loud-alarm
     * failure this codebase names repeatedly — and it would do it in the one
     * category the column exists to make trustworthy.
     */
    public function test_rewrapping_the_guidance_does_not_move_the_digest(): void
    {
        $original = (string) config('locale.profiles.en-CN.guidance');
        $before = $this->locale->fingerprintFor('en-CN');

        config(['locale.profiles.en-CN.guidance' => str_replace(' ', "\n   ", $original)]);

        $this->assertSame($before, $this->locale->fingerprintFor('en-CN'));
    }

    /**
     * …but a real word change does move it. The pair to the case above: a
     * normalisation loose enough to ignore an edit would satisfy the reflow
     * case perfectly and make the column worthless.
     */
    public function test_a_word_change_does_move_the_digest(): void
    {
        $original = (string) config('locale.profiles.en-CN.guidance');
        $before = $this->locale->fingerprintFor('en-CN');

        config(['locale.profiles.en-CN.guidance' => $original.' One more sentence.']);

        $this->assertNotSame($before, $this->locale->fingerprintFor('en-CN'));
    }

    /**
     * Frozen on first use and never re-read.
     *
     * The case that earns it is a PARTIAL re-run: an act re-drafted after a
     * guidance edit must not silently reattribute the whole story to text that
     * only part of it saw. Same argument as `locale_profile` and
     * `sized_against_wpm`.
     */
    public function test_the_fingerprint_is_frozen_on_first_use(): void
    {
        $story = Story::factory()->create(['locale_profile' => 'en-CN']);

        $frozen = $this->locale->freezeFingerprintFor($story);

        $this->assertSame($frozen, $story->fresh()?->locale_guidance_fingerprint);

        config(['locale.profiles.en-CN.guidance' => 'Rewritten from scratch.']);

        $this->assertSame($frozen, $this->locale->freezeFingerprintFor($story->fresh()));
        $this->assertSame($frozen, $story->fresh()?->locale_guidance_fingerprint);
    }

    /**
     * Reading does not write.
     *
     * `fingerprintFor()` is what a page or a report asks; only the generator
     * calls the freezing one. A page that froze provenance as a side effect of
     * being looked at would date a story to whenever somebody opened it — which
     * is precisely the `updated_at`-as-publication-date defect, one column
     * along.
     */
    public function test_asking_for_the_digest_does_not_record_it(): void
    {
        $story = Story::factory()->create(['locale_profile' => 'en-CN']);

        $this->locale->fingerprintFor('en-CN');

        $this->assertNull($story->fresh()?->locale_guidance_fingerprint);
    }

    /**
     * NULL means unknown, and is a legitimate permanent value.
     *
     * Two real rows hold it: `sample-story`, whose acts came from a Phase 0
     * fixture and were written against no guidance at all, and story 21, whose
     * creation predates the only commit recording the en-CN text. Defaulting
     * the column would have made "nobody recorded this" and "written against
     * today's guidance" the same value.
     */
    public function test_a_story_that_has_not_been_generated_carries_no_digest(): void
    {
        $story = Story::factory()->create(['locale_profile' => 'en-CN']);

        $this->assertNull($story->locale_guidance_fingerprint);
    }

    // ---------------------------------------------------------------------
    // The naming convention itself
    // ---------------------------------------------------------------------

    /**
     * The names the guidance offers as examples must survive the guard it is
     * checked by.
     *
     * Four English given names — Tito, Lola, Ate and Po — are in the shared
     * operator-leak warn list, because each is also a Filipino honorific. That
     * list is shared by every profile on purpose: it is about where the
     * OPERATOR sits, not where the story is set, so moving a story to China
     * does not make "Lola" safe.
     *
     * A guidance block whose own examples tripped it would produce a per-scene
     * warning on every story that followed the instruction, which is the
     * "documented guard firing on its own fix" shape.
     */
    public function test_the_guidance_examples_do_not_trip_the_operator_leak_list(): void
    {
        $guidance = $this->locale->guidanceFor('en-CN');

        foreach (['Kevin Lin', 'Amy Sun', 'Grace Zhou', 'Leo Xu', 'Wang Suhua', 'Chen Wei'] as $name) {
            $this->assertStringContainsString($name, $guidance, $name.' is no longer an example.');

            $prose = sprintf('%s walked in. "I am here," %s said, and nobody answered.', $name, $name);

            $this->assertSame(
                [],
                $this->locale->warnings($prose, 'en-CN'),
                $name.' trips the locale warn list and must not be offered as an example.',
            );
        }
    }

    /**
     * And the four that must never be offered are named as forbidden.
     *
     * The paired half: asserting the examples are clean would pass on a
     * guidance block that simply said nothing about the collision, and the
     * model would then be free to pick "Lola" for a lead.
     */
    public function test_the_guidance_forbids_the_names_that_collide_with_the_warn_list(): void
    {
        $guidance = $this->locale->guidanceFor('en-CN');

        foreach (['Tito', 'Lola', 'Ate', 'Po'] as $name) {
            $this->assertStringContainsString($name, $guidance, $name.' is not named as forbidden.');
        }

        // Each of them really is on the shared list, so the instruction is
        // about a live collision rather than a remembered one.
        foreach (['Tito', 'Lola', 'Ate', 'Po'] as $name) {
            $this->assertNotSame(
                [],
                $this->locale->warnings($name.' opened the door.', 'en-CN'),
                $name.' is forbidden by the guidance but no longer collides with anything.',
            );
        }
    }

    /**
     * The convention is stated in both directions.
     *
     * A block naming only the young generation's form would leave the elders
     * to whatever the model reached for, which on a mixed cast is the whole
     * question. Both halves are asserted, and so is the one-form rule that
     * keeps a character from acquiring a second name an elder uses.
     */
    public function test_the_guidance_states_both_generations_and_the_one_form_rule(): void
    {
        $guidance = $this->locale->guidanceFor('en-CN');

        $this->assertStringContainsString('twenties', $guidance);
        $this->assertStringContainsString('English given name', $guidance);
        $this->assertStringContainsString('Parents, grandparents, in-laws', $guidance);
        $this->assertStringContainsString('full', $guidance);
        $this->assertStringContainsString('ONE form per character', $guidance);
    }
}
