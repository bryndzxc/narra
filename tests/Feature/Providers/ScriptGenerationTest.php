<?php

namespace Tests\Feature\Providers;

use App\Actions\GenerateActScripts;
use App\Actions\GenerateOutline;
use App\Contracts\ImageGenerator;
use App\Contracts\ScriptWriter;
use App\Contracts\SpeechSynthesizer;
use App\Contracts\Transcriber;
use App\Enums\CostCategory;
use App\Enums\CostUnit;
use App\Enums\Gate;
use App\Enums\StoryFormat;
use App\Enums\StoryStatus;
use App\Exceptions\GateViolationException;
use App\Exceptions\LocaleViolationException;
use App\Models\Act;
use App\Models\CostEntry;
use App\Models\Story;
use App\Services\Fake\FakeImageGenerator;
use App\Services\Fake\FakeScriptWriter;
use App\Services\Fake\FakeSpeechSynthesizer;
use App\Services\Fake\FakeTranscriber;
use App\Support\LocaleGuard;
use App\Support\ScriptSizing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * Phase 2a — chunked script generation.
 *
 * Three properties are load-bearing and none of them are visible in the
 * database when they break:
 *
 *  1. Generation is CHUNKED and SEQUENTIAL. Act 4 is written knowing acts 1-3.
 *     Fan it out and every act still has a script, every word count is still
 *     right, and the video contradicts itself at minute twenty.
 *  2. Locale leaks FAIL the stage. An operator reading 7,000 words will not
 *     catch one "sari-sari store"; a US viewer will catch it immediately.
 *  3. Every call writes a cost row — including the ones that happen before
 *     Gate 2, which is where all of script generation happens.
 */
class ScriptGenerationTest extends TestCase
{
    use RefreshDatabase;

    private FakeScriptWriter $writer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->writer = app(ScriptWriter::class);
    }

    // -- Wiring --------------------------------------------------------------

    public function test_every_provider_resolves_to_a_fake_in_tests(): void
    {
        // Tests never hit the network. Not "should not" — cannot: the binding
        // ignores config entirely in the testing environment, so a stray
        // PROVIDER_SCRIPT_WRITER=anthropic in someone's .env cannot spend money
        // proving a fake works.
        $this->assertInstanceOf(FakeScriptWriter::class, app(ScriptWriter::class));
        $this->assertInstanceOf(FakeImageGenerator::class, app(ImageGenerator::class));
        $this->assertInstanceOf(FakeSpeechSynthesizer::class, app(SpeechSynthesizer::class));
        $this->assertInstanceOf(FakeTranscriber::class, app(Transcriber::class));
    }

    // -- Chunked and sequential ----------------------------------------------

    public function test_the_outline_is_generated_before_any_act_and_parks_at_gate_one(): void
    {
        $story = $this->draftStory();

        $draft = app(GenerateOutline::class)->handle($story, 5);

        $this->assertCount(5, $draft->acts);
        $this->assertSame(5, $story->acts()->count());
        $this->assertSame([1, 2, 3, 4, 5], $story->acts()->pluck('sequence')->all());

        // Parked at Gate 1. Nothing else runs until an operator approves the
        // structure the entire 7,000-word script will be written against.
        $story->refresh();
        $this->assertSame(StoryStatus::Outlined, $story->status);
        $this->assertSame(Gate::Outline, $story->awaitingGate());

        // No act has a script yet, and none claims a rehook.
        $this->assertSame(0, $story->acts()->whereNotNull('script')->count());
        $this->assertSame(0, $story->acts()->where('is_rehook_written', true)->count());
    }

    public function test_each_act_is_written_knowing_every_act_before_it(): void
    {
        // THE test for this phase. A fan-out implementation passes every other
        // assertion in this file and fails this one.
        $story = $this->outlinedStory(5);

        app(GenerateActScripts::class)->handle($story);

        $actCalls = array_values(array_filter(
            $this->writer->calls,
            fn (array $call): bool => $call['method'] === 'actScript'
        ));

        $this->assertCount(5, $actCalls);

        foreach ($actCalls as $index => $call) {
            // Called in order...
            $this->assertSame($index + 1, $call['sequence'], 'Acts were not written in sequence order.');

            // ...and act N arrived carrying the summaries of acts 1..N-1.
            $this->assertSame(
                $index,
                $call['prior_summaries'],
                "Act {$call['sequence']} was written without the summaries of the acts before it. "
                .'That is what makes a 7,000-word script contradict itself.'
            );

            // And each one saw the whole outline, not just its own entry.
            $this->assertSame(5, $call['outline_size']);
        }
    }

    public function test_a_written_act_replaces_its_outline_summary_with_what_was_actually_written(): void
    {
        // The next act needs the truth, not the plan. Acts diverge from their
        // outline entry, and feeding the plan forward would compound the drift.
        $story = $this->outlinedStory(3);
        $planned = $story->acts()->where('sequence', 1)->value('summary');

        app(GenerateActScripts::class)->handle($story);

        $written = $story->acts()->where('sequence', 1)->value('summary');

        $this->assertNotSame($planned, $written);
        $this->assertStringContainsString('Act 1:', $written);
    }

    public function test_the_word_target_comes_from_the_story_own_runtime_window(): void
    {
        // THIS TEST FIRED, AND ITS OWN MESSAGE IS WHAT IT SAID TO DO. It read
        // "The narration rate moved; the word band and runtime window both
        // follow it", asserting 160 wpm and 1,120 words an act. The rate did
        // move — deliberately, from the fallback 160 to the measured 197 — and
        // the band follows it, which is this file's own rule: runtime is the
        // product and the word band is derived from it.
        //
        // That distinction matters here more than anywhere. Updating a number
        // because the result came out differently is the false-success pattern;
        // updating it because the INPUT was corrected for a stated reason is
        // the derivation working. 160 was never measured against a vendor, 197
        // is 186 real scenes, and the assertion below is the one that could not
        // have passed before: a script written exactly to its budget lands
        // inside the window it was sized for.
        $story = $this->outlinedStory(5);

        app(GenerateActScripts::class)->handle($story);

        $target = collect($this->writer->calls)->firstWhere('method', 'actScript')['target_words'];

        $this->assertSame(
            197,
            ScriptSizing::wpmFor($story->refresh()),
            'The narration rate moved again; the word band and runtime window both follow it.',
        );
        $this->assertSame(1379, $target);

        // The property, not the number: a hardcoded target would give a
        // 20-minute story and a 40-minute story the same length.
        $this->assertSame(
            ScriptSizing::targetWordsPerAct($story, 5),
            $target,
            'The per-act target must be the story\'s own budget divided by its acts.',
        );

        // The whole script lands inside the runtime window it was sized for.
        // This is the assertion the 160-wpm target could not pass: it produced
        // 5,600 words, which run 28.4 minutes against a 30-40 window.
        $minutes = ScriptSizing::minutesFor($story, $target * 5);

        $this->assertTrue(
            ScriptSizing::withinWindow($story, $minutes),
            sprintf('A script written to its own budget runs %.1f minutes.', $minutes),
        );

        // And inside the spec's word band, which is derived from the same rate.
        $this->assertGreaterThanOrEqual(5500, $target * 5);
        $this->assertLessThanOrEqual(8000, $target * 5);
    }

    public function test_rerunning_the_stage_keeps_acts_that_already_have_scripts(): void
    {
        // Every one of these calls bills. Re-running after act 4 failed must
        // cost one act, not five.
        $story = $this->outlinedStory(4);

        app(GenerateActScripts::class)->handle($story);
        $this->writer->calls = [];

        app(GenerateActScripts::class)->handle($story);

        $rewritten = array_filter($this->writer->calls, fn (array $c): bool => $c['method'] === 'actScript');

        $this->assertSame([], $rewritten, 'A completed stage re-billed every act.');
    }

    public function test_a_single_act_can_be_rewritten_and_still_sees_its_predecessors(): void
    {
        $story = $this->outlinedStory(4);
        app(GenerateActScripts::class)->handle($story);
        $this->writer->calls = [];

        app(GenerateActScripts::class)->handle($story, only: [3]);

        $calls = array_values(array_filter($this->writer->calls, fn (array $c): bool => $c['method'] === 'actScript'));

        $this->assertCount(1, $calls);
        $this->assertSame(3, $calls[0]['sequence']);
        // Still given acts 1 and 2 — a skipped act's summary still feeds the
        // running context, or a partial re-run would write act 3 blind.
        $this->assertSame(2, $calls[0]['prior_summaries']);
    }

    public function test_act_scripts_cannot_be_written_without_an_outline(): void
    {
        $story = $this->draftStory();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no acts');

        app(GenerateActScripts::class)->handle($story);
    }

    public function test_an_outline_cannot_be_replaced_once_acts_carry_scripts(): void
    {
        $story = $this->outlinedStory(3);
        app(GenerateActScripts::class)->handle($story);
        // Through Gate 1, the only way to `scripted` — an operator approving
        // the outline is exactly what makes it too late to replace it.
        $story->refresh()->approveGate(Gate::Outline);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('orphan');

        app(GenerateOutline::class)->handle($story->refresh());
    }

    // -- Locale --------------------------------------------------------------

    /**
     * KEPT AND SHOWN, NOT REFUSED (2026-09-17). This test used to assert the
     * act was thrown away; story 36's act 2 was, over "car park", at the cost
     * of the billed act and the run. The act is stored now, the job row says
     * what it was kept with, and Gate 1 puts the phrase in front of the
     * operator — which is the half that answers "an operator will scroll
     * past it in 7,000 words".
     */
    public function test_a_denied_term_in_an_act_is_kept_named_on_the_job_row_and_shown_at_gate_one(): void
    {
        $story = $this->outlinedStory(3);

        $this->writer->injectIntoScript = 'She walked to the sari-sari store on the corner.';

        app(GenerateActScripts::class)->handle($story);

        $this->assertStringContainsString('sari-sari store', (string) $story->acts()->where('sequence', 1)->value('script'));

        $log = (string) \App\Models\RenderJob::query()->where('story_id', $story->id)
            ->where('stage', \App\Enums\RenderStage::ActScripts)->value('log');
        $this->assertStringContainsString('Act 1 kept with 1 term(s) the en-US denylist names', $log);
        $this->assertStringContainsString('"sari-sari store"', $log);

        $denied = app(GenerateActScripts::class)->localeDenied($story->refresh());
        $this->assertSame(['where' => 'script', 'act' => 1, 'editable' => false], array_intersect_key($denied[0], array_flip(['where', 'act', 'editable'])));
        $this->assertSame('sari-sari store', $denied[0]['term']);

        // A script term is NOT a judgement: scene drafting refuses it. The page
        // said "kept for your judgement ... if this one does, leave it" until
        // 2026-09-19, and story 38's act 4 paid two refused scene drafts for it.
        \Livewire\Livewire::test(\App\Livewire\Gates\OutlineGate::class, ['story' => $story])
            ->assertSee('1 of them in an act script')
            ->assertSee('A denied term in an act script is refused at scene drafting.')
            ->assertSee('has to come out before you approve')
            ->assertDontSee('kept for your judgement')
            ->assertDontSee('if one does, it can stay')
            ->assertSee('sari-sari store')
            ->assertSee('story:write --acts-only=N');
    }

    /**
     * RED/GREEN on the approval clause. Past Gate 1 there is no approval to
     * come before, so the sentence keeps the fact and drops the claim — the
     * state story 38 was in when it was refused. The shared page fixture has
     * no denied script term, so the contract's claim check never renders this
     * sentence; this case is what does.
     */
    public function test_red_green_a_script_term_past_gate_one_says_the_refusal_not_the_approval(): void
    {
        $story = $this->outlinedStory(3);
        $this->writer->injectIntoScript = 'She walked to the sari-sari store on the corner.';
        app(GenerateActScripts::class)->handle($story);

        $story->refresh()->forceFill(['status' => StoryStatus::Scripted])->save();

        \Livewire\Livewire::test(\App\Livewire\Gates\OutlineGate::class, ['story' => $story->fresh()])
            ->assertSee('A denied term in an act script is refused at scene drafting.')
            ->assertSee('Until it comes out, scene drafting refuses it.')
            ->assertDontSee('has to come out before you approve');
    }

    public function test_the_denylist_still_names_honorifics_and_commonwealth_spelling(): void
    {
        foreach (['Ay naku, she thought.', 'Her favourite colour was grey.', 'Opo, she said.'] as $leak) {
            $story = $this->outlinedStory(2);
            $this->writer->injectIntoScript = $leak;

            app(GenerateActScripts::class)->handle($story);

            $this->assertNotEmpty(
                array_filter(app(GenerateActScripts::class)->localeDenied($story->refresh()), fn (array $hit): bool => $hit['where'] === 'script'),
                "Not shown at Gate 1: {$leak}",
            );
        }
    }

    /** A clean act raises no denied alert, so the red panel means something when it appears. */
    public function test_a_clean_story_shows_no_denied_terms(): void
    {
        $story = $this->outlinedStory(2);

        app(GenerateActScripts::class)->handle($story);

        $this->assertSame([], app(GenerateActScripts::class)->localeDenied($story->refresh()));

        \Livewire\Livewire::test(\App\Livewire\Gates\OutlineGate::class, ['story' => $story])
            ->assertDontSee('kept for your judgement');
    }

    /**
     * The outline is kept too, and an outline term is the cheap repair: the
     * field is edited on the Gate 1 page, and the alert is recomputed from the
     * stored text, so the save that removes the term removes the alert.
     */
    public function test_a_denied_term_in_the_outline_is_kept_and_editing_it_out_clears_it(): void
    {
        $story = $this->draftStory();
        $this->writer->injectIntoOutline = 'The barangay captain refused.';

        app(GenerateOutline::class)->handle($story, 4);

        $this->assertSame(4, $story->acts()->count());
        $this->assertSame(StoryStatus::Outlined, $story->fresh()->status);

        $denied = app(GenerateActScripts::class)->localeDenied($story->refresh());
        $this->assertNotEmpty($denied);
        $this->assertTrue(collect($denied)->every(fn (array $hit): bool => $hit['editable'] && $hit['term'] === 'barangay'));

        $log = (string) \App\Models\RenderJob::query()->where('story_id', $story->id)
            ->where('stage', \App\Enums\RenderStage::Outline)->value('log');
        $this->assertStringContainsString('Kept with 1 term(s) the en-US denylist names', $log);

        $gate = \Livewire\Livewire::test(\App\Livewire\Gates\OutlineGate::class, ['story' => $story])
            ->assertSee('kept for your judgement');

        foreach (array_keys($gate->get('acts')) as $index) {
            $gate->set("acts.{$index}.summary", str_replace('The barangay captain refused.', 'The neighborhood captain refused.', $gate->get("acts.{$index}.summary")));
        }

        $gate->call('save')->assertHasNoErrors()->assertDontSee('kept for your judgement');

        $this->assertSame([], app(GenerateActScripts::class)->localeDenied($story->refresh()));
    }

    /**
     * Scenes still refuse, deliberately: a frame is one of 150-250 strings no
     * page puts in front of anyone. Pinned here beside the kept stages so the
     * split reads as a decision and not as a missed caller.
     */
    public function test_the_guard_still_throws_for_the_stages_gate_one_does_not_show(): void
    {
        $this->expectException(LocaleViolationException::class);

        app(LocaleGuard::class)->assert('A frame of the car park at dusk.', 'en-US', 'scene drafting for act 1');
    }

    public function test_an_english_word_that_collides_with_an_honorific_does_not_fail_a_stage(): void
    {
        // Regression, and an expensive one to have found live: "ate" is the
        // Filipino word for older sister AND the past tense of "eat". It was in
        // the denylist and discarded a generated act on the sentence "the
        // second one was always cold and she ate it anyway" — a paid call
        // thrown away over correct American English.
        //
        // The rule that came out of it: the denylist holds only terms with no
        // plausible English reading, because a hit there costs an act. Anything
        // ambiguous warns instead.
        $story = $this->outlinedStory(2);
        $this->writer->injectIntoScript = 'The second one was always cold and she ate it anyway.';

        app(GenerateActScripts::class)->handle($story);

        $this->assertNotNull($story->acts()->where('sequence', 1)->value('script'));
    }

    public function test_no_denylisted_term_is_a_common_english_word(): void
    {
        // The rule, enforced rather than remembered. Every denylist entry is
        // checked against a passage of ordinary American prose; a term that
        // fires on it would fail real acts.
        $innocent = <<<'TEXT'
            She ate the sandwich at the picnic table behind the post office and watched the
            grey pickup idle by the curb. Her aunt Lola called it a holiday, though nobody
            else did. He paid in pesos once, at a gas station outside El Paso, and the
            clerk laughed. Tito Reyes coached football at the high school for thirty years.
            The chips were cold, the queue was long, and the flat by the river had been
            empty since spring. Her mum sent a biscuit tin every Christmas.
            TEXT;

        $guard = app(LocaleGuard::class);

        // Warns freely...
        $this->assertNotEmpty($guard->warnings($innocent, 'en-US'));

        // ...but does not fail. Every hit in that passage is ambiguous, and
        // ambiguous never costs an act.
        $guard->assert($innocent, 'en-US', 'regression check');

        $this->addToAssertionCount(1);
    }

    public function test_ambiguous_terms_warn_rather_than_fail(): void
    {
        // "mum" is a flower and "flat" is a tyre. Failing on these would teach
        // an operator to switch the guard off, and a guard that gets switched
        // off catches nothing.
        $story = $this->outlinedStory(2);
        $this->writer->injectIntoScript = 'She put the mum on the pavement and looked at the flat.';

        app(GenerateActScripts::class)->handle($story);

        $warnings = app(GenerateActScripts::class)->localeWarnings($story);
        $terms = array_column($warnings, 'term');

        $this->assertContains('mum', $terms);
        $this->assertContains('flat', $terms);
        $this->assertNotNull($story->acts()->where('sequence', 1)->value('script'));
    }

    public function test_clean_american_prose_passes_untouched(): void
    {
        $story = $this->outlinedStory(3);

        app(GenerateActScripts::class)->handle($story);

        $this->assertSame(3, $story->acts()->whereNotNull('script')->count());
        $this->assertSame(3, $story->acts()->where('is_rehook_written', true)->count());
    }

    // -- Cost ----------------------------------------------------------------

    public function test_script_generation_writes_cost_rows_before_gate_two(): void
    {
        // The question this phase had to answer. Script generation costs money
        // and runs at `draft`/`outlined` — both below `scenes_approved`. The
        // old guard refused every row before Gate 2, which made "every paid
        // call writes a row" impossible for this entire stage.
        $story = $this->draftStory();

        $this->assertFalse($story->canGeneratePaidAssets());

        app(GenerateOutline::class)->handle($story, 4);
        app(GenerateActScripts::class)->handle($story->refresh());

        $entries = $story->costEntries()->get();

        $this->assertCount(5, $entries, 'One outline call plus four acts should be five cost rows.');
        $this->assertTrue($entries->every(fn (CostEntry $e): bool => $e->category === CostCategory::Text));

        // Still below Gate 2 the whole time.
        $this->assertFalse($story->fresh()->canGeneratePaidAssets());
    }

    public function test_the_money_line_is_untouched_by_that(): void
    {
        // The gate still refuses asset spend before Gate 2. Widening the text
        // category must not have widened the thing it was carved out of.
        $story = $this->draftStory();

        $this->expectException(GateViolationException::class);
        $this->expectExceptionMessage('no paid asset generation may begin');

        CostEntry::factory()->for($story)->image()->create();
    }

    public function test_a_cost_row_with_no_category_defaults_to_the_gated_one(): void
    {
        // A caller that has not thought about which kind of spend it is gets
        // the restrictive answer, not the permissive one.
        $story = $this->draftStory();

        try {
            CostEntry::create([
                'story_id' => $story->id,
                'provider' => 'somebody',
                'operation' => 'unspecified',
                'quantity' => 1,
                'unit' => CostUnit::Requests,
                'usd_cost' => 1.0,
            ]);
            $this->fail('An uncategorised cost row was written before Gate 2.');
        } catch (GateViolationException) {
            $this->assertSame(0, $story->costEntries()->count());
        }
    }

    public function test_the_story_total_matches_the_sum_of_its_rows(): void
    {
        // A draft story, to make the point twice: these are text rows, they
        // are written before Gate 2, and they still reach the total.
        $story = $this->draftStory();

        CostEntry::factory()->for($story)->text(tokens: 3000, usd: 0.2500)->create();
        CostEntry::factory()->for($story)->text(tokens: 4000, usd: 0.3300)->create();

        $this->assertSame('0.5800', $story->fresh()->total_cost_usd);
        $this->assertSame(
            (float) $story->costEntries()->sum('usd_cost'),
            (float) $story->fresh()->total_cost_usd
        );
    }

    public function test_a_free_fake_call_still_writes_a_row(): void
    {
        // "This cost nothing" and "nobody recorded what this cost" must not
        // look the same from the table the cost question is answered from.
        $story = $this->draftStory();

        app(GenerateOutline::class)->handle($story, 3);

        $this->assertSame(1, $story->costEntries()->count());
        $this->assertSame('0.0000', $story->costEntries()->first()->usd_cost);
    }

    // -- Fixtures ------------------------------------------------------------

    private function draftStory(): Story
    {
        return Story::factory()->status(StoryStatus::Draft)->create([
            'format' => StoryFormat::Anthology,
            'locale_profile' => 'en-US',
            'target_duration_min' => 30,
            'target_duration_max' => 40,
            'premise' => 'Five strangers in a small Ohio town each find something they were not meant to.',
        ]);
    }

    private function outlinedStory(int $acts): Story
    {
        $story = $this->draftStory();

        app(GenerateOutline::class)->handle($story, $acts);

        $this->writer->calls = [];

        return $story->refresh();
    }
}
