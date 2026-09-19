<?php

namespace Tests\Feature\Providers;

use Anthropic\Client;
use App\Actions\DraftScenes;
use App\Actions\GenerateActScripts;
use App\Actions\GenerateOutline;
use App\Actions\ValidateOutlineSpine;
use App\Contracts\ScriptWriter;
use App\Enums\ActPhase;
use App\Enums\Gate;
use App\Enums\StoryFormat;
use App\Enums\StoryStatus;
use App\Livewire\Gates\OutlineGate;
use App\Models\Act;
use App\Models\Character;
use App\Models\Story;
use App\Services\Claude\ClaudeScriptWriter;
use App\Services\Fake\FakeScriptWriter;
use App\Support\CharacterTextGuard;
use App\Support\LocaleGuard;
use App\Support\Providers\ActOutline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Livewire\Livewire;
use ReflectionMethod;
use Tests\TestCase;

/**
 * THE BETRAYAL IS A SCENE, NOT A DISCOVERY — and every consumer of the field
 * that says so.
 *
 * Seven stories (23, 25, 28, 29, 30, 31, 32) found their betrayal or heard it
 * at a kitchen table, first said the antagonist's justification in public at
 * 9-11 minutes or never, and never put the person it was done with in a room
 * with the narrator. The reference stages all three at 1:31, straight after
 * the cold open. See CLAUDE.md 3e.
 *
 * The consumer question, asked in the change rather than a phase later: the
 * outline writes it, Gate 1 checks it and edits it, the act 1 call stages it,
 * every act call carries it, the act 1 scene call draws it, the refusal check
 * answers it, and a fork copies it. Each has an arrival assertion below. The
 * red/green pairs for the four Gate 1 checks live in GuardsGoRedTest, with the
 * other guards.
 */
class BetrayalSceneTest extends TestCase
{
    use RefreshDatabase;

    private FakeScriptWriter $writer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->writer = app(ScriptWriter::class);
    }

    // -- Produced and stored -------------------------------------------------

    public function test_the_outline_produces_and_stores_the_betrayal_scene(): void
    {
        $story = $this->outlinedStory();

        $this->assertNotSame('', trim((string) $story->betrayal_scene));
        $this->assertFalse($story->outlined_before_betrayal_scene);
    }

    public function test_the_outline_schema_requires_it(): void
    {
        $schema = $this->invoke($this->claude(), 'outlineSchema');

        $this->assertArrayHasKey('betrayal_scene', $schema['properties']);
        $this->assertContains('betrayal_scene', $schema['required']);
    }

    /**
     * A regenerated outline is not the outline the migration froze, so the
     * flag goes with it — and an empty answer from the new one is MISSING.
     */
    public function test_regenerating_an_outline_clears_the_unasked_flag(): void
    {
        $story = $this->draftStory();
        $story->forceFill(['outlined_before_betrayal_scene' => true])->save();

        app(GenerateOutline::class)->handle($story);

        $this->assertFalse($story->refresh()->outlined_before_betrayal_scene);
    }

    // -- What the outline writer is told -------------------------------------

    public function test_the_outline_prompt_asks_for_the_scene_and_moves_the_second_landing(): void
    {
        $prompt = $this->invoke($this->claude(), 'outlinePrompt', Story::factory()->single()->create(), 5);

        $this->assertStringContainsString('betrayal_scene: THE BETRAYAL AS A SCENE, NOT A DISCOVERY', $prompt);
        // The permission that became a motif, gone; the accomplice speaks in
        // his act instead (3g).
        // And to the narrator: story 38's premise roll had him speak to the
        // room in all three candidates while the question named no addressee.
        $this->assertStringContainsString('AND HAVE THEM SPEAK TO THE NARRATOR in the act you wrote in accomplice_performance', $prompt);
        $this->assertStringNotContainsString('do not have to speak', $prompt);
        $this->assertStringNotContainsString('never says a word', $prompt);
        $this->assertStringContainsString('ALOUD, to the narrator\'s face', $prompt);
        $this->assertStringContainsString('THE BETRAYAL SCENE KEEPS IT', $prompt);
        $this->assertStringContainsString('hands back the sentence she said in the betrayal scene', $prompt);

        // The instruction stories 29-32 obeyed, in every place it lived.
        $this->assertStringNotContainsString('act 2 or 3', $prompt);
    }

    /**
     * Beat 4 still refuses a confrontation in the opening, and now says that a
     * LOST round is not the reckoning — otherwise "the confrontation is the
     * final act" reaches past the hook into chapter one.
     */
    public function test_beat_four_separates_a_lost_round_from_the_reckoning(): void
    {
        $prompt = $this->invoke($this->claude(), 'outlinePrompt', Story::factory()->single()->create(), 5);

        $this->assertStringContainsString('ONE small, cold action by the narrator. Not a confrontation', $prompt);
        $this->assertStringContainsString('THE RECKONING is the final act', $prompt);
        $this->assertStringContainsString('A round the narrator LOSES is not the reckoning', $prompt);
        $this->assertStringNotContainsString('The confrontation is the final act', $prompt);
    }

    // -- The genre contract --------------------------------------------------

    public function test_the_genre_contract_opens_the_escalation_on_the_scene(): void
    {
        $guidance = $this->invoke($this->claude(), 'genreGuidance', Story::factory()->single()->create());

        $this->assertStringContainsString('IT OPENS ON THE BETRAYAL AS A SCENE, NOT A', $guidance);
        // Flattened: the heredoc wraps, and a needle across a line break is
        // the self-defeating check CLAUDE.md numbers eleventh.
        $flat = preg_replace('/\s+/u', ' ', $guidance);
        $this->assertStringContainsString('an accomplice with a stake speaks here, in his act', $flat);
        $this->assertStringNotContainsString('does not need a line', $flat);
        $this->assertStringNotContainsString('never speaks in the whole video', $flat);
        $this->assertStringContainsString('THE BETRAYAL SCENE IS PUBLIC TOO, AND IT IS NOT THIS PAYOFF', $guidance);
    }

    /**
     * The caption reading, built in as a DISTINCTION rather than a swapped
     * example: 2:56 is narration, 3:30 is dialogue, and the pairing is the
     * register.
     */
    public function test_the_head_is_funny_and_the_mouth_is_controlled(): void
    {
        $guidance = preg_replace('/\s+/u', ' ', $this->invoke($this->claude(), 'genreGuidance', Story::factory()->single()->create()));

        // Renamed off the crude example (3g): the rule is the channel, and
        // "Marry you my ass" is one instance of what can travel in it.
        $this->assertStringContainsString('THE HEAD IS FUNNY; THE MOUTH IS CONTROLLED', $guidance);
        $this->assertStringNotContainsString('THE CRUDE LINE IS WHAT THEY THINK', $guidance);
        $this->assertStringContainsString('THE THOUGHT DOES NOT HAVE TO BE CRUDE, AND MOSTLY SHOULD NOT BE', $guidance);

        // The first reference's pairing survives as the example it is.
        $this->assertStringContainsString('THINKS, to the listener: "Our wedding? Marry you my ass."', $guidance);
        $this->assertStringContainsString('new boy toy?"', $guidance);
        $this->assertStringContainsString('Never put the thought in the narrator\'s mouth, and never let the spoken line go crude', $guidance);

        // The misreading, in the wording it had.
        $this->assertStringNotContainsString('narrator says "Marry you my ass"', $guidance);
        $this->assertStringNotContainsString('crude and funny — "marry you my ass"', $guidance);
    }

    // -- The epilogue ---------------------------------------------------------

    /**
     * "No epilogue" contradicted CLAUDE.md 3d: the transcript closes on a
     * year-later chapter. Since 2026-09-19 that is one of two chosen endings
     * — the narrator's new life, as a scene — and never stacked with the
     * antagonist's chapter. The ending's own contract is EndingChoiceTest;
     * this holds that the old "end within a few sentences" did not come back.
     */
    public function test_the_refusal_act_ends_on_a_year_later_chapter_in_the_narrators_voice(): void
    {
        $ending = $this->endingFor(ActPhase::Refusal);

        $this->assertStringContainsString('THE NARRATOR\'S NEW LIFE', $ending);
        $this->assertStringContainsString('about a year after the refusal', $ending);
        // The factory story's ending is the new life, so nobody else speaks.
        $this->assertStringContainsString('No chapter is told from anybody else\'s point of view', $ending);
        $this->assertStringNotContainsString('no epilogue', $ending);
        $this->assertStringNotContainsString('End within a few sentences of the last refusal', $ending);

        $guidance = $this->invoke($this->claude(), 'genreGuidance', Story::factory()->single()->create());

        $this->assertStringContainsString('ONE ENDING, about a year on', $guidance);
        $this->assertStringContainsString('the only one who didn\'t show up', preg_replace('/\s+/', ' ', $guidance));
        $this->assertStringNotContainsString('THE END, within a few sentences', $guidance);
    }

    /**
     * The phaseless branch keeps the old shape on purpose — it is the shape
     * the pre-phase outlines were built to — and that is asserted so the
     * difference reads as a decision rather than as a missed copy.
     */
    public function test_the_phaseless_final_act_keeps_the_shape_its_outline_was_built_to(): void
    {
        $story = Story::factory()->single()->create(['exposure_moment' => 'At the banquet.']);
        $act = new ActOutline(sequence: 3, title: 'T', summary: 'S', phase: null);

        $ending = $this->invoke($this->claude(), 'endingFor', $story, $act, true);

        $this->assertStringContainsString('no epilogue about what everyone learned', $ending);
    }

    // -- Every consumer --------------------------------------------------------

    public function test_act_one_is_handed_the_scene_as_chapter_one(): void
    {
        $story = $this->outlinedStory();

        $prompt = $this->actPromptFor($story, 1);

        $this->assertStringContainsString('CHAPTER ONE IS THE BETRAYAL SCENE', $prompt);
        $this->assertStringContainsString((string) $story->betrayal_scene, $prompt);
        // The fake outline declares an accomplice with an act, so he talks.
        $this->assertStringContainsString('in the room for all of it, AND HE TALKS', $prompt);
        $this->assertStringNotContainsString('do not need a line', $prompt);
        $this->assertStringContainsString('the funny version stays in their head, tagged as a thought', $prompt);
    }

    public function test_a_later_act_carries_it_in_the_spine_and_is_not_told_to_stage_it(): void
    {
        $story = $this->outlinedStory();

        $prompt = $this->actPromptFor($story, 2);

        $this->assertStringContainsString("Where she first said it aloud, to the narrator's face, in chapter one:", $prompt);
        $this->assertStringNotContainsString('CHAPTER ONE IS THE BETRAYAL SCENE', $prompt);
    }

    public function test_a_story_with_no_scene_gets_the_opening_it_had(): void
    {
        $story = $this->outlinedStory();
        $story->update(['betrayal_scene' => null]);

        $prompt = $this->actPromptFor($story->refresh(), 1);

        $this->assertStringNotContainsString('CHAPTER ONE IS THE BETRAYAL SCENE', $prompt);
        $this->assertStringNotContainsString('Where she first said it aloud', $prompt);
        $this->assertStringContainsString('Five beats, in this order', $prompt);
    }

    /** The fake records what it was handed, so a dropped field fails here. */
    public function test_every_act_writer_call_is_handed_the_scene(): void
    {
        $story = $this->outlinedStory();
        $story->approveGate(Gate::Outline);
        $this->writer->calls = [];

        app(GenerateActScripts::class)->handle($story->refresh());

        $calls = collect($this->writer->calls)->where('method', 'actScript');

        $this->assertNotEmpty($calls);
        $this->assertTrue(
            $calls->every(fn (array $call): bool => trim((string) $call['betrayal_scene']) !== ''),
            'An act was written without knowing the justification was already said aloud in chapter one.',
        );
    }

    public function test_the_act_one_scene_call_is_told_who_is_in_the_room(): void
    {
        $story = $this->outlinedStory();
        $story->approveGate(Gate::Outline);
        app(GenerateActScripts::class)->handle($story->refresh());

        Character::factory()->for($story)->create(['name' => 'Dana Whitfield']);
        Character::factory()->for($story)->create(['name' => 'Erin Whitfield']);

        $this->writer->calls = [];
        app(DraftScenes::class)->handle($story->refresh());

        $calls = collect($this->writer->calls)->where('method', 'scenes')->keyBy('act');

        $this->assertNotSame('', trim((string) $calls[1]['betrayal_scene']));
        $this->assertNull($calls[2]['betrayal_scene']);

        // The prompt half: the fake reads the model, so it cannot see a line
        // dropped from the real builder. This can.
        $act1 = $story->acts()->where('sequence', 1)->first();
        $act2 = $story->acts()->where('sequence', 2)->first();

        $this->assertStringContainsString(
            'THE BETRAYAL SCENE (who is in the room):',
            $this->invoke($this->claude(), 'sceneContext', $story->refresh(), $act1),
        );
        $this->assertStringNotContainsString(
            'THE BETRAYAL SCENE',
            $this->invoke($this->claude(), 'sceneContext', $story, $act2),
        );
    }

    public function test_a_refusal_can_answer_the_betrayal_scene_by_name(): void
    {
        $story = $this->outlinedStory();
        $story->update([
            'refusal' => 'When Dana reached me she asked me to come home. I asked whether I was also paying '
                .'for the soup at the engagement dinner, and whether Mark would like to look at his plate '
                .'again, and I said no.',
        ]);

        $review = app(ValidateOutlineSpine::class)->handle($story->refresh());

        $this->assertSame('the betrayal scene', $review['spine']['refusal']['answers'] ?? null);
    }

    public function test_a_fork_carries_the_scene_and_the_age_of_its_outline(): void
    {
        $source = $this->outlinedStory();
        $source->forceFill(['outlined_before_betrayal_scene' => true])->save();

        Artisan::call('story:fork', ['story' => $source->slug]);

        $fork = Story::query()->whereKeyNot($source->id)->latest('id')->firstOrFail();

        $this->assertSame($source->betrayal_scene, $fork->betrayal_scene);
        $this->assertTrue($fork->outlined_before_betrayal_scene);
    }

    // -- Gate 1 --------------------------------------------------------------

    public function test_a_healthy_scene_names_the_sentence_she_says_aloud(): void
    {
        $review = app(ValidateOutlineSpine::class)->handle($this->outlinedStory());

        $this->assertSame('ok', $review['spine']['betrayal_scene']['state']);
        $this->assertNotEmpty($review['spine']['betrayal_scene']['says'] ?? '');
        $this->assertStringNotContainsString('betrayal scene', mb_strtolower(implode(' ', $review['warnings'])));
    }

    public function test_a_missing_scene_is_a_problem_on_an_outline_that_was_asked(): void
    {
        $story = $this->outlinedStory();
        $story->update(['betrayal_scene' => '']);

        $review = app(ValidateOutlineSpine::class)->handle($story->refresh());

        $this->assertStringContainsString('Betrayal scene is missing', implode(' ', $review['problems']));
        $this->assertSame('missing', $review['spine']['betrayal_scene']['state']);
    }

    /** Stories 22-32: said once, as what it is, and absent rather than missing. */
    public function test_an_outline_written_before_the_scene_was_asked_says_so_once(): void
    {
        $story = $this->outlinedStory();
        $story->update(['betrayal_scene' => '']);
        $story->forceFill(['outlined_before_betrayal_scene' => true])->save();

        $review = app(ValidateOutlineSpine::class)->handle($story->refresh());

        $this->assertSame([], $review['problems']);
        $this->assertSame('absent', $review['spine']['betrayal_scene']['state']);
        $this->assertSame(1, substr_count(
            implode(' ', $review['warnings']),
            'generated before it was asked for the betrayal as a scene',
        ));
    }

    public function test_typing_the_scene_in_by_hand_brings_the_checks_back(): void
    {
        $story = $this->outlinedStory();
        $story->forceFill(['outlined_before_betrayal_scene' => true])->save();
        $story->update(['betrayal_scene' => 'At our kitchen table Dana told me the venue was mine to pay.']);

        $review = app(ValidateOutlineSpine::class)->handle($story->refresh());

        $warnings = implode(' ', $review['warnings']);

        $this->assertStringNotContainsString('generated before it was asked for the betrayal', $warnings);
        $this->assertStringContainsString('The betrayal scene names nobody watching', $warnings);
    }

    public function test_gate_one_lets_the_operator_write_the_scene_and_shows_what_she_says(): void
    {
        $story = $this->outlinedStory();

        Livewire::test(OutlineGate::class, ['story' => $story])
            ->assertSee('Betrayal scene')
            ->assertSee('says aloud')
            ->set('spine.betrayal_scene', 'At the engagement dinner, in front of twenty relatives, Dana '
                .'said it to my face: I have no kids and no mortgage, and family helps family.')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertStringContainsString('twenty relatives', (string) $story->fresh()->betrayal_scene);
    }

    public function test_an_anthology_skips_the_act_one_check(): void
    {
        $story = $this->outlinedStory();
        $story->update(['format' => StoryFormat::Anthology]);
        $story->acts()->where('sequence', 1)->update(['summary' => 'On the telephone, Dana argues.']);

        $review = app(ValidateOutlineSpine::class)->handle($story->refresh());

        $this->assertStringNotContainsString('does not stage the betrayal scene', implode(' ', $review['warnings']));
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
                .'family I had offered.',
        ]);
    }

    private function outlinedStory(): Story
    {
        $story = $this->draftStory();

        app(GenerateOutline::class)->handle($story);

        $this->writer->calls = [];

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

    private function endingFor(ActPhase $phase): string
    {
        $story = Story::factory()->single()->create([
            'departure' => 'She left on a Tuesday.',
            'reversal_beats' => 'She paid a man. Then she went to his aunt.',
            'exposure_moment' => 'At the banquet.',
            'refusal' => 'No.',
        ]);

        $act = new ActOutline(sequence: 5, title: 'T', summary: 'S', phase: $phase);

        return $this->invoke($this->claude(), 'endingFor', $story, $act, true);
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
