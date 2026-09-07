<?php

namespace Tests\Feature;

use App\Enums\CostCategory;
use App\Enums\StoryStatus;
use App\Livewire\Dashboard;
use App\Models\CostEntry;
use App\Models\Story;
use App\Support\SpendSummary;
use App\Support\WorkerHealth;
use App\Support\WorkerRegistry;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\PageProbe;
use Tests\TestCase;

/**
 * The landing page, asserted on the two axes it was built for.
 *
 * The first is reachability: it must render at all, with providers resolved
 * from the container rather than from config. The second is the one this page
 * could most easily get wrong — it summarises money, and a summary is the most
 * tempting place in an application to write a second copy of a figure that
 * already exists.
 */
class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_dashboard_renders(): void
    {
        $this->get(route('dashboard'))->assertOk()->assertSee('Waiting on you');
    }

    /**
     * The alarm band carries every queue and says the shared part once.
     *
     * The band replaced three stacked alert boxes. That is a prominence GAIN —
     * it is full-bleed, saturated, and sits above everything — but the way a
     * consolidation goes wrong is by consolidating the facts too, so this
     * asserts the facts survived: every queue named, every pending count
     * printed, every command still pasteable, and the ~60 words of shared
     * explanation present exactly once rather than once per queue.
     */
    public function test_the_alarm_band_names_every_queue_and_explains_once(): void
    {
        config(['queue.default' => 'redis']);

        foreach (['text', 'assets', 'render'] as $queue) {
            WorkerRegistry::flush($queue);
        }

        WorkerHealth::forget();
        Queue::shouldReceive('connection')->andReturn(Mockery::mock(['size' => 152]));

        $html = Livewire::test(Dashboard::class)->html();

        // Blade keeps its own indentation, so a sentence that wraps in the
        // template arrives with newlines inside it. Collapsed for the prose
        // assertions; the raw html is kept for the markup ones.
        $text = (string) preg_replace('/\s+/', ' ', $html);

        $this->assertStringContainsString('class="band bleed"', $html);

        // The queues are named TOGETHER with their own counts, rather than one
        // headline per queue. The assertion moved with the phrasing; what it is
        // protecting did not — every queue named, every count printed.
        foreach (['text' => 'NarraText', 'assets' => 'NarraAssets', 'render' => 'NarraRender'] as $queue => $service) {
            $this->assertStringContainsString(
                $queue.' (152)',
                $text,
                $queue.' is not named in the band with its own count.',
            );
            // `Restart-Service`, not `nssm start`. nssm is not on PATH on this
            // machine — the installer resolves it three ways and the panel did
            // none of them — and `start` is a no-op against a service that is
            // running and taking nothing.
            $this->assertStringContainsString(
                'Restart-Service '.$service,
                $html,
                $queue.' has no pasteable fix.',
            );
        }

        // The total, so the collapse states how much work has stopped rather
        // than leaving it to be added up from three lines.
        $this->assertStringContainsString('456 job(s) stranded across 3 queues', $text);

        // The shared explanation, once — not once per queue. This is the whole
        // point of the collapse and the thing most likely to regress.
        $this->assertSame(
            1,
            substr_count($text, 'a hand-started worker exits at'),
            'The shared explanation should appear once, not once per queue.',
        );

        // WorkerHealth's per-state advice, also once.
        $this->assertSame(
            1,
            substr_count($text, 'nothing waiting on a queue with no worker will run until one starts'),
            'The per-state advice should appear once, not once per queue.',
        );

        // And exactly one band, rather than one per queue.
        $this->assertSame(1, substr_count($html, 'class="band bleed"'));
    }

    /**
     * "In flight" means work that is MOVING, not work whose next step happens
     * to be a queued job.
     *
     * The reading to prevent, and it was live: six abandoned drafts — four of
     * which had produced nothing at all, no acts, no scenes, no jobs, no billed
     * calls — listed under a heading that says work is in progress. Every
     * number in the section was true and the heading was false, which is the
     * same defect as a progress page reading "118 done, nothing failed" with
     * 152 scenes stranded in Redis.
     *
     * `NextAction` was answering a different question honestly: it says what
     * KIND of thing comes next, and for a story parked at `draft` the answer is
     * "a queued job" whether one was ever dispatched or not. The dashboard was
     * reading that as evidence of a batch. It is not.
     */
    public function test_a_draft_that_never_started_is_not_in_flight(): void
    {
        $abandoned = Story::factory()->create([
            'title' => 'Never Dispatched',
            'status' => StoryStatus::Draft,
        ]);

        $moving = Story::factory()->create([
            'title' => 'Actually Generating',
            'status' => StoryStatus::AssetsGenerating,
        ]);

        $html = (string) preg_replace('/\s+/', ' ', Livewire::test(Dashboard::class)->html());

        // Both are still on the page — nothing is hidden.
        $this->assertStringContainsString('Never Dispatched', $html);
        $this->assertStringContainsString('Actually Generating', $html);

        // But the abandoned one is under "Not moving", with what it has built,
        // rather than under a heading that claims it is progressing.
        $this->assertStringContainsString('Not moving', $html);

        $inFlight = Livewire::test(Dashboard::class)->viewData('inFlight');
        $notMoving = Livewire::test(Dashboard::class)->viewData('notMoving');

        $this->assertTrue($inFlight->contains(fn (Story $s): bool => $s->id === $moving->id));
        $this->assertFalse($inFlight->contains(fn (Story $s): bool => $s->id === $abandoned->id));
        $this->assertTrue($notMoving->contains(fn (Story $s): bool => $s->id === $abandoned->id));
    }

    /**
     * With nothing running and nothing wrong, the page reflows.
     *
     * The dashboard was drawn for the busy case — three columns, an alarm band,
     * a scene grid — and most of the time none of that is true. The busy layout
     * with nothing in it is not a calm page: it is the same containers at the
     * same size holding gaps, and "In flight: idle" took a full column to say
     * nothing while the gates that were the only actionable thing competed with
     * it for attention.
     *
     * A test cannot measure a screenful, so this asserts the structural
     * properties that produce one: the grid is in its quiet mode, the decisions
     * come first in the document, and what is NOT happening is a line rather
     * than a card. Those are the things that would have to regress for the
     * layout to go back to reading as scattered.
     */
    public function test_a_quiet_page_gives_the_room_to_the_decisions(): void
    {
        Story::factory()->create(['title' => 'Needs A Decision', 'status' => StoryStatus::ScenesDrafted]);
        Story::factory()->create(['title' => 'Abandoned Draft', 'status' => StoryStatus::Draft]);

        $html = Livewire::test(Dashboard::class)->html();

        $this->assertStringContainsString('class="dash quiet"', $html);

        // The strip, not a second card with a heading over nothing.
        $this->assertStringContainsString('class="strip"', $html);
        $this->assertStringNotContainsString('<h2>In flight</h2>', $html);
        $this->assertStringNotContainsString('<h2>Not moving</h2>', $html);

        // Nothing is dropped: the abandoned draft is still reachable, behind a
        // disclosure rather than above the decisions.
        $this->assertStringContainsString('Abandoned Draft', $html);
        $this->assertStringContainsString('1 not moving', $html);

        // The decisions come first in the document, so they are first on a
        // narrow screen too rather than only in the grid.
        $this->assertLessThan(
            strpos($html, 'class="strip"'),
            strpos($html, 'Waiting on you'),
            'The actionable section must come before the one describing what is not happening.',
        );
    }

    /**
     * And the busy layout comes back when there is something to be busy about.
     */
    public function test_work_in_flight_restores_the_full_layout(): void
    {
        Story::factory()->create(['title' => 'Actually Generating', 'status' => StoryStatus::AssetsGenerating]);

        $html = Livewire::test(Dashboard::class)->html();

        $this->assertStringNotContainsString('class="dash quiet"', $html);
        $this->assertStringContainsString('<h2>In flight</h2>', $html);
    }

    /**
     * A fixture is not work, and every section that means "do something about
     * this" leaves it out.
     *
     * The reading to prevent: a section that always contains something it
     * should not. Three stories on this machine exist to be measured against
     * rather than published — the Phase 0 render fixture, parked at `rendered`
     * and therefore a permanent resident of "Waiting on you", and the two
     * style-preview casts, permanent residents of "Not moving". Neither was
     * ever going to move, so both sections were guaranteed never to empty.
     *
     * That is the same failure as an alarm that is always on. It is not a wrong
     * number; it is a true one that has stopped being read, and it costs you the
     * day something real lands beside it.
     */
    public function test_a_fixture_is_kept_out_of_the_sections_that_mean_do_something(): void
    {
        // Parked where the Phase 0 fixture actually sits: at a gate, waiting
        // on the operator, forever.
        Story::factory()->create([
            'title' => 'Sample Story (Phase 0 Fixture)',
            'status' => StoryStatus::Rendered,
            'is_fixture' => true,
            'fixture_note' => 'The Phase 0 render fixture. It stops at Gate 3 on purpose.',
        ]);

        // And where the style-preview casts sit: waiting on a queue nothing
        // will ever dispatch to.
        Story::factory()->create([
            'title' => 'STYLE PREVIEW FIXTURE',
            'status' => StoryStatus::Draft,
            'is_fixture' => true,
            'fixture_note' => 'The style measuring stick. Never re-extracted.',
        ]);

        $component = Livewire::test(Dashboard::class);

        $this->assertCount(0, $component->viewData('waitingOnOperator'));
        $this->assertCount(0, $component->viewData('notMoving'));
        $this->assertCount(0, $component->viewData('inFlight'));

        // And the rail's badge, which reads the same sweep.
        \App\Support\ConsoleCounts::forget();
        $this->assertSame(0, \App\Support\ConsoleCounts::all()['waiting']);
    }

    /**
     * It is hidden from the queue of things to do, never from the app.
     *
     * A flag that made a story vanish would trade one silent wrongness for
     * another: an operator looking for the fixture would find nothing and have
     * no way to learn why. It keeps its page, its costs and its place on the
     * index — and its page says what it is and why it never advances, so the
     * absence from every count is explained where somebody would go looking.
     */
    public function test_a_fixture_keeps_its_page_and_says_what_it_is(): void
    {
        $story = Story::factory()->create([
            'title' => 'Sample Story (Phase 0 Fixture)',
            'status' => StoryStatus::Rendered,
            'is_fixture' => true,
            'fixture_note' => 'The Phase 0 render fixture: twelve hand-made scenes.',
        ]);

        $this->get(route('stories.preview', $story))
            ->assertOk()
            ->assertSee('This is a fixture. It is not going to be published, and it is not waiting on you.')
            ->assertSee('The Phase 0 render fixture: twelve hand-made scenes.');

        // Still on the index, and marked there rather than silently absent.
        $this->get(route('stories.index'))
            ->assertOk()
            ->assertSee('Sample Story (Phase 0 Fixture)')
            ->assertSee('fixture');
    }

    /**
     * A story at a gate is named, with the gate it is standing at.
     */
    public function test_a_story_waiting_on_the_operator_is_listed_under_its_gate(): void
    {
        $story = Story::factory()->create([
            'title' => 'The House in Ohio',
            'status' => StoryStatus::ScenesDrafted,
        ]);

        Livewire::test(Dashboard::class)
            ->assertSee('The House in Ohio')
            ->assertSee('Gate 2');

        $this->assertSame(StoryStatus::ScenesDrafted, $story->fresh()->status);
    }

    /**
     * Published is terminal. Carrying it would make this page longer every
     * time a video ships, which is the opposite of what a summary is for.
     */
    public function test_a_published_story_is_not_carried(): void
    {
        Story::factory()->create(['title' => 'Already On YouTube', 'status' => StoryStatus::Published]);

        Livewire::test(Dashboard::class)->assertDontSee('Already On YouTube');
    }

    /**
     * The month total and the per-story totals must be built from ONE
     * predicate.
     *
     * `stories.total_cost_usd` is maintained by `CostEntry::booted()` through
     * `CostCategory::countsTowardVideoCost()`. This asserts the roll-up asks
     * the same question rather than restating it — the failure it guards
     * against is a fifth category being added and counted in one place and not
     * the other, which is exactly how one narration multiplier ended up
     * applied at three different prices.
     */
    public function test_the_month_roll_up_agrees_with_the_denormalised_story_totals(): void
    {
        $story = Story::factory()->create(['status' => StoryStatus::ScenesApproved]);

        CostEntry::create([
            'story_id' => $story->id,
            'provider' => 'anthropic',
            'operation' => 'generate_outline',
            'category' => CostCategory::Text,
            'quantity' => 1000,
            'unit' => \App\Enums\CostUnit::OutputTokens,
            'usd_cost' => 1.2500,
        ]);

        CostEntry::create([
            'story_id' => $story->id,
            'provider' => 'fal',
            'operation' => 'generate_image',
            'category' => CostCategory::Asset,
            'quantity' => 1,
            'unit' => \App\Enums\CostUnit::Images,
            'usd_cost' => 0.7500,
        ]);

        // Evaluation is real spend and deliberately outside the per-video
        // total. It must be outside the roll-up's video figure too, and
        // visible separately in both — logged and nowhere on screen is the
        // same defect one level up.
        CostEntry::create([
            'story_id' => $story->id,
            'provider' => 'fal',
            'operation' => 'style_preview',
            'category' => CostCategory::Evaluation,
            'quantity' => 1,
            'unit' => \App\Enums\CostUnit::Images,
            'usd_cost' => 0.3000,
        ]);

        $spend = SpendSummary::forCurrentMonth();

        $this->assertSame(2.0, round($spend->monthVideoSpend, 4));
        $this->assertSame(0.3, round($spend->monthEvaluationSpend, 4));

        // The identity that matters: the roll-up and the column agree.
        $this->assertSame(
            round((float) $story->fresh()->total_cost_usd, 4),
            round($spend->monthVideoSpend, 4),
        );
        $this->assertSame(0.3, round($story->fresh()->evaluationSpend(), 4));
    }

    /**
     * The dashboard's own columns cut no empty track.
     *
     * THE LAST KNOWN COPY OF THE DEFECT, and the page that started the line of
     * work: `.dash` is a fixed three-track template whose children are
     * populated conditionally. It has never actually produced a void — measured
     * across every shape below, not assumed — and the only reason is that every
     * column happens to carry an unconditional wrapper. That is an accident, and
     * one `@if` around one card would end it.
     *
     * The columns are `x-gate-group`s now, so a column with nothing in it
     * renders no element and the template restates itself for two. This is the
     * same assertion Gates 1, 2 and 4 carry, pointed at `.dash` — the travelling
     * half of the fix rather than a fourth hand-written instance of it.
     */
    public function test_the_dashboard_columns_cut_no_empty_track(): void
    {
        foreach ($this->everyDashboardShape() as $label => $html) {
            $this->assertSame(
                [],
                PageProbe::emptyRowGroups($html, 'dash'),
                sprintf(
                    'The dashboard in its "%s" shape renders a column with nothing in it. The grid '
                    .'cuts it a track, so it is a void beside the section that can be acted on.',
                    $label,
                ),
            );
        }
    }

    /**
     * Every shape the dashboard's layout has, rendered.
     *
     * Quiet with nothing at all, quiet with a gate waiting, and busy with a
     * render in flight — the three that between them exercise both templates.
     *
     * @return array<string, string>
     */
    private function everyDashboardShape(): array
    {
        $shapes = [];

        $shapes['empty'] = Livewire::test(Dashboard::class)->html();

        Story::factory()->status(StoryStatus::Outlined)->create(['slug' => 'dash-shape-waiting']);
        $shapes['a gate waiting'] = Livewire::test(Dashboard::class)->html();

        Story::factory()->status(StoryStatus::Rendering)->create(['slug' => 'dash-shape-running']);
        $shapes['work in flight'] = Livewire::test(Dashboard::class)->html();

        return $shapes;
    }
}
