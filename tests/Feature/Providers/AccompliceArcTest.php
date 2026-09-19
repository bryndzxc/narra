<?php

namespace Tests\Feature\Providers;

use Anthropic\Client;
use App\Actions\DraftScenes;
use App\Actions\GenerateActScripts;
use App\Actions\GenerateOutline;
use App\Actions\ValidateOutlineSpine;
use App\Contracts\ScriptWriter;
use App\Enums\ActPhase;
use App\Enums\CastRole;
use App\Enums\Gate;
use App\Enums\StoryFormat;
use App\Enums\StoryStatus;
use App\Livewire\Gates\OutlineGate;
use App\Models\Act;
use App\Models\Character;
use App\Models\CostEntry;
use App\Models\Story;
use App\Services\Claude\ClaudeScriptWriter;
use App\Services\Fake\FakeScriptWriter;
use App\Support\CharacterTextGuard;
use App\Support\LocaleGuard;
use App\Support\Providers\ActOutline;
use App\Support\Providers\CastMember;
use App\Support\Providers\ScriptWriterException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Livewire\Livewire;
use ReflectionMethod;
use Tests\TestCase;

/**
 * THE ACCOMPLICE HAS A STAKE, AND THE NARRATOR HAS A RUNNING THOUGHT — every
 * consumer of the four fields, asked in the change. See CLAUDE.md 3g.
 *
 * The consumer question, answered:
 *
 *   outline schema + prompt   produces all four, in generation order
 *   GenerateOutline           stores them (empty included), refuses coded terms
 *   every act prompt          motive, performance, thought in the spine block
 *   departure/search/refusal  the fall, and ONLY those acts
 *   act 1                     he talks in chapter one; the thought is planted
 *   refusal act               pays the thought off
 *   last-three scene calls    the fall, so he is drawn when he loses
 *   Gate 1                    edits, states, badges, refusal of coded terms
 *   story:fork                copies all four and the age
 *   ExtractCharacters         nothing — it reads scripts, not the spine
 *
 * The red/green pairs for the Gate 1 checks live in GuardsGoRedTest.
 */
class AccompliceArcTest extends TestCase
{
    use RefreshDatabase;

    private FakeScriptWriter $writer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->writer = app(ScriptWriter::class);
    }

    // -- Produced and stored -------------------------------------------------

    public function test_the_outline_produces_and_stores_all_four(): void
    {
        $story = $this->outlinedStory();

        foreach (['accomplice_motive', 'accomplice_performance', 'accomplice_fall', 'running_thought'] as $field) {
            $this->assertNotSame('', trim((string) $story->{$field}), "{$field} was not stored.");
        }

        $this->assertFalse($story->outlined_before_accomplice_and_thought);
    }

    /**
     * Property order is generation order: his act before the scene he speaks
     * in, the thought before the refusal that pays it off.
     */
    public function test_the_schema_requires_them_in_generation_order(): void
    {
        $schema = $this->invoke($this->claude(), 'outlineSchema');
        $order = array_keys($schema['properties']);

        foreach (['accomplice_motive', 'accomplice_performance', 'accomplice_fall', 'running_thought'] as $field) {
            $this->assertContains($field, $schema['required']);
        }

        $this->assertLessThan(array_search('betrayal_scene', $order, true), array_search('accomplice_performance', $order, true));
        $this->assertLessThan(array_search('refusal', $order, true), array_search('running_thought', $order, true));
        $this->assertLessThan(array_search('accomplice_fall', $order, true), array_search('reversal_beats', $order, true));
    }

    /**
     * THE array_filter TRAP. The spine is stored through a filter that drops
     * empty values, which is harmless for fields every outline fills. Empty
     * accomplice fields are a legitimate answer, so a re-outline whose cast
     * has no accomplice must CLEAR the previous outline's accomplice rather
     * than leave him standing on a story that no longer has one.
     */
    public function test_a_regenerated_outline_with_no_accomplice_clears_the_old_one(): void
    {
        $story = $this->outlinedStory();
        $this->assertNotSame('', (string) $story->accomplice_fall);

        $this->writer->castOverride = [
            new CastMember('Erin Vasquez', CastRole::Narrator, 'the narrator'),
            new CastMember('Kyle Vasquez', CastRole::Antagonist, 'her husband'),
        ];
        $this->writer->accompliceOverride = [
            'accomplice_motive' => '',
            'accomplice_performance' => '',
            'accomplice_fall' => '',
        ];

        app(GenerateOutline::class)->handle($story->refresh());

        $story->refresh();
        $this->assertNull($story->accomplice_motive);
        $this->assertNull($story->accomplice_performance);
        $this->assertNull($story->accomplice_fall);
    }

    public function test_regenerating_clears_the_age_flag(): void
    {
        $story = $this->draftStory();
        $story->forceFill(['outlined_before_accomplice_and_thought' => true])->save();

        app(GenerateOutline::class)->handle($story);

        $this->assertFalse($story->refresh()->outlined_before_accomplice_and_thought);
    }

    // -- The exclusion ---------------------------------------------------------

    /**
     * RED: Gerald's own line as the accomplice's act. Refused AFTER the cost
     * row, so the billed call is on the ledger and nothing is stored.
     */
    public function test_an_outline_building_the_act_on_orientation_is_refused_after_the_cost_row(): void
    {
        $story = $this->draftStory();
        $this->writer->accompliceOverride = [
            'accomplice_performance' => 'Paul tells me, "I\'m not into women, Dana and I are basically sisters," '
                .'in a soft voice, and offers to apologize.',
        ];

        try {
            app(GenerateOutline::class)->handle($story);
            $this->fail('An outline building the accomplice\'s act on orientation was stored.');
        } catch (ScriptWriterException $e) {
            $this->assertStringContainsString('not into women', $e->getMessage());
        }

        $this->assertSame(1, CostEntry::query()->where('story_id', $story->id)->count());
        $this->assertSame(0, $story->acts()->count());
        $this->assertNull($story->refresh()->accomplice_performance);
    }

    /** GREEN: the same act built on a role is stored. */
    public function test_the_same_act_built_on_a_role_is_stored(): void
    {
        $story = $this->draftStory();
        $this->writer->accompliceOverride = [
            'accomplice_performance' => 'Paul tells me, "Dana and I go back to primary school, I would never '
                .'come between you," and offers to apologize.',
        ];

        app(GenerateOutline::class)->handle($story);

        $this->assertStringContainsString('never come between you', (string) $story->refresh()->accomplice_performance);
    }

    public function test_two_accomplices_are_a_cast_problem(): void
    {
        $story = $this->draftStory();
        $this->writer->castOverride = [
            new CastMember('Erin Vasquez', CastRole::Narrator, 'the narrator'),
            new CastMember('Kyle Vasquez', CastRole::Antagonist, 'her husband'),
            new CastMember('Paul Ostrander', CastRole::Accomplice, 'the adviser'),
            new CastMember('Nina Brennan', CastRole::Accomplice, 'the adviser\'s partner'),
        ];

        $this->expectException(ScriptWriterException::class);
        $this->expectExceptionMessage('The cast has 2 accomplices');

        app(GenerateOutline::class)->handle($story);
    }

    // -- What the writers are told ---------------------------------------------

    public function test_the_outline_prompt_asks_for_the_stake_the_act_the_fall_and_the_thought(): void
    {
        $prompt = $this->flat($this->invoke($this->claude(), 'outlinePrompt', Story::factory()->single()->create(), 5));

        $this->assertStringContainsString('accomplice_motive: WHAT THE ACCOMPLICE WANTS FOR HIMSELF', $prompt);
        $this->assertStringContainsString('accomplice_performance: THE HARMLESS ACT HE PUTS ON FOR HER', $prompt);
        $this->assertStringContainsString('Quote at least one line he says TO THE NARRATOR', $prompt);
        $this->assertStringContainsString('BUILD THE ACT ON A ROLE, NEVER ON SEXUAL ORIENTATION', $prompt);
        $this->assertStringContainsString('accomplice_fall: WHERE THE ACCOMPLICE LOSES, AND HOW OFTEN. Not one humiliation', $prompt);
        $this->assertStringContainsString('starting no earlier than the departure act', $prompt);
        $this->assertStringContainsString('not the narrator winning early', $prompt);
        $this->assertStringContainsString('running_thought: THE NARRATOR\'S ONE PRIVATE JOKE', $prompt);
        $this->assertStringContainsString('ONE REFUSAL PAYS OFF THE running_thought', $prompt);
    }

    /**
     * The permission that became a motif, gone from all three places it lived:
     * the genre contract, the outline's betrayal-scene bullet and act 1.
     */
    public function test_no_prompt_says_the_accomplice_does_not_need_a_line(): void
    {
        $story = $this->outlinedStory();

        $texts = [
            'genre contract' => $this->invoke($this->claude(), 'genreGuidance', $story),
            'outline prompt' => $this->invoke($this->claude(), 'outlinePrompt', $story, 5),
            'act 1 prompt' => $this->actPromptFor($story, 1),
        ];

        foreach ($texts as $where => $text) {
            $flat = $this->flat($text);

            foreach (['need a line', 'not have to speak', 'never speaks in the whole', 'never says a word'] as $phrase) {
                $this->assertStringNotContainsString($phrase, $flat, "The {$where} still says \"{$phrase}\".");
            }
        }
    }

    public function test_the_genre_contract_gives_the_accomplice_a_stake_an_act_and_a_fall(): void
    {
        $guidance = $this->flat($this->invoke($this->claude(), 'genreGuidance', Story::factory()->single()->create()));

        $this->assertStringContainsString('THE ACCOMPLICE (when the cast has one)', $guidance);
        $this->assertStringContainsString('STAKE OF THEIR OWN, and it is not hers', $guidance);
        $this->assertStringContainsString('HE TALKS, to her and to the narrator', $guidance);
        $this->assertStringContainsString('Before the departure he wins every round with her', $guidance);
        $this->assertStringContainsString('not the narrator winning early', $guidance);
        $this->assertStringContainsString('BUILD THE ACT ON A ROLE. Never on sexual orientation', $guidance);
        $this->assertStringContainsString('THE ACCOMPLICE IS LOSING TOO', $guidance);
    }

    public function test_the_genre_contract_tags_thoughts_and_plants_the_running_one_after_the_beats(): void
    {
        $guidance = $this->flat($this->invoke($this->claude(), 'genreGuidance', Story::factory()->single()->create()));

        $this->assertStringContainsString('EVERY THOUGHT IS TAGGED AS A THOUGHT. One voice reads', $guidance);
        $this->assertStringContainsString('twenty-eight of its thirty thoughts', $guidance);
        $this->assertStringContainsString('the narrating ban below covers thoughts too', $guidance);
        $this->assertStringContainsString('after the five beats of the opening, never inside them', $guidance);
        $this->assertStringContainsString('with no profanity at all', $guidance);
    }

    // -- Routing: every act, and only the right acts -----------------------------

    public function test_every_act_prompt_carries_the_stake_the_act_and_the_thought(): void
    {
        $story = $this->outlinedStory();

        foreach ([1, 2, 3, 4, 5] as $sequence) {
            $prompt = $this->actPromptFor($story, $sequence);

            $this->assertStringContainsString((string) $story->accomplice_motive, $prompt, "Act {$sequence} lacks the motive.");
            $this->assertStringContainsString((string) $story->accomplice_performance, $prompt, "Act {$sequence} lacks the act.");
            $this->assertStringContainsString((string) $story->running_thought, $prompt, "Act {$sequence} lacks the thought.");
        }
    }

    /**
     * THE ROUTING IS THE REASON FOR THREE COLUMNS. An escalation act told how
     * he ends spends it early — the narrator winning early, which the arc
     * decision rules out. The fall reaches the last three acts and no other.
     */
    public function test_the_fall_reaches_the_last_three_acts_and_no_escalation_act(): void
    {
        $story = $this->outlinedStory();
        $fall = (string) $story->accomplice_fall;

        foreach ($story->acts()->orderBy('sequence')->get() as $act) {
            $prompt = $this->actPromptFor($story, $act->sequence);

            if ($act->phase === ActPhase::Escalation) {
                $this->assertStringNotContainsString($fall, $prompt, "Escalation act {$act->sequence} was handed his fall.");
                $this->assertStringContainsString('THE ACCOMPLICE IS IN THIS ACT AND HE TALKS', $prompt);
            } else {
                $this->assertStringContainsString($fall, $prompt, "The {$act->phase->value} act was not handed his fall.");
            }
        }
    }

    public function test_act_one_puts_his_line_and_the_thought_in_chapter_one_not_the_beats(): void
    {
        $story = $this->outlinedStory();
        $prompt = $this->actPromptFor($story, 1);

        $this->assertStringContainsString('in the room for all of it, AND HE TALKS', $prompt);
        $this->assertStringContainsString('PLANT THE RUNNING THOUGHT IN THIS CHAPTER', $prompt);
        $this->assertStringContainsString('The accomplice\'s act and the running thought start there too, in chapter one', $prompt);
        $this->assertStringContainsString('The accomplice talks in his act, and the narrator\'s running thought is planted. NOT HERE', $prompt);
    }

    public function test_the_refusal_act_pays_off_the_thought(): void
    {
        $story = $this->outlinedStory();
        $refusal = $story->acts()->where('phase', ActPhase::Refusal)->firstOrFail();
        $prompt = $this->actPromptFor($story, $refusal->sequence);

        $this->assertStringContainsString('ONE OF THE REFUSALS PAYS OFF THE RUNNING THOUGHT', $prompt);
        $this->assertStringContainsString('THE ACCOMPLICE\'S FALL COMPLETES HERE', $prompt);
    }

    /** A story with neither gets exactly the prompt it had. */
    public function test_a_story_without_an_accomplice_or_a_thought_gets_the_prompt_it_had(): void
    {
        $story = $this->outlinedStory();
        $story->update([
            'accomplice_motive' => null,
            'accomplice_performance' => null,
            'accomplice_fall' => null,
            'running_thought' => null,
        ]);
        $story->refresh();

        foreach ([1, 3, 5] as $sequence) {
            $prompt = $this->actPromptFor($story, $sequence);

            $this->assertStringNotContainsString('THE ACCOMPLICE IS', $prompt);
            $this->assertStringNotContainsString('ACCOMPLICE\'S FALL', $prompt);
            $this->assertStringNotContainsString('The accomplice — what he wants', $prompt);
            $this->assertStringNotContainsString('RUNNING THOUGHT', $prompt);
            $this->assertStringNotContainsString('running thought start there too', $prompt);

            // The no-accomplice branch of act 1 is its own sentence, and the
            // silence permission could come back there alone: a drill that
            // restored it in this branch stayed green until this line existed.
            $this->assertStringNotContainsString('need a line', $prompt);
        }
    }

    /** The fake records what it was handed, so a field dropped on the way fails here. */
    public function test_every_act_writer_call_is_handed_all_four(): void
    {
        $story = $this->outlinedStory();
        $story->approveGate(Gate::Outline);
        $this->writer->calls = [];

        app(GenerateActScripts::class)->handle($story->refresh());

        $calls = collect($this->writer->calls)->where('method', 'actScript');

        $this->assertNotEmpty($calls);

        foreach (['accomplice_motive', 'accomplice_performance', 'accomplice_fall', 'running_thought'] as $field) {
            $this->assertTrue(
                $calls->every(fn (array $call): bool => trim((string) ($call[$field] ?? '')) !== ''),
                "An act was written without {$field}.",
            );
        }
    }

    public function test_the_last_three_scene_calls_are_told_he_is_in_the_room_when_he_loses(): void
    {
        $story = $this->outlinedStory();
        $story->approveGate(Gate::Outline);
        app(GenerateActScripts::class)->handle($story->refresh());

        Character::factory()->for($story)->create(['name' => 'Erin Vasquez']);
        Character::factory()->for($story)->create(['name' => 'Paul Ostrander']);

        $this->writer->calls = [];
        app(DraftScenes::class)->handle($story->refresh());

        $calls = collect($this->writer->calls)->where('method', 'scenes')->keyBy('act');
        $acts = $story->acts()->orderBy('sequence')->get()->keyBy('sequence');

        foreach ($acts as $sequence => $act) {
            $context = $this->invoke($this->claude(), 'sceneContext', $story->refresh(), $act);

            if ($act->phase === ActPhase::Escalation) {
                $this->assertNull($calls[$sequence]['accomplice_fall']);
                $this->assertStringNotContainsString('THE ACCOMPLICE\'S FALL', $context);
            } else {
                $this->assertNotSame('', trim((string) $calls[$sequence]['accomplice_fall']));
                $this->assertStringContainsString('THE ACCOMPLICE\'S FALL (he is in the room when he loses):', $context);
            }
        }
    }

    public function test_a_fork_carries_all_four_and_the_age_of_its_outline(): void
    {
        $source = $this->outlinedStory();
        $source->forceFill(['outlined_before_accomplice_and_thought' => true])->save();

        Artisan::call('story:fork', ['story' => $source->slug]);

        $fork = Story::query()->whereKeyNot($source->id)->latest('id')->firstOrFail();

        foreach (['accomplice_motive', 'accomplice_performance', 'accomplice_fall', 'running_thought'] as $field) {
            $this->assertSame($source->{$field}, $fork->{$field});
        }

        $this->assertTrue($fork->outlined_before_accomplice_and_thought);
    }

    // -- Gate 1 --------------------------------------------------------------

    public function test_a_healthy_outline_reads_ok_and_names_what_the_fall_exposes_and_where_the_thought_lands(): void
    {
        $review = app(ValidateOutlineSpine::class)->handle($this->outlinedStory());

        foreach (['accomplice_motive', 'accomplice_performance', 'accomplice_fall', 'running_thought'] as $field) {
            $this->assertSame('ok', $review['spine'][$field]['state'], "{$field} is not ok.");
        }

        $this->assertNotEmpty($review['spine']['accomplice_fall']['exposes'] ?? '');
        // The refusal sentence that says it aloud, as a badge label (cut at 60).
        $this->assertStringStartsWith('Then I told her the family helps family fund', (string) ($review['spine']['running_thought']['pays_off'] ?? ''));

        $warnings = mb_strtolower(implode(' ', $review['warnings']));
        $this->assertStringNotContainsString('accomplice', $warnings);
        $this->assertStringNotContainsString('running thought', $warnings);
        $this->assertSame([], $review['problems']);
    }

    /** A mother-in-law does it with nobody: empty is the right answer, not missing. */
    public function test_a_cast_with_no_accomplice_reads_none_rather_than_missing(): void
    {
        $story = $this->outlinedStory();
        $story->update([
            'outline_cast' => [
                ['name' => 'Erin Vasquez', 'role' => 'narrator', 'relationship' => 'the narrator'],
                ['name' => 'Kyle Vasquez', 'role' => 'antagonist', 'relationship' => 'her husband'],
            ],
            'accomplice_motive' => '',
            'accomplice_performance' => '',
            'accomplice_fall' => '',
        ]);

        $review = app(ValidateOutlineSpine::class)->handle($story->refresh());

        $this->assertSame('none', $review['spine']['accomplice_fall']['state']);
        $this->assertStringNotContainsString('Accomplice', implode(' ', $review['problems']));
    }

    public function test_an_accomplice_in_the_cast_with_no_arc_is_a_problem(): void
    {
        $story = $this->outlinedStory();
        $story->update(['accomplice_motive' => '', 'accomplice_performance' => '', 'accomplice_fall' => '']);

        $review = app(ValidateOutlineSpine::class)->handle($story->refresh());
        $problems = implode(' ', $review['problems']);

        $this->assertStringContainsString('Accomplice — what he wants is missing', $problems);
        $this->assertStringContainsString('Accomplice — his fall is missing', $problems);
    }

    public function test_a_missing_thought_is_a_problem_on_an_outline_that_was_asked(): void
    {
        $story = $this->outlinedStory();
        $story->update(['running_thought' => '']);

        $review = app(ValidateOutlineSpine::class)->handle($story->refresh());

        $this->assertStringContainsString('Running thought is missing', implode(' ', $review['problems']));
    }

    public function test_an_outline_written_before_both_were_asked_says_so_once(): void
    {
        $story = $this->outlinedStory();
        $story->update([
            'accomplice_motive' => '',
            'accomplice_performance' => '',
            'accomplice_fall' => '',
            'running_thought' => '',
        ]);
        $story->forceFill(['outlined_before_accomplice_and_thought' => true])->save();

        $review = app(ValidateOutlineSpine::class)->handle($story->refresh());

        $this->assertSame([], $review['problems']);
        $this->assertSame('absent', $review['spine']['running_thought']['state']);
        $this->assertSame('absent', $review['spine']['accomplice_fall']['state']);
        $this->assertSame(1, substr_count(
            implode(' ', $review['warnings']),
            'generated before it was asked for the accomplice\'s stake',
        ));
    }

    public function test_typing_one_field_in_by_hand_brings_the_checks_back(): void
    {
        $story = $this->outlinedStory();
        $story->update([
            'accomplice_motive' => '',
            'accomplice_performance' => '',
            'accomplice_fall' => '',
            'running_thought' => 'Every time she says it I add a dollar in my head to the fund.',
        ]);
        $story->forceFill(['outlined_before_accomplice_and_thought' => true])->save();

        $review = app(ValidateOutlineSpine::class)->handle($story->refresh());

        $this->assertStringNotContainsString('generated before it was asked for the accomplice', implode(' ', $review['warnings']));
        $this->assertStringContainsString('Accomplice — his fall is missing', implode(' ', $review['problems']));
    }

    public function test_gate_one_edits_the_arc_and_reports_an_act_built_on_orientation(): void
    {
        $story = $this->outlinedStory();

        Livewire::test(OutlineGate::class, ['story' => $story])
            ->assertSee('Accomplice — the act he puts on')
            ->assertSee('Running thought')
            ->assertSee('exposes')
            ->assertSee('said aloud in')
            ->set('spine.accomplice_performance', 'Paul speaks in a sissy voice and says, "I am not into women."')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSee('builds the accomplice&#039;s act on orientation', false);

        $this->assertStringContainsString('sissy voice', (string) $story->fresh()->accomplice_performance);
    }

    public function test_gate_one_shows_none_for_a_cast_with_no_accomplice(): void
    {
        $story = $this->outlinedStory();
        $story->update([
            'outline_cast' => [
                ['name' => 'Erin Vasquez', 'role' => 'narrator', 'relationship' => 'the narrator'],
                ['name' => 'Kyle Vasquez', 'role' => 'antagonist', 'relationship' => 'her husband'],
            ],
            'accomplice_motive' => null,
            'accomplice_performance' => null,
            'accomplice_fall' => null,
        ]);

        Livewire::test(OutlineGate::class, ['story' => $story->refresh()])
            ->assertSee('no accomplice in the cast');
    }

    // -- Fixtures ------------------------------------------------------------

    private function draftStory(): Story
    {
        return Story::factory()->status(StoryStatus::Draft)->create([
            'format' => StoryFormat::Single,
            'locale_profile' => 'en-US',
            'target_duration_min' => 30,
            'target_duration_max' => 40,
            'premise' => 'My sister billed me for her entire wedding over eleven months and told the '
                .'family I had offered, with the family adviser smoothing it over.',
        ]);
    }

    private function outlinedStory(): Story
    {
        $story = $this->draftStory();

        app(GenerateOutline::class)->handle($story);

        $this->writer->calls = [];
        $this->writer->castOverride = null;
        $this->writer->accompliceOverride = [];

        return $story->refresh();
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

        return $this->invoke(
            $this->claude(),
            'actPrompt',
            $story,
            $outline[$sequence - 1],
            $outline,
            $sequence === 1 ? [] : ['Act 1 happened.'],
            985,
        );
    }

    /** Whitespace collapsed: the prompts are wrapped heredocs, and a needle must not straddle a line. */
    private function flat(string $text): string
    {
        return (string) preg_replace('/\s+/u', ' ', $text);
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
