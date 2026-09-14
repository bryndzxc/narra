<?php

namespace Tests\Feature;

use App\Actions\GenerateOutline;
use App\Enums\ActPhase;
use App\Enums\StoryFormat;
use App\Enums\StoryStatus;
use App\Livewire\Stories\NewStory;
use App\Models\Act;
use App\Models\Story;
use App\Support\ScriptSizing;
use Faker\Generator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * How many acts a story gets, and the one place that decides it.
 *
 * ---------------------------------------------------------------------------
 * WHY THE COUNT AND NOT THE TARGET
 * ---------------------------------------------------------------------------
 *
 * The word target barely steers the writer. Across five measured stories the
 * fitted slope is +0.30 — story 21 was asked for 800 and wrote 1,152, a probe
 * was asked for 985 and wrote 1,123 — so an act comes back at ~1,100 words
 * whatever the prompt says. The act count is the lever that actually moves the
 * runtime, because it multiplies a length the prompt cannot argue with.
 *
 * Seven acts of natural length is 39.9 minutes against a 30-40 window. Six is
 * 34.2.
 *
 * ---------------------------------------------------------------------------
 * AND IT HAD ALREADY DRIFTED IN TWO PLACES, BOTH ON MONEY SCREENS
 * ---------------------------------------------------------------------------
 *
 * Measured before the change, for a single story where the run makes 8 calls:
 *
 *   NewStory::estimate()        6 acts / 7 calls
 *   StoryWrite::confirmSpend()  5 acts / 6 calls
 *
 * Both UNDERSTATED, which is the expensive direction, and both agreed with the
 * Action for an anthology — so the one branch anybody would spot-check was the
 * one that was right. That is the $2.12 / $4.24 / 42,017 shape: one figure,
 * three pieces of code, no comparison between them.
 */
class ActCountTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The default is five for a single narrative.
     *
     * RED against the six this replaces, which was RED against seven. Stated
     * as a number rather than as a property because the number IS the
     * decision — the window arithmetic below is what makes it the right one,
     * and this is what makes it the current one.
     */
    public function test_a_single_narrative_gets_five_acts(): void
    {
        $this->assertSame(5, GenerateOutline::DEFAULT_ACTS_SINGLE);
        $this->assertSame(5, GenerateOutline::defaultActCountForFormat(StoryFormat::Single));
        $this->assertSame(5, GenerateOutline::defaultActCountForFormat(StoryFormat::Anthology));
    }

    /**
     * Five acts of the writer's natural length lands mid-window; six does not.
     *
     * The reason for the change, as arithmetic rather than as a docblock —
     * and it reads off `naturalActWords()` rather than a literal, which is
     * the whole point. The previous version of this test hard-coded 1,123, a
     * figure measured when an act came back as TWO chapters; five acts of
     * that is 28.2 minutes, so this test would have called the correct act
     * count too short. **A measurement pasted into an assertion is a
     * measurement that cannot be corrected.**
     */
    public function test_five_acts_of_natural_length_lands_inside_the_window(): void
    {
        $story = $this->story(StoryFormat::Single);

        $natural = ScriptSizing::naturalActWords();
        $acts = GenerateOutline::defaultActCountFor($story);

        $minutes = ScriptSizing::minutesFor($story, $natural * $acts);

        $this->assertTrue(
            ScriptSizing::withinWindow($story, $minutes),
            sprintf('%d acts at the natural length runs %.1f minutes.', $acts, $minutes),
        );

        // And the count that was there before does not: six acts of the
        // three-chapter length is 44.4 minutes, which is what moved it.
        $atSix = ScriptSizing::minutesFor($story, $natural * 6);

        $this->assertGreaterThan($minutes, $atSix);
        $this->assertGreaterThan(
            (float) $story->target_duration_max,
            $atSix,
            'Six acts of the measured length is over the ceiling. That is what five is for.',
        );
    }

    /**
     * THE MEASUREMENT'S CONDITION, ASSERTED.
     *
     * `naturalActWords()` is not a property of the writer — it is a function
     * of how many chapters the act is asked to open, and for a phase that was
     * invisible because the chapter count was STATED in the prompt and
     * therefore never varied. A constant that is really a function of
     * something nobody varied looks settled for exactly the reason a check
     * that cannot fire looks passed: nothing has ever disagreed with it.
     *
     * This is the coupling made visible. The configured chapter budget must
     * still divide the measured act length into the number of chapters that
     * length was measured under. Move `chapters.target_seconds` without
     * re-measuring and this goes red, instead of every runtime projection on
     * the console going quietly wrong.
     */
    public function test_the_measured_act_length_still_holds_at_the_configured_chapter_budget(): void
    {
        $story = $this->story(StoryFormat::Single);

        $this->assertSame(
            ScriptSizing::naturalActWordsMeasuredAtChapters(),
            ScriptSizing::chaptersPerAct($story, ScriptSizing::naturalActWords()),
            sprintf(
                'The act length was measured at %d chapters per act and the configured chapter '
                .'budget now divides it into %d. One of the two has moved without the other; '
                .'re-measure the act length before trusting any runtime projection.',
                ScriptSizing::naturalActWordsMeasuredAtChapters(),
                ScriptSizing::chaptersPerAct($story, ScriptSizing::naturalActWords()),
            ),
        );

        $this->assertTrue(ScriptSizing::measurementStillHolds($story));
    }

    /**
     * Six acts keeps every reversal phase. Only the escalation gives ground.
     *
     * THE THING THE 6->7 MOVE WAS MADE FOR, ASSERTED RATHER THAN ASSUMED.
     * `departureActFor()` clamps the departure to `count - 2`, so there are
     * always two acts behind it — the search and the refusal. Going back to six
     * costs one escalation act out of four, not a phase.
     */
    public function test_five_acts_keeps_the_departure_search_and_refusal(): void
    {
        $plan = ActPhase::planFor(5);

        $this->assertSame(
            [ActPhase::Escalation, ActPhase::Escalation,
                ActPhase::Departure, ActPhase::Search, ActPhase::Refusal],
            array_values($plan),
            'Five acts must still carry the whole five-movement arc.',
        );

        foreach ([ActPhase::Departure, ActPhase::Search, ActPhase::Refusal] as $phase) {
            $this->assertContains(
                $phase,
                $plan,
                sprintf('%s vanished at six acts, which would be the trade this change refuses.', $phase->value),
            );
        }

        // The cost, named: escalation 3 -> 2. Four at seven, three at six, two
        // at five, and never a phase at any of them.
        $this->assertCount(4, array_filter(ActPhase::planFor(7), fn (ActPhase $p): bool => $p === ActPhase::Escalation));
        $this->assertCount(3, array_filter(ActPhase::planFor(6), fn (ActPhase $p): bool => $p === ActPhase::Escalation));
        $this->assertCount(2, array_filter($plan, fn (ActPhase $p): bool => $p === ActPhase::Escalation));
    }

    /**
     * AND AT FIVE THE CLAMP IS WHAT SAVES THE SEARCH ACT.
     *
     * The two-thirds point of five acts is act 4. A departure there makes act
     * 5 the refusal and leaves NO SEARCH ACT AT ALL — the compressed ending
     * the whole phase structure exists to replace. `departureActFor()` clamps
     * to `count - 2` and pulls it back to act 3.
     *
     * At six and seven that clamp is INERT: the two-thirds point already
     * lands on `count - 2`, so it has never been the term that decided
     * anything, and it spent two act-count moves as a guarantee nobody could
     * watch working. Five is where it starts doing the work — so five is the
     * count that breaks first if it is ever loosened, and that is asserted
     * here rather than left in a docblock.
     */
    public function test_the_departure_clamp_is_what_keeps_a_search_act_at_five(): void
    {
        $this->assertSame(3, ActPhase::departureActFor(5));

        // The unclamped two-thirds point, which is what the clamp overrides.
        $this->assertSame(4, (int) ceil(5 * 2 / 3));

        // And what that would have produced: no search phase at all.
        $unclamped = [];
        for ($sequence = 1; $sequence <= 5; $sequence++) {
            $unclamped[$sequence] = match (true) {
                $sequence < 4 => ActPhase::Escalation,
                $sequence === 4 => ActPhase::Departure,
                default => ActPhase::Refusal,
            };
        }
        $this->assertNotContains(ActPhase::Search, $unclamped);
        $this->assertContains(ActPhase::Search, ActPhase::planFor(5));

        // Inert at the counts this project used before, which is why it was
        // never seen to matter.
        $this->assertSame((int) ceil(6 * 2 / 3), ActPhase::departureActFor(6));
        $this->assertSame((int) ceil(7 * 2 / 3), ActPhase::departureActFor(7));
    }

    /**
     * Every surface that quotes a first run agrees with what the run does.
     *
     * THE GUARD, and the reason it is behavioural rather than a grep: the drift
     * was hard-coded integers, not a named constant, so nothing textual could
     * have found it. What can be checked is that the three answers are one
     * answer — and they were not.
     */
    public function test_every_surface_quotes_the_act_count_the_action_will_use(): void
    {
        foreach ([StoryFormat::Single, StoryFormat::Anthology] as $format) {
            $expected = GenerateOutline::defaultActCountForFormat($format);

            $form = Livewire::test(NewStory::class)
                ->set('format', $format->value)
                ->set('acts', null)
                ->instance()
                ->estimate();

            $this->assertSame(
                $expected,
                $form['acts'],
                sprintf(
                    'The new-story form quotes %d acts for a %s story and the run writes %d. It is '
                    .'the screen where the spend is authorised.',
                    $form['acts'],
                    $format->value,
                    $expected,
                ),
            );

            $this->assertSame(
                $expected + 1,
                $form['calls'],
                'One outline call, then one per act. A quote that is short is short in dollars.',
            );

            // And the story-shaped question gives the identical answer.
            $this->assertSame($expected, GenerateOutline::defaultActCountFor($this->story($format)));
        }
    }

    /**
     * An operator-chosen act count still wins, and is quoted honestly.
     *
     * The default is a default. `acts` is an input on the form with its own
     * 3-8 bounds, and a story that names one must not be re-quoted against the
     * default it declined.
     */
    public function test_an_operator_chosen_count_overrides_the_default(): void
    {
        $form = Livewire::test(NewStory::class)
            ->set('format', 'single')
            ->set('acts', 8)
            ->instance()
            ->estimate();

        $this->assertSame(8, $form['acts']);
        $this->assertSame(9, $form['calls']);
    }

    /**
     * No existing story re-plans, and no existing story is regenerated.
     *
     * `defaultActCountFor()` is consulted only when a story has no acts, so a
     * seven-act story keeps seven acts and the phases already persisted on
     * them. Story 22 — the sizing probe — is exactly that shape and is the
     * reason this is asserted rather than assumed.
     */
    public function test_a_story_that_already_has_acts_keeps_them(): void
    {
        $story = $this->story(StoryFormat::Single);

        app(Generator::class)->unique(reset: true);

        foreach (ActPhase::planFor(7) as $sequence => $phase) {
            Act::factory()->for($story)->atSequence($sequence)->create(['phase' => $phase]);
        }

        $story->refresh();

        $this->assertSame(7, $story->acts()->count());
        $this->assertSame(
            7,
            $story->acts()->whereNotNull('phase')->count(),
            'The phases are persisted on the acts. Nothing recomputes them from the new default.',
        );

        // The word budget follows the acts the story HAS, not the default.
        $this->assertSame(
            (int) round(ScriptSizing::targetWords($story) / 7),
            ScriptSizing::targetWordsPerAct($story, $story->acts()->count()),
        );
    }

    private function story(StoryFormat $format): Story
    {
        return Story::factory()->status(StoryStatus::Draft)->create([
            'slug' => 'acts-'.$format->value,
            'format' => $format,
            'voice_id' => 'nPczCjzI2devNBz1zQrb',
            'locale_profile' => 'en-US',
            'target_duration_min' => 30,
            'target_duration_max' => 40,
        ]);
    }
}
