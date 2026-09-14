<?php

namespace Tests\Feature\Providers;

use Anthropic\Client;
use App\Actions\GenerateOutline;
use App\Contracts\ScriptWriter;
use App\Enums\StoryStatus;
use App\Models\Story;
use App\Services\Claude\ClaudeScriptWriter;
use App\Services\Fake\FakeScriptWriter;
use App\Support\CharacterTextGuard;
use App\Support\ChapterAnnouncement;
use App\Support\LocaleGuard;
use App\Support\Providers\ActOutline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

/**
 * ACT 1 IS WHERE INSTRUCTIONS COLLIDE, AND UNTIL NOW NOTHING ASKED WHETHER
 * THEY CAN ALL HOLD AT ONCE.
 *
 * ---------------------------------------------------------------------------
 * THE INSTANCE
 * ---------------------------------------------------------------------------
 *
 * Story 30's act 1 dropped the stored hook and two of its five beats. Beat 4
 * — one small, cold action, explicitly "not a confrontation" — came back as
 * an answer-back, and beat 5's promise of the departure was not written at
 * all. The chapter announcement went missing from the act entirely.
 *
 * Nothing was broken. Every instruction was correct, current, and measured
 * against a real video:
 *
 *   1. `hookInstruction()` — five beats, beat 4 a cold action and NOT a
 *      confrontation, because the confrontation is the final act and
 *      spending it in the opening spends the video.
 *   2. `endingFor(Escalation)` — the narrator answers back in EVERY SCENE the
 *      antagonist is in, added from the transcript, where a narrator who says
 *      "Yes, Mother" for twenty minutes loses the audience in three.
 *   3. `chapterInstruction()` — every chapter opens by SPEAKING ITS NUMBER,
 *      also from the transcript, where the cold open runs 62 seconds and then
 *      "chapter 1" is spoken.
 *
 * Act 1 is the only act that carries all three, and the antagonist speaks
 * inside the hook (beat 3 is her justification, quoted), so rule 2's "every
 * scene" reaches inside rule 1's beats and contradicts beat 4 directly. It
 * arrived after the beats in the prompt and it won.
 *
 * ---------------------------------------------------------------------------
 * WHY THIS IS ITS OWN FILE
 * ---------------------------------------------------------------------------
 *
 * **A contract that was built and measured was silently overridden by a
 * prompt edit made somewhere else.** Each of the three has its own tests and
 * all of them were green: `ChapterUnitTest` asserts the announcement is
 * asked for, `ContactThroughTheMiddleTest` asserts the answer-back is asked
 * for, `OutlineSpineTest` asserts the beats are asked for. Every one of them
 * checks that its own instruction is PRESENT. Not one could see that another
 * instruction present in the same prompt makes it unfollowable.
 *
 * That is the axis question from CLAUDE.md pointed at a prompt: three checks
 * on three subjects, none of them on the interaction, and the interaction is
 * where the defect lives. So the assertions here are deliberately about PAIRS
 * — for each rule that reaches into the opening, the block that owns the
 * opening names it and says which wins.
 */
class ActOneOpeningContractTest extends TestCase
{
    use RefreshDatabase;

    private FakeScriptWriter $writer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->writer = app(ScriptWriter::class);
    }

    /**
     * The three instructions are all present in act 1's prompt.
     *
     * The precondition for everything below. If one of them ever stops being
     * sent, the collision assertions become vacuous — a guard whose input
     * cannot contain the defect — and this is what goes red instead.
     */
    public function test_act_one_carries_all_three_opening_instructions(): void
    {
        $prompt = $this->actOnePrompt();

        $this->assertStringContainsString('Five beats, in this order', $prompt, 'the hook');
        $this->assertStringContainsString('AND THE NARRATOR ANSWERS BACK', $prompt, 'the answer-back');
        $this->assertStringContainsString('EVERY CHAPTER OPENS BY SPEAKING ITS NUMBER', $prompt, 'the announcement');
    }

    /**
     * The answer-back rule says "every scene" and the opening is the one
     * place it does not reach — stated on BOTH sides, because either alone
     * is a rule that has to be remembered rather than read.
     */
    public function test_the_answer_back_rule_names_the_opening_as_its_exception(): void
    {
        $prompt = $this->actOnePrompt();

        // On the block that owns the opening.
        $this->assertStringContainsString(
            'The narrator answers back in every scene the antagonist is in. NOT HERE.',
            $prompt,
        );
        $this->assertStringContainsString('Beat 4 is the narrator\'s move in the opening', $prompt);

        // And on the rule itself, where a writer reading top to bottom meets
        // it first.
        $this->assertStringContainsString('THE EXCEPTION IS THE OPENING OF THIS ACT', $prompt);
        $this->assertStringContainsString('beat 4 is a cold action', $prompt);

        // Beat 4's own wording is untouched: the answer-back did not soften
        // it, which was the other available "fix" and is the wrong one.
        $this->assertStringContainsString(
            'ONE small, cold action by the narrator. Not a confrontation',
            $prompt,
        );
    }

    /** And on an act that has no opening, the exception is not mentioned at all. */
    public function test_a_later_escalation_act_gets_the_answer_back_with_no_exception(): void
    {
        $prompt = $this->actPromptFor(2);

        $this->assertStringContainsString('AND THE NARRATOR ANSWERS BACK', $prompt);
        $this->assertStringNotContainsString('THE EXCEPTION IS THE OPENING OF THIS ACT', $prompt);
        $this->assertStringNotContainsString('Five beats, in this order', $prompt);
    }

    /**
     * The chapter announcement is act 1's second collision, and it is the one
     * that actually went missing in the prose rather than merely being
     * reshaped: acts 1 and 4 of story 30 announced nothing at all.
     */
    public function test_the_chapter_announcement_defers_to_the_hook_and_then_takes_over(): void
    {
        $prompt = $this->actOnePrompt();

        $this->assertTrue(ChapterAnnouncement::enabled());

        // The chapter block hands act 1 over rather than restating the rule:
        // one owner, so the two cannot drift apart the way the retry prompt
        // and CharacterTextGuard did.
        $this->assertStringContainsString('THIS ACT IS THE EXCEPTION', $prompt);
        $this->assertStringContainsString('the OPENING block below sets its order', $prompt);

        // The opening block says what follows the beats, and it names the
        // exact sentence rather than "the chapter number" — the same string
        // ChapterAnnouncement gives the check that reads it back.
        $this->assertStringContainsString(
            'THEN, AND ONLY THEN, THE VIDEO STARTS: "'.ChapterAnnouncement::sentenceFor(1).'"',
            $prompt,
        );
        $this->assertStringContainsString('every other rule in this prompt is back in force', $prompt);
    }

    /**
     * The re-hook is the third, and it is the quietest: with the number
     * spoken first, "the chapter's opening line" stops meaning the re-hook.
     *
     * Story 30 stored "Chapter three." as the `rehook_line` of four of its
     * twelve chapters, so `acts.is_rehook_written` said yes on acts whose
     * recorded opening line was a chapter marker, and Gate 1's re-hook
     * advisory could not fire on them.
     */
    public function test_the_rehook_is_asked_for_as_the_sentence_after_the_number(): void
    {
        $prompt = $this->actOnePrompt();

        $this->assertStringContainsString('the chapter\'s RE-HOOK, quoted back exactly', $prompt);
        $this->assertStringContainsString('Never the spoken chapter number', $prompt);
        $this->assertStringContainsString('its re-hook line is the first sentence after the chapter number', $prompt);
    }

    /**
     * The ordering, asserted rather than left to chance.
     *
     * Not the mechanism — the blocks above name their own exceptions in
     * words, which is what a model actually reads — but an instruction about
     * the first thirty seconds should not be the furthest thing in the prompt
     * from the request that follows it. Both blocks that displaced it now
     * come first.
     */
    public function test_the_opening_block_comes_after_the_rules_that_reach_into_it(): void
    {
        $prompt = $this->actOnePrompt();

        $opening = mb_strpos($prompt, 'THE OPENING. THIS ACT OPENS THE VIDEO');
        $answerBack = mb_strpos($prompt, 'AND THE NARRATOR ANSWERS BACK');
        $chapters = mb_strpos($prompt, 'WRITE THIS ACT AS CHAPTERS');

        $this->assertIsInt($opening);
        $this->assertIsInt($answerBack);
        $this->assertIsInt($chapters);

        $this->assertGreaterThan($answerBack, $opening);
        $this->assertGreaterThan($chapters, $opening);
    }

    /**
     * THE FOURTH INSTRUCTION, and the collision it was placed to avoid.
     *
     * The betrayal scene is chapter one, so it meets all three rules above at
     * once: it follows the spoken number, its first sentence is the re-hook,
     * the answer-back is back in force inside it, and beat 4's "not a
     * confrontation" must not reach it. It lives INSIDE the opening block —
     * one owner — rather than as a fifth block the others would argue with.
     */
    public function test_chapter_one_is_the_betrayal_scene_and_every_opening_rule_agrees(): void
    {
        $prompt = $this->actOnePrompt();

        $then = mb_strpos($prompt, 'THEN, AND ONLY THEN, THE VIDEO STARTS');
        $scene = mb_strpos($prompt, 'CHAPTER ONE IS THE BETRAYAL SCENE');
        $hook = mb_strpos($prompt, 'THIS IS THE OPENING THE OUTLINE WROTE FOR THIS STORY');

        $this->assertIsInt($then);
        $this->assertIsInt($scene, 'Act 1 was not handed the betrayal scene.');
        $this->assertIsInt($hook);

        // After the number and the re-hook are placed; before the stored hook,
        // which keeps the closing position story 30 showed it needs.
        $this->assertGreaterThan($then, $scene);
        $this->assertLessThan($hook, $scene);

        $this->assertStringContainsString('Straight after the chapter number and its re-hook', $prompt);

        // The answer-back: excepted inside the beats, back in force in the scene.
        $this->assertStringContainsString('the answer-back begins at the first scene AFTER the beats', $prompt);
        $this->assertStringContainsString('The answer-back is back in force here', $prompt);

        // Beat 4 no longer reaches it: a lost round is not the reckoning.
        $this->assertStringContainsString('A round the narrator loses is not the reckoning', $prompt);
        $this->assertStringNotContainsString('The confrontation is the final act', $prompt);

        // And the second landing of the justification points HERE, not at act 2 or 3.
        $this->assertStringContainsString('THE BETRAYAL SCENE KEEPS IT', $prompt);
        $this->assertStringNotContainsString('act 2 or 3', $prompt);
    }

    /**
     * THE COLLISION THIS FILE MISSED, FOUND BY THE FIRST REAL CALL.
     *
     * The betrayal block closed on "the scene is the chapter". The chapter
     * block says 2-4 chapters per act. Story 33's first act-1 call returned one
     * chapter of 479 words that stopped before the antagonist entered, at 1,449
     * output tokens, and was refused by the bound — billed, not stored. The test
     * above asserted the scene's POSITION against three rules and never asked
     * whether it claimed the act's LENGTH, which is the fourth rule it touches.
     */
    public function test_the_betrayal_scene_does_not_claim_the_whole_act(): void
    {
        $prompt = $this->actOnePrompt();

        $this->assertStringContainsString('WRITE THIS ACT AS CHAPTERS', $prompt, 'precondition: the count rule is present');
        $this->assertStringContainsString('Never fewer than', $prompt);

        $this->assertStringContainsString('THE SCENE IS CHAPTER ONE, NOT THE WHOLE ACT', $prompt);
        $this->assertStringContainsString('the act goes on into its further chapters', $prompt);
        $this->assertStringNotContainsString('the scene is the chapter.', $prompt);
    }

    // -- Helpers -------------------------------------------------------------

    private function actOnePrompt(): string
    {
        return $this->actPromptFor(1);
    }

    private function actPromptFor(int $sequence): string
    {
        $story = $this->outlinedStory();

        $outline = $story->acts()->orderBy('sequence')->get()
            ->map(fn ($act): ActOutline => new ActOutline(
                sequence: $act->sequence,
                title: (string) $act->title,
                summary: (string) $act->summary,
                escalationBeat: (string) $act->escalation_beat,
                phase: $act->phase,
                timeframe: $act->timeframe,
            ))
            ->all();

        $writer = new ClaudeScriptWriter(
            client: app(Client::class),
            locale: app(LocaleGuard::class),
            text: app(CharacterTextGuard::class),
        );

        return (new ReflectionMethod($writer, 'actPrompt'))->invoke(
            $writer,
            $story,
            $outline[$sequence - 1],
            $outline,
            $sequence === 1 ? [] : ['Act 1 happened.'],
            985,
        );
    }

    private function outlinedStory(): Story
    {
        $story = Story::factory()->single()->create(['status' => StoryStatus::Draft]);

        app(GenerateOutline::class)->handle($story);

        return $story->refresh();
    }
}
