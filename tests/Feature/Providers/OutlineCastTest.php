<?php

namespace Tests\Feature\Providers;

use Anthropic\Client;
use App\Actions\ExtractCharacters;
use App\Actions\GenerateOutline;
use App\Actions\ValidateOutlineSpine;
use App\Contracts\ScriptWriter;
use App\Enums\CastRole;
use App\Enums\FailureKind;
use App\Enums\RenderStage;
use App\Enums\StoryFormat;
use App\Enums\StoryStatus;
use App\Jobs\WriteStoryJob;
use App\Livewire\Gates\OutlineGate;
use App\Models\Act;
use App\Models\Character;
use App\Models\RenderJob;
use App\Models\Story;
use App\Services\Claude\ClaudeScriptWriter;
use App\Services\Fake\FakeScriptWriter;
use App\Support\CharacterTextGuard;
use App\Support\LocaleGuard;
use App\Support\OutlineCast;
use App\Support\Providers\ActOutline;
use App\Support\Providers\CastMember;
use App\Support\Providers\ScriptWriterException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;

/**
 * THE CAST IS DECIDED AT THE OUTLINE — and every consumer of it.
 *
 * Measured on seven stories before this existed: 76% of the characters were
 * named in the spine, the act writer added more, the extractor invented three
 * from role labels, and casts ran 8 to 13. `cast_age_profile` reached only the
 * extractor. See CLAUDE.md 3f and App\Support\OutlineCast.
 *
 * The consumer question, asked in the change: the outline writes it (schema,
 * prompt, refusal), Gate 1 reviews and edits it before any act is bought, the
 * act writer is handed it, the extractor describes it and nothing else, a fork
 * copies it. Each has an arrival assertion below, and each refusal a green
 * case beside its red one.
 */
class OutlineCastTest extends TestCase
{
    use RefreshDatabase;

    private FakeScriptWriter $writer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->writer = app(ScriptWriter::class);
    }

    // -- Produced, refused, stored -------------------------------------------

    /**
     * RED/GREEN: the future partner is not given a gender by the guidance.
     *
     * The role read "the woman the narrator ends up with", written for a man
     * narrating. Story 33 is a woman narrating a partner betrayal, and the
     * outline writer was being told her future partner is a woman. The premise
     * says who it is. The red half is the shipped sentence, held to the same
     * detector as the live one so the detector is shown able to fire.
     */
    public function test_the_future_partner_guidance_does_not_assume_a_gender(): void
    {
        $gendered = static fn (string $text): bool => (bool) preg_match('/\b(woman|women|man|men|she|her|hers|he|him|his)\b/i', $text);

        $this->assertTrue($gendered('the woman the narrator ends up with, if the premise names one. At most one. Her relationship says who she arrives through'));
        $this->assertFalse($gendered(CastRole::FuturePartner->guidance()));
        $this->assertFalse($gendered(CastRole::FuturePartner->label()));
    }

    public function test_the_outline_schema_asks_for_the_cast_before_the_spine(): void
    {
        $schema = $this->invoke($this->claude(), 'outlineSchema');
        $keys = array_keys($schema['properties']);

        $this->assertContains('cast', $schema['required']);
        // Property order is generation order: a cast decided after the spine
        // is harvested from a spine that already named everyone.
        $this->assertLessThan(array_search('hook', $keys, true), array_search('cast', $keys, true));
    }

    /**
     * THE NARRATOR IS A REQUIRED PROPERTY, AND ON A SINGLE NARRATIVE NOT A ROLE.
     *
     * Story 37's outline returned eight cast rows and no narrator and was
     * refused after a billed call. Required and ahead of the cast, it cannot
     * be left off; out of the cast's role enum, there cannot be two. An
     * anthology keeps `narrator` in the enum, because each act's story has its
     * own first person and the property carries act 1's.
     */
    public function test_the_narrator_is_a_required_property_ahead_of_the_cast_and_not_a_cast_role(): void
    {
        $single = $this->invoke($this->claude(), 'outlineSchema', StoryFormat::Single);
        $keys = array_keys($single['properties']);

        $this->assertContains('narrator', $single['required']);
        $this->assertSame(['name', 'relationship'], $single['properties']['narrator']['required']);
        $this->assertLessThan(array_search('cast', $keys, true), array_search('narrator', $keys, true));

        $enum = $single['properties']['cast']['items']['properties']['role']['enum'];
        $this->assertNotContains('narrator', $enum);
        $this->assertSame(array_column(OutlineCast::castArrayRoles(StoryFormat::Single), 'value'), $enum);

        $anthology = $this->invoke($this->claude(), 'outlineSchema', StoryFormat::Anthology);
        $this->assertContains('narrator', $anthology['required']);
        $this->assertContains('narrator', $anthology['properties']['cast']['items']['properties']['role']['enum']);
    }

    /**
     * RED/GREEN at the real writer's decode: the narrator property becomes the
     * FIRST cast row, so everything that reads `outline_cast` sees what it saw
     * before. The red half is story 37's shape — a cast array and no narrator
     * property — which decodes to no narrator row and is refused by the
     * structural check that stays behind the schema.
     */
    public function test_red_green_the_narrator_property_is_merged_back_as_the_first_cast_row(): void
    {
        $story = $this->draftStory();
        $usage = \App\Support\Providers\ProviderUsage::simulated(
            operation: 'generate_outline',
            category: \App\Enums\CostCategory::Text,
            quantity: 0.0,
            unit: \App\Enums\CostUnit::TotalTokens,
        );
        $response = [
            'title' => 'T',
            'cast' => [
                ['name' => 'Sophie Ruan', 'role' => 'antagonist', 'relationship' => 'my girlfriend of four years'],
                ['name' => 'Eric Cao', 'role' => 'accomplice', 'relationship' => 'the man she arrived with'],
            ],
            'acts' => [['title' => 'A', 'summary' => 'S', 'escalation_beat' => 'C', 'timeframe' => 'present']],
        ];

        $green = $this->invoke($this->claude(), 'outlineDraftFrom', $story, 5, $response + [
            'narrator' => ['name' => 'Wes Tang', 'relationship' => 'a studio designer; her boyfriend of four years'],
        ], $usage);

        $this->assertSame(['Wes Tang', 'Sophie Ruan', 'Eric Cao'], array_map(fn ($m) => $m->name, $green->cast));
        $this->assertSame(CastRole::Narrator, $green->cast[0]->role);
        $this->assertSame([], OutlineCast::structuralProblems($green->cast, StoryFormat::Single));

        $red = $this->invoke($this->claude(), 'outlineDraftFrom', $story, 5, $response, $usage);

        $this->assertSame(['Sophie Ruan', 'Eric Cao'], array_map(fn ($m) => $m->name, $red->cast));
        $this->assertStringContainsString('0 narrators', implode(' ', OutlineCast::structuralProblems($red->cast, StoryFormat::Single)));

        // An empty narrator name still becomes a row, so the refusal names the
        // exact repair — "has no name" — rather than "0 narrators".
        $blank = $this->invoke($this->claude(), 'outlineDraftFrom', $story, 5, $response + [
            'narrator' => ['name' => '', 'relationship' => 'the narrator'],
        ], $usage);
        $this->assertStringContainsString('Cast row 1 has no name', implode(' ', OutlineCast::structuralProblems($blank->cast, StoryFormat::Single)));
    }

    /**
     * The five refusals after the cost row record their kind and which check
     * fired, so the progress page can name the button rather than saying no
     * repair is known. The cast refusal is the one story 37 hit.
     */
    public function test_a_refused_cast_records_the_outline_refused_kind_and_its_check(): void
    {
        $story = $this->draftStory();
        $this->writer->castOverride = [
            new CastMember('Sophie Ruan', CastRole::Antagonist, 'my girlfriend of four years'),
            new CastMember('Eric Cao', CastRole::Accomplice, 'the man she arrived with'),
        ];

        try {
            app(GenerateOutline::class)->handle($story);
            $this->fail('Expected a cast with no narrator to be refused.');
        } catch (ScriptWriterException $e) {
            $this->assertStringContainsString('0 narrators', $e->getMessage());
        }

        $row = RenderJob::query()->where('story_id', $story->id)->where('stage', RenderStage::Outline)->sole();

        $this->assertSame(FailureKind::OutlineRefused, $row->failure_kind);
        $this->assertSame(['check' => 'cast_structure'], $row->failure_facts);
    }

    public function test_the_outline_stores_its_cast_and_ends_the_legacy_excuse(): void
    {
        $story = $this->draftStory();
        $story->forceFill(['outlined_before_cast' => true])->save();

        app(GenerateOutline::class)->handle($story);
        $story->refresh();

        $this->assertFalse($story->outlined_before_cast);
        $this->assertSame(
            ['Erin Vasquez', 'Kyle Vasquez', 'Danielle Vasquez', 'Paul Ostrander'],
            array_column($story->outline_cast, 'name'),
        );
        $this->assertSame('narrator', $story->outline_cast[0]['role']);
    }

    public function test_a_cast_with_two_narrators_is_refused_after_the_cost_row_and_nothing_is_stored(): void
    {
        $story = $this->draftStory();
        $this->writer->castOverride = [
            new CastMember('Erin Vasquez', CastRole::Narrator, 'the narrator'),
            new CastMember('Nora Pryce', CastRole::Narrator, 'also the narrator'),
            new CastMember('Kyle Vasquez', CastRole::Antagonist, 'her husband'),
        ];

        try {
            app(GenerateOutline::class)->handle($story);
            $this->fail('Expected a cast with two narrators to be refused.');
        } catch (ScriptWriterException $e) {
            $this->assertStringContainsString('2 narrators', $e->getMessage());
        }

        $this->assertSame(0, $story->acts()->count());
        $this->assertNull($story->fresh()->outline_cast);
        $this->assertSame(1, $story->costEntries()->where('operation', 'generate_outline')->count(), 'The call was billed.');
    }

    /**
     * RED: a name a recent story's CHARACTER used is refused — the published
     * videos carry their names only in `characters`. GREEN: the same name on a
     * fixture story is not taken, and neither is a role label like "Second
     * Uncle", or an outline containing an uncle would be refused.
     */
    public function test_a_name_a_recent_story_used_is_refused(): void
    {
        $published = Story::factory()->status(StoryStatus::Published)->create();
        Character::factory()->for($published)->create(['name' => 'Grace Zhou']);

        $story = $this->draftStory();
        $this->writer->castOverride = $this->castNaming('Grace Zhou');

        try {
            app(GenerateOutline::class)->handle($story);
            $this->fail('Expected a reused full name to be refused.');
        } catch (ScriptWriterException $e) {
            $this->assertStringContainsString('Grace Zhou ('.$published->slug.')', $e->getMessage());
        }

        $this->assertSame(0, $story->acts()->count());
    }

    public function test_a_fixture_and_a_role_label_do_not_take_a_name(): void
    {
        $fixture = Story::factory()->status(StoryStatus::Rendered)->create(['is_fixture' => true]);
        Character::factory()->for($fixture)->create(['name' => 'Grace Zhou']);

        $published = Story::factory()->status(StoryStatus::Published)->create();
        Character::factory()->for($published)->create(['name' => 'Second Uncle']);
        Character::factory()->for($published)->create(['name' => "Sophie's Father"]);

        $story = $this->draftStory();
        $this->writer->castOverride = array_merge($this->castNaming('Grace Zhou'), [
            new CastMember('Second Uncle', CastRole::AntagonistSide, 'his uncle'),
        ]);

        app(GenerateOutline::class)->handle($story);

        $this->assertContains('Grace Zhou', array_column($story->fresh()->outline_cast, 'name'));
    }

    public function test_the_outline_prompt_lists_the_taken_names_and_no_longer_asks_for_named_witnesses(): void
    {
        $published = Story::factory()->status(StoryStatus::Published)->create();
        Character::factory()->for($published)->create(['name' => 'Wang Suhua']);

        $prompt = $this->invoke($this->claude(), 'outlinePrompt', $this->draftStory(), 5);

        $this->assertStringContainsString('FIRST, THE NARRATOR', $prompt);
        $this->assertStringContainsString('THEN THE CAST: every OTHER person', $prompt);
        $flatPrompt = preg_replace('/\s+/', ' ', $prompt);
        $this->assertStringContainsString('At most '.OutlineCast::maxNamed().' people besides the narrator', $flatPrompt);
        // Why a person nobody names still needs a name, and what to do when
        // the premise gives none: the two sentences story 37 needed.
        $this->assertStringContainsString('If the premise does not name the narrator, give them a name', $flatPrompt);
        // And the role list no longer offers "narrator" on a single narrative:
        // the property is the only place a narrator can come from.
        $this->assertStringNotContainsString("\n    narrator — ", $prompt);
        $this->assertStringContainsString("\n    antagonist — ", $prompt, 'The role list itself must render, or the line above is vacuous.');
        $this->assertStringContainsString('future_partner', $prompt);
        $this->assertStringContainsString('NOT available', $prompt);
        $this->assertStringContainsString('Wang Suhua', $prompt);

        // The collision the cast would have had: four sentences further down
        // asking for more names than the cast allows.
        $flat = preg_replace('/\s+/', ' ', $prompt);
        $this->assertStringNotContainsString('name who is watching', $flat);
        $this->assertStringNotContainsString('Name the occasion and the witnesses', $flat);
    }

    // -- The act writer -------------------------------------------------------

    public function test_the_act_writer_is_handed_the_cast_as_the_people_who_exist(): void
    {
        $story = $this->outlinedStory();

        app(\App\Actions\GenerateActScripts::class)->handle($story);

        $calls = array_values(array_filter($this->writer->calls, fn (array $c): bool => $c['method'] === 'actScript'));
        $this->assertNotEmpty($calls);

        foreach ($calls as $call) {
            $this->assertSame(array_column($story->outline_cast, 'name'), $call['outline_cast']);
        }

        $prompt = $this->actPromptFor($story->fresh(), 5);
        $this->assertStringContainsString('THE PEOPLE IN THIS STORY', $prompt);
        $this->assertStringContainsString('Kyle Vasquez (Antagonist)', $prompt);
        $this->assertStringContainsString('Do not name anyone else', $prompt);
        $this->assertStringNotContainsString('in the room and name them', preg_replace('/\s+/', ' ', $prompt));
    }

    public function test_a_story_outlined_before_the_cast_gets_the_prompt_it_had(): void
    {
        $story = $this->outlinedStory();
        $story->forceFill(['outline_cast' => null, 'outlined_before_cast' => true])->save();

        $prompt = $this->actPromptFor($story->fresh(), 5);

        $this->assertStringNotContainsString('THE PEOPLE IN THIS STORY', $prompt);
        $this->assertStringContainsString('Put the witnesses in the room and name them.', $prompt);
    }

    // -- The extractor --------------------------------------------------------

    public function test_the_extraction_prompt_names_the_cast_and_nobody_else(): void
    {
        $story = $this->outlinedStory();

        $prompt = $this->invoke($this->claude(), 'characterPrompt', $story, ['Erin stood in the kitchen.']);

        $this->assertStringContainsString('describe these people and NOBODY ELSE', $prompt);
        // Paul is the fake's accomplice since 3g, so the fixture outline has one.
        $this->assertStringContainsString('- Paul Ostrander (Accomplice)', $prompt);
        $this->assertStringNotContainsString('APPEARS IN MORE THAN ONE SCENE', $prompt);
    }

    /**
     * RED: names the outline never declared are dropped and named on the job
     * row, and a declared name not returned is named too. GREEN: a story with
     * no outline cast keeps everything the extractor returned.
     */
    public function test_extraction_keeps_the_declared_cast_and_says_what_it_dropped(): void
    {
        $story = $this->scriptedStory([
            ['name' => 'Erin Vasquez', 'role' => 'narrator', 'relationship' => 'the narrator'],
            ['name' => 'Kyle Vasquez', 'role' => 'antagonist', 'relationship' => 'her husband'],
            ['name' => 'Maya Okafor', 'role' => 'future_partner', 'relationship' => 'arrives through Paul'],
        ]);
        $this->writer->ignoreOutlineCast = true;

        app(ExtractCharacters::class)->handle($story);

        $this->assertEqualsCanonicalizing(['Erin Vasquez', 'Kyle Vasquez'], $story->characters()->pluck('name')->all());

        $log = (string) RenderJob::query()->where('story_id', $story->id)->where('stage', RenderStage::ExtractCast)->value('log');
        $this->assertStringContainsString('Dropped 2 not in the outline cast: Danielle Vasquez, Paul Ostrander', $log);
        $this->assertStringContainsString('Not returned from the outline cast: Maya Okafor', $log);
    }

    public function test_extraction_without_an_outline_cast_is_untouched(): void
    {
        $story = $this->scriptedStory(null);
        $this->writer->ignoreOutlineCast = true;

        app(ExtractCharacters::class)->handle($story);

        $this->assertSame(4, $story->characters()->count());
    }

    public function test_an_extraction_matching_nobody_is_refused_and_stores_nothing(): void
    {
        $story = $this->scriptedStory([
            ['name' => 'Nobody Here', 'role' => 'narrator', 'relationship' => 'the narrator'],
        ]);
        $this->writer->ignoreOutlineCast = true;

        try {
            app(ExtractCharacters::class)->handle($story);
            $this->fail('Expected an extraction that ignored the whole list to be refused.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('none of them is in the outline cast', $e->getMessage());
        }

        $this->assertSame(0, $story->characters()->count());
    }

    // -- Gate 1 ---------------------------------------------------------------

    public function test_a_cast_missing_from_an_asked_outline_is_a_problem_and_from_a_legacy_one_is_not(): void
    {
        $story = $this->outlinedStory();
        $story->forceFill(['outline_cast' => null])->save();

        $problems = app(ValidateOutlineSpine::class)->handle($story->fresh())['problems'];
        $this->assertStringContainsString('The cast is missing', implode(' ', $problems));

        $story->forceFill(['outlined_before_cast' => true])->save();
        $review = app(ValidateOutlineSpine::class)->handle($story->fresh());

        $this->assertStringNotContainsString('The cast is missing', implode(' ', $review['problems']));
        $this->assertStringContainsString('before it was asked for its cast', implode(' ', $review['warnings']));
    }

    public function test_gate_one_reports_a_broken_cast_an_oversized_one_and_a_reused_name(): void
    {
        $published = Story::factory()->status(StoryStatus::Published)->create();
        Character::factory()->for($published)->create(['name' => 'Grace Zhou']);

        $story = $this->outlinedStory();
        $rows = [['name' => 'Grace Zhou', 'role' => 'narrator', 'relationship' => 'the narrator']];

        // The budget is people BESIDES the narrator, as the prompt states it:
        // the narrator plus exactly the budget is not over it.
        foreach (range(1, OutlineCast::maxNamed()) as $n) {
            $rows[] = ['name' => "Person Number{$n}x", 'role' => 'narrator_side', 'relationship' => 'a friend'];
        }

        $story->forceFill(['outline_cast' => $rows])->save();
        $this->assertStringNotContainsString('against a budget of', implode(' ', app(ValidateOutlineSpine::class)->handle($story->fresh())['warnings']));

        $rows[] = ['name' => 'Person Numberoverx', 'role' => 'narrator_side', 'relationship' => 'a friend'];
        $story->forceFill(['outline_cast' => $rows])->save();
        $review = app(ValidateOutlineSpine::class)->handle($story->fresh());

        $this->assertStringContainsString('Cast: The cast has 0 antagonists', implode(' ', $review['problems']));
        $this->assertStringContainsString('names '.(OutlineCast::maxNamed() + 1).' people besides the narrator against a budget of', implode(' ', $review['warnings']));
        $this->assertStringContainsString('Grace Zhou was already used by '.$published->slug, implode(' ', $review['warnings']));
        $this->assertSame($published->slug, $review['cast'][0]['reused']);
    }

    /**
     * RED: a future partner the scripts never name is reported, in the words
     * that say what it measures. GREEN: her given name alone in one act is
     * enough — "Maya" is how a script refers to Maya Okafor.
     */
    public function test_a_future_partner_named_in_no_act_is_reported(): void
    {
        $story = $this->scriptedStory([
            ['name' => 'Erin Vasquez', 'role' => 'narrator', 'relationship' => 'the narrator'],
            ['name' => 'Kyle Vasquez', 'role' => 'antagonist', 'relationship' => 'her husband'],
            ['name' => 'Maya Okafor', 'role' => 'future_partner', 'relationship' => 'arrives through Paul'],
        ]);
        // First person, never naming the narrator — the state the exemption is
        // FOR. The shared script names Erin, and with it the exemption drill
        // stayed green: a fixture that could not hold the case it was checking.
        $story->acts()->update(['script' => 'I stood in the kitchen. Kyle would not look at me.']);
        $story->refresh();

        $warnings = implode(' ', app(ValidateOutlineSpine::class)->handle($story)['warnings']);
        $this->assertStringContainsString('Maya Okafor, the future partner, is named in no act script', $warnings);
        $this->assertStringNotContainsString('Erin Vasquez is in the cast and named in no act', $warnings, 'The narrator is exempt.');

        $story->acts()->update(['script' => 'I stood in the kitchen. Kyle would not look at me. Maya brought coffee.']);
        $warnings = implode(' ', app(ValidateOutlineSpine::class)->handle($story->fresh())['warnings']);
        $this->assertStringNotContainsString('Maya Okafor, the future partner', $warnings);
    }

    public function test_the_cast_is_edited_at_gate_one(): void
    {
        $story = $this->outlinedStory();

        Livewire::test(OutlineGate::class, ['story' => $story])
            ->call('removeCastMember', 3)
            ->call('addCastMember')
            ->set('cast.3.name', 'Maya Okafor')
            ->set('cast.3.role', 'future_partner')
            ->set('cast.3.relationship', 'Paul\'s cousin, who arrives in act 4')
            ->call('save')
            ->assertHasNoErrors();

        $cast = $story->fresh()->outline_cast;
        $this->assertSame(['Erin Vasquez', 'Kyle Vasquez', 'Danielle Vasquez', 'Maya Okafor'], array_column($cast, 'name'));
        $this->assertSame('future_partner', $cast[3]['role']);
    }

    public function test_a_duplicate_name_is_refused_at_gate_one(): void
    {
        $story = $this->outlinedStory();

        Livewire::test(OutlineGate::class, ['story' => $story])
            ->set('cast.1.name', 'erin vasquez')
            ->call('save')
            ->assertHasErrors('cast.1.name');
    }

    /**
     * The first press writes the outline and stops. The cast is reviewed on
     * this page, so an act bought in the same press would be bought against a
     * cast nobody had read.
     */
    public function test_the_first_write_press_queues_the_outline_only(): void
    {
        Queue::fake();
        $story = $this->draftStory();

        $component = Livewire::test(OutlineGate::class, ['story' => $story]);
        $this->assertSame(['calls' => 1, 'outline' => true, 'acts' => 0], array_intersect_key(
            $component->instance()->writeEstimate(),
            array_flip(['calls', 'outline', 'acts']),
        ));

        $component->call('write');

        Queue::assertPushed(WriteStoryJob::class, fn (WriteStoryJob $job): bool => $job->outlineOnly && $job->actsOnly === []);
    }

    public function test_the_second_press_writes_the_acts(): void
    {
        Queue::fake();
        $story = $this->outlinedStory();

        Livewire::test(OutlineGate::class, ['story' => $story])->call('write');

        Queue::assertPushed(WriteStoryJob::class, fn (WriteStoryJob $job): bool => ! $job->outlineOnly && $job->actsOnly !== []);
    }

    /**
     * RED: approving with unwritten acts is refused and says which. Between
     * the two presses that is the ordinary state, and crossing there would
     * leave a story at `scripted` where writing is refused.
     */
    public function test_approving_with_unwritten_acts_is_refused(): void
    {
        $story = $this->outlinedStory();

        $component = Livewire::test(OutlineGate::class, ['story' => $story])->call('approve');

        $this->assertSame(StoryStatus::Outlined, $story->fresh()->status);
        $this->assertStringContainsString('Gate 1 not crossed: act(s) 1, 2, 3, 4, 5 have no script', (string) $component->get('problem'));
    }

    // -- The fork -------------------------------------------------------------

    public function test_a_fork_carries_the_cast_and_its_age(): void
    {
        $source = $this->outlinedStory();
        $source->forceFill(['outlined_before_cast' => true])->save();

        Artisan::call('story:fork', ['story' => $source->slug]);

        $fork = Story::query()->whereKeyNot($source->id)->latest('id')->firstOrFail();

        $this->assertSame($source->outline_cast, $fork->outline_cast);
        $this->assertTrue($fork->outlined_before_cast);
    }

    // -- Fixtures -------------------------------------------------------------

    /** @return array<int, CastMember> */
    private function castNaming(string $narrator): array
    {
        return [
            new CastMember($narrator, CastRole::Narrator, 'the narrator'),
            new CastMember('Kyle Brennan', CastRole::Antagonist, 'her husband'),
        ];
    }

    private function draftStory(): Story
    {
        return Story::factory()->status(StoryStatus::Draft)->create([
            'format' => StoryFormat::Single,
            'locale_profile' => 'en-US',
            'premise' => 'My sister billed me for her entire wedding over eleven months and told the '
                .'family I had offered.',
        ]);
    }

    private function outlinedStory(): Story
    {
        $story = $this->draftStory();

        app(GenerateOutline::class)->handle($story);

        $this->writer->calls = [];
        $this->writer->castOverride = null;

        return $story->refresh();
    }

    /** @param  array<int, array<string, string>>|null  $cast */
    private function scriptedStory(?array $cast): Story
    {
        $story = Story::factory()->status(StoryStatus::Scripted)->create(['outline_cast' => $cast]);

        Act::factory()->for($story)->atSequence(1)->create([
            'script' => 'Erin stood in the kitchen. Kyle would not look at her. '
                .'The house had been their mothers and now it was not.',
        ]);

        return $story;
    }

    private function actPromptFor(Story $story, int $sequence): string
    {
        $outline = $story->acts()->orderBy('sequence')->get()
            ->map(fn (Act $act): ActOutline => new ActOutline(
                sequence: $act->sequence,
                title: (string) $act->title,
                summary: (string) $act->summary,
                escalationBeat: (string) $act->escalation_beat,
                phase: $act->phase,
                timeframe: $act->timeframe,
            ))
            ->all();

        return $this->invoke($this->claude(), 'actPrompt', $story, $outline[$sequence - 1], $outline, ['Act 1 happened.'], 985);
    }

    private function claude(): ClaudeScriptWriter
    {
        return new ClaudeScriptWriter(
            client: app(Client::class),
            locale: app(LocaleGuard::class),
            text: app(CharacterTextGuard::class),
        );
    }

    private function invoke(object $target, string $method, mixed ...$args): mixed
    {
        return (new ReflectionMethod($target, $method))->invoke($target, ...$args);
    }
}
