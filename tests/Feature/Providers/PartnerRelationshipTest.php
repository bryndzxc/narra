<?php

namespace Tests\Feature\Providers;

use Anthropic\Client;
use App\Enums\ActPhase;
use App\Enums\StoryFormat;
use App\Models\Act;
use App\Models\Story;
use App\Services\Claude\ClaudeMetadataWriter;
use App\Services\Claude\ClaudeScriptWriter;
use App\Support\CharacterTextGuard;
use App\Support\LocaleGuard;
use App\Support\Providers\ActOutline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

/**
 * WHAT THE PARTNER IS TO THE NARRATOR BY THE END — asked for, stage by stage.
 *
 * Story 38 (CLAUDE.md 3f, second reading): the future partner was in seven
 * scenes and the last chapter, and every one of them read as a colleague. The
 * writers were told who the partner ARRIVES as and never what the two of them
 * become, so nothing produced a couple. Same shape as the expression field and
 * the hook: nothing asked, so nothing came.
 *
 * Every stage that writes about the partner, with its arrival assertion: the
 * outline (and NOT the premise, the operator's decision), each act phase, the
 * last chapter, the scene call on the act that carries it, and the metadata
 * brief. There is deliberately no check on the prose: whether two people are
 * written as a couple is a reading, and the operator reads the last chapter at
 * Gate 1.
 */
class PartnerRelationshipTest extends TestCase
{
    use RefreshDatabase;

    private const CAST = [
        ['name' => 'Jason Kong', 'role' => 'narrator', 'relationship' => 'the narrator'],
        ['name' => 'Nicole Pei', 'role' => 'antagonist', 'relationship' => 'my wife'],
        ['name' => 'Chloe Rong', 'role' => 'future_partner', 'relationship' => 'Nicole\'s best friend since university'],
    ];

    public function test_the_outline_asks_what_the_partner_is_by_the_end_and_the_premise_does_not(): void
    {
        $story = Story::factory()->create(['format' => StoryFormat::Single, 'locale_profile' => 'en-US']);

        $outline = $this->flat($this->invoke($this->claude(), 'outlinePrompt', $story, 5));
        $this->assertStringContainsString('IF YOUR CAST HAS A FUTURE PARTNER', $outline);
        $this->assertStringContainsString('the act summaries have to say so', $outline);

        // "a couple, together, a year on" WAS this assertion, and it was the
        // defect: story 39's outline answered it with "introduces me to a room
        // as her partner" against an idea that said married, because those
        // three were the only words on offer. With no state chosen the four
        // are named and the weak ones are marked as the default.
        $this->assertStringNotContainsString('by the end: a couple, together, a year on', $outline);
        $this->assertStringContainsString('married, engaged, living together, or together and nothing further', $outline);
        $this->assertStringContainsString('say the least of any of them', $outline);

        // THE OUTLINE IS WHERE THE THREE BEATS GET DISTRIBUTED, because it
        // writes the act summaries the act writer is built from. This
        // assertion did not exist until a drill that reverted the outline
        // to one moment stayed GREEN — every other case here reads the ACT
        // prompt, so the stage that decides where the beats go was covered
        // by nothing.
        $this->assertStringContainsString('THREE MOMENTS GET THEM THERE AND THEY ESCALATE BY WHO CAN SEE THEM', $outline);
        $this->assertStringContainsString('two in the search act, one in the refusal act', $outline);
        $this->assertStringContainsString('the antagonist is one of them', $outline);
        $this->assertStringContainsString('NOBODY SEES', $outline);
        $this->assertStringContainsString('names both of its moments', $outline);

        // The premise keeps its line and gains nothing: the relationship lives
        // downstream of it, by the operator's decision.
        $premise = $this->flat($this->invoke($this->claude(), 'premisePrompt', $story, 'My wife cheated with her intern.', 3));
        $this->assertStringContainsString('What happens between them later is the video\'s, not the premise\'s.', $premise);
        $this->assertStringNotContainsString('IF YOUR CAST HAS A FUTURE PARTNER', $premise);
        $this->assertStringNotContainsString('a couple', $premise);
    }

    /**
     * Each phase says what they are to each other THERE: nothing romantic
     * before the narrator leaves, THREE MOMENTS after it, and together by
     * the refusal. And it reaches the act prompt, not only the builder.
     *
     * The three escalate by WHO CAN SEE THEM — private, then in front of
     * people with the antagonist among them, then one nobody sees at all.
     * That axis is a correction to the obvious one: "closer" is not a
     * property of prose anybody can write to, and being seen is what makes
     * a beat cost the antagonist something, which is what this genre pays
     * off on.
     */
    public function test_each_act_phase_says_what_they_are_to_each_other_there(): void
    {
        $story = Story::factory()->create(['outline_cast' => self::CAST]);

        $early = $this->arc($story, ActPhase::Escalation);
        $this->assertStringContainsString('only what they arrive as — Nicole\'s best friend since university', $early);
        $this->assertStringContainsString('Nothing romantic between them here', $early);
        $this->assertSame($early, $this->arc($story, ActPhase::Departure));

        // THREE BEATS, AND THEY ESCALATE BY WHO CAN SEE THEM. A stated
        // COUNT rather than "several", because a count is satisfiable
        // exactly and steers absolutely where a quantity of prose does not.
        $search = $this->arc($story, ActPhase::Search);
        $this->assertStringContainsString('TWO MOMENTS', $search);
        $this->assertStringContainsString('THEY ESCALATE BY WHO CAN SEE THEM', $search);
        $this->assertStringContainsString('the first is private or nearly so', $search);
        $this->assertStringContainsString('the antagonist is one of them', $search);
        $this->assertStringNotContainsString('Nothing romantic', $search);

        // The bound that stops two moments becoming a mood over the act.
        $this->assertStringContainsString('not a mood over the act', $search);

        // AND THE SUMMARY JOB, which is the whole of why this is not a
        // prompt line alone: GenerateActScripts REPLACES the outline's
        // summary with the act writer's own, and the refusal act is written
        // from it. Story 38's search summary happened to keep the partner
        // twice; nothing had asked it to.
        $this->assertStringContainsString('SENTENCE FOUR OF YOUR SUMMARY NAMES BOTH', $search);

        $refusal = $this->arc($story, ActPhase::Refusal);
        $this->assertStringContainsString('the narrator and Chloe Rong are together', $refusal);
        $this->assertStringContainsString('Sentence four of the summary says', $refusal);

        // The third beat, and the inversion: the two that escalate are
        // public, and the one that pays off is not for anybody.
        $this->assertStringContainsString('NOBODY SEES THIS ONE', $refusal);
        $this->assertStringContainsString('not for an audience', $refusal);

        $act = new ActOutline(sequence: 4, title: 'The Search', summary: 'She looks.', phase: ActPhase::Search);
        $prompt = $this->flat($this->invoke($this->claude(), 'actPrompt', $story, $act, [$act], [], 1000));
        $this->assertStringContainsString('THE PERSON THE NARRATOR ENDS UP WITH: Chloe Rong. In this act it becomes more', $prompt);
    }

    public function test_no_partner_and_no_phase_say_nothing(): void
    {
        $alone = Story::factory()->create(['outline_cast' => array_slice(self::CAST, 0, 2)]);
        $this->assertSame('', $this->arc($alone, ActPhase::Refusal));

        $partnered = Story::factory()->create(['outline_cast' => self::CAST]);
        $phaseless = new ActOutline(sequence: 2, title: 'T', summary: 'S', phase: null);
        $this->assertSame('', $this->invoke($this->claude(), 'partnerArcFor', $partnered, $phaseless));

        $anthology = Story::factory()->create(['format' => StoryFormat::Anthology]);
        $this->assertStringNotContainsString('IF YOUR CAST HAS A FUTURE PARTNER', $this->invoke($this->claude(), 'outlinePrompt', $anthology, 4));
    }

    public function test_the_last_chapter_makes_them_a_couple_and_not_a_boss_at_a_staff_dinner(): void
    {
        $story = Story::factory()->create(['refusal' => 'I said no.', 'outline_cast' => self::CAST]);
        $act = new ActOutline(sequence: 5, title: 'T', summary: 'S', phase: ActPhase::Refusal);
        $ending = $this->flat($this->invoke($this->claude(), 'endingFor', $story, $act, true));

        $this->assertStringContainsString(
            'CHLOE RONG IS IN THE SCENE, ON SCREEN, AND THIS IS WHAT THE TWO OF THEM ARE TO EACH OTHER NOW',
            mb_strtoupper($ending),
        );
        $this->assertStringContainsString('Chloe Rong arrived as Nicole\'s best friend since university', $ending);
        $this->assertStringContainsString('Never a manager and an employee', $ending);
        $this->assertStringContainsString('if the scene could be read as work, it is the wrong scene', $ending);
    }

    /**
     * The scene call decides who is in a frame and how they stand, and it
     * hears about the partner on the two acts the act prompt writes her into:
     * the SEARCH act's two moments and the REFUSAL act's last chapter.
     *
     * The search half was missing for a phase, and story 38 is what that cost:
     * its search act gave her four scenes and drew all four as work — the
     * operations director, a rebate schedule, a line review — because nothing
     * at scene resolution had been told she existed, let alone that one of
     * those frames was meant to be the moment. `partnerArcFor(Search)` had
     * been asking the ACT writer for it the whole time.
     *
     * Still nothing on an escalation or departure act: drawing them as a
     * couple before the narrator leaves is the romance the operator ruled out.
     * And nothing on her ending's last act, where the partner is not on screen.
     */
    public function test_the_scene_call_names_the_partner_on_the_search_act_and_the_new_lifes_act(): void
    {
        $story = Story::factory()->create(['outline_cast' => self::CAST]);

        // The last chapter: what they ARE.
        $refusal = Act::factory()->for($story)->create(['sequence' => 5, 'phase' => ActPhase::Refusal]);
        $this->assertStringContainsString('THE NARRATOR\'S PARTNER: Chloe Rong', $this->invoke($this->claude(), 'sceneContext', $story, $refusal));
        $this->assertStringContainsString('never across a desk', $this->invoke($this->claude(), 'sceneContext', $story, $refusal));

        // The search act: the TWO MOMENTS, and the bound that stops them
        // becoming a mood over every frame she is in.
        $search = Act::factory()->for($story)->create(['sequence' => 4, 'phase' => ActPhase::Search]);
        $scene = $this->flat($this->invoke($this->claude(), 'sceneContext', $story, $search));

        $this->assertStringContainsString('THE NARRATOR\'S PARTNER: Chloe Rong', $scene);
        $this->assertStringContainsString('TWO MOMENTS', $scene);
        $this->assertStringContainsString('not a work frame', $scene);
        $this->assertStringContainsString('EVERY OTHER FRAME SHE IS IN THIS ACT IS NOT ONE OF', $scene);

        // The pictures carry the same axis as the prose: the second moment
        // has the people who can see it in the frame.
        $this->assertStringContainsString('WHO IS WATCHING', $scene);
        $this->assertStringContainsString('the people '.'who can see it are in the frame too', $scene);

        // It agrees with the act prompt rather than restating it differently:
        // both ask for two moments, and both say what she is in every other
        // scene of that act.
        $this->assertStringContainsString('TWO MOMENTS', $this->arc($story, ActPhase::Search));

        // AND IT DOES NOT HAND THE SEARCH ACT THE ENDING. The relationship
        // LINE is written for the outline and routinely carries the outcome
        // in it; the first draft of this block interpolated it, so a frame
        // instruction about two people BECOMING something told the scene
        // writer what they become. The act prompt had always said 'what they
        // arrived as' instead, and now both do. Found by rendering story
        // 39's real prompt, not by a test — which is why there is now one.
        $ending = Story::factory()->create([
            'outline_cast' => [
                ['name' => 'Jason Kong', 'role' => 'narrator', 'relationship' => 'the narrator'],
                ['name' => 'Nicole Pei', 'role' => 'antagonist', 'relationship' => 'my wife'],
                ['name' => 'Chloe Rong', 'role' => 'future_partner',
                    'relationship' => 'her university roommate, and who by the end of this story is my wife'],
            ],
        ]);
        $endingAct = Act::factory()->for($ending)->create(['sequence' => 4, 'phase' => ActPhase::Search]);
        $leaky = $this->flat($this->invoke($this->claude(), 'sceneContext', $ending, $endingAct));

        $this->assertStringContainsString('THE NARRATOR\'S PARTNER: Chloe Rong', $leaky);
        $this->assertStringNotContainsString('by the end of this story is my wife', $leaky);
        $this->assertStringContainsString('becoming more than what they arrived as', $leaky);

        $early = Act::factory()->for($story)->create(['sequence' => 1, 'phase' => ActPhase::Escalation]);
        $this->assertStringNotContainsString('THE NARRATOR\'S PARTNER', $this->invoke($this->claude(), 'sceneContext', $story, $early));

        $departure = Act::factory()->for($story)->create(['sequence' => 3, 'phase' => ActPhase::Departure]);
        $this->assertStringNotContainsString('THE NARRATOR\'S PARTNER', $this->invoke($this->claude(), 'sceneContext', $story, $departure));

        $hers = Story::factory()->endsInTheAntagonistsVoice()->create(['outline_cast' => self::CAST]);
        $hersRefusal = Act::factory()->for($hers)->create(['sequence' => 5, 'phase' => ActPhase::Refusal]);
        $this->assertStringNotContainsString('THE NARRATOR\'S PARTNER', $this->invoke($this->claude(), 'sceneContext', $hers, $hersRefusal));

        // The search line is NOT gated on the ending, and that is deliberate:
        // the act prompt asks for the moment whatever the ending is, and the
        // point of this block is that the two stages agree. A partner on her
        // ending already gets a Gate 1 warning; it does not get a prompt that
        // disagrees with itself.
        $hersSearch = Act::factory()->for($hers)->create(['sequence' => 4, 'phase' => ActPhase::Search]);
        $this->assertStringContainsString('THE NARRATOR\'S PARTNER', $this->invoke($this->claude(), 'sceneContext', $hers, $hersSearch));
    }

    /**
     * RED/GREEN on story 38's shape: a partner in the cast and a last act whose
     * summary says she poured him a drink at a staff dinner. The brief bounds
     * the promise by that summary, and the summary is in the brief to read.
     */
    public function test_red_green_the_title_may_promise_only_what_the_last_summary_says(): void
    {
        $story = Story::factory()->create(['outline_cast' => self::CAST]);
        Act::factory()->for($story)->create([
            'sequence' => 5,
            'phase' => ActPhase::Refusal,
            'summary' => 'A year on, at the workshop\'s spring dinner, Chloe Rong poured for him.',
        ]);

        $brief = $this->flat($this->invoke(new ClaudeMetadataWriter(app(Client::class), app(LocaleGuard::class)), 'storyBrief', $story->refresh()));

        $this->assertStringContainsString('What the two of them are to each other at the end is what the LAST ACT\'S SUMMARY below says', $brief);
        $this->assertStringContainsString('a wedding, a marriage or a proposal only if it says one happened', $brief);
        $this->assertStringContainsString('Chloe Rong poured for him', $brief, 'The summary the promise is bounded by is in the brief.');
        $this->assertGreaterThan(
            strpos($brief, 'LAST ACT\'S SUMMARY'),
            strpos($brief, 'Chloe Rong poured for him'),
            '"below" has to be true: the acts follow the ending line.',
        );
    }

    private function arc(Story $story, ActPhase $phase): string
    {
        $act = new ActOutline(sequence: 3, title: 'T', summary: 'S', phase: $phase);

        return $this->flat($this->invoke($this->claude(), 'partnerArcFor', $story, $act));
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
