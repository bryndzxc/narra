<?php

namespace Tests\Feature;

use App\Actions\GenerateActScripts;
use App\Enums\StoryStatus;
use App\Models\Act;
use App\Models\Story;
use App\Support\ScriptSizing;
use Faker\Generator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The reading rate a script was written to, recorded before the rate changes.
 *
 * ---------------------------------------------------------------------------
 * WHAT THIS IS PROTECTING
 * ---------------------------------------------------------------------------
 *
 * `targetWordsPerAct()` is about to move from the fallback 160 to the measured
 * 197. The moment it does, a story with no record of its own target cannot be
 * judged against anything: `NarrationPace` would hold story 9's script to 197
 * when it was sized to 160, and nothing in the record could say otherwise. That
 * is the shape this project already paid for once — a guard measuring correctly
 * and being certain about the wrong thing, at the cost of a 270-scene batch
 * cancelled at scene 2.
 *
 * Steps one and two are recoverable and step three is not, so the assertions
 * here are about the two that are: the figure gets written, it never moves
 * afterwards, and absence still means absence.
 */
class SizedAgainstWpmTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Sizing a script records the rate it was sized against.
     *
     * The RED half of the pair below. A column nothing writes is the
     * declared-but-never-called seam this project keeps finding at phase
     * boundaries, and it would be a particularly bad one here: silently null
     * everywhere is exactly the state that makes the next change irreversible.
     */
    public function test_writing_act_scripts_freezes_the_rate_it_sized_against(): void
    {
        $story = $this->storyWithActs();

        $this->assertNull($story->sized_against_wpm, 'The fixture must start with nothing recorded.');

        $expected = ScriptSizing::wpmFor($story);

        app(GenerateActScripts::class)->handle($story);

        $this->assertSame(
            $expected,
            (int) $story->refresh()->sized_against_wpm,
            'A story whose script has been written must record the rate it was written to.',
        );
    }

    /**
     * The word target follows the frozen rate, whatever the rate now is.
     *
     * SUPERSEDED, AND THE SUPERSESSION IS THE POINT. This asserted that
     * recording the rate changed no number — true of the pass that added the
     * column, whose whole promise was that it wrote the figure already in use.
     * The next pass corrected that figure on purpose, so the old assertion
     * described behaviour this project deliberately left behind.
     *
     * What it was actually protecting survives and is asserted here instead:
     * the target is a function of the FROZEN rate, not of config. That is what
     * makes "nothing re-sizes an existing story" true — story 9 keeps a
     * 5,600-word budget from its frozen 160 while a story written today gets
     * the measured one — and it is a stronger claim than the one it replaces,
     * because it holds on both sides of the correction.
     */
    public function test_the_word_target_follows_the_frozen_rate(): void
    {
        $fresh = $this->storyWithActs('wpm-fresh');
        $sized = $this->storyWithActs('wpm-sized');
        $sized->forceFill(['sized_against_wpm' => 160])->save();

        app(GenerateActScripts::class)->handle($fresh);

        $this->assertNotSame(
            160,
            (int) $fresh->refresh()->sized_against_wpm,
            'The premise: a story written today is no longer sized against the fallback.',
        );

        $this->assertSame(
            (int) round(35 * 160 / $sized->acts()->count()),
            $this->wordTargetFor($sized->refresh()),
            'A story already sized keeps its own budget. Anything else re-sizes a script that has '
            .'already been written, against a rate it was not written to.',
        );

        $this->assertSame(
            (int) round(35 * (int) $fresh->sized_against_wpm / $fresh->acts()->count()),
            $this->wordTargetFor($fresh),
            'And a fresh story gets the budget its own frozen rate implies.',
        );
    }

    /**
     * A second run does not re-read config, even when config has moved.
     *
     * THE FREEZE, drilled the only way it can be: by moving the constant between
     * runs and asserting the story does not follow it. Without this the column
     * is a cache of config rather than a record of what happened, and a partial
     * re-run — `--only=4`, on a story whose other acts were written before a
     * config edit — would size one act to a different budget from its
     * neighbours and leave no trace that it had.
     */
    public function test_a_frozen_rate_survives_a_config_change(): void
    {
        $story = $this->storyWithActs();

        app(GenerateActScripts::class)->handle($story);

        $frozen = (int) $story->refresh()->sized_against_wpm;

        config(['render.narration.words_per_minute' => 197]);

        // Clear the scripts so the acts are rewritten rather than skipped.
        $story->acts()->update(['script' => null]);

        app(GenerateActScripts::class)->handle($story);

        $this->assertSame(
            $frozen,
            (int) $story->refresh()->sized_against_wpm,
            'The rate is frozen. A re-run reading config would describe a script that no longer '
            .'exists, and would do it silently.',
        );
        $this->assertSame(
            (int) round(35 * $frozen / $story->acts()->count()),
            $this->wordTargetFor($story->refresh()),
            'And the target must follow the frozen rate, not the new config value.',
        );
    }

    /**
     * The backfill reaches every story that has a script, and no others.
     *
     * FIVE STORIES CARRY GENERATED SCRIPTS IN THE LIVE DATABASE, not the two
     * that were noticed. A backfill written as "9 and 21" would have fixed the
     * pair somebody looked at and left three behind exactly the defect the
     * column exists to prevent, so the predicate is the fact — a story has a
     * generated script, therefore it was sized at 160 — rather than a list of
     * ids.
     *
     * The negative half is not decoration. `sample-story` has acts with no
     * scripts, imported from a Phase 0 fixture, and was sized against nothing;
     * null is its correct answer for ever. A backfill that defaulted every row
     * would have claimed otherwise, which is absence reading as agreement.
     */
    public function test_the_backfill_reaches_scripted_stories_and_leaves_the_rest_null(): void
    {
        $scripted = $this->storyWithActs('bf-scripted');
        $scripted->acts()->update(['script' => 'Words that were written at some point.']);

        $imported = $this->storyWithActs('bf-imported');
        $imported->acts()->update(['script' => null]);

        $noActs = Story::factory()->status(StoryStatus::Draft)->create(['slug' => 'bf-empty']);

        DB::table('stories')->update(['sized_against_wpm' => null]);

        $this->runBackfill();

        $this->assertSame(
            160,
            (int) $scripted->refresh()->sized_against_wpm,
            'A story with a generated script was sized at 160 — the only value the target has ever '
            .'been derived from.',
        );
        $this->assertNull(
            $imported->refresh()->sized_against_wpm,
            'Acts with no script were sized against nothing. Null is the true answer here, and a '
            .'default would have made "nobody recorded this" and "this was 160" the same value.',
        );
        $this->assertNull($noActs->refresh()->sized_against_wpm);
    }

    /** And a re-run cannot overwrite a figure written since. */
    public function test_the_backfill_does_not_touch_a_story_that_already_has_a_rate(): void
    {
        $story = $this->storyWithActs('bf-frozen');
        $story->acts()->update(['script' => 'Written.']);
        $story->forceFill(['sized_against_wpm' => 197])->save();

        $this->runBackfill();

        $this->assertSame(
            197,
            (int) $story->refresh()->sized_against_wpm,
            'The backfill is guarded on whereNull. Overwriting is the opposite of a freeze.',
        );
    }

    // -- Fixtures ------------------------------------------------------------

    /**
     * The backfill migration's own statement, run against the test database.
     *
     * The migration itself rather than a copy of its predicate: a hand-written
     * restatement of a machine-run rule is a second source of truth that agrees
     * only on the day it is written, which this codebase has paid for in a retry
     * prompt and a `--max-time` hint.
     */
    private function runBackfill(): void
    {
        $migration = require database_path('migrations/2026_09_04_150100_backfill_sized_against_wpm.php');

        $migration->up();
    }

    private function wordTargetFor(Story $story): int
    {
        $midpoint = ($story->target_duration_min + $story->target_duration_max) / 2;

        return (int) round($midpoint * (int) $story->sized_against_wpm / $story->acts()->count());
    }

    private function storyWithActs(string $slug = 'wpm-story'): Story
    {
        $story = Story::factory()->status(StoryStatus::Outlined)->create([
            'slug' => $slug,
            'target_duration_min' => 30,
            'target_duration_max' => 40,
        ]);

        // The act factory draws unique titles; without a reset a second story
        // in one test exhausts the generator rather than failing on anything
        // this test is about.
        app(Generator::class)->unique(reset: true);

        for ($i = 1; $i <= 6; $i++) {
            Act::factory()->for($story)->atSequence($i)->create(['script' => null]);
        }

        return $story->refresh();
    }
}
