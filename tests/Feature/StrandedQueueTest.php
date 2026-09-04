<?php

namespace Tests\Feature;

use App\Enums\RenderJobStatus;
use App\Enums\RenderStage;
use App\Enums\StoryStatus;
use App\Models\Act;
use App\Models\Scene;
use App\Models\Story;
use App\Support\RunFingerprint;
use App\Support\WorkerHealth;
use App\Support\WorkerRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/**
 * A queue holding jobs with nothing listening, and the page that has to say so.
 *
 * The instance: story 21, 270 scenes, three asset stages. The `assets` worker
 * was started by hand with `--max-time=3600` — a number sized when stories were
 * 186 scenes — and a 270-scene run needs six hours of it, so the worker exited
 * cleanly part way through and nothing brought it back. From the progress page
 * that was indistinguishable from work in progress: 118 stills done, no
 * failures, no stale heartbeat, and 152 scenes waiting in Redis.
 *
 * Every number on the page was correct. The page as a whole was false, which is
 * what the spec's false-success table is a list of. The mechanism is worth
 * naming because it is structural rather than a bug: `RenderJob::open()` runs
 * INSIDE the job, so an unstarted scene has no row at all and `render_jobs`
 * cannot count the backlog however carefully it is asked. Only the queue knows,
 * so the queue is what gets asked.
 *
 * Each test below names the reading it is meant to prevent.
 */
class StrandedQueueTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['text', 'assets', 'render'] as $queue) {
            WorkerRegistry::flush($queue);
        }

        // The depth memo is held for a second, and these tests change the
        // answer several times inside one.
        WorkerHealth::forget();

        // The suite runs on `sync`, where WorkerHealth correctly short-circuits
        // to INLINE — the dispatching process IS the worker and cannot be
        // stranded from itself. These tests are about the driver that has a
        // separate worker, so they say so.
        config(['queue.default' => 'redis']);
    }

    /**
     * The reading to prevent: "queued" taken to mean "about to run".
     */
    public function test_a_queue_holding_jobs_with_no_worker_is_stranded_not_absent(): void
    {
        $this->queueDepthIs(152);

        $health = WorkerHealth::forQueue('assets');

        $this->assertSame(WorkerHealth::STRANDED, $health['state']);
        $this->assertSame(152, $health['pending']);
        $this->assertStringContainsString('152 job(s) are waiting', $health['headline']);
        $this->assertStringContainsString('nothing is listening', $health['headline']);
    }

    /**
     * The other half of the pair, and the reason STRANDED is its own state: an
     * empty queue with no worker is a note, not an alarm. Nothing is stopped,
     * because nothing was asked for.
     */
    public function test_an_empty_queue_with_no_worker_stays_a_warning(): void
    {
        $this->queueDepthIs(0);

        $health = WorkerHealth::forQueue('assets');

        $this->assertSame(WorkerHealth::ABSENT, $health['state']);
        $this->assertSame(0, $health['pending']);
    }

    /**
     * The reading to prevent, and the one this project keeps making: a check
     * that could not run reported as a check that passed.
     *
     * A dead Redis answers no question about depth. Reporting 0 would turn a
     * stranded queue into a calm one at exactly the moment the instrument
     * broke, which is absence read as agreement one level up.
     */
    public function test_a_depth_that_cannot_be_read_is_reported_as_unreadable_never_as_empty(): void
    {
        Queue::shouldReceive('connection')
            ->andThrow(new RuntimeException('Connection refused [tcp://127.0.0.1:6379]'));

        $health = WorkerHealth::forQueue('assets');

        $this->assertNull($health['pending'], 'An unreadable depth must not collapse to zero.');
        $this->assertStringContainsString('could not be read', $health['headline']);
        $this->assertStringNotContainsString('It is empty', $health['headline']);
    }

    /**
     * A live worker with a backlog is not an alarm — it is a worker working.
     * The count is still shown, because "how much is left" is the question an
     * operator opens this page to ask.
     */
    public function test_a_live_worker_with_a_backlog_is_healthy_and_says_how_much_is_left(): void
    {
        $this->queueDepthIs(416);
        WorkerRegistry::heartbeat('assets', RunFingerprint::shared(), force: true);

        $health = WorkerHealth::forQueue('assets');

        $this->assertSame(WorkerHealth::OK, $health['state']);
        $this->assertSame(416, $health['pending']);
        $this->assertStringContainsString('working through 416 queued job(s)', $health['headline']);
    }

    /**
     * The page, end to end, against the exact shape story 21 took: every
     * `render_jobs` row finished, no failures, no stale heartbeat, and the work
     * still undone.
     */
    public function test_the_progress_page_raises_the_alarm_when_the_worker_has_gone_and_the_work_has_not(): void
    {
        $this->queueDepthIs(152);
        $story = $this->storyMidAssetRun();

        $response = $this->get(route('renders.show', $story->slug));

        $response->assertOk();
        $response->assertSee('152 job(s) stranded');
    }

    /**
     * The regression proper, and the quietest part of the failure.
     *
     * `$overall['active']` is computed from `render_jobs`. With every row
     * finished it goes false, the meta refresh comes off the page, and the
     * footer reads "Nothing running — this page is not refreshing itself."
     * That is the page announcing the completion of a run that is a third done.
     */
    public function test_the_page_keeps_refreshing_while_jobs_wait_even_with_no_row_running(): void
    {
        $this->queueDepthIs(152);
        $story = $this->storyMidAssetRun();

        $this->get(route('renders.show', $story->slug))
            ->assertSee('http-equiv="refresh"', false);
    }

    /**
     * And the inverse, so the fix cannot quietly become "refresh forever": a
     * genuinely finished story with empty queues still stops.
     */
    public function test_a_finished_story_with_empty_queues_still_stops_refreshing(): void
    {
        $this->queueDepthIs(0);
        $story = $this->storyMidAssetRun();

        $this->get(route('renders.show', $story->slug))
            ->assertDontSee('http-equiv="refresh"', false);
    }

    /**
     * Every `render_jobs` row for this story is finished and none failed. The
     * backlog exists only in the queue, which is the whole point.
     */
    private function storyMidAssetRun(): Story
    {
        $story = Story::factory()->status(StoryStatus::AssetsGenerating)->create(['slug' => 'stranded-story']);
        $act = Act::factory()->for($story)->atSequence(1)->create();

        $rows = [];

        for ($i = 1; $i <= 6; $i++) {
            $scene = Scene::factory()->forAct($act)->atSequence($i)->create();

            $rows[] = [
                'story_id' => $story->id,
                'scene_id' => $scene->id,
                'stage' => RenderStage::Images->value,
                'status' => RenderJobStatus::Succeeded->value,
                'started_at' => now()->subMinutes(20),
                'finished_at' => now()->subMinutes(19),
                'created_at' => now()->subMinutes(20),
                'updated_at' => now()->subMinutes(19),
            ];
        }

        DB::table('render_jobs')->insert($rows);

        return $story;
    }

    /**
     * The queue's own answer, faked. Nothing here reaches Redis: the point of
     * the test is what the app does with the number, not that predis works.
     */
    private function queueDepthIs(int $jobs): void
    {
        Queue::shouldReceive('connection')
            ->andReturn(Mockery::mock(['size' => $jobs]));
    }
}
