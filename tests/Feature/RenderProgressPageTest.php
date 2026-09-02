<?php

namespace Tests\Feature;

use App\Enums\CostCategory;
use App\Enums\CostUnit;
use App\Enums\RenderJobStatus;
use App\Enums\RenderStage;
use App\Enums\StoryStatus;
use App\Models\Act;
use App\Models\CostEntry;
use App\Models\RenderJob;
use App\Models\Scene;
use App\Models\Story;
use App\Support\RenderProgress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The batch progress page — this project's stand-in for Horizon's dashboard.
 *
 * The bar is not "it renders". At 200 scenes it has to answer three questions
 * without the operator reading anything carefully: how far along, did anything
 * fail, and which scenes. A fourth, which nothing else on this platform can
 * answer at all: is a worker hung?
 */
class RenderProgressPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_page_stays_a_fixed_number_of_queries_at_two_hundred_scenes(): void
    {
        // The number that matters. A page that issues a query per scene is a
        // page that stops loading at exactly the point it becomes necessary.
        $story = $this->storyWithBatch(scenes: 200, failed: 4);

        DB::enableQueryLog();

        RenderProgress::for($story);

        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThan(
            15,
            $queries,
            "The progress report issued {$queries} queries. It must not scale with scene count."
        );
    }

    public function test_the_report_answers_how_far_along_and_what_failed(): void
    {
        $story = $this->storyWithBatch(scenes: 200, failed: 3, running: 2);

        $report = RenderProgress::for($story);

        $this->assertSame(200, $report['overall']['total']);
        $this->assertSame(195, $report['overall']['succeeded']);
        $this->assertSame(3, $report['overall']['failed']);
        $this->assertSame(2, $report['overall']['running']);
        $this->assertTrue($report['overall']['active']);

        $stage = collect($report['stages'])->firstWhere('stage', RenderStage::SceneClips);

        $this->assertSame(200, $stage['total']);
        $this->assertSame(3, $stage['failed']);

        // And the question a count cannot answer.
        $this->assertCount(3, $report['failures']);
        $this->assertSame([1, 2, 3], array_column($report['failures'], 'scene'));
        $this->assertStringContainsString('Provider returned 429', $report['failures'][0]['error']);
    }

    public function test_the_batch_row_is_read_from_job_batches(): void
    {
        $story = $this->storyWithBatch(scenes: 12, failed: 1);

        $report = RenderProgress::for($story);

        $this->assertCount(1, $report['batches']);
        $this->assertSame(12, $report['batches'][0]['total']);
        $this->assertSame(1, $report['batches'][0]['failed']);
        $this->assertSame("scene-clips:{$story->slug}", $report['batches'][0]['name']);
    }

    public function test_a_batch_whose_failures_were_never_retried_is_not_reported_as_in_flight(): void
    {
        // Laravel's incrementFailedJobs() does not decrement pending_jobs — a
        // failed job stays counted as pending so it can be retried into the
        // batch — so an allowFailures batch with unretried failures never gets
        // finished_at. The page read that as "in flight" and had an operator
        // waiting on workers that had already exited.
        $story = Story::factory()->status(StoryStatus::AssetsGenerating)->create(['slug' => 'abandoned-batch']);
        $act = Act::factory()->for($story)->atSequence(1)->create();
        $scene = Scene::factory()->forAct($act)->atSequence(1)->create();

        DB::table('job_batches')->insert([
            'id' => 'batch-abandoned',
            'name' => "scene-assets:{$story->slug}",
            'total_jobs' => 371,
            // Every job ran: 327 succeeded, 44 failed and stayed "pending".
            'pending_jobs' => 44,
            'failed_jobs' => 44,
            'failed_job_ids' => '[]',
            'created_at' => now()->subMinutes(30)->timestamp,
            'finished_at' => null,
        ]);

        DB::table('render_jobs')->insert([
            'story_id' => $story->id, 'scene_id' => $scene->id,
            'stage' => RenderStage::Images->value, 'status' => RenderJobStatus::Succeeded->value,
            'batch_id' => 'batch-abandoned', 'started_at' => now()->subMinutes(20),
            'finished_at' => now()->subMinutes(19), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $batch = RenderProgress::for($story)['batches'][0];

        $this->assertFalse($batch['in_flight'], 'Nothing is queued; this must not read as in flight.');
        $this->assertTrue($batch['abandoned']);

        // And the count reflects what RAN, not total-minus-pending, which
        // understated a finished batch by exactly its failure count.
        $this->assertSame(371, $batch['processed']);
        $this->assertSame(100, $batch['percent']);

        $this->get(route('renders.show', $story->slug))
            ->assertOk()
            ->assertSee('closed with failures')
            ->assertDontSee('in flight');
    }

    public function test_a_stage_is_labelled_by_what_it_cost_not_by_what_it_could_cost(): void
    {
        // RenderStage::isPaid() says a stage CAN spend money. Only the ledger
        // says whether this run did. Tagging a stand-in run "paid" is the same
        // class of mislabel that let phantom spend read as a bill.
        $story = Story::factory()->status(StoryStatus::AssetsReady)->create(['slug' => 'stage-labels']);
        $act = Act::factory()->for($story)->atSequence(1)->create();
        $scene = Scene::factory()->forAct($act)->atSequence(1)->create();

        foreach ([
            [RenderStage::Images, 'generate_image'],
            [RenderStage::SceneNarration, 'synthesize_speech'],
        ] as [$stage, $operation]) {
            DB::table('render_jobs')->insert([
                'story_id' => $story->id, 'scene_id' => $scene->id,
                'stage' => $stage->value, 'status' => RenderJobStatus::Succeeded->value,
                'started_at' => now()->subMinute(), 'finished_at' => now(),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        // A real still, and a stand-in narration.
        CostEntry::create([
            'story_id' => $story->id, 'provider' => 'fal', 'simulated' => false,
            'operation' => 'generate_image', 'category' => CostCategory::Asset,
            'quantity' => 1, 'unit' => CostUnit::Images, 'usd_cost' => 0.035,
        ]);
        CostEntry::create([
            'story_id' => $story->id, 'provider' => 'fake', 'simulated' => true,
            'operation' => 'synthesize_speech', 'category' => CostCategory::Asset,
            'quantity' => 100, 'unit' => CostUnit::Characters, 'usd_cost' => 0.0,
        ]);

        $stages = collect(RenderProgress::for($story)['stages'])->keyBy(fn (array $s): string => $s['stage']->value);

        $this->assertSame(1, $stages[RenderStage::Images->value]['billed_calls']);
        $this->assertEqualsWithDelta(0.035, $stages[RenderStage::Images->value]['usd'], 0.0001);

        $this->assertSame(0, $stages[RenderStage::SceneNarration->value]['billed_calls']);
        $this->assertSame(1, $stages[RenderStage::SceneNarration->value]['simulated_calls']);

        $this->get(route('renders.show', $story->slug))
            ->assertOk()
            ->assertSee('paid $0.0350')
            ->assertSee('simulated $0.00');
    }

    public function test_a_silent_heartbeat_is_surfaced_as_its_own_alarm(): void
    {
        $story = $this->storyWithBatch(scenes: 4);

        RenderJob::factory()->for($story)->stale()->create([
            'stage' => RenderStage::Mux,
        ]);

        $report = RenderProgress::for($story);

        $this->assertCount(1, $report['stale']);
        $this->assertSame(RenderStage::Mux, $report['stale'][0]['stage']);

        $muxStage = collect($report['stages'])->firstWhere('stage', RenderStage::Mux);
        $this->assertTrue($muxStage['stale']);
    }

    public function test_the_scene_grid_carries_one_cell_per_scene_in_order(): void
    {
        $story = $this->storyWithBatch(scenes: 200, failed: 2);

        $grid = RenderProgress::for($story)['scene_grid'][RenderStage::SceneClips->value];

        $this->assertCount(200, $grid);
        $this->assertSame(1, $grid[0]['sequence']);
        $this->assertSame(200, $grid[199]['sequence']);
        $this->assertSame('failed', $grid[0]['status']);
        $this->assertSame('succeeded', $grid[199]['status']);
    }

    public function test_the_page_renders_and_names_the_failed_scenes(): void
    {
        $story = $this->storyWithBatch(scenes: 200, failed: 2);

        $response = $this->get(route('renders.show', $story->slug));

        $response->assertOk();
        $response->assertSee($story->title);
        $response->assertSee('Scene Clips');
        $response->assertSee('Provider returned 429');
        // 200 cells, of which two are red.
        $response->assertSeeInOrder(['cell failed', 'cell failed', 'cell succeeded']);
    }

    public function test_the_page_only_refreshes_itself_while_work_is_in_flight(): void
    {
        // A page that reloads forever is a page an operator closes, and then it
        // is not open when the heartbeat goes quiet.
        $idle = $this->storyWithBatch(scenes: 4);
        $this->get(route('renders.show', $idle->slug))->assertDontSee('http-equiv="refresh"', false);

        $busy = $this->storyWithBatch(scenes: 4, running: 1, slug: 'busy-story');
        $this->get(route('renders.show', $busy->slug))->assertSee('http-equiv="refresh"', false);
    }

    public function test_the_index_lists_stories_with_their_progress(): void
    {
        $this->storyWithBatch(scenes: 12, failed: 1);

        $response = $this->get(route('renders.index'));

        $response->assertOk();
        $response->assertSee('sample-slug');
        $response->assertSee('11/12 done');
    }

    public function test_an_unknown_story_is_a_404_not_an_error(): void
    {
        $this->get(route('renders.show', 'no-such-story'))->assertNotFound();
    }

    private function storyWithBatch(
        int $scenes,
        int $failed = 0,
        int $running = 0,
        string $slug = 'sample-slug',
    ): Story {
        $story = Story::factory()->status(StoryStatus::Rendering)->create(['slug' => $slug]);
        $act = Act::factory()->for($story)->atSequence(1)->create();

        $batchId = 'batch-'.$slug;

        DB::table('job_batches')->insert([
            'id' => $batchId,
            'name' => "scene-clips:{$slug}",
            'total_jobs' => $scenes,
            'pending_jobs' => $running,
            'failed_jobs' => $failed,
            'failed_job_ids' => '[]',
            'created_at' => now()->subMinutes(5)->timestamp,
            'finished_at' => $running === 0 ? now()->timestamp : null,
        ]);

        $rows = [];

        for ($i = 1; $i <= $scenes; $i++) {
            $scene = Scene::factory()->forAct($act)->atSequence($i)->create();

            $status = match (true) {
                $i <= $failed => RenderJobStatus::Failed,
                $i <= $failed + $running => RenderJobStatus::Running,
                default => RenderJobStatus::Succeeded,
            };

            $rows[] = [
                'story_id' => $story->id,
                'scene_id' => $scene->id,
                'stage' => RenderStage::SceneClips->value,
                'status' => $status->value,
                'batch_id' => $batchId,
                'started_at' => now()->subMinutes(4),
                'finished_at' => $status === RenderJobStatus::Running ? null : now()->subMinutes(1),
                'error' => $status === RenderJobStatus::Failed ? 'Provider returned 429.' : null,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        DB::table('render_jobs')->insert($rows);

        return $story;
    }
}
