<?php

namespace Tests\Feature\Providers;

use Anthropic\Client;
use App\Enums\ActPhase;
use App\Models\Story;
use App\Services\Claude\ClaudeScriptWriter;
use App\Support\CharacterTextGuard;
use App\Support\LocaleGuard;
use App\Support\Providers\ActOutline;
use App\Support\SpineQuestions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

/**
 * The escalation phase asks for an ATTEMPT, and the audience knows what the
 * card is.
 *
 * ---------------------------------------------------------------------------
 * THE MEASUREMENT, 2026-09-20
 * ---------------------------------------------------------------------------
 *
 * The first direct audience feedback on this channel: three commenters,
 * independently, across three videos, called the narrator passive — "should
 * have stood up for himself by now", "that's what you get for a doormat simp",
 * "kept giving non-stop for no reason at all". One of the three is story 33,
 * written after the answer-back register landed, so whatever they were
 * reacting to survived that change.
 *
 * Measured on the escalation and departure acts of the four published stories
 * (33, 36, 37, 38), 49 rounds by hand and cross-checked by a said-tag counter:
 *
 *   - the narrator speaks in 30 of 49 (the register fix works)
 *   - the narrator complies with the SUBSTANCE in 46 of 49
 *   - there are 3 counter-moves in the whole corpus
 *   - 7 rounds are the reference's shape: resisted, and lost because the room
 *     sided with the antagonist
 *
 * And all 53 escalation and departure beats in the database read "the narrator
 * loses X". Not one names a thing the narrator tried.
 *
 * The cause was an asymmetry inside one enum. `SpineQuestions::reversalBeats()`
 * has always required the antagonist's losses to be the price of ATTEMPTS — "a
 * search that costs her nothing is a montage of somebody looking worried" —
 * while `beatLabel()` asked the escalation half for a cost and nothing else.
 * Her losses were the price of trying; his were required to be nothing but
 * losses.
 *
 * An attempt that fails is the only move that produces an EXTERNAL loss
 * without winning a round, which is why the arc is untouched by this and
 * `endsWorseForNarrator()` is too.
 *
 * ---------------------------------------------------------------------------
 * AND THE SECOND HALF: "COMES OUT" MEANT "IS REVEALED TO THE VIEWER"
 * ---------------------------------------------------------------------------
 *
 * Three phases said "the withheld information does not come out here" and the
 * refusal said "comes out here and nowhere earlier". Measured in rendered
 * time, the card's CONTENT reaches the viewer at 36% of story 33, 51% of 37,
 * 75% of 36 and 75% of 38 — story 36 shows the same pink envelope five times
 * across twenty-two minutes and never says what it can do.
 *
 * This contract draws the audience/antagonist split correctly twice, one
 * paragraph away: `endingFor(Departure)` on where the narrator went, and hook
 * beat 4 on the cold action. It was never drawn for the one field where the
 * whole middle depends on it.
 *
 * These are reflection tests because a prompt rule dropped from one of the
 * places it lives is the shape this project keeps paying for. There is NO
 * mechanism behind either request and that is deliberate and recorded in
 * `ValidateOutlineSpine::checkEscalation()`: the obvious ATTEMPT_MARKERS list
 * was built, measured against all 53 stored beats, and clears 17 of them on
 * incidental words ("being TOLD to his face", "my SAY in my own home") — a
 * 100% false-negative rate on what it passes. The measurement is the next
 * story, not a green test.
 */
class FailedAttemptTest extends TestCase
{
    use RefreshDatabase;

    // -- The attempt ---------------------------------------------------------

    public function test_the_beat_label_asks_for_the_attempt_and_the_cost(): void
    {
        $this->assertStringContainsString('what the narrator tried', ActPhase::Escalation->beatLabel());
        $this->assertStringContainsString('what it cost them that it failed', ActPhase::Escalation->beatLabel());
        $this->assertStringContainsString('what the narrator tried last', ActPhase::Departure->beatLabel());

        // The old shape, which asked for a cost and nothing else.
        $this->assertStringNotContainsString(
            'what this act costs the narrator',
            ActPhase::Escalation->beatLabel(),
        );

        // The search half is untouched: it already had both halves, and it is
        // the thing the escalation half was made to mirror.
        $this->assertStringContainsString('what this attempt costs the antagonist', ActPhase::Search->beatLabel());
    }

    public function test_the_phase_guidance_shown_at_gate_one_asks_for_the_attempt(): void
    {
        $escalation = ActPhase::Escalation->guidance();

        $this->assertStringContainsString('THE NARRATOR TRIES SOMETHING HERE AND IT FAILS', $escalation);
        $this->assertStringContainsString('the room, the family or the institution overrides it', $escalation);
        $this->assertStringContainsString('not instead of an attempt', $escalation);

        $this->assertStringContainsString('the LAST attempt', ActPhase::Departure->guidance());

        // The answer-back survived the edit: this change is about the hands,
        // and the register fix reached the mouth and is not the complaint.
        $this->assertStringContainsString('answers back in every scene', $escalation);
    }

    public function test_the_ledger_is_unchanged_by_the_attempt(): void
    {
        // An attempt that FAILS still ends the act worse off, which is the
        // whole reason it is the move that fits this arc. If this pair ever
        // flips, the five movements have been traded for something else.
        $this->assertTrue(ActPhase::Escalation->endsWorseForNarrator());
        $this->assertTrue(ActPhase::Departure->endsWorseForNarrator());
        $this->assertFalse(ActPhase::Search->endsWorseForNarrator());
        $this->assertFalse(ActPhase::Refusal->endsWorseForNarrator());
    }

    public function test_the_outline_prompt_asks_for_both_halves_of_the_beat(): void
    {
        $prompt = $this->invoke($this->writer(), 'outlinePrompt', Story::factory()->single()->create(), 5);

        $this->assertStringContainsString('WHAT THE NARRATOR TRIED AND WHAT IT COST THEM THAT IT FAILED', $prompt);
        $this->assertStringContainsString('because the room, the family or the institution overrides it', $prompt);
        $this->assertStringContainsString('A beat that names only a loss', $prompt);

        // The old bullet, which is what produced 53 beats of pure loss.
        $this->assertStringNotContainsString('one sentence naming what this act COSTS, and to whom', $prompt);
    }

    public function test_the_escalation_ending_stages_the_attempt_failing(): void
    {
        $ending = $this->endingFor(ActPhase::Escalation);

        $this->assertStringContainsString('THE NARRATOR TRIES SOMETHING IN THIS ACT AND IT FAILS', $ending);
        $this->assertStringContainsString('Stage the attempt', $ending);

        // Off the page is the failure mode this sentence exists for: an
        // attempt the audience does not watch fail is indistinguishable from
        // a narrator who did nothing, which is what 46 of 49 rounds were.
        $this->assertStringContainsString('an attempt the audience does not watch fail', $ending);
        $this->assertStringContainsString('worse off BECAUSE the attempt failed', $ending);
    }

    public function test_the_departure_ending_carries_the_last_attempt(): void
    {
        $ending = $this->endingFor(ActPhase::Departure);

        $this->assertStringContainsString('it is the LAST attempt', $ending);
        $this->assertStringContainsString('Stage it failing', $ending);
        $this->assertStringContainsString('THEY DO NOT ANNOUNCE IT', $ending);
    }

    public function test_the_genre_contract_states_the_attempt_in_movement_one(): void
    {
        $guidance = $this->flat($this->invoke($this->writer(), 'genreGuidance', Story::factory()->single()->create()));

        $this->assertStringContainsString('THE NARRATOR TRIES, IN EVERY ONE OF THOSE ACTS, AND IS OVERRULED', $guidance);
        $this->assertStringContainsString('a man things happen to and a man losing a fight', $guidance);
        $this->assertStringContainsString('an attempt that succeeds is the thing to avoid, not an attempt', $guidance);

        // Untouched, because the arc is not what the feedback hit.
        $this->assertStringContainsString('THE SHAPE — FIVE MOVEMENTS, IN THIS ORDER', $guidance);
        $this->assertStringContainsString('NEITHER is revenge', $guidance);
    }

    /**
     * The context block names the FIELD, not half of it.
     *
     * "COSTS:" in front of "he asked for the schedule and never got it"
     * teaches the writer that the attempt is not part of the answer — the
     * label is read by every act in the story, on every call.
     */
    public function test_the_act_context_block_labels_the_beat_rather_than_the_cost(): void
    {
        $prompt = $this->actPrompt(ActPhase::Escalation);

        $this->assertStringContainsString('BEAT:', $prompt);
        $this->assertStringNotContainsString('COSTS:', $prompt);
    }

    // -- The audience / antagonist split -------------------------------------

    public function test_the_escalation_and_departure_endings_split_the_audience_from_the_antagonist(): void
    {
        foreach ([ActPhase::Escalation, ActPhase::Departure, ActPhase::Search] as $phase) {
            $ending = $this->endingFor($phase);

            $this->assertStringContainsString(
                'THE WITHHELD INFORMATION DOES NOT COME OUT TO THE ANTAGONIST HERE — AND THE AUDIENCE ALREADY HAS IT',
                $ending,
                sprintf('The %s ending does not draw the split.', $phase->value),
            );
            $this->assertStringContainsString('cannot price anything the narrator gives up', $ending);

            // The bare sentence that produced a card nobody could read.
            $this->assertStringNotContainsString(
                'The withheld information does not come out here.',
                $ending,
                sprintf('The %s ending still says it without saying to whom.', $phase->value),
            );
        }
    }

    public function test_the_refusal_ending_says_the_reveal_is_hers_and_not_the_viewers(): void
    {
        $ending = $this->endingFor(ActPhase::Refusal);

        $this->assertStringContainsString('COMES OUT TO THE ANTAGONIST AND THE ROOM HERE', $ending);
        $this->assertStringContainsString('It is not news to the audience', $ending);
        $this->assertStringContainsString('What lands here is HER face when she learns it, not the fact', $ending);
        $this->assertStringNotContainsString('comes out here and nowhere earlier. ', $ending);
    }

    public function test_the_genre_contract_tells_the_audience_in_act_one(): void
    {
        $guidance = $this->flat($this->invoke($this->writer(), 'genreGuidance', Story::factory()->single()->create()));

        $this->assertStringContainsString('THE AUDIENCE IS TOLD WHAT IT IS AND WHAT IT CAN DO, IN ACT 1', $guidance);
        $this->assertStringContainsString('THE ANTAGONIST IS NOT TOLD UNTIL THE FINAL ACT', $guidance);
        $this->assertStringContainsString('Establish it early, price it, and do not use it', $guidance);

        // The sentence that told the writer to withhold it from everybody.
        $this->assertStringNotContainsString('established early and not used', $guidance);
    }

    /**
     * The split is drawn the way this contract already drew it twice, and
     * those two are asserted here so a later edit cannot take one away and
     * leave this change looking like the odd one out.
     */
    public function test_the_two_places_this_split_was_already_drawn_still_draw_it(): void
    {
        $departure = $this->endingFor(ActPhase::Departure);
        $story = Story::factory()->single()->create(['hook' => 'A hook.']);
        $hook = $this->invoke($this->writer(), 'hookInstruction', $story);

        $this->assertStringContainsString('the audience may know, the antagonist must not', $departure);
        $this->assertStringContainsString('the audience understands and the antagonist does not', $hook);
    }

    public function test_the_spine_question_asks_for_a_reason_the_narrator_can_say(): void
    {
        $body = SpineQuestions::withheldInformation();

        $this->assertStringContainsString('one the narrator can state out loud in their own words in act 1', $body);
        $this->assertStringContainsString('Write the reason here as that sentence', $body);

        // The half that was always there and is the reason this field exists.
        $this->assertStringContainsString('WHAT THE NARRATOR MUST PRODUCE IN PERSON', $body);
    }

    /**
     * The phaseless branch is NOT given the split, deliberately.
     *
     * It is an anthology act or an outline written before the reversal
     * existed, and it is kept as the shape its outline was built to — the
     * same reason it keeps "no epilogue". No story in the pipeline is
     * phaseless. Asserted so the boundary is a decision rather than a gap
     * somebody closes without reading this.
     */
    public function test_the_phaseless_branch_keeps_the_shape_its_outline_was_built_to(): void
    {
        $story = Story::factory()->single()->create(['exposure_moment' => 'At the banquet.']);
        $act = new ActOutline(sequence: 2, title: 'T', summary: 'S', phase: null);

        $ending = $this->invoke($this->writer(), 'endingFor', $story, $act, false);

        $this->assertStringContainsString('The withheld information does not come out here.', $ending);
        $this->assertStringNotContainsString('AND THE AUDIENCE ALREADY HAS IT', $ending);
    }

    // -- helpers -------------------------------------------------------------

    /**
     * Runs of whitespace collapsed to one space.
     *
     * `genreGuidance()` is a wrapped nowdoc, so a needle longer than a few
     * words straddles a line break and `assertStringContainsString` cannot
     * see it. That is entry 11 in this project's self-defeating-checks table
     * — a test written the same morning as the entry it repeated — and it
     * happened again while writing this file. Collapsing here makes the whole
     * class unreachable rather than fixing one needle, which is the same move
     * `PageProbe::claimsNotEntitledTo()` had to make for a template that
     * wrapped a fragment.
     */
    private function flat(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    private function endingFor(ActPhase $phase): string
    {
        $story = Story::factory()->single()->create([
            'departure' => 'She left on a Tuesday.',
            'reversal_beats' => 'She paid a man. Then she went to his aunt.',
            'exposure_moment' => 'At the banquet.',
            'refusal' => 'No.',
        ]);

        $act = new ActOutline(sequence: 3, title: 'T', summary: 'S', phase: $phase);

        return $this->invoke($this->writer(), 'endingFor', $story, $act, false);
    }

    private function actPrompt(ActPhase $phase): string
    {
        $story = Story::factory()->single()->create([
            'departure' => 'She left on a Tuesday.',
            'reversal_beats' => 'She paid a man.',
            'exposure_moment' => 'At the banquet.',
            'refusal' => 'No.',
        ]);

        $act = new ActOutline(
            sequence: 2,
            title: 'The Bridge Loan',
            summary: 'He asks for the schedule and does not get it.',
            escalationBeat: 'He asked for the loan schedule in front of the table and never got it.',
            phase: $phase,
        );

        return $this->invoke($this->writer(), 'actPrompt', $story, $act, [$act], [], 985);
    }

    private function writer(): ClaudeScriptWriter
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
