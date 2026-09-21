<?php

namespace Tests\Feature\Providers;

use Anthropic\Client;
use App\Actions\CreateStory;
use App\Actions\DispatchTextStage;
use App\Actions\GenerateOutline;
use App\Actions\ValidateOutlineSpine;
use App\Enums\ActPhase;
use App\Enums\PartnerEndState;
use App\Enums\StoryEnding;
use App\Enums\StoryFormat;
use App\Enums\StoryStatus;
use App\Exceptions\DispatchRefusedException;
use App\Livewire\Gates\OutlineGate;
use App\Models\Act;
use App\Models\Story;
use App\Services\Claude\ClaudeMetadataWriter;
use App\Services\Claude\ClaudeScriptWriter;
use App\Support\CharacterTextGuard;
use App\Support\LocaleGuard;
use App\Support\PartnerEnding;
use App\Support\Providers\ActOutline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use ReflectionMethod;
use Tests\TestCase;

/**
 * WHAT THE NARRATOR AND THE PARTNER ARE TO EACH OTHER BY THE END — CHOSEN BY
 * THE OPERATOR, AND SAID IN WORDS THAT CAN CARRY IT.
 *
 * Story 39, 2026-09-20. The idea said "i am married to her older sister"; the
 * outline's act-5 summary came back "Nancy introduces me to a room as her
 * partner". Measured, the entire vocabulary any stage offered was "a couple",
 * "together" and "a year on" — so that summary obeyed every instruction there
 * was. Nothing could have produced a marriage, because nothing asked for one.
 *
 * Two halves, and the operator's words for why they are not alternatives: the
 * wider vocabulary "fixes the words", the column "fixes the authority". A
 * writer handed four end states and no instruction picks the weakest one it
 * can defend, which is what "partner" already is.
 *
 * Every consumer, with its arrival assertion, per this project's standing
 * practice: the outline prompt, each act phase, the last chapter, the scene
 * call, the metadata brief, Gate 1's check, the pickers, `CreateStory`,
 * `story:write` and `story:fork`. And the three refusal points, which are the
 * ending's own three.
 */
class PartnerEndStateTest extends TestCase
{
    use RefreshDatabase;

    private const CAST = [
        ['name' => 'Daniel Ye', 'role' => 'narrator', 'relationship' => 'the narrator'],
        ['name' => 'Jenny Kong', 'role' => 'antagonist', 'relationship' => 'my fiancee of four years'],
        ['name' => 'Nancy Kong', 'role' => 'future_partner', 'relationship' => 'Jenny\'s older sister, a furniture designer'],
    ];

    /**
     * Story 39's real act-5 summary, at the end, abbreviated to the two
     * sentences that matter — and they are the reason this check is
     * sentence-scoped rather than a word search over the summary.
     *
     * The first sentence contains "together" and is the ANTAGONIST quoting
     * herself about the ACCOMPLICE. The second is the only one that names the
     * partner, and it says "partner".
     */
    private const STORY_39_TAIL = 'Jenny, asked why I left, says her line again in front of everyone: they '
        .'grew up together, he knows the real her, one kiss between old friends never had to mean anything. '
        .'A year later, at a client evening in Hangzhou, Nancy introduces me to a room as her partner and I '
        .'am the one carrying the chairs on purpose.';

    // -- The column, and the three refusal points -------------------------------

    /**
     * RED/GREEN on the refusal, and it fires only where the answer was
     * knowable before the call: a cast CHOSEN with the premise.
     */
    public function test_red_green_the_outline_is_refused_when_the_chosen_cast_names_a_partner_and_nothing_says_what_they_become(): void
    {
        $red = $this->draftWithChosenCast(['partner_end_state' => null]);

        try {
            app(GenerateOutline::class)->handle($red);
            $this->fail('An outline with a chosen partner and no end state should have been refused.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('what they are to each other by the end', $e->getMessage());
            $this->assertStringContainsString('married, engaged, living together, or together', $e->getMessage());
        }

        // Its own names: `OutlineCast::reused()` reads the last ten stories,
        // so a GREEN half sharing the RED half's cast is refused for the name
        // check instead and proves nothing about this one.
        $green = $this->draftWithChosenCast([
            'partner_end_state' => PartnerEndState::Married,
            'outline_cast' => [
                ['name' => 'Aaron Shen', 'role' => 'narrator', 'relationship' => 'the narrator'],
                ['name' => 'Tina Luo', 'role' => 'antagonist', 'relationship' => 'my fiancee'],
                ['name' => 'Bella Luo', 'role' => 'future_partner', 'relationship' => 'Tina\'s older sister'],
            ],
        ]);

        app(GenerateOutline::class)->handle($green);
        $this->assertTrue($green->refresh()->acts()->exists());
    }

    /**
     * The scope: a cast that NAMES a future partner, however it got there.
     *
     * A typed premise's first outline has no cast at all, so there is nobody
     * to ask about and nothing is refused. A RE-OUTLINE does have one — the
     * previous outline wrote it — and is refused, which is the case story 39
     * is in and the case the first version of this predicate missed by reading
     * `chosenBeforeOutline()`, which is empty once a story has acts.
     */
    public function test_a_reoutline_is_refused_too_and_a_typed_premise_with_no_cast_is_not(): void
    {
        $reoutline = $this->draftWithChosenCast(['partner_end_state' => null]);
        Act::factory()->for($reoutline)->create(['sequence' => 1]);
        $this->assertTrue(
            PartnerEnding::requiredBeforeOutline($reoutline->refresh()),
            'Story 39 is a re-outline with a partner in its cast and no end state.',
        );

        $typed = $this->draftWithChosenCast(['partner_end_state' => null, 'outline_cast' => null]);
        $this->assertFalse(PartnerEnding::requiredBeforeOutline($typed));
    }

    /**
     * Three GREENs that between them say what else the scope excludes: no
     * partner in the cast, her ending, and an anthology.
     */
    public function test_the_refusal_is_scoped_to_a_partner_this_ending_puts_on_screen(): void
    {
        $alone = $this->draftWithChosenCast([
            'partner_end_state' => null,
            'outline_cast' => array_slice(self::CAST, 0, 2),
        ]);
        $this->assertFalse(PartnerEnding::requiredBeforeOutline($alone));

        $hers = $this->draftWithChosenCast([
            'partner_end_state' => null,
            'ending' => StoryEnding::AntagonistVoice,
        ]);
        $this->assertFalse(PartnerEnding::requiredBeforeOutline($hers));

        $anthology = $this->draftWithChosenCast([
            'partner_end_state' => null,
            'format' => StoryFormat::Anthology,
            'ending' => null,
        ]);
        $this->assertFalse(PartnerEnding::requiredBeforeOutline($anthology));
    }

    public function test_the_dispatch_and_the_gate_one_button_refuse_before_anything_queues(): void
    {
        Queue::fake();

        $story = $this->draftWithChosenCast(['partner_end_state' => null]);

        try {
            app(DispatchTextStage::class)->writeScript($story, checkWorkers: false);
            $this->fail('The dispatch should have refused.');
        } catch (DispatchRefusedException $e) {
            $this->assertSame(GenerateOutline::NO_PARTNER_END_STATE, $e->getMessage());
        }

        Livewire::test(OutlineGate::class, ['story' => $story])
            ->call('askToWrite')
            ->assertSet('confirmingWrite', false)
            ->assertSet('problem', GenerateOutline::NO_PARTNER_END_STATE);

        Queue::assertNothingPushed();
    }

    /**
     * The ACTS press is refused too, and stops being refused the moment the
     * choice can no longer be made.
     *
     * The acts are where the words land: every summary is written to this and
     * so is the last chapter. But once a script exists the picker is closed,
     * and a guard nobody can satisfy would kill exactly the press this
     * pipeline most needs — the resume after a run that died on act 4.
     */
    public function test_the_acts_press_is_refused_too_but_never_the_resume(): void
    {
        Queue::fake();

        $story = $this->draftWithChosenCast(['partner_end_state' => null]);
        $story->transitionTo(StoryStatus::Outlined);
        Act::factory()->for($story)->create(['sequence' => 1, 'script' => null]);

        try {
            app(DispatchTextStage::class)->writeScript($story->refresh(), checkWorkers: false);
            $this->fail('The acts press should have been refused.');
        } catch (DispatchRefusedException $e) {
            $this->assertSame(GenerateOutline::NO_PARTNER_END_STATE, $e->getMessage());
        }

        // Act 1 written, act 2 not: the choice is closed, so the resume runs.
        $story->acts()->where('sequence', 1)->update(['script' => 'Act one, written.']);
        Act::factory()->for($story)->create(['sequence' => 2, 'script' => null]);

        app(DispatchTextStage::class)->writeScript($story->refresh(), checkWorkers: false);
        Queue::assertPushed(\App\Jobs\WriteStoryJob::class);
    }

    /**
     * Gate 1 saves it, and it stays choosable a stage LONGER than the ending.
     *
     * The ending is fixed once acts exist because the outline schema branches
     * on it. This is read by the act writer too, and the act writer replaces
     * each summary with its own — so the line is drawn at the first script,
     * where the words are in the prose. Without that, a story whose partner
     * the outline invented could never have an end state at all: acts exist
     * from the moment the outline lands.
     */
    public function test_gate_one_saves_it_and_fixes_it_at_the_first_act_script(): void
    {
        $story = $this->draftWithChosenCast(['partner_end_state' => null]);

        Livewire::test(OutlineGate::class, ['story' => $story])
            ->set('partnerEndState', PartnerEndState::Married->value)
            ->assertSee('By the end they are married.');

        $this->assertSame(PartnerEndState::Married, $story->refresh()->partner_end_state);

        // Outlined, acts written, no scripts: still choosable.
        $story->transitionTo(StoryStatus::Outlined);
        Act::factory()->for($story)->create(['sequence' => 1, 'script' => null]);
        $this->assertTrue(PartnerEnding::stillChoosable($story->refresh()));

        Act::factory()->for($story)->create(['sequence' => 2, 'script' => 'The act, written.']);
        $this->assertFalse(PartnerEnding::stillChoosable($story->refresh()));

        Livewire::test(OutlineGate::class, ['story' => $story->refresh()])
            ->set('partnerEndState', PartnerEndState::Together->value)
            ->assertForbidden();

        $this->assertSame(PartnerEndState::Married, $story->refresh()->partner_end_state);
    }

    /**
     * The screen story 39 is actually on: outlined, five acts, no scripts, a
     * partner in the cast and no end state. The picker has to be reachable
     * there — a refusal with no way to satisfy it on the same page is the
     * capability-with-no-button shape landing on a money screen.
     */
    public function test_the_picker_is_on_the_page_that_refuses_the_press(): void
    {
        $story = $this->draftWithChosenCast(['partner_end_state' => null]);
        $story->transitionTo(StoryStatus::Outlined);
        Act::factory()->for($story)->create(['sequence' => 1, 'script' => null]);

        Livewire::test(OutlineGate::class, ['story' => $story->refresh()])
            ->assertSee('By the end, the two of them are')
            ->assertSee('Your cast names')
            ->assertSee('Nancy Kong')
            ->assertSee('The outline is refused until you choose')
            ->assertSee('not chosen.');
    }

    public function test_create_story_and_the_form_carry_it_and_an_anthology_has_none(): void
    {
        $story = app(CreateStory::class)->handle(
            premise: 'She held his hand at the reunion and told the table I had been gone for years.',
            partnerEndState: PartnerEndState::Engaged,
        );

        $this->assertSame(PartnerEndState::Engaged, $story->partner_end_state);

        $anthology = app(CreateStory::class)->handle(
            premise: 'Three stories about the same street.',
            format: StoryFormat::Anthology,
            partnerEndState: PartnerEndState::Engaged,
        );

        $this->assertNull($anthology->partner_end_state, 'An anthology has no ending to be a partner in.');

        // Optional on the form, unlike the ending: there is no cast yet, so a
        // required answer would be a question about a person who may never
        // exist. An unknown value is still refused.
        $rules = (new ReflectionMethod(\App\Livewire\Stories\NewStory::class, 'rules'))
            ->invoke(new \App\Livewire\Stories\NewStory);

        $this->assertContains('nullable', $rules['partnerEndState']);
    }

    public function test_a_fork_keeps_the_end_state_it_was_written_to(): void
    {
        $story = $this->draftWithChosenCast(['partner_end_state' => PartnerEndState::LivingTogether]);
        Act::factory()->for($story)->create(['sequence' => 1, 'script' => 'Written.']);

        $this->artisan('story:fork', ['story' => $story->slug])->assertSuccessful();

        $fork = Story::query()->whereKeyNot($story->id)->latest('id')->first();
        $this->assertSame(PartnerEndState::LivingTogether, $fork?->partner_end_state);
    }

    // -- The words, at every consumer -------------------------------------------

    public function test_the_outline_prompt_names_the_chosen_state_and_otherwise_names_the_four(): void
    {
        $chosen = $this->flat($this->invoke(
            $this->claude(),
            'outlinePrompt',
            $this->draftWithChosenCast(['partner_end_state' => PartnerEndState::Married]),
            5,
        ));

        $this->assertStringContainsString('WHICH IS CHOSEN FOR THIS STORY AND IS NOT YOURS TO SOFTEN', $chosen);
        $this->assertStringContainsString($this->flat(PartnerEndState::Married->instruction()), $chosen);
        $this->assertStringContainsString('The last act\'s summary says they are Married, in those words, in the same sentence as the partner\'s name.', $chosen);

        $unchosen = $this->flat($this->invoke(
            $this->claude(),
            'outlinePrompt',
            $this->draftWithChosenCast(['partner_end_state' => null]),
            5,
        ));

        $this->assertStringContainsString('married, engaged, living together, or together and nothing further', $unchosen);
        $this->assertStringNotContainsString('NOT YOURS TO SOFTEN', $unchosen);

        // Her ending never names an end state: the narrator's year is not on
        // screen there at all, so a state would contradict doesNotCarry().
        $hers = $this->flat($this->invoke(
            $this->claude(),
            'outlinePrompt',
            $this->draftWithChosenCast([
                'ending' => StoryEnding::AntagonistVoice,
                'partner_end_state' => PartnerEndState::Married,
            ]),
            5,
        ));

        $this->assertStringNotContainsString('NOT YOURS TO SOFTEN', $hers);
    }

    /**
     * The refusal act and the last chapter are a YEAR apart, and only the
     * second carries the state. Asking the refusal act for it would be asking
     * the wrong scene.
     */
    public function test_the_refusal_act_puts_the_state_in_the_last_chapter_and_in_sentence_four(): void
    {
        $story = $this->storyWithState(PartnerEndState::Married);
        $act = new ActOutline(sequence: 5, title: 'T', summary: 'S', phase: ActPhase::Refusal);

        $arc = $this->flat($this->invoke($this->claude(), 'partnerArcFor', $story, $act));

        $this->assertStringContainsString('In the last chapter, a year on, they are Married', $arc);
        $this->assertStringContainsString('Sentence four of the summary says so, in those words, in the same sentence as Nancy Kong\'s name', $arc);

        // And it reaches the act PROMPT, not only the builder.
        $prompt = $this->flat($this->invoke($this->claude(), 'actPrompt', $story, $act, [$act], [], 1000));
        $this->assertStringContainsString('In the last chapter, a year on, they are Married', $prompt);

        // Nothing romantic before the departure is untouched by the state.
        $early = $this->flat($this->invoke($this->claude(), 'partnerArcFor', $story, new ActOutline(sequence: 1, title: 'T', summary: 'S', phase: ActPhase::Escalation)));
        $this->assertStringContainsString('Nothing romantic between them here', $early);
        $this->assertStringNotContainsString('Married', $early);
    }

    public function test_the_last_chapter_and_the_scene_call_carry_the_state(): void
    {
        $story = $this->storyWithState(PartnerEndState::Married);
        $act = new ActOutline(sequence: 5, title: 'T', summary: 'S', phase: ActPhase::Refusal);

        $ending = $this->flat($this->invoke($this->claude(), 'endingFor', $story, $act, true));
        $this->assertStringContainsString('THIS IS WHAT THE TWO OF THEM ARE TO EACH OTHER NOW', mb_strtoupper($ending));
        $this->assertStringContainsString($this->flat(PartnerEndState::Married->instruction()), $ending);

        $stored = Act::factory()->for($story)->create(['sequence' => 5, 'phase' => ActPhase::Refusal]);
        $scene = $this->invoke($this->claude(), 'sceneContext', $story->refresh(), $stored);
        $this->assertStringContainsString('the two of them are married', $scene);

        // With nothing chosen the scene call keeps the sentence it had.
        $unchosen = $this->storyWithState(null);
        $storedUnchosen = Act::factory()->for($unchosen)->create(['sequence' => 5, 'phase' => ActPhase::Refusal]);
        $this->assertStringContainsString(
            'the two of them are a couple',
            $this->invoke($this->claude(), 'sceneContext', $unchosen->refresh(), $storedUnchosen),
        );
    }

    /**
     * The brief names the chosen state AND keeps the summary as the bound.
     *
     * The column is an INTENT recorded before the outline; the summary is what
     * the acts came back with. Promising from the intent would be the record
     * standing in for the artifact, which is the defect `thumbnail_selected`
     * was repaired out of.
     */
    public function test_the_metadata_brief_names_the_state_and_still_bounds_the_promise_by_the_summary(): void
    {
        $story = $this->storyWithState(PartnerEndState::Married);
        Act::factory()->for($story)->create([
            'sequence' => 5,
            'phase' => ActPhase::Refusal,
            'summary' => 'A year on, Nancy introduces me to a room as her partner.',
        ]);

        $brief = $this->flat($this->invoke(
            new ClaudeMetadataWriter(app(Client::class), app(LocaleGuard::class)),
            'storyBrief',
            $story->refresh(),
        ));

        $this->assertStringContainsString('This video was written to end with them married', $brief);
        $this->assertStringContainsString('the summary is what the video has and the summary is what you may promise', $brief);
        // The 3f sentence is untouched: it is what makes this true of story 38.
        $this->assertStringContainsString('a wedding, a marriage or a proposal only if it says one happened', $brief);
    }

    // -- Gate 1's positive check ------------------------------------------------

    /**
     * RED/GREEN ON STORY 39'S OWN SUMMARY, and the RED half is why the check
     * is sentence-scoped.
     */
    public function test_red_green_the_last_acts_summary_states_the_chosen_state_beside_the_partners_name(): void
    {
        $red = $this->reviewWithSummary(PartnerEndState::Married, self::STORY_39_TAIL);
        $this->assertStringContainsString('are married by the end, and act 5\'s summary never says so', implode(' ', $red));
        $this->assertStringContainsString('"married", "marry", "marries"', implode(' ', $red));

        $green = $this->reviewWithSummary(
            PartnerEndState::Married,
            'A year later, at a client evening in Hangzhou, Nancy and I had been married four months and '
            .'I was the one carrying the chairs on purpose.',
        );
        $this->assertStringNotContainsString('never says so', implode(' ', $green));
    }

    /**
     * THE VACUOUS VERSION OF THIS CHECK, PINNED. A word search over the whole
     * summary passes story 39 for an end state it does not have: "together"
     * is in it twice and both are the antagonist about the accomplice.
     */
    public function test_a_summary_wide_word_search_would_have_passed_story_39(): void
    {
        $this->assertStringContainsString(
            'together',
            mb_strtolower(self::STORY_39_TAIL),
            'The fixture has to contain the word for the naive check to pass it.',
        );

        $warnings = $this->reviewWithSummary(
            PartnerEndState::Together,
            'Jenny says her line again: they grew up together, he knows the real her. A year later Nancy '
            .'hands me the folder and I am the one carrying the chairs.',
        );

        $this->assertStringContainsString('never says so', implode(' ', $warnings));
    }

    /**
     * "Her partner" IS together, and that is the finding rather than a gap.
     * The floor state takes every other state's words, because married,
     * engaged and living together each entail being together.
     */
    public function test_partner_satisfies_together_and_not_married(): void
    {
        $this->assertStringNotContainsString(
            'never says so',
            implode(' ', $this->reviewWithSummary(PartnerEndState::Together, self::STORY_39_TAIL)),
        );

        $married = 'A year later Nancy and I were married in the spring.';
        $this->assertStringNotContainsString(
            'never says so',
            implode(' ', $this->reviewWithSummary(PartnerEndState::Together, $married)),
        );
        $this->assertStringNotContainsString(
            'never says so',
            implode(' ', $this->reviewWithSummary(PartnerEndState::Married, $married)),
        );
    }

    /**
     * AN ACCENT AND A PHRASE BOTH COUNT, and a word inside a longer word does
     * not.
     *
     * "fiancée" is here because it is the natural spelling of the one end
     * state whose commonest word carries an accent, and because the first
     * matcher written for this was a `\pL` lookaround pair justified by the
     * claim that `\b` could not handle it. Measured, `\b` handles it: PHP's
     * `/u` turns on UCP. The claim was reasoned and wrong, and the drill that
     * removed the lookarounds is what said so.
     *
     * "Wednesday" is the other direction: the word lists are deliberately
     * generous because this check reports an ABSENCE, and "wed" inside
     * "Wednesday" is exactly the over-report that generosity buys without a
     * word boundary.
     */
    public function test_an_accent_and_a_phrase_count_and_a_word_inside_a_word_does_not(): void
    {
        $this->assertStringContainsString(
            'never says so',
            implode(' ', $this->reviewWithSummary(
                PartnerEndState::Married,
                'A year later Nancy came by on the Wednesday and I was the one carrying the chairs.',
            )),
            '"wed" must not match inside "Wednesday".',
        );

        $this->assertStringNotContainsString(
            'never says so',
            implode(' ', $this->reviewWithSummary(
                PartnerEndState::Engaged,
                'A year later Nancy was my fiancée and I was the one carrying the chairs.',
            )),
        );

        // And a phrase, which is the other thing `\b` is awkward about.
        $this->assertStringNotContainsString(
            'never says so',
            implode(' ', $this->reviewWithSummary(
                PartnerEndState::LivingTogether,
                'A year later Nancy and I were living together above the workshop.',
            )),
        );
    }

    /**
     * NULL IS NOT A MISSING ANSWER. A story outlined before the column
     * existed, or one whose partner the outline invented, was never asked —
     * reporting it would put a finding nobody can act on onto every earlier
     * story, which is the legacy-age treatment this project applies four
     * times over.
     */
    public function test_a_story_that_never_chose_is_not_reported(): void
    {
        $this->assertSame([], array_values(array_filter(
            $this->reviewWithSummary(null, self::STORY_39_TAIL),
            fn (string $w): bool => str_contains($w, 'never says so'),
        )));
    }

    /** The check needs no chapter rows: it reads the act's summary. */
    public function test_the_check_reads_the_summary_and_not_the_chapters(): void
    {
        $story = $this->storyWithState(PartnerEndState::Married);
        Act::factory()->for($story)->create([
            'sequence' => 5,
            'phase' => ActPhase::Refusal,
            'script' => 'She asked me to come back. I said no.',
            'summary' => self::STORY_39_TAIL,
        ]);

        $warnings = app(ValidateOutlineSpine::class)->handle($story->refresh())['warnings'];

        $this->assertStringContainsString('never says so', implode(' ', $warnings));
    }

    /**
     * AND IT FIRES BEFORE A SINGLE ACT IS BOUGHT, which is the state story 39
     * is in and the state the first version of this check could not see: it
     * waited for a script, and at `outlined` there is none.
     *
     * The repair is built when the page is read rather than frozen into the
     * sentence, because it is a different repair on each side of the first act
     * call — the outline's plan, or the act writer's own summary.
     */
    public function test_it_fires_on_an_outline_with_no_scripts_and_names_the_cheap_repair(): void
    {
        $story = $this->storyWithState(PartnerEndState::Married);
        Act::factory()->for($story)->create([
            'sequence' => 5,
            'phase' => ActPhase::Refusal,
            'script' => null,
            'summary' => self::STORY_39_TAIL,
        ]);

        $warnings = implode(' ', app(ValidateOutlineSpine::class)->handle($story->refresh())['warnings']);

        $this->assertStringContainsString('never says so', $warnings);
        $this->assertStringContainsString('writing the outline again is the cheap fix', $warnings);
        $this->assertStringNotContainsString('rewriting this act asks again', $warnings);
    }

    // ---------------------------------------------------------------------------

    /** @return array<int, string> */
    private function reviewWithSummary(?PartnerEndState $state, string $summary): array
    {
        $story = $this->storyWithState($state);

        $act = Act::factory()->for($story)->create([
            'sequence' => 5,
            'phase' => ActPhase::Refusal,
            'script' => 'She asked me to come back. I said no. '.$summary,
            'summary' => $summary,
        ]);

        \App\Models\Chapter::factory()->forAct($act)->atSequence(1, 1)->create(['title' => 'The Corridor']);

        return app(ValidateOutlineSpine::class)->handle($story->refresh())['warnings'];
    }

    private function storyWithState(?PartnerEndState $state): Story
    {
        return Story::factory()->status(StoryStatus::Outlined)->create([
            'format' => StoryFormat::Single,
            'ending' => StoryEnding::NewLife,
            'partner_end_state' => $state,
            'outline_cast' => self::CAST,
            'refusal' => 'I said no.',
        ]);
    }

    /** @param  array<string, mixed>  $attributes */
    private function draftWithChosenCast(array $attributes = []): Story
    {
        return Story::factory()->status(StoryStatus::Draft)->create($attributes + [
            'format' => StoryFormat::Single,
            'locale_profile' => 'en-US',
            'ending' => StoryEnding::NewLife,
            'outline_cast' => self::CAST,
            'premise' => 'She held his hand at our reunion and told eleven of our friends I had been gone for years.',
        ]);
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
