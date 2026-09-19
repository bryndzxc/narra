<?php

namespace Tests\Feature\Providers;

use Anthropic\Client;
use App\Actions\DispatchTextStage;
use App\Actions\GenerateOutline;
use App\Actions\ValidateOutlineSpine;
use App\Contracts\ScriptWriter;
use App\Enums\ActPhase;
use App\Enums\StoryEnding;
use App\Enums\StoryStatus;
use App\Exceptions\DispatchRefusedException;
use App\Jobs\WriteStoryJob;
use App\Livewire\Gates\OutlineGate;
use App\Livewire\Stories\NewStory;
use App\Models\Act;
use App\Models\Chapter;
use App\Models\Story;
use App\Services\Claude\ClaudeMetadataWriter;
use App\Services\Claude\ClaudeScriptWriter;
use App\Services\Fake\FakeScriptWriter;
use App\Support\AntagonistPointOfView;
use App\Support\CharacterTextGuard;
use App\Support\LocaleGuard;
use App\Support\Providers\ActOutline;
use App\Support\RecentEndings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use ReflectionMethod;
use Tests\TestCase;

/**
 * THE ENDING IS CHOSEN, EXCLUSIVE, AND SAYS WHAT IT DOES NOT CARRY.
 *
 * Built 2026-09-19. Every story with a written refusal act ended the same way:
 * the epilogue was a list (a headcount, her decline in three facts, one object
 * sent back), and from story 37 on the regret was required, so her chapter was
 * stacked after it and the two restated one year. Now the operator picks one
 * of two endings before the outline. What each case holds:
 *
 *  - The outline is refused, before its call, on a single narrative with no
 *    ending — at the Action, the dispatch and the Gate 1 button — and never on
 *    an anthology.
 *  - The regret is asked and stored only on the antagonist's ending, and
 *    dropped if a writer returns one anyway.
 *  - The refusal act is asked for exactly one ending, with its edge stated,
 *    and the new life asks for a scene, not numbers; the cast decides the
 *    partner.
 *  - Gate 1: a regret on the new life, a partner on her ending, and a partner
 *    missing from the new life's last chapter, each RED/GREEN.
 *  - The picker: required, the history beside it, a streak said out loud.
 *  - The metadata brief says which ending the title may promise.
 */
class EndingChoiceTest extends TestCase
{
    use RefreshDatabase;

    private FakeScriptWriter $writer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->writer = app(ScriptWriter::class);
    }

    // -- Chosen before the outline -----------------------------------------------

    public function test_red_green_the_outline_is_refused_before_its_call_without_an_ending(): void
    {
        $unchosen = Story::factory()->status(StoryStatus::Draft)->create(['ending' => null]);

        try {
            app(GenerateOutline::class)->handle($unchosen);
            $this->fail('An outline with no ending should have been refused.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Choose the ending before the outline', $e->getMessage());
        }

        $this->assertSame([], array_filter($this->writer->calls, fn (array $c): bool => $c['method'] === 'outline'), 'Refused before the call.');
        $this->assertSame(0, $unchosen->costEntries()->count());

        // GREEN: chosen, it runs; and an anthology is never asked.
        app(GenerateOutline::class)->handle(Story::factory()->status(StoryStatus::Draft)->create());
        app(GenerateOutline::class)->handle(Story::factory()->anthology()->status(StoryStatus::Draft)->create());
        $this->assertCount(2, array_filter($this->writer->calls, fn (array $c): bool => $c['method'] === 'outline'));
    }

    public function test_the_dispatch_and_the_gate_one_button_refuse_before_anything_queues(): void
    {
        Queue::fake();
        $story = Story::factory()->status(StoryStatus::Draft)->create(['ending' => null]);

        try {
            app(DispatchTextStage::class)->writeScript($story, checkWorkers: false);
            $this->fail('The dispatch should refuse a story with no ending.');
        } catch (DispatchRefusedException $e) {
            $this->assertSame(GenerateOutline::NO_ENDING, $e->getMessage());
        }

        Livewire::test(OutlineGate::class, ['story' => $story])
            ->call('askToWrite')
            ->assertSet('confirmingWrite', false)
            ->assertSee('Choose the ending before the outline');

        Queue::assertNotPushed(WriteStoryJob::class);
    }

    // -- The regret, asked and kept only on her ending ---------------------------

    public function test_red_green_a_regret_the_new_life_did_not_ask_for_is_dropped(): void
    {
        $this->writer->accompliceOverride = ['antagonist_regret' => 'A regret nobody asked this outline for, about a year on.'];

        $newLife = Story::factory()->status(StoryStatus::Draft)->create();
        app(GenerateOutline::class)->handle($newLife);
        $this->assertNull($newLife->refresh()->antagonist_regret);

        $hers = Story::factory()->endsInTheAntagonistsVoice()->status(StoryStatus::Draft)->create();
        app(GenerateOutline::class)->handle($hers);
        $this->assertSame('A regret nobody asked this outline for, about a year on.', $hers->refresh()->antagonist_regret);
    }

    public function test_the_ending_decides_whether_her_chapter_is_asked_and_legacy_keeps_the_old_rule(): void
    {
        $cast = [
            ['name' => 'Erin Vasquez', 'role' => 'narrator', 'relationship' => 'the narrator'],
            ['name' => 'Dana Vasquez', 'role' => 'antagonist', 'relationship' => 'her sister'],
        ];

        $make = fn (?StoryEnding $ending): Story => Story::factory()->create([
            'ending' => $ending,
            'outline_cast' => $cast,
            'antagonist_regret' => 'The chance, and about a year on.',
        ]);

        $this->assertNull(AntagonistPointOfView::nameFor($make(StoryEnding::NewLife)), 'Exclusive: a written regret does not bring her chapter back.');
        $this->assertSame('Dana Vasquez', AntagonistPointOfView::nameFor($make(StoryEnding::AntagonistVoice)));
        $this->assertSame('Dana Vasquez', AntagonistPointOfView::nameFor($make(null)), 'Outlined before the choice: the old rule, unchanged.');
    }

    // -- The refusal act: one ending, its edge stated ----------------------------

    public function test_the_new_life_is_a_scene_with_no_numbers_and_no_antagonist_year(): void
    {
        $ending = $this->refusalEnding(Story::factory()->create([
            'refusal' => 'I said no.',
            'antagonist_regret' => 'Written anyway, and not to be asked for.',
            'outline_cast' => [['name' => 'Dana Vasquez', 'role' => 'antagonist', 'relationship' => 'her sister']],
        ]));

        $this->assertStringContainsString('THE NARRATOR\'S NEW LIFE', $ending);
        $this->assertStringContainsString('ONE SCENE, NOT A SUMMARY OF THE YEAR', $ending);
        $this->assertStringContainsString('NO NUMBERS', $ending);
        $this->assertStringContainsString($this->flat(StoryEnding::NewLife->doesNotCarry()), $ending);
        $this->assertStringContainsString('ALONE AND FINE', $ending, 'No partner row: the cast decides.');
        $this->assertStringContainsString('No chapter is told from anybody else\'s point of view', $ending);
        $this->assertStringNotContainsString('THE ANTAGONIST\'S CHAPTER', $ending);
        $this->assertStringNotContainsString('Written anyway', $ending);
        // The old epilogue's instruction, the one every list came from.
        $this->assertStringNotContainsString('what the narrator\'s life is now, and one concrete fact', $ending);
    }

    public function test_the_new_life_puts_the_cast_partner_on_screen(): void
    {
        $ending = $this->refusalEnding(Story::factory()->create([
            'refusal' => 'I said no.',
            'outline_cast' => [['name' => 'Vivian Cao', 'role' => 'future_partner', 'relationship' => 'her best friend since university']],
        ]));

        $this->assertStringContainsString('VIVIAN CAO IS IN THE SCENE, ON SCREEN', mb_strtoupper($ending));
        $this->assertStringContainsString('her best friend since university', $ending);
        $this->assertStringNotContainsString('ALONE AND FINE', $ending);
    }

    public function test_her_ending_drops_the_narrators_year_and_is_the_last_chapter(): void
    {
        $ending = $this->refusalEnding(Story::factory()->endsInTheAntagonistsVoice()->create([
            'refusal' => 'I said no.',
            'antagonist_regret' => 'The chance on the stairs, and about a year on.',
            'outline_cast' => [['name' => 'Dana Vasquez', 'role' => 'antagonist', 'relationship' => 'her sister']],
        ]));

        $this->assertStringContainsString('THE ENDING IS THE ANTAGONIST\'S', $ending);
        $this->assertStringContainsString($this->flat(StoryEnding::AntagonistVoice->doesNotCarry()), $ending);
        $this->assertStringContainsString('THE ANTAGONIST\'S CHAPTER', $ending);
        $this->assertStringContainsString('The chance on the stairs', $ending);
        $this->assertStringNotContainsString('THE NARRATOR\'S NEW LIFE', $ending, 'Exclusive: no epilogue before her chapter.');
    }

    public function test_each_ending_names_what_it_does_not_carry_and_they_point_at_each_other(): void
    {
        $this->assertStringContainsString('DOES NOT CARRY THE ANTAGONIST\'S YEAR', StoryEnding::NewLife->doesNotCarry());
        $this->assertStringContainsString('DOES NOT CARRY THE NARRATOR\'S YEAR', StoryEnding::AntagonistVoice->doesNotCarry());
        $this->assertStringContainsString('ONE fact', StoryEnding::AntagonistVoice->doesNotCarry(), 'Story 37 restated three.');
    }

    // -- Gate 1 -----------------------------------------------------------------

    public function test_red_green_a_regret_on_the_new_life_is_named_as_the_other_endings_material(): void
    {
        $cast = [['name' => 'Dana Vasquez', 'role' => 'antagonist', 'relationship' => 'her sister']];

        $red = $this->review(Story::factory()->create(['outline_cast' => $cast, 'antagonist_regret' => 'The chance, and about a year on.']));
        $this->assertStringContainsString('the other one\'s material', implode(' ', $red['warnings']));
        $this->assertStringNotContainsString('cast names no antagonist', implode(' ', $red['warnings']));

        // GREEN: empty on the new life is not missing — it was never asked.
        $green = $this->review(Story::factory()->create(['outline_cast' => $cast, 'antagonist_regret' => null]));
        $this->assertSame('none', $green['spine']['antagonist_regret']['state']);
        $this->assertStringNotContainsString('last chance, and a year on is missing', implode(' ', $green['problems']));

        // And missing is still missing on her ending.
        $hers = $this->review(Story::factory()->endsInTheAntagonistsVoice()->create(['outline_cast' => $cast, 'antagonist_regret' => null]));
        $this->assertSame('missing', $hers['spine']['antagonist_regret']['state']);
    }

    public function test_red_green_a_partner_on_her_ending_is_said_before_the_acts(): void
    {
        $cast = [
            ['name' => 'Dana Vasquez', 'role' => 'antagonist', 'relationship' => 'her sister'],
            ['name' => 'Vivian Cao', 'role' => 'future_partner', 'relationship' => 'her best friend'],
        ];

        $red = $this->review(Story::factory()->endsInTheAntagonistsVoice()->create(['outline_cast' => $cast]));
        $this->assertStringContainsString('Vivian Cao is never on screen at the end', implode(' ', $red['warnings']));

        $green = $this->review(Story::factory()->endsInTheAntagonistsVoice()->create(['outline_cast' => array_slice($cast, 0, 1)]));
        $this->assertStringNotContainsString('never on screen at the end', implode(' ', $green['warnings']));
    }

    public function test_red_green_the_new_lifes_last_chapter_names_the_partner(): void
    {
        $red = $this->review($this->newLifeWithRefusalAct('We ate on the balcony. My sister brought the fish. Nobody mentioned the old flat.'));
        $this->assertStringContainsString('last chapter ("The Balcony") never names them', implode(' ', $red['warnings']));

        $green = $this->review($this->newLifeWithRefusalAct('We ate on the balcony. Vivian brought the fish. Nobody mentioned the old flat.'));
        $this->assertStringNotContainsString('never names them', implode(' ', $green['warnings']));
    }

    public function test_gate_one_saves_the_ending_at_draft_and_refuses_after_the_outline(): void
    {
        $story = Story::factory()->status(StoryStatus::Draft)->create(['ending' => null]);

        Livewire::test(OutlineGate::class, ['story' => $story])
            ->assertSee('Ending: ')
            ->set('ending', StoryEnding::AntagonistVoice->value)
            ->assertSee('Ending set:');

        $this->assertSame(StoryEnding::AntagonistVoice, $story->refresh()->ending);

        $outlined = Story::factory()->status(StoryStatus::Outlined)->create();
        Act::factory()->for($outlined)->create(['sequence' => 1]);

        Livewire::test(OutlineGate::class, ['story' => $outlined])
            ->assertSee('Fixed: the outline was written to it.')
            ->set('ending', StoryEnding::AntagonistVoice->value)
            ->assertForbidden();

        $this->assertSame(StoryEnding::NewLife, $outlined->refresh()->ending);
    }

    // -- The picker and its history ---------------------------------------------

    public function test_red_green_the_form_requires_an_ending_on_a_single_narrative_only(): void
    {
        Queue::fake();

        Livewire::test(NewStory::class)
            ->set('premise', 'A premise comfortably longer than the twenty character minimum.')
            ->set('narratorGender', 'male')
            ->call('askToCreate')
            ->assertHasErrors(['ending' => 'required']);

        Livewire::test(NewStory::class)
            ->set('premise', 'A premise comfortably longer than the twenty character minimum.')
            ->set('narratorGender', 'male')
            ->set('format', 'anthology')
            ->call('askToCreate')
            ->assertHasNoErrors(['ending']);

        Livewire::test(NewStory::class)
            ->set('premise', 'A premise comfortably longer than the twenty character minimum.')
            ->set('narratorGender', 'male')
            ->set('ending', StoryEnding::AntagonistVoice->value)
            ->call('create');

        $this->assertSame(StoryEnding::AntagonistVoice, Story::query()->latest('id')->first()->ending);
    }

    public function test_red_green_three_in_a_row_is_said_before_the_fourth_is_picked(): void
    {
        foreach (range(1, 3) as $i) {
            Story::factory()->endsInTheAntagonistsVoice()->create(['title' => "Hers {$i}"]);
        }

        $rows = RecentEndings::last();
        $this->assertSame(['ending' => StoryEnding::AntagonistVoice, 'count' => 3], RecentEndings::streak($rows));

        Livewire::test(NewStory::class)
            ->assertSee('The last 3 videos all ended the same way')
            ->assertSee('Hers 3');

        // GREEN: broken by one of the other.
        Story::factory()->create(['title' => 'Mine']);
        $this->assertNull(RecentEndings::streak(RecentEndings::last()));
    }

    public function test_a_story_before_the_choice_is_read_from_its_chapters_and_a_fixture_is_skipped(): void
    {
        $legacy = Story::factory()->create(['ending' => null, 'title' => 'Before']);
        $act = Act::factory()->for($legacy)->create(['sequence' => 5, 'phase' => ActPhase::Refusal, 'script' => 'One. Two.']);
        Chapter::factory()->forAct($act)->atSequence(1, 1)->create();
        Chapter::factory()->forAct($act)->atSequence(2, 2)->create(['point_of_view' => 'Dana Vasquez']);

        Story::factory()->endsInTheAntagonistsVoice()->create(['is_fixture' => true, 'title' => 'A fixture']);

        $rows = RecentEndings::last();

        $this->assertCount(1, $rows);
        $this->assertSame('Before', $rows[0]['title']);
        $this->assertSame(StoryEnding::AntagonistVoice, $rows[0]['ending']);
        $this->assertFalse($rows[0]['chosen'], 'A reading is not a choice.');
    }

    // -- The title --------------------------------------------------------------

    public function test_red_green_the_title_brief_says_which_ending_it_may_promise(): void
    {
        $partner = [['name' => 'Vivian Cao', 'role' => 'future_partner', 'relationship' => 'her best friend']];

        $hers = $this->brief(Story::factory()->endsInTheAntagonistsVoice()->create(['outline_cast' => $partner]));
        $this->assertStringContainsString('How the video ends: the antagonist\'s own chapter', $hers);
        $this->assertStringContainsString('Vivian Cao is not on screen at the end', $hers);
        $this->assertStringContainsString('Do not promise a wedding, a remarriage or a new love', $hers);

        $withPartner = $this->brief(Story::factory()->create(['outline_cast' => $partner]));
        $this->assertStringContainsString('with Vivian Cao (her best friend) on screen', $withPartner);
        $this->assertStringNotContainsString('Do not promise a wedding', $withPartner);

        $alone = $this->brief(Story::factory()->create(['outline_cast' => []]));
        $this->assertStringContainsString('alone and fine', $alone);

        $this->assertStringNotContainsString('How the video ends', $this->brief(Story::factory()->create(['ending' => null])));

        $rules = $this->invoke(new ClaudeMetadataWriter(app(Client::class), app(LocaleGuard::class)), 'packagingRules', Story::factory()->create());
        $this->assertStringContainsString('PROMISE ONLY THE ENDING', $rules);
    }

    public function test_a_fork_keeps_the_ending_it_was_outlined_to(): void
    {
        $source = Story::factory()->endsInTheAntagonistsVoice()->status(StoryStatus::Outlined)->create();
        Act::factory()->for($source)->create(['sequence' => 1]);

        $this->artisan('story:fork', ['story' => $source->slug])->assertSuccessful();

        $this->assertSame(StoryEnding::AntagonistVoice, Story::query()->latest('id')->first()->ending);
    }

    /**
     * RED/GREEN, found by the case above: story:fork limited the TITLE to 60
     * and then appended "-xxxx", so a long title made a 65-character slug on a
     * 64-character column. A random factory title hit it two runs in six; this
     * title hits it every time.
     */
    public function test_a_fork_of_a_long_titled_story_fits_the_slug_column(): void
    {
        $source = Story::factory()->status(StoryStatus::Outlined)->create([
            'title' => str_repeat('A very long working title ', 4),
        ]);
        Act::factory()->for($source)->create(['sequence' => 1]);

        $this->artisan('story:fork', ['story' => $source->slug])->assertSuccessful();

        $this->assertLessThanOrEqual(64, strlen((string) Story::query()->latest('id')->first()->slug));
    }

    public function test_story_write_refuses_a_new_single_premise_with_no_ending(): void
    {
        $this->artisan('story:write', ['--premise' => 'A premise long enough to be a story.', '--narrator' => 'male', '--yes' => true])
            ->assertFailed();

        $this->assertSame(0, Story::query()->count());
    }

    // ---------------------------------------------------------------------------

    private function newLifeWithRefusalAct(string $lastChapter): Story
    {
        $story = Story::factory()->status(StoryStatus::Outlined)->create([
            'outline_cast' => [['name' => 'Vivian Cao', 'role' => 'future_partner', 'relationship' => 'her best friend']],
        ]);

        $act = Act::factory()->for($story)->create([
            'sequence' => 5,
            'phase' => ActPhase::Refusal,
            'script' => 'She asked me to come back. I said no. '.$lastChapter,
        ]);

        Chapter::factory()->forAct($act)->atSequence(1, 1)->create(['title' => 'The Corridor']);
        Chapter::factory()->forAct($act)->atSequence(2, 3)->create(['title' => 'The Balcony']);

        return $story->refresh();
    }

    /** @return array{problems: array<int, string>, warnings: array<int, string>, spine: array<string, array<string, string>>} */
    private function review(Story $story): array
    {
        return app(ValidateOutlineSpine::class)->handle($story->refresh());
    }

    private function refusalEnding(Story $story): string
    {
        $act = new ActOutline(sequence: 5, title: 'T', summary: 'S', phase: ActPhase::Refusal);

        return $this->flat($this->invoke($this->claude(), 'endingFor', $story, $act, true));
    }

    private function brief(Story $story): string
    {
        return $this->invoke(new ClaudeMetadataWriter(app(Client::class), app(LocaleGuard::class)), 'storyBrief', $story);
    }

    private function flat(string $text): string
    {
        return (string) preg_replace('/\s+/', ' ', $text);
    }

    private function claude(): ClaudeScriptWriter
    {
        return new ClaudeScriptWriter(client: app(Client::class), locale: app(LocaleGuard::class), text: app(CharacterTextGuard::class));
    }

    private function invoke(object $target, string $method, mixed ...$args): mixed
    {
        return (new ReflectionMethod($target, $method))->invoke($target, ...$args);
    }
}
