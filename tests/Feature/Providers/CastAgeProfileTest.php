<?php

namespace Tests\Feature\Providers;

use Anthropic\Client;
use App\Models\Story;
use App\Services\Claude\ClaudeScriptWriter;
use App\Support\CharacterTextGuard;
use App\Support\LocaleGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

/**
 * The story's stated cast age range, and where it is allowed to reach.
 *
 * This column exists because a rendering rule was being asked to carry a
 * casting decision. `scenes.art_style` is one string shared by every story, so
 * the only thing it can say about age is how age is DRAWN — and when it was
 * made to say "adults are drawn at their true age", an anime generator did the
 * only thing it can do with that instruction and drew photoreal wrinkles.
 *
 * So the range is stated per story, upstream, at the one point a character's
 * age is decided and frozen: extraction. Every still that character appears in
 * is built from the description written there.
 *
 * Two properties are load-bearing and both are asserted here:
 *
 *  1. Absent means ABSENT. A story that states no range must send no age
 *     instruction at all — not a default one. A default would be the app
 *     casting the video.
 *  2. The script outranks the profile. A profile that could overrule a stated
 *     age would put the picture in contradiction with the narration, which is
 *     the same failure the style line was rewritten to avoid.
 *
 * It has also become the one place the assembled extraction prompt is read
 * back, so the other instructions that prompt must carry are pinned here too.
 * They are worth pinning precisely because they are prose: nothing else fails
 * when a rule is edited out of a heredoc, and the cost of finding out is a
 * cast.
 */
class CastAgeProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_stated_range_reaches_the_extraction_prompt(): void
    {
        $story = Story::factory()->create([
            'cast_age_profile' => 'Spouses in their late twenties and thirties. Nobody over forty.',
        ]);

        $prompt = $this->systemPrompt($story);

        $this->assertStringContainsString(
            'Spouses in their late twenties and thirties. Nobody over forty.',
            $prompt,
            'The operator stated an age range and the extraction prompt did not carry it, which '
                .'leaves the art style constant compensating for a casting decision again.'
        );
    }

    public function test_a_story_without_one_sends_no_age_instruction(): void
    {
        $story = Story::factory()->create(['cast_age_profile' => null]);

        $prompt = $this->systemPrompt($story);

        $this->assertStringNotContainsString(
            'THE INTENDED AGE RANGE OF THIS CAST',
            $prompt,
            'A story that stated no age range was given one anyway. Null means no intention was '
                .'expressed, not that a young cast was intended.'
        );
    }

    public function test_an_empty_string_is_treated_as_absent(): void
    {
        // A blank textarea posts '' rather than null, and '' and null must not
        // be two different kinds of nothing.
        $story = Story::factory()->create(['cast_age_profile' => '   ']);

        $this->assertStringNotContainsString(
            'THE INTENDED AGE RANGE OF THIS CAST',
            $this->systemPrompt($story)
        );
    }

    public function test_the_profile_never_outranks_an_age_the_script_states(): void
    {
        $story = Story::factory()->create([
            'cast_age_profile' => 'Everyone is in their twenties.',
        ]);

        $prompt = $this->systemPrompt($story);

        // The instruction the column is only safe with. Without it, a profile
        // saying "everyone is in their twenties" against a script about a
        // grandmother produces a picture arguing with the narrator, and the
        // viewer hears both.
        $this->assertStringContainsString('the script wins', $prompt);
    }

    public function test_the_prompt_forbids_ageing_texture_outright(): void
    {
        // The rule used to say only where to PUT age, and the live cast came
        // back with "deeply lined round face" and "soft sagging jawline" while
        // passing it. A rule that says where the right answer goes does not
        // forbid the wrong one.
        $prompt = $this->systemPrompt(Story::factory()->create());

        $this->assertStringContainsString('NEVER WRITE AGEING TEXTURE', $prompt);

        // Bare words, not phrases. The ban read "weathered skin" and the
        // model wrote "weathered square jaw" — near enough to read itself as
        // compliant, and it cost three dispatches and six billed calls. The
        // prompt now bans the word and says so explicitly.
        foreach (['deeply lined', 'sagging', 'weathered', 'creased', 'furrowed'] as $term) {
            $this->assertStringContainsString($term, $prompt, "The ban no longer names: {$term}");
        }

        $this->assertStringContainsString('the ban is on the WORD', $prompt);
    }

    public function test_the_prompt_reaches_for_longer_styled_hair_by_default(): void
    {
        // The output skewed short and practical; this genre's look is longer
        // hair with visible styling. The vocabulary is in the prompt rather
        // than the style constant because hair is per-character.
        $prompt = $this->systemPrompt(Story::factory()->create());

        $this->assertStringContainsString('LONGER, STYLED hair', $prompt);

        foreach (['high ponytail', 'braid', 'waist-length', 'half-up'] as $look) {
            $this->assertStringContainsString($look, $prompt, "The prompt no longer offers: {$look}");
        }
    }

    public function test_the_cast_check_compares_shape_not_just_length(): void
    {
        // Longer hair makes distinctness HARDER — three women with long hair
        // converge fast — so the group check cannot be satisfied by giving them
        // different lengths.
        $prompt = $this->systemPrompt(Story::factory()->create());

        foreach (['where the mass sits', 'up or down', 'the parting', 'volume and texture'] as $axis) {
            $this->assertStringContainsString($axis, $prompt, "The cast check dropped an axis: {$axis}");
        }

        $this->assertStringContainsString('may both have long hair only if the', $prompt);
    }

    public function test_the_prompt_discourages_hats(): void
    {
        // Nothing else in the rules reaches a hat: it is real clothing, so the
        // style_notes rules let it through. It is discouraged rather than
        // banned because a script can require one — CharacterTextGuard reports
        // it as an advisory and never refuses it.
        $prompt = $this->systemPrompt(Story::factory()->create());

        $this->assertStringContainsString('NO HATS unless the script actually requires one', $prompt);
        $this->assertStringContainsString('covers the hair', $prompt);
    }

    public function test_the_prompt_no_longer_spends_words_on_build(): void
    {
        // Measured, not preferred. A character written "broad and thick through
        // the chest" rendered lean and one written "small and frail with
        // rounded stooped shoulders" rendered upright — under the current
        // style, and again under an explicit style clause saying stated build
        // is preserved exactly. The clause changed nothing, so build is no
        // longer an identity axis or an age axis, and asking for it would be
        // spending description words the renderer discards.
        $prompt = $this->systemPrompt(Story::factory()->create());

        $this->assertStringContainsString('DO NOT DESCRIBE BUILD, HEIGHT OR FRAME', $prompt);

        // The three places it used to be asked for.
        $this->assertStringNotContainsString('body shape (thickened through the middle', $prompt);
        $this->assertStringNotContainsString('THEN the fixed features: build, height', $prompt);
        $this->assertStringNotContainsString('no two characters should share a', $prompt);

        // And age now names exactly the three things that do render.
        $this->assertStringContainsString('AGE MUST READ IN THE HEAD', $prompt);
    }

    /**
     * The prompt as the provider would build it.
     *
     * Reached by reflection because the assembly is private and has no other
     * seam: the call goes out through the Anthropic SDK's streaming client,
     * not through Laravel's HTTP client, so there is nothing to fake between
     * here and the wire. The alternative is asserting nothing about the one
     * thing this column does.
     */
    private function systemPrompt(Story $story): string
    {
        $writer = new ClaudeScriptWriter(
            // Never called: only the private prompt builder is invoked.
            client: app(Client::class),
            locale: app(LocaleGuard::class),
            text: app(CharacterTextGuard::class),
        );

        $method = new ReflectionMethod($writer, 'characterSystemPrompt');
        $method->setAccessible(true);

        return (string) $method->invoke($writer, $story);
    }
}
