<?php

namespace Tests\Feature;

use App\Enums\RenderJobStatus;
use App\Enums\RenderStage;
use App\Enums\StoryStatus;
use App\Models\Act;
use App\Models\RenderJob;
use App\Models\Scene;
use App\Models\Story;
use App\Support\RunFingerprint;
use App\Support\WorkerHealth;
use App\Support\WorkerRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
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
    /**
     * Three queues in the same state produce ONE alert, and it still names all
     * three.
     *
     * The reading to prevent is the opposite of the usual one here. This file
     * is otherwise a list of things that were too quiet; this is the one place
     * the console was too loud in a way that made it quieter. With every worker
     * down — the ordinary state of a machine that has just booted — the panel
     * emitted three full alerts whose only differences were a queue name and a
     * service name, and the ~60 words of shared explanation about `--max-time`
     * and NSSM appeared three times.
     *
     * Three copies of one message is not three times as loud. It is one message
     * nobody finishes, with the two facts that actually differ pushed a
     * paragraph apart.
     *
     * So this asserts both halves, because either alone can be satisfied by the
     * wrong fix: the explanation appears ONCE (not summarised away, not
     * repeated), and every affected queue is still named with its own command.
     */
    public function test_queues_in_the_same_state_collapse_into_one_alert(): void
    {
        $this->queueDepthIs(0);

        $html = $this->get(route('renders.index'))->getContent();

        // All three queues are absent, so all three must still be named.
        foreach (['text', 'assets', 'render'] as $queue) {
            $this->assertStringContainsString(
                'Nothing is listening on &quot;'.$queue.'&quot;',
                $html,
                $queue.' is not named on the page.',
            );
            $this->assertStringContainsString(
                'nssm start Narra'.ucfirst($queue),
                $html,
                $queue.' has no pasteable fix.',
            );
        }

        // …in one alert box rather than three.
        $this->assertSame(
            1,
            substr_count($html, 'class="alert warn mt-4"'),
            'Three queues in the same state should collapse into a single alert.',
        );
    }

    /**
     * And never ACROSS states, which is the collapse getting it wrong.
     *
     * Stale, stranded and absent want opposite reactions — stale refuses the
     * next dispatch, stranded means the pipeline has stopped right now, absent
     * is a note about a queue with nobody on it and nothing in it. Folding them
     * into one box would be the "calmer than the truth" edit the stylesheet's
     * one rule forbids, dressed up as tidying.
     */
    public function test_different_states_stay_in_different_alerts(): void
    {
        $this->queueDepthIs(152);

        // Every queue reads STRANDED at this depth with no workers, so the
        // page carries the loud box and not the quiet one.
        $html = $this->get(route('renders.index'))->getContent();

        $this->assertStringContainsString('class="alert err mt-4"', $html);
        $this->assertStringNotContainsString('class="alert warn mt-4"', $html);
        $this->assertStringContainsString('152 job(s) stranded', $html);
    }

    /**
     * A worker that stopped heartbeating is gone, however recently the CACHE
     * KEY was written.
     *
     * The reading to prevent: a panel naming pids that no longer exist on the
     * machine. The registry holds one map per queue and rewrites the whole map
     * on every heartbeat, so a live worker keeps refreshing the key's TTL for
     * everything in it — the expiry that matters is therefore per ENTRY, on
     * `seen_at`, not on the key.
     *
     * This pins that, because the two are easy to confuse and the failure is
     * silent: the panel would go on reporting a dead worker as live and current
     * for as long as any worker on that queue kept the key alive.
     */
    public function test_an_entry_that_stopped_heartbeating_is_not_live(): void
    {
        $now = microtime(true);

        // One worker last seen four and a half hours ago, one seen just now —
        // in the same map, so the key is fresh either way.
        Cache::put('narra:workers:text', [
            '99999' => [
                'pid' => 99999,
                'booted_at' => $now - 16380,
                'seen_at' => $now - 16380,
                'fingerprint' => ['code' => 'old'],
                'digest' => 'deadbeefdead',
            ],
            '11111' => [
                'pid' => 11111,
                'booted_at' => $now - 600,
                'seen_at' => $now - 5,
                'fingerprint' => RunFingerprint::shared(),
                'digest' => RunFingerprint::digest(RunFingerprint::shared()),
            ],
        ], 300);

        WorkerHealth::forget();
        $health = WorkerHealth::forQueue('text');

        $this->assertSame(1, $health['live'], 'A worker that stopped heartbeating is still being counted.');
        $this->assertSame(0, $health['stale']);
        $this->assertSame([11111], $health['pids'], 'The panel is naming a pid that is gone.');
        $this->assertSame(WorkerHealth::OK, $health['state']);
    }

    /**
     * Every reading carries the moment it was taken.
     *
     * A rendered page cannot know how long it has been open, and the renders
     * views deliberately drop their meta refresh whenever nothing is running —
     * which is exactly when workers get restarted. Without a stamp, a panel
     * loaded before a restart is indistinguishable from a live one: every
     * number on it correct, as of a moment that has passed.
     *
     * That is the same defect as a render page reporting a previous run's
     * stages as current, and it is the one this panel could least afford,
     * because it is the reading taken before authorising a spend.
     */
    public function test_the_panel_says_when_it_was_read(): void
    {
        $this->queueDepthIs(0);

        $health = WorkerHealth::forQueue('text');

        $this->assertEqualsWithDelta(microtime(true), $health['read_at'], 5.0);

        $html = $this->get(route('renders.index'))->getContent();

        // The stamp, and the pids beside it. Both exist so the panel can be
        // checked against the machine rather than believed.
        $this->assertStringContainsString('data-read-at=', $html);
        $this->assertStringContainsString('data-reading-age', $html);
    }

    /**
     * A worker inside a long job stays visible.
     *
     * The reading to prevent, and it is the inverse of every other test in this
     * file: the panel saying nothing is listening while a worker is mid-encode.
     *
     * `Looping` does not fire while a job runs and `JobProcessing` fires once
     * before it, so anything longer than the registry TTL used to age its own
     * worker out. On this pipeline that is not a corner case — the mux
     * re-encodes a 40-minute video in a single job, and the assets queue runs
     * hundreds of image calls at about 53 seconds each. The panel an operator
     * reads before authorising a spend would have said ABSENT while everything
     * was fine, which is the same false reading as a stranded queue with the
     * sign flipped.
     *
     * The long jobs already tick `RenderJob::heartbeat()` for the row's own
     * staleness clock. This asserts that beat now reaches the registry too.
     */
    public function test_a_worker_inside_a_long_job_stays_visible(): void
    {
        config(['queue.default' => 'redis']);
        $this->queueDepthIs(0);

        // The worker announces itself once, as `Looping` does at the top of the
        // poll, and then picks up a job.
        WorkerRegistry::heartbeat('render', RunFingerprint::shared());

        $this->assertSame(1, WorkerHealth::forQueue('render')['live']);

        // Now the silence. `Looping` will not fire again until the job is done
        // and `JobProcessing` has already fired, so nothing refreshes the entry
        // and it ages out — which `flush()` stands in for here, because the
        // alternative is a test that sleeps for five minutes.
        WorkerRegistry::flush('render');
        WorkerHealth::forget();

        $this->assertSame(
            0,
            WorkerHealth::forQueue('render')['live'],
            'The entry should be gone — otherwise this test proves nothing.',
        );

        // Now the same silence, with the job ticking its heartbeat the way
        // ConcatRenderJob, GenerateSubtitlesJob and RenderStageJob all do.
        $story = Story::factory()->create(['status' => StoryStatus::Rendering]);
        $job = RenderJob::create([
            'story_id' => $story->id,
            'stage' => RenderStage::Mux,
            'status' => RenderJobStatus::Running,
            'started_at' => now(),
        ]);

        $job->heartbeat();
        WorkerHealth::forget();

        $health = WorkerHealth::forQueue('render');

        $this->assertSame(1, $health['live'], 'A worker mid-job is being reported as gone.');
        $this->assertSame(WorkerHealth::OK, $health['state']);
        $this->assertSame([getmypid()], $health['pids']);
    }

    private function queueDepthIs(int $jobs): void
    {
        Queue::shouldReceive('connection')
            ->andReturn(Mockery::mock(['size' => $jobs]));
    }
}
