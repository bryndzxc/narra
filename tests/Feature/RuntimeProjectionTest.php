<?php

namespace Tests\Feature;

use App\Actions\GenerateOutline;
use App\Enums\CostCategory;
use App\Enums\CostUnit;
use App\Enums\StoryFormat;
use App\Enums\StoryStatus;
use App\Livewire\Gates\OutlineGate;
use App\Livewire\Stories\NewStory;
use App\Models\Act;
use App\Models\Story;
use App\Support\Providers\ProviderUsage;
use App\Support\ScriptSizing;
use Faker\Generator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Two runtimes on every surface that decides one, and the token unit under them.
 *
 * ---------------------------------------------------------------------------
 * WHY TWO NUMBERS
 * ---------------------------------------------------------------------------
 *
 * The word target is a design point, not a forecast. Across five measured
 * stories the fitted response to the target is +0.30 — an act comes back at
 * ~1,100 words whatever the prompt asks for — so a page showing only the
 * target's runtime shows the runtime of a script nobody is going to get.
 *
 * Showing only the PROJECTION would be the opposite error: it rests on one
 * measured act, and a single observation presented alone is a forecast it has
 * not earned. So both, each labelled with what it is. Same rule as
 * `sized_against_wpm` being null rather than 160 — a figure and its provenance
 * travel together, or the next reader inherits a number with no way to weigh it.
 */
class RuntimeProjectionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Gate 1 shows the design point and the projection, and they differ.
     *
     * RED is a page carrying one number. The `assertNotSame` is what stops this
     * passing on a page that prints the same figure twice under two labels,
     * which is what a projection wired to the target would do.
     */
    public function test_gate_one_shows_both_runtimes(): void
    {
        $story = $this->draft();

        $component = Livewire::test(OutlineGate::class, ['story' => $story]);
        $sizing = $component->instance()->sizing();
        $html = $component->html();

        $this->assertNotNull($sizing['projected'], 'A story with no script must carry a projection.');

        $this->assertSame(6895, $sizing['target']);
        $this->assertSame(6738, $sizing['projected']['words']);

        $this->assertNotSame(
            round($sizing['minutes'], 1),
            round($sizing['projected']['minutes'], 1),
            'Two labels over one number is not two numbers. The projection must come from the '
            .'measured act length, not from the target.',
        );

        $this->assertStringContainsString('Projected runtime', $html);
        $this->assertStringContainsString('Runtime if written to target', $html);
    }

    /**
     * The projection says it is a projection, and says what from.
     *
     * The half that makes the number safe to publish. A bare "34.2 min" beside
     * "35.0 min" invites the reader to treat them as two equally-founded
     * estimates; one rests on the narrator's measured rate and the other on a
     * single act.
     */
    public function test_the_projection_carries_its_provenance(): void
    {
        $html = Livewire::test(OutlineGate::class, ['story' => $this->draft()])->html();

        $this->assertMatchesRegularExpression(
            '/projection from one measured act/i',
            $html,
            'One act is a projection, not a forecast, and the page has to say which.',
        );

        $this->assertStringContainsString(
            (string) ScriptSizing::naturalActWordsMeasuredOn(),
            $html,
            'And where it was measured, so a stale figure is visible as stale.',
        );
    }

    /** And it says the target is advisory, with the slope on the record. */
    public function test_both_surfaces_say_the_target_is_advisory(): void
    {
        $slope = '+'.number_format(ScriptSizing::targetResponseSlope(), 2);

        $gate = Livewire::test(OutlineGate::class, ['story' => $this->draft()])->html();
        $form = Livewire::test(NewStory::class)->html();

        foreach (['Gate 1' => $gate, 'the new-story form' => $form] as $where => $html) {
            $flat = (string) preg_replace('/\s+/', ' ', $html);

            $this->assertStringContainsString('advisory', $flat, "{$where} does not say the target is advisory.");
            $this->assertStringContainsString($slope, $flat, "{$where} does not carry the slope.");
            $this->assertStringContainsString('act count', $flat, "{$where} does not name the lever.");
        }
    }

    /**
     * The form shows a runtime at all, which it did not.
     *
     * The act count is chosen HERE and it is the lever. Moving it from 6 to 8
     * moved the video by nine minutes with nothing on the screen saying so.
     */
    public function test_the_new_story_form_shows_a_runtime_that_follows_the_act_count(): void
    {
        $six = Livewire::test(NewStory::class)->set('acts', 6)->instance()->estimate();
        $eight = Livewire::test(NewStory::class)->set('acts', 8)->instance()->estimate();

        $this->assertGreaterThan(
            $six['projected_minutes'] + 8,
            $eight['projected_minutes'],
            'Two more acts is about nine more minutes. If the form does not move with the field, '
            .'the field is a lever with no dial on it.',
        );

        $this->assertTrue($six['projected_in_window']);
        $this->assertFalse(
            $eight['projected_in_window'],
            'Eight acts projects past the ceiling, and the form is where that is decided.',
        );
    }

    /**
     * The default window comes from the column, not from a copy of it.
     *
     * `CreateStory` omits these keys on purpose so the schema answers. A form
     * estimate writing `[30, 40]` would be the second copy — in the one file
     * that has already been wrong twice about a default it kept its own version
     * of.
     */
    public function test_the_form_reads_the_window_from_the_column(): void
    {
        $window = Story::defaultDurationWindow();

        $this->assertSame(['min' => 30, 'max' => 40], $window);

        $this->assertSame(
            $window,
            Livewire::test(NewStory::class)->instance()->estimate()['window'],
        );

        // And a story created through the Action lands on the same numbers.
        $story = $this->draft();
        $this->assertSame($window['min'], $story->target_duration_min);
        $this->assertSame($window['max'], $story->target_duration_max);
    }

    /**
     * A story with a script shows what it wrote, not a projection.
     *
     * The GREEN case for withholding. Once a script exists, `written` is a
     * measurement of THIS story and a projection beside it would be a guess
     * competing with a fact — the same reason the UNKNOWN state prints no
     * target at all.
     */
    public function test_a_written_story_gets_no_projection(): void
    {
        $story = $this->draft('proj-written');

        app(Generator::class)->unique(reset: true);

        for ($i = 1; $i <= 6; $i++) {
            Act::factory()->for($story)->atSequence($i)->create([
                'script' => str_repeat('word ', 900),
            ]);
        }

        $sizing = Livewire::test(OutlineGate::class, ['story' => $story->refresh()])->instance()->sizing();

        $this->assertGreaterThan(0, $sizing['written']);
        $this->assertNull(
            $sizing['projected'],
            'A script that exists is measured, not projected.',
        );
    }

    // -- The token unit ------------------------------------------------------

    /**
     * The unit says what the quantity holds.
     *
     * `quantity` has always been the TOTAL — input + output + cache_read +
     * cache_write, verified as `4220 + 3752 + 2203 + 0 = 10175` on story 21 —
     * and the unit said `output_tokens`. Three of four readers hard-code "tok"
     * and were right either way; `ProviderUsage::summary()` renders the unit and
     * printed "9581 output_tokens" for a call whose output was 3,651, twice per
     * act, from `story:write`.
     */
    public function test_a_token_usage_is_labelled_as_a_total(): void
    {
        $usage = new ProviderUsage(
            provider: 'anthropic',
            operation: 'generate_act_script',
            category: CostCategory::Text,
            quantity: 9581.0,
            unit: CostUnit::TotalTokens,
            usdCost: 0.1237,
            detail: ['input_tokens' => 3727, 'output_tokens' => 3651],
        );

        $this->assertStringContainsString('total_tokens', $usage->summary());
        $this->assertStringNotContainsString(
            'output_tokens',
            $usage->summary(),
            'The one surface that renders the unit must not call a total an output count.',
        );
    }

    /**
     * `InputTokens` is gone, and `OutputTokens` is not.
     *
     * The enum was modelling a per-class split the recorder never intended to
     * write — no writer, no reader, no row, a phase on the open list. Dropping
     * it is the true close.
     *
     * `OutputTokens` STAYS, and that is not an oversight: rows exist under it,
     * they hold totals, and removing the case would make them fail to cast. The
     * enum's docblock is what makes those rows readable — the same remedy as
     * `sized_against_wpm`, for the same reason.
     */
    public function test_the_enum_drops_the_case_nothing_wrote_and_keeps_the_one_rows_use(): void
    {
        $values = array_column(CostUnit::cases(), 'value');

        $this->assertNotContains('input_tokens', $values);
        $this->assertContains('total_tokens', $values);
        $this->assertContains(
            'output_tokens',
            $values,
            'Historic rows carry this. cost_entries is write-once, so the case has to survive for '
            .'them to cast at all.',
        );
    }

    /**
     * A stand-in counts nothing and says it is a stand-in.
     *
     * It stored the TARGET WORD COUNT under a token unit — a word count filed as
     * tokens, so a ledger summing tokens by operation added a number that is not
     * one. And it built the usage with `new ProviderUsage(provider: 'fake')`,
     * which leaves `simulated` at its false default: a fake row the ledger could
     * not tell from a real one at the flag. That cost nothing yet — all 372 fake
     * rows came from providers using the factory — but it is the flag on the
     * $8.12 row at the top of this project's false-success table.
     */
    public function test_the_fake_writer_counts_nothing_and_is_flagged(): void
    {
        $story = $this->draft('proj-fake');

        app(Generator::class)->unique(reset: true);

        for ($i = 1; $i <= 3; $i++) {
            Act::factory()->for($story)->atSequence($i)->create(['script' => null]);
        }

        $draft = app(\App\Contracts\ScriptWriter::class)->actScript(
            story: $story->refresh(),
            act: new \App\Support\Providers\ActOutline(sequence: 1, title: 'One', summary: 'A summary.'),
            fullOutline: [new \App\Support\Providers\ActOutline(sequence: 1, title: 'One', summary: 'A summary.')],
            priorSummaries: [],
            targetWords: 985,
        );

        $this->assertSame(0.0, $draft->usage->quantity, 'Nothing was spent, so nothing is counted.');
        $this->assertTrue($draft->usage->simulated, 'A stand-in must be flagged as one on the row.');
        $this->assertSame(0.0, $draft->usage->usdCost);
        $this->assertSame(
            985,
            $draft->usage->detail['target_words'],
            'The target it was asked for stays in detail, where it is a note rather than a metered '
            .'quantity.',
        );
    }

    private function draft(string $slug = 'proj-story'): Story
    {
        return Story::factory()->status(StoryStatus::Draft)->create([
            'slug' => $slug,
            'format' => StoryFormat::Single,
            'voice_id' => 'nPczCjzI2devNBz1zQrb',
            'locale_profile' => 'en-US',
        ]);
    }
}
