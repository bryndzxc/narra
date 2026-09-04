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
     * The default is six for a single narrative.
     *
     * RED against the seven this replaces. Stated as a number rather than as a
     * property because the number IS the decision — the window arithmetic below
     * is what makes it the right one, and this is what makes it the current one.
     */
    public function test_a_single_narrative_gets_six_acts(): void
    {
        $this->assertSame(6, GenerateOutline::DEFAULT_ACTS_SINGLE);
        $this->assertSame(6, GenerateOutline::defaultActCountForFormat(StoryFormat::Single));
        $this->assertSame(5, GenerateOutline::defaultActCountForFormat(StoryFormat::Anthology));
    }

    /**
     * Six acts of the writer's natural length lands mid-window; seven does not.
     *
     * The reason for the change, as arithmetic rather than as a docblock. 1,123
     * words is the one unconfounded observation — one act, en-US, current code,
     * asked for 985.
     */
    public function test_six_acts_of_natural_length_lands_inside_the_window(): void
    {
        $story = $this->story(StoryFormat::Single);

        $natural = 1123;
        $acts = GenerateOutline::defaultActCountFor($story);

        $minutes = ScriptSizing::minutesFor($story, $natural * $acts);

        $this->assertTrue(
            ScriptSizing::withinWindow($story, $minutes),
            sprintf('%d acts at the natural length runs %.1f minutes.', $acts, $minutes),
        );

        // And the count that was there before does not, other than by seconds.
        $atSeven = ScriptSizing::minutesFor($story, $natural * 7);

        $this->assertGreaterThan(
            $minutes,
            $atSeven,
            'The premise: seven acts is longer, and it is longer at the ceiling.',
        );
        $this->assertGreaterThan(
            39.0,
            $atSeven,
            'Seven acts of natural length is 39.9 min against a 40 min ceiling — inside by seconds, '
            .'and one long act puts it over. That is what six is for.',
        );
    }

    /**
     * Six acts keeps every reversal phase. Only the escalation gives ground.
     *
     * THE THING THE 6->7 MOVE WAS MADE FOR, ASSERTED RATHER THAN ASSUMED.
     * `departureActFor()` clamps the departure to `count - 2`, so there are
     * always two acts behind it — the search and the refusal. Going back to six
     * costs one escalation act out of four, not a phase.
     */
    public function test_six_acts_keeps_the_departure_search_and_refusal(): void
    {
        $plan = ActPhase::planFor(6);

        $this->assertSame(
            [ActPhase::Escalation, ActPhase::Escalation, ActPhase::Escalation,
                ActPhase::Departure, ActPhase::Search, ActPhase::Refusal],
            array_values($plan),
            'Six acts must still carry the whole five-movement arc.',
        );

        foreach ([ActPhase::Departure, ActPhase::Search, ActPhase::Refusal] as $phase) {
            $this->assertContains(
                $phase,
                $plan,
                sprintf('%s vanished at six acts, which would be the trade this change refuses.', $phase->value),
            );
        }

        // The cost, named: escalation 4 -> 3.
        $this->assertCount(4, array_filter(ActPhase::planFor(7), fn (ActPhase $p): bool => $p === ActPhase::Escalation));
        $this->assertCount(3, array_filter($plan, fn (ActPhase $p): bool => $p === ActPhase::Escalation));
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
