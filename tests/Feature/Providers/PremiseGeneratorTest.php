<?php

namespace Tests\Feature\Providers;

use Anthropic\Client;
use App\Actions\GeneratePremises;
use App\Actions\ValidateOutlineSpine;
use App\Contracts\ScriptWriter;
use App\Enums\CastRole;
use App\Enums\CostCategory;
use App\Enums\CostUnit;
use App\Enums\RenderJobStatus;
use App\Enums\RenderStage;
use App\Enums\StoryEnding;
use App\Enums\StoryFormat;
use App\Enums\StoryStatus;
use App\Jobs\GeneratePremisesJob;
use App\Livewire\Gates\OutlineGate;
use App\Livewire\Stories\NewStory;
use App\Models\Act;
use App\Models\Character;
use App\Models\RenderJob;
use App\Models\Story;
use App\Services\Claude\ClaudeScriptWriter;
use App\Services\Fake\FakeScriptWriter;
use App\Support\CharacterTextGuard;
use App\Support\LocaleGuard;
use App\Support\NarratorVoice;
use App\Support\PremiseIdea;
use App\Support\Providers\CastMember;
use App\Support\Providers\PremiseCandidate;
use App\Support\Providers\ProviderUsage;
use App\Support\SpineQuestions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use ReflectionMethod;
use Tests\TestCase;

/**
 * THE PREMISE GENERATOR: AN IDEA -> THREE PREMISES, CHECKED BEFORE THE PICK.
 *
 * Built 2026-09-19. What each case holds, in the order the build went:
 *
 *  - The spine questions have ONE copy (SpineQuestions), called by both the
 *    outline prompt and the premise prompt. The outline prompt was captured
 *    before the move and compared after it, byte-identical but one space; the
 *    guard here is that the question text is no longer in the writer at all,
 *    so a pasted-back copy goes red.
 *  - The premise prompt asks what the operator listed and nothing twice.
 *  - Three candidates, one array of identical items, no labelled slots.
 *  - The Action bills, keeps a short roll, and refuses outside a draft.
 *  - Every Gate 1 check the premise can be held to, RED/GREEN, from a fake
 *    whose default candidates pass them all — so each red half breaks one
 *    thing and the green half is the same candidate unbroken.
 *  - The revenge line: the generator's report, and the second reading.
 *  - The page: shown on a draft, candidates in red rather than dropped, a pick
 *    that does not move the story, hidden on an anthology with a reason.
 *  - The form: the narrator is required and picks the voice; an idea queues
 *    nothing.
 */
class PremiseGeneratorTest extends TestCase
{
    use RefreshDatabase;

    private FakeScriptWriter $writer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->writer = app(ScriptWriter::class);
    }

    // -- One copy of each question -------------------------------------------

    public function test_both_prompts_ask_the_spine_questions_from_the_one_copy(): void
    {
        $story = $this->draftStory();
        $premise = $this->invoke($this->claude(), 'premisePrompt', $story, 'My wife cheated at her own party.', 3);

        // Against the SCHEMA's spine, not against OUTLINE_ORDER: a test that
        // walked the list under test went green when the drill dropped
        // `departure` from it, because the assertion shrank with the list.
        // Every string property the outline schema requires, less the ones
        // that are not spine questions, must be asked in the prompt.
        //
        // For BOTH endings: the regret is asked only on the antagonist's, and
        // the schema and the prompt have to agree about that on each.
        foreach (StoryEnding::cases() as $ending) {
            $story->ending = $ending;
            $outline = $this->invoke($this->claude(), 'outlinePrompt', $story, 5);
            $schema = $this->invoke($this->claude(), 'outlineSchema', $story->format, $ending);
            $spine = array_values(array_diff($schema['required'], ['title', 'narrator', 'cast', 'acts']));

            $expected = $ending->asksForRegret()
                ? SpineQuestions::OUTLINE_ORDER
                : array_values(array_diff(SpineQuestions::OUTLINE_ORDER, ['antagonist_regret']));

            $this->assertNotEmpty($spine);
            $this->assertEqualsCanonicalizing($expected, $spine, "{$ending->value}: every spine field the schema requires is asked, and nothing else.");
            $this->assertSame($ending->asksForRegret(), array_key_exists('antagonist_regret', $schema['properties']), "{$ending->value}: the regret property.");

            foreach ($spine as $field) {
                $this->assertStringContainsString(SpineQuestions::bullet($field, $story), $outline, "{$ending->value} outline: {$field}");
            }

            if (! $ending->asksForRegret()) {
                $this->assertStringNotContainsString(SpineQuestions::bullet('antagonist_regret', $story), $outline, 'The new life is not asked for her regret.');
            }
        }

        foreach (PremiseCandidate::FIELDS as $field) {
            $this->assertStringContainsString(SpineQuestions::bullet($field, $story), $premise, "premise: {$field}");
        }
    }

    /**
     * RED if a question is pasted back into the writer: the drift the refactor
     * exists to prevent. Comment-stripped, so a docblock naming a phrase is
     * not read as a copy.
     */
    public function test_no_spine_question_text_lives_in_the_writer_any_more(): void
    {
        $code = $this->codeOf(app_path('Services/Claude/ClaudeScriptWriter.php'));

        // Phrases found ONLY in a spine question. Two tempting ones are not:
        // "WHERE THEY WENT IS NOT ANNOUNCED" is also the genre contract's own
        // departure movement, and the hook's five beats have a second,
        // pre-existing copy in act 1's hookInstruction() written for the act
        // writer — recorded in CLAUDE.md, not folded in here, because folding
        // it would change the act 1 prompt.
        foreach (['THE BETRAYAL AS A SCENE, NOT A DISCOVERY', 'MUST PRODUCE IN PERSON', 'THE HARMLESS ACT HE PUTS ON FOR HER',
            'WHERE THE ACCOMPLICE LOSES, AND HOW OFTEN', 'DO NOT START AT THE BEGINNING',
            'LAST CHANCE, AND WHAT A YEAR LOOKS LIKE', 'WHICH EARLIER MOMENT EACH REFUSAL ANSWERS'] as $phrase) {
            $this->assertStringNotContainsString($phrase, $code, "\"{$phrase}\" is back in ClaudeScriptWriter: a second copy of a spine question.");
            $this->assertStringContainsString($phrase, $this->codeOf(app_path('Support/SpineQuestions.php')));
        }
    }

    // -- The premise prompt ----------------------------------------------------

    public function test_the_premise_prompt_asks_for_what_the_operator_listed(): void
    {
        $story = $this->draftStory(['voice_id' => NarratorVoice::voiceFor('male')]);
        $prompt = preg_replace('/\s+/', ' ', $this->invoke($this->claude(), 'premisePrompt', $story, 'my CEO wife cheated so I made them pay', 3));

        $this->assertStringContainsString('my CEO wife cheated so I made them pay', $prompt);
        $this->assertStringContainsString('THE NARRATOR IS A MAN', $prompt);
        $this->assertStringContainsString('FRIENDS', $prompt);
        $this->assertStringContainsString('No enumerated history', $prompt);
        $this->assertStringContainsString('NAMES EVERY PERSON IN YOUR CAST', $prompt);
        $this->assertStringContainsString('at most '.config('cast.premise_named').' besides the narrator', $prompt);
        $this->assertStringContainsString('PLACES THE FUTURE PARTNER BEFORE THE DEPARTURE', $prompt);
        $this->assertStringContainsString('said TO THE NARRATOR\'S FACE rather than to the table or the room', $prompt);
        $this->assertStringContainsString('DIFFER IN TWO THINGS AND NOTHING ELSE: the occasion', $prompt);
        $this->assertStringContainsString('what only the narrator can produce in person', $prompt);
        $this->assertStringContainsString('idea_was_revenge_shaped', $prompt);
        $this->assertStringContainsString('FIRST, THE NARRATOR', $prompt, 'The cast question is called, not copied.');

        $woman = $this->invoke($this->claude(), 'premisePrompt', $this->draftStory(['voice_id' => NarratorVoice::voiceFor('female')]), 'idea here', 3);
        $this->assertStringContainsString('THE NARRATOR IS A WOMAN', $woman);
    }

    /**
     * RED/GREEN, one called instruction, two readers. Story 38's first roll
     * declared six people and named four: the premise prompt asked for a
     * count, and the cast instruction it calls told the premise writer "the
     * premise may name the people it needs" and allowed max_named (8) rows.
     * That sentence is right for the OUTLINE, which reads a premise, and wrong
     * for the writer of one. The outline keeps it, byte for byte.
     */
    public function test_red_green_the_premise_cast_is_the_people_the_prose_names(): void
    {
        $story = $this->draftStory();
        $flat = fn (string $s): string => preg_replace('/\s+/', ' ', $s);

        $premise = $flat($this->invoke($this->claude(), 'premisePrompt', $story, 'idea', 3));
        $outline = $flat($this->invoke($this->claude(), 'outlinePrompt', $story, 5));

        $this->assertStringNotContainsString('The premise may name the people it needs', $premise);
        $this->assertStringContainsString('Everyone in this cast is named in the premise you write', $premise);
        $this->assertStringContainsString('At most '.config('cast.premise_named').' people besides the narrator', $premise);

        $this->assertStringContainsString('The premise may name the people it needs', $outline);
        $this->assertStringNotContainsString('Everyone in this cast is named in the premise you write', $outline);
        $this->assertStringContainsString('At most '.config('cast.max_named').' people besides the narrator', $outline);
    }

    /**
     * The two betrayal-scene defects of story 38's roll, as the shared spine
     * question now asks: the accomplice speaks TO the narrator (all three
     * spoke to the room), and the justification is quoted in the scene
     * rather than pointed at (all three wrote "delivers the justification").
     */
    public function test_the_betrayal_scene_question_asks_for_the_line_to_the_narrator_and_her_words(): void
    {
        $question = preg_replace('/\s+/', ' ', SpineQuestions::betrayalScene());

        $this->assertStringContainsString('HAVE THEM SPEAK TO THE NARRATOR', $question);
        $this->assertStringContainsString('QUOTE HER HERE', $question);
        $this->assertStringContainsString('never "she delivers the justification"', $question);
        $this->assertStringContainsString('not to the room, the table or the guests', preg_replace('/\s+/', ' ', SpineQuestions::accomplicePerformance()));
    }

    public function test_the_premise_system_prompt_is_the_outlines_genre_contract_called(): void
    {
        $story = $this->draftStory();
        $system = $this->invoke($this->claude(), 'premiseSystemPrompt', $story);

        $this->assertStringContainsString($this->invoke($this->claude(), 'genreGuidance', $story), $system);
        $this->assertStringContainsString(app(LocaleGuard::class)->guidanceFor('en-US'), $system);
    }

    /**
     * Three, one array of identical items, and no labelled slot anywhere: the
     * title schema's `short_titles` returns a short title every time.
     */
    public function test_the_premise_schema_is_one_array_of_identical_candidates(): void
    {
        $schema = $this->invoke($this->claude(), 'premiseSchema');

        $this->assertSame(['idea_was_revenge_shaped', 'translation', 'candidates'], array_keys($schema['properties']));
        $arrays = array_filter($schema['properties'], fn (array $p): bool => ($p['type'] ?? null) === 'array');
        $this->assertSame(['candidates'], array_keys($arrays), 'One array. A second one is a labelled slot.');

        $item = $schema['properties']['candidates']['items'];
        $keys = array_keys($item['properties']);
        $this->assertSame($keys, $item['required']);
        $this->assertSame('narrator', $keys[0]);
        $this->assertSame('premise', end($keys), 'The prose is written last, to its answers.');
        $this->assertNotContains('narrator', $item['properties']['cast']['items']['properties']['role']['enum']);
        $this->assertSame(PremiseCandidate::FIELDS, array_slice($keys, 2, count(PremiseCandidate::FIELDS)));
    }

    public function test_the_decode_puts_the_narrator_first_and_keeps_what_it_said_about_the_idea(): void
    {
        $set = $this->invoke($this->claude(), 'premiseDraftFrom', [
            'idea_was_revenge_shaped' => true,
            'translation' => 'Asked for revenge; became a refusal at the shareholder meeting.',
            'candidates' => [[
                'narrator' => ['name' => 'Owen Fu', 'relationship' => 'her husband'],
                'cast' => [['name' => 'Sophie Weng', 'role' => 'antagonist', 'relationship' => 'my wife']],
                'premise' => 'P.',
                'betrayal_scene' => 'B.',
            ]],
        ], $this->usage(), 3);

        $this->assertTrue($set->ideaWasRevengeShaped);
        $this->assertStringContainsString('became a refusal', $set->translation);
        $this->assertSame(['Owen Fu', 'Sophie Weng'], array_map(fn ($m) => $m->name, $set->candidates[0]->cast));
        $this->assertSame(CastRole::Narrator, $set->candidates[0]->cast[0]->role);
        $this->assertSame('B.', $set->candidates[0]->fields['betrayal_scene']);
        $this->assertSame('', $set->candidates[0]->fields['departure']);
        $this->assertSame(3, $set->requested);
    }

    // -- The Action ------------------------------------------------------------

    public function test_a_roll_is_billed_stored_and_recorded_as_its_own_stage(): void
    {
        $story = $this->draftStory(['voice_id' => NarratorVoice::voiceFor('female')]);

        app(GeneratePremises::class)->handle($story, 'My husband sat his mistress in my chair.');
        $story->refresh();

        $this->assertCount(3, $story->premise_candidates['candidates']);
        $this->assertSame('My husband sat his mistress in my chair.', $story->premise_candidates['idea']);
        $this->assertSame(StoryStatus::Draft, $story->status, 'A roll moves nothing.');
        $this->assertSame(1, $story->costEntries()->where('operation', 'generate_premises')->count());
        $this->assertSame(RenderJobStatus::Succeeded, RenderJob::query()->where('story_id', $story->id)->where('stage', RenderStage::Premises)->sole()->status);

        $call = collect($this->writer->calls)->firstWhere('method', 'premises');
        $this->assertSame('female', $call['narrator_gender'], 'The narrator\'s gender reaches the writer.');
        $this->assertSame(GeneratePremises::COUNT, $call['count']);
    }

    public function test_a_short_roll_is_kept_and_named_not_refused(): void
    {
        $story = $this->draftStory();
        $this->writer->premiseOverride = [$this->cleanCandidate($story)];

        app(GeneratePremises::class)->handle($story, 'My wife cheated at her own party.');

        $this->assertCount(1, $story->fresh()->premise_candidates['candidates']);
        $this->assertStringContainsString('1 of 3 premise(s) written. SHORT', (string) RenderJob::query()->where('story_id', $story->id)->where('stage', RenderStage::Premises)->sole()->log);
    }

    /** An empty roll is a refused output: Write premises again is the move, unmeasured. */
    public function test_an_empty_roll_is_recorded_as_a_refused_output(): void
    {
        $story = $this->draftStory();
        $this->writer->premiseOverride = [];

        try {
            app(GeneratePremises::class)->handle($story, 'My wife cheated at her own party.');
            $this->fail('A roll with no candidates must be refused.');
        } catch (\App\Support\Providers\ScriptWriterException $e) {
            $this->assertSame(\App\Enums\FailureKind::OutputRefused, $e->failureKind());
            $this->assertSame(['stage' => 'premises', 'check' => 'no_candidates'], $e->failureFacts());
        }
    }

    public function test_red_green_a_roll_is_refused_outside_a_single_narrative_draft_with_no_outline(): void
    {
        $idea = 'My wife cheated at her own party.';

        foreach ([
            'outlined' => $this->draftStory(['status' => StoryStatus::Outlined]),
            'anthology' => $this->draftStory(['format' => StoryFormat::Anthology]),
            'has acts' => tap($this->draftStory(), fn (Story $s) => Act::factory()->for($s)->create(['sequence' => 1])),
        ] as $case => $story) {
            try {
                app(GeneratePremises::class)->handle($story, $idea);
                $this->fail("{$case}: expected a refusal.");
            } catch (\Throwable $e) {
                $this->assertSame(0, $story->costEntries()->count(), "{$case}: refused before the call.");
            }
        }

        app(GeneratePremises::class)->handle($this->draftStory(), $idea);
    }

    // -- The checks, RED/GREEN -------------------------------------------------

    public function test_green_the_fakes_candidates_pass_every_premise_check(): void
    {
        $story = $this->draftStory();

        foreach ($this->writer->premises($story, 'idea', 3)->candidates as $candidate) {
            $checks = $this->checks($story, $candidate);
            $this->assertSame([], $checks['problems'], implode("\n", $checks['problems']));
            $this->assertSame([], $checks['warnings'], implode("\n", $checks['warnings']));
            $this->assertNotEmpty($checks['ran']);
        }
    }

    public function test_red_a_name_a_recent_video_used_is_a_problem_because_the_outline_would_be_refused(): void
    {
        $published = Story::factory()->status(StoryStatus::Published)->create();
        Character::factory()->for($published)->create(['name' => 'Grace Zhou']);

        $story = $this->draftStory();
        $clean = $this->cleanCandidate($story);
        $cast = $clean->cast;
        $cast[2] = new CastMember('Grace Zhou', $cast[2]->role, $cast[2]->relationship);

        $checks = $this->checks($story, new PremiseCandidate(
            str_replace($clean->cast[2]->name, 'Grace Zhou', $clean->premise), $cast, $clean->fields,
        ));

        $this->assertStringContainsString('Grace Zhou was already used by '.$published->slug, implode(' ', $checks['problems']));
    }

    public function test_red_a_cast_with_no_narrator_is_a_problem(): void
    {
        $story = $this->draftStory();
        $clean = $this->cleanCandidate($story);

        $checks = $this->checks($story, new PremiseCandidate($clean->premise, array_slice($clean->cast, 1), $clean->fields));

        $this->assertStringContainsString('0 narrators', implode(' ', $checks['problems']));
    }

    public function test_red_green_a_betrayal_nobody_watches_found_rather_than_done_and_justification_unsaid(): void
    {
        $story = $this->draftStory();
        $clean = $this->cleanCandidate($story);

        $private = $this->withField($clean, 'betrayal_scene',
            'At home he read the messages on her phone and discovered the booking. I asked about it and he shrugged.');
        $warnings = implode(' ', $this->checks($story, $private)['warnings']);

        $this->assertStringContainsString('names nobody watching', $warnings);
        $this->assertStringContainsString('reads as FOUND', $warnings);
        $this->assertStringContainsString('not said in the betrayal scene', $warnings);
    }

    public function test_red_an_announced_departure_and_a_narrator_who_produces_nothing(): void
    {
        $story = $this->draftStory();
        $clean = $this->cleanCandidate($story);

        $candidate = $this->withField($this->withField($clean, 'departure',
            'I told them all at breakfast that I was moving to Denver and gave them the address.'),
            'narrator_at_exposure', 'My lawyer handles the meeting while I stay home.');
        $warnings = implode(' ', $this->checks($story, $candidate)['warnings']);

        $this->assertStringContainsString('departure reads as announced', $warnings);
        $this->assertStringContainsString('names nothing that only they can produce', $warnings);
    }

    public function test_red_green_the_prose_must_carry_the_names_and_the_answers(): void
    {
        $story = $this->draftStory();
        $clean = $this->cleanCandidate($story);

        $thin = new PremiseCandidate(
            'We had a party. Something went wrong between us. I decided to leave town and start over somewhere quiet.',
            $clean->cast,
            $clean->fields,
        );
        $warnings = implode(' ', $this->checks($story, $thin)['warnings']);

        $this->assertStringContainsString('never names', $warnings);
        $this->assertStringContainsString('does not carry the betrayal scene', $warnings);
        $this->assertStringContainsString('does not carry the justification', $warnings);
        $this->assertStringContainsString('must produce in person', $warnings);
    }

    /**
     * RED/GREEN: the future partner declared and never in the prose — story
     * 38's roll, all three candidates — is reported on its own line, naming
     * her, and not folded into the general missing-names list. GREEN is the
     * same candidate with her placed in the room for the betrayal.
     */
    public function test_red_green_a_future_partner_the_prose_never_names(): void
    {
        $story = $this->draftStory();
        $clean = $this->cleanCandidate($story);
        $cast = [...$clean->cast, new CastMember('Vivian Cao', CastRole::FuturePartner, 'her best friend since university')];

        $dropped = implode(' ', $this->checks($story, new PremiseCandidate($clean->premise, $cast, $clean->fields))['warnings']);

        $this->assertStringContainsString('The future partner, Vivian Cao, is in the cast and never in the premise', $dropped);
        $this->assertStringNotContainsString('never names Vivian Cao', $dropped, 'Her own line, not one name in a list.');

        $placed = new PremiseCandidate(
            str_replace('His sister', 'Her best friend Vivian Cao looked at her plate. His sister', $clean->premise),
            $cast,
            $clean->fields,
        );
        $warnings = implode(' ', $this->checks($story, $placed)['warnings']);

        $this->assertStringNotContainsString('Vivian Cao', $warnings);
    }

    public function test_red_green_enumerated_history_in_the_prose(): void
    {
        $story = $this->draftStory();
        $clean = $this->cleanCandidate($story);

        $history = new PremiseCandidate(
            'The first time he did it I forgave him. The second time I said nothing. '.$clean->premise,
            $clean->cast,
            $clean->fields,
        );
        $years = new PremiseCandidate('In 2015 it began, and in 2017 it happened again. '.$clean->premise, $clean->cast, $clean->fields);
        $once = new PremiseCandidate('The first time I saw the seat was at the door. '.$clean->premise, $clean->cast, $clean->fields);

        $this->assertStringContainsString('enumerates history', implode(' ', $this->checks($story, $history)['warnings']));
        $this->assertStringContainsString('the years 2015, 2017', implode(' ', $this->checks($story, $years)['warnings']));
        $this->assertStringNotContainsString('enumerates history', implode(' ', $this->checks($story, $once)['warnings']));
    }

    public function test_red_a_coded_accomplice_and_a_denied_locale_term_are_problems(): void
    {
        $story = $this->draftStory();
        $clean = $this->cleanCandidate($story);

        $coded = $this->withField($clean, 'accomplice_performance',
            'He plays it soft and sissy so she thinks he is not into women. He says, "I only want everyone happy."');
        $this->assertStringContainsString('orientation', implode(' ', $this->checks($story, $coded)['problems']));

        $local = new PremiseCandidate($clean->premise.' We met in the barangay hall.', $clean->cast, $clean->fields);
        $this->assertStringContainsString('"barangay" is on the en-US denylist', implode(' ', $this->checks($story, $local)['problems']));
    }

    public function test_red_green_the_idea_is_read_for_revenge_in_its_own_words(): void
    {
        $this->assertSame(['made them pay'], PremiseIdea::revengeMarkers('My CEO wife cheated so I made them pay'));
        $this->assertSame(['got even'], PremiseIdea::revengeMarkers('She left me for my boss and I got even.'));
        $this->assertSame([], PremiseIdea::revengeMarkers('She paid for the party and made them laugh.'));
    }

    // -- The page --------------------------------------------------------------

    public function test_the_page_offers_premises_on_a_single_narrative_draft_and_queues_one_roll(): void
    {
        Queue::fake();
        $story = $this->draftStory(['premise' => 'my CEO wife cheated so I made them pay']);

        Livewire::test(OutlineGate::class, ['story' => $story])
            ->assertSet('idea', 'my CEO wife cheated so I made them pay')
            ->assertSee('Write premises from an idea')
            ->call('askToWritePremises')
            ->assertSee('One billed call')
            ->call('writePremises')
            ->assertSee('Queued: three premises');

        Queue::assertPushed(GeneratePremisesJob::class, fn (GeneratePremisesJob $job): bool => $job->storyId === $story->id);
    }

    /**
     * Failures shown in red, never dropped; the revenge line on top; a pick
     * that writes the premise and moves nothing.
     */
    public function test_candidates_render_with_their_failures_and_a_pick_moves_nothing(): void
    {
        $story = $this->draftStory();
        $clean = $this->cleanCandidate($story);
        $this->writer->premiseOverride = [
            $clean,
            new PremiseCandidate('The first time he did it I forgave him. The second time I said nothing. '.$clean->premise, $clean->cast, $clean->fields),
        ];
        $this->writer->premiseRevengeShaped = true;
        $this->writer->premiseTranslation = 'Asked to make them pay; became a refusal in the stairwell.';

        app(GeneratePremises::class)->handle($story, 'so I made them pay');

        Livewire::test(OutlineGate::class, ['story' => $story->fresh()])
            ->assertSee('Your idea was revenge-shaped, and became a refusal.')
            ->assertSee('became a refusal in the stairwell')
            ->assertSee('Premise 2')
            ->assertSee('enumerates history')
            ->assertSee('passed every check')
            ->assertSee('Asked for 3 premises and got 2')
            ->call('usePremise', 1)
            ->assertSet('premise', 'The first time he did it I forgave him. The second time I said nothing. '.$clean->premise);

        $this->assertSame(StoryStatus::Draft, $story->fresh()->status, 'Picking a premise must not move the story: save() would.');
        $this->assertStringStartsWith('The first time', $story->fresh()->premise);
    }

    public function test_red_green_the_second_reading_speaks_when_the_generator_did_not(): void
    {
        $story = $this->draftStory();
        app(GeneratePremises::class)->handle($story, 'My wife cheated so I made them pay');

        Livewire::test(OutlineGate::class, ['story' => $story->fresh()])
            ->assertSee('Your idea reads as revenge-shaped')
            ->assertSee('made them pay');

        $calm = $this->draftStory();
        app(GeneratePremises::class)->handle($calm, 'My wife sat another man in my chair at her party.');

        Livewire::test(OutlineGate::class, ['story' => $calm->fresh()])
            ->assertDontSee('revenge-shaped');
    }

    public function test_the_panel_is_hidden_on_an_anthology_with_the_reason_and_gone_once_outlined(): void
    {
        Livewire::test(OutlineGate::class, ['story' => $this->draftStory(['format' => StoryFormat::Anthology])])
            ->assertDontSee('Write premises from an idea')
            ->assertSee('single narrative only');

        Livewire::test(OutlineGate::class, ['story' => $this->draftStory(['status' => StoryStatus::Outlined])])
            ->assertDontSee('Write premises from an idea');
    }

    // -- The form --------------------------------------------------------------

    public function test_red_green_the_narrator_is_required_and_picks_the_voice(): void
    {
        Queue::fake();

        Livewire::test(NewStory::class)
            ->set('premise', 'A premise comfortably longer than the twenty character minimum.')
            ->call('askToCreate')
            ->assertHasErrors(['narratorGender' => 'required']);

        Livewire::test(NewStory::class)
            ->set('premise', 'A premise comfortably longer than the twenty character minimum.')
            ->set('narratorGender', 'female')
            ->set('ending', 'new_life')
            ->call('create');

        $this->assertSame(NarratorVoice::voiceFor('female'), Story::query()->latest('id')->first()->voice_id);
    }

    public function test_an_idea_creates_a_draft_and_queues_nothing_and_is_not_offered_on_an_anthology(): void
    {
        Queue::fake();

        Livewire::test(NewStory::class)
            ->set('startFrom', 'idea')
            ->set('premise', 'my CEO wife cheated so I made them pay')
            ->set('narratorGender', 'male')
            ->set('ending', 'new_life')
            ->assertSee('Create the draft')
            ->call('create')
            ->assertRedirect();

        Queue::assertNothingPushed();
        $story = Story::query()->latest('id')->first();
        $this->assertSame(StoryStatus::Draft, $story->status);
        $this->assertSame(NarratorVoice::voiceFor('male'), $story->voice_id);

        Livewire::test(NewStory::class)
            ->set('format', 'anthology')
            ->set('startFrom', 'idea')
            ->set('premise', 'my CEO wife cheated so I made them pay')
            ->set('narratorGender', 'male')
            ->set('ending', 'new_life')
            ->call('create')
            ->assertHasErrors(['startFrom']);
    }

    public function test_story_write_refuses_a_new_premise_with_no_narrator(): void
    {
        $this->artisan('story:write', ['--premise' => 'A premise long enough to be a story.', '--yes' => true])
            ->assertFailed();

        $this->assertSame(0, Story::query()->count());
    }

    // ---------------------------------------------------------------------

    private function checks(Story $story, PremiseCandidate $candidate): array
    {
        return app(ValidateOutlineSpine::class)->premiseChecks($story, $candidate);
    }

    private function cleanCandidate(Story $story): PremiseCandidate
    {
        return $this->writer->premiseCandidate($story, 'his cousin\'s wedding reception', 'sixty guests', 'house', 'title office');
    }

    private function withField(PremiseCandidate $c, string $field, string $value): PremiseCandidate
    {
        return new PremiseCandidate($c->premise, $c->cast, [$field => $value] + $c->fields);
    }

    private function draftStory(array $attributes = []): Story
    {
        $status = $attributes['status'] ?? StoryStatus::Draft;
        unset($attributes['status']);

        return Story::factory()->status($status)->create($attributes + [
            'format' => StoryFormat::Single,
            'locale_profile' => 'en-US',
            'premise' => 'My sister billed me for her entire wedding and told the family I had offered.',
        ]);
    }

    private function usage(): ProviderUsage
    {
        return ProviderUsage::simulated(operation: 'generate_premises', category: CostCategory::Text, quantity: 0.0, unit: CostUnit::TotalTokens);
    }

    private function claude(): ClaudeScriptWriter
    {
        return new ClaudeScriptWriter(client: app(Client::class), locale: app(LocaleGuard::class), text: app(CharacterTextGuard::class));
    }

    private function invoke(object $target, string $method, mixed ...$args): mixed
    {
        return (new ReflectionMethod($target, $method))->invoke($target, ...$args);
    }

    /** Source with comments removed, so an explanation is not read as a copy. */
    private function codeOf(string $path): string
    {
        $code = '';

        foreach (token_get_all((string) file_get_contents($path)) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $code .= is_array($token) ? $token[1] : $token;
        }

        return $code;
    }
}
