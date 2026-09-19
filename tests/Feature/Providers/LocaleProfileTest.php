<?php

namespace Tests\Feature\Providers;

use App\Actions\CreateStory;
use App\Exceptions\LocaleViolationException;
use App\Models\Story;
use App\Support\LocaleGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Two settings, one narrator.
 *
 * `en-CN` sets the story in China and leaves the narration in American English
 * — the translated-Chinese-web-novel register. It is the first profile added
 * since the mechanism was built, so these tests are as much about what the
 * mechanism assumed as about the new list.
 *
 * The assumption it turned out to be carrying: that "wrong idiom" is one thing.
 * It is two, and only one of them moves with the setting. Filipino terms are a
 * leak because of where the OPERATOR sits; British spellings are a leak because
 * of who the NARRATOR is. Neither changes when the story moves to China, and
 * both had to survive into the new profile or the second setting would quietly
 * be guarded less well than the first.
 */
class LocaleProfileTest extends TestCase
{
    use RefreshDatabase;

    private LocaleGuard $guard;

    protected function setUp(): void
    {
        parent::setUp();

        $this->guard = app(LocaleGuard::class);
    }

    // -- The mechanism was already data ---------------------------------------

    public function test_both_profiles_exist_and_carry_all_three_pieces(): void
    {
        foreach (['en-US', 'en-CN'] as $profile) {
            $this->assertNotSame('', $this->guard->guidanceFor($profile), "{$profile} has no guidance.");
            $this->assertNotEmpty(config("locale.profiles.{$profile}.denylist"));
            $this->assertNotEmpty(config("locale.profiles.{$profile}.warnlist"));
        }
    }

    public function test_adding_a_profile_needed_no_code_in_the_guard(): void
    {
        // The guard reads every list by key off the story's profile, so the
        // only thing that had to exist for a second setting was config. This
        // pins that: a profile invented in a test, with no PHP written for it
        // anywhere, guards correctly.
        config()->set('locale.profiles.en-ZZ', [
            'label' => 'Nowhere',
            'guidance' => 'Write about nowhere.',
            'denylist' => ['flumdiddle'],
            'warnlist' => ['possibly'],
        ]);

        // The profile's own guidance, then the shared American-word line every
        // profile carries, because the list it names is shared by every profile.
        $this->assertSame(
            "Write about nowhere.\n\n".$this->guard->americanWordsLine(),
            $this->guard->guidanceFor('en-ZZ'),
        );
        $this->assertSame(['en-US', 'en-CN', 'en-ZZ'], array_keys($this->guard->profiles()));

        $this->expectException(LocaleViolationException::class);
        $this->guard->assert('A flumdiddle appeared.', 'en-ZZ', 'probe');
    }

    // -- What en-CN catches ---------------------------------------------------

    public function test_american_setting_leaks_fail_a_chinese_story(): void
    {
        // The inverse of the Filipino check, and the reason the profile exists.
        $this->expectException(LocaleViolationException::class);

        $this->guard->assert(
            'It was ninety degrees Fahrenheit and she called 911 when the sheriff arrived for Thanksgiving.',
            'en-CN',
            'act 3',
        );
    }

    public function test_every_american_leak_is_reported_not_just_the_first(): void
    {
        // One re-run should fix the prompt, not six.
        try {
            $this->guard->assert(
                'It was ninety degrees Fahrenheit and she called 911 when the sheriff arrived for Thanksgiving.',
                'en-CN',
                'act 3',
            );
            $this->fail('Expected the act to be refused.');
        } catch (LocaleViolationException $e) {
            $terms = array_column($e->hits, 'term');
        }

        foreach (['fahrenheit', 'called 911', 'sheriff', 'thanksgiving'] as $expected) {
            $this->assertContains($expected, $terms);
        }
    }

    public function test_a_term_with_a_chinese_reading_warns_rather_than_fails(): void
    {
        // The denylist's own rule: a term belongs there only if it has NO
        // plausible reading in a story set in China. Both sentences are the
        // real refused text — story 34 act 3 and story 29 act 3, each a billed
        // act thrown away — and both describe China correctly.
        $text = 'The lease ended on the twenty-fourth of July, and I did not renew it. '
            .'Chen Wei was buying for his son\'s school district, and paid in Hong Kong dollars.';

        $this->guard->assert($text, 'en-CN', 'act 3');

        $this->assertEqualsCanonicalizing(
            ['fourth of july', 'school district', 'dollars'],
            array_column($this->guard->warnings($text, 'en-CN'), 'term'),
        );
    }

    public function test_operator_idiom_whose_reading_depends_on_the_setting_splits_by_profile(): void
    {
        // Mexican adobo is a US reading and a Chinese three-wheeler is a
        // Chinese one, so neither can sit in a list shared by both settings.
        $this->guard->assert('She ordered chicken in adobo.', 'en-US', 'act 1');
        $this->guard->assert('The tricycle driver waited at the county bus station.', 'en-CN', 'act 1');

        foreach ([['She ordered chicken in adobo.', 'en-CN'], ['The tricycle driver honked.', 'en-US']] as [$text, $profile]) {
            try {
                $this->guard->assert($text, $profile, 'act 1');
                $this->fail("{$profile} passed: {$text}");
            } catch (LocaleViolationException $e) {
                $this->assertNotEmpty($e->hits);
            }
        }
    }

    public function test_the_shared_leaks_survive_into_the_new_profile(): void
    {
        // Moving the story to China does not move the operator out of Manila,
        // and does not make the narrator British. A second profile that dropped
        // either list would be guarded less well than the first while looking
        // identical from the outside.
        foreach ([
            'Filipino' => 'Ay naku, the barangay captain had already left for the palengke.',
            'British' => 'She realised the colour of the envelope was wrong.',
        ] as $kind => $text) {
            try {
                $this->guard->assert($text, 'en-CN', 'act 1');
                $this->fail("{$kind} idiom passed the en-CN guard.");
            } catch (LocaleViolationException $e) {
                $this->assertNotEmpty($e->hits);
            }
        }
    }

    // -- What en-CN must NOT catch -------------------------------------------

    public function test_correct_chinese_register_prose_passes_clean(): void
    {
        // Every one of 'county', 'middle school' and 'mayor' is correct English
        // for something that exists in China, and each was a candidate for the
        // deny list. A guard that failed an act for describing the setting
        // accurately is worse than no guard, because it teaches the operator to
        // distrust it.
        $text = 'Chen Wei set down the teacup. Second Uncle had already decided, and the family '
            .'register would show it by the end of the week. The bride price was ninety thousand '
            .'yuan, and Mother said refusing it would cost the family face in front of the whole '
            .'county. He had walked to the county town that morning, six kilometers in the cold, '
            .'past the middle school where he had taught for eleven years. His sister-in-law was '
            .'shameless, and he said so to her face, at the family banquet, in front of the mayor.';

        $this->guard->assert($text, 'en-CN', 'act 1');

        $this->assertSame([], $this->guard->warnings($text, 'en-CN'));
    }

    public function test_imperial_units_warn_rather_than_fail(): void
    {
        // 'feet' are body parts, 'pounds' and 'inches' are verbs, 'miles' is a
        // name. Every one of them is wrong for a metric setting and none of
        // them is safe to fail an act on.
        $text = 'He drove sixty miles and lost twenty pounds that winter.';

        $this->guard->assert($text, 'en-CN', 'act 2');

        $this->assertSame(['miles', 'pounds'], array_column($this->guard->warnings($text, 'en-CN'), 'term'));
    }

    // -- en-US is untouched ---------------------------------------------------

    public function test_the_us_profile_still_behaves_exactly_as_it_did(): void
    {
        $american = 'Erin drove forty miles to the county courthouse, paid two hundred dollars, '
            .'and was home before Thanksgiving dinner. The sheriff never called back.';

        // Every term that fails en-CN is required in en-US.
        $this->guard->assert($american, 'en-US', 'act 1');

        $this->expectException(LocaleViolationException::class);
        $this->guard->assert('Ay naku, she said, and walked to the sari-sari store.', 'en-US', 'act 1');
    }

    public function test_the_us_warn_list_still_carries_the_ambiguous_terms(): void
    {
        // Pinned because these moved into a shared array when the second
        // profile arrived, and a refactor that dropped one would look like
        // nothing at all.
        $terms = array_column($this->guard->warnings('Ate the biscuit, mum, on the pavement.', 'en-US'), 'term');

        foreach (['ate', 'biscuit', 'mum', 'pavement'] as $expected) {
            $this->assertContains($expected, $terms);
        }
    }

    // -- The profile has a producer -------------------------------------------

    public function test_a_story_can_be_created_in_either_setting(): void
    {
        // Without this the new profile is unreachable: `locale_profile` had no
        // input anywhere and CreateStory always wrote the configured default,
        // so a second profile would have been a column value no story could
        // ever hold.
        $cn = app(CreateStory::class)->handle(
            premise: 'A daughter-in-law is told the bride price will be returned to her husband.',
            localeProfile: 'en-CN',
        );

        $this->assertSame('en-CN', $cn->locale_profile);

        $us = app(CreateStory::class)->handle(
            premise: 'My younger brother moved into our late mother house in Ohio without asking.',
        );

        $this->assertSame('en-US', $us->locale_profile, 'Omitting it must still give the house setting.');
    }

    public function test_an_unknown_profile_is_refused_at_creation_not_at_generation(): void
    {
        // LocaleGuard also refuses one, but it refuses at the first generation
        // call — after the row exists and the outline has been queued and
        // billed. Refusing here costs nothing.
        $this->expectException(InvalidArgumentException::class);

        app(CreateStory::class)->handle(
            premise: 'A premise long enough to be accepted by the action under test.',
            localeProfile: 'en-XX',
        );
    }

    public function test_the_profile_column_holds_every_key_that_exists(): void
    {
        // varchar(16). A key longer than that would truncate on write and then
        // fail to resolve at generation time, which is a long way from the edit
        // that caused it.
        foreach (array_keys($this->guard->profiles()) as $key) {
            $this->assertLessThanOrEqual(16, strlen($key), "Profile key '{$key}' will not fit the column.");
        }
    }

    public function test_no_denied_term_in_a_profile_is_also_warned_in_it(): void
    {
        // A term on both lists fails the stage, so its warn entry can never be
        // reached. Harmless today and a silent contradiction the moment someone
        // reads the warn list and believes it.
        foreach (array_keys($this->guard->profiles()) as $profile) {
            $overlap = array_intersect(
                array_map('strtolower', (array) config("locale.profiles.{$profile}.denylist")),
                array_map('strtolower', (array) config("locale.profiles.{$profile}.warnlist")),
            );

            $this->assertSame([], array_values($overlap), "{$profile} lists these both ways.");
        }
    }

    public function test_a_story_carries_its_own_setting_into_generation(): void
    {
        // The guidance injected into every call comes off the story, so two
        // stories in flight at once must not read each other's setting.
        $cn = Story::factory()->create(['locale_profile' => 'en-CN']);
        $us = Story::factory()->create(['locale_profile' => 'en-US']);

        // Whitespace-normalised: the guidance is a wrapped heredoc, so any
        // phrase long enough to be distinctive is also long enough to have a
        // line break in it.
        $flatten = fn (string $p): string => (string) preg_replace('/\s+/', ' ', $this->guard->guidanceFor($p));

        $this->assertStringContainsString('a story set in China', $flatten($cn->locale_profile));
        $this->assertStringContainsString('bride price', $flatten($cn->locale_profile));
        $this->assertStringNotContainsString('China', $flatten($us->locale_profile));
    }
}
