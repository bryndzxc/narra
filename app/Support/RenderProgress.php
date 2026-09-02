<?php

namespace App\Support;

use App\Enums\RenderJobStatus;
use App\Enums\RenderStage;
use App\Models\RenderJob;
use App\Models\Story;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Everything the render progress page shows, assembled in a fixed number of
 * queries regardless of how many scenes there are.
 *
 * This is the replacement for Horizon's batch dashboard, which is not available
 * here — Horizon hard-requires pcntl and posix, and neither exists in Windows
 * PHP. Bus::batch() itself works fine, so the data is all there; what is
 * missing is somewhere to look at it. At 200 scenes a partial failure is
 * otherwise invisible.
 *
 * Three questions, and the page is built around answering them in that order:
 *
 *   1. How far along is it?
 *   2. Did anything fail?
 *   3. Which scenes, and why?
 *
 * A fourth, which nothing else on this platform can answer: is a worker hung?
 * `queue:work --timeout` is enforced with a pcntl alarm and is silently
 * ineffective here, so a job whose heartbeat has gone quiet is the only
 * evidence — and it looks identical to a job that is working hard unless
 * somebody checks the clock.
 */
class RenderProgress
{
    /**
     * @return array<string, mixed>
     */
    public static function for(Story $story): array
    {
        $counts = self::stageCounts($story->id);
        $stages = self::stages($counts, self::spendByStage($story->id), $story->scenes()->count());

        return [
            'story' => $story,
            'stages' => $stages,
            'overall' => self::overall($stages),
            'batches' => self::batches($story->id),
            'failures' => self::failures($story->id),
            'stale' => self::staleJobs($story->id),
            'scene_grid' => self::sceneGrid($story->id),
            'generated_at' => Carbon::now(),
        ];
    }

    /**
     * Stories with any render activity, newest first — the index page.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public static function index(int $limit = 25): Collection
    {
        $stories = Story::query()
            ->withCount('scenes')
            ->orderByDesc('updated_at')
            ->limit($limit)
            ->get();

        if ($stories->isEmpty()) {
            return collect();
        }

        // One aggregate query for every story on the page, rather than one per
        // row. The index is the page an operator leaves open.
        $counts = RenderJob::query()
            ->selectRaw('story_id')
            ->selectRaw('COUNT(*) as total')
            ->selectRaw("SUM(status = 'succeeded') as succeeded")
            ->selectRaw("SUM(status = 'failed') as failed")
            ->selectRaw("SUM(status = 'running') as running")
            ->selectRaw('MAX(updated_at) as last_activity')
            ->whereIn('story_id', $stories->modelKeys())
            ->groupBy('story_id')
            ->get()
            ->keyBy('story_id');

        $stale = RenderJob::query()
            ->stale()
            ->whereIn('story_id', $stories->modelKeys())
            ->selectRaw('story_id, COUNT(*) as stale')
            ->groupBy('story_id')
            ->pluck('stale', 'story_id');

        return $stories->map(function (Story $story) use ($counts, $stale): array {
            $row = $counts->get($story->id);
            $total = (int) ($row->total ?? 0);
            $succeeded = (int) ($row->succeeded ?? 0);

            return [
                'story' => $story,
                'jobs' => $total,
                'succeeded' => $succeeded,
                'failed' => (int) ($row->failed ?? 0),
                'running' => (int) ($row->running ?? 0),
                'stale' => (int) ($stale[$story->id] ?? 0),
                'percent' => $total === 0 ? 0 : (int) floor($succeeded / $total * 100),
                'last_activity' => isset($row->last_activity) ? Carbon::parse($row->last_activity) : null,
            ];
        });
    }

    /**
     * One row per stage, with a count per status.
     *
     * Conditional aggregation rather than fetching 200 rows and counting them
     * in PHP: the page must cost the same at 12 scenes and at 250.
     */
    private static function stageCounts(int $storyId): Collection
    {
        return RenderJob::query()
            ->selectRaw('stage')
            ->selectRaw('COUNT(*) as total')
            ->selectRaw("SUM(status = 'succeeded') as succeeded")
            ->selectRaw("SUM(status = 'failed') as failed")
            ->selectRaw("SUM(status = 'running') as running")
            ->selectRaw("SUM(status = 'queued') as queued")
            ->selectRaw("SUM(status = 'cancelled') as cancelled")
            ->selectRaw('MIN(started_at) as started_at')
            ->selectRaw('MAX(finished_at) as finished_at')
            ->selectRaw('MAX(updated_at) as last_activity')
            ->selectRaw('MAX(batch_id) as batch_id')
            ->where('story_id', $storyId)
            ->groupBy('stage')
            ->get()
            ->keyBy(fn ($row): string => $row->stage->value);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    /**
     * What each stage actually cost, from the ledger rather than from the enum.
     *
     * `RenderStage::isPaid()` says a stage CAN spend money. It cannot say
     * whether this run did, and the page was tagging stages "paid" that had
     * been served entirely by stand-ins at $0.00. That is the same mislabel
     * that let $8.12 of phantom spend look like a bill — a static property of
     * the stage standing in for a fact about the run.
     *
     * @return array<string, array{real: float, simulated: float, calls: int, simulated_calls: int}>
     */
    private static function spendByStage(int $storyId): array
    {
        $operations = [
            'generate_image' => RenderStage::Images->value,
            'synthesize_speech' => RenderStage::SceneNarration->value,
            'transcribe' => RenderStage::SceneTimings->value,
        ];

        $rows = DB::table('cost_entries')
            ->where('story_id', $storyId)
            ->whereIn('operation', array_keys($operations))
            ->selectRaw('operation, simulated, COUNT(*) as calls, SUM(usd_cost) as usd')
            ->groupBy('operation', 'simulated')
            ->get();

        $spend = [];

        foreach ($rows as $row) {
            $stage = $operations[$row->operation];
            $spend[$stage] ??= ['real' => 0.0, 'simulated' => 0.0, 'calls' => 0, 'simulated_calls' => 0];

            $spend[$stage]['calls'] += (int) $row->calls;

            if ((bool) $row->simulated) {
                $spend[$stage]['simulated'] += (float) $row->usd;
                $spend[$stage]['simulated_calls'] += (int) $row->calls;
            } else {
                $spend[$stage]['real'] += (float) $row->usd;
            }
        }

        return $spend;
    }

    /**
     * @param  array<string, array<string, mixed>>  $spend
     * @return array<int, array<string, mixed>>
     */
    private static function stages(Collection $counts, array $spend = [], int $sceneCount = 0): array
    {
        $stages = [];

        // Pipeline order, not database order. An operator reads this top to
        // bottom expecting it to match the order things happen in.
        foreach (RenderStage::cases() as $stage) {
            $row = $counts->get($stage->value);

            if ($row === null) {
                continue;
            }

            $total = (int) $row->total;
            $succeeded = (int) $row->succeeded;
            $failed = (int) $row->failed;
            $running = (int) $row->running;
            $lastActivity = Carbon::parse($row->last_activity);

            $stages[] = [
                'stage' => $stage,
                'total' => $total,
                'succeeded' => $succeeded,
                'failed' => $failed,
                'running' => $running,
                'queued' => (int) $row->queued,
                'cancelled' => (int) $row->cancelled,
                'percent' => $total === 0 ? 0 : (int) floor(($succeeded + $failed) / $total * 100),
                'started_at' => $row->started_at ? Carbon::parse($row->started_at) : null,
                'finished_at' => $row->finished_at ? Carbon::parse($row->finished_at) : null,
                'last_activity' => $lastActivity,
                'batch_id' => $row->batch_id,
                // A running stage that has not touched its row recently is the
                // shape a hung FFmpeg takes. Nothing else will report it.
                'stale' => $running > 0 && $lastActivity->lt(Carbon::now()->subMinutes(RenderJob::staleAfterMinutes())),
                'quiet_for' => $running > 0 ? $lastActivity->diffInSeconds(Carbon::now()) : null,
                // What this stage actually cost on this story, and whether a
                // stand-in produced it. Null for the free render stages.
                'usd' => $spend[$stage->value]['real'] ?? null,
                'simulated_calls' => $spend[$stage->value]['simulated_calls'] ?? 0,
                'billed_calls' => isset($spend[$stage->value])
                    ? $spend[$stage->value]['calls'] - $spend[$stage->value]['simulated_calls']
                    : 0,
                // Fan-out stages run one job per scene, so the story's scene
                // count is the honest denominator. A stage total that is lower
                // means some scenes never ran a job for it — skipped by
                // idempotency, or lost with truncated job history — and saying
                // "185/185" for a 186-scene story hides that rather than
                // showing it.
                'scene_count' => $stage->fansOut() ? $sceneCount : null,
            ];
        }

        return $stages;
    }

    /**
     * @param  array<int, array<string, mixed>>  $stages
     * @return array<string, mixed>
     */
    private static function overall(array $stages): array
    {
        $total = array_sum(array_column($stages, 'total'));
        $succeeded = array_sum(array_column($stages, 'succeeded'));
        $failed = array_sum(array_column($stages, 'failed'));
        $running = array_sum(array_column($stages, 'running'));

        return [
            'total' => $total,
            'succeeded' => $succeeded,
            'failed' => $failed,
            'running' => $running,
            'queued' => array_sum(array_column($stages, 'queued')),
            'percent' => $total === 0 ? 0 : (int) floor($succeeded / $total * 100),
            'active' => $running > 0 || array_sum(array_column($stages, 'queued')) > 0,
        ];
    }

    /**
     * The `job_batches` half of the picture.
     *
     * Read directly rather than through a model: this table belongs to the
     * framework, and the page is a reader of it, not an owner.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function batches(int $storyId): array
    {
        $ids = RenderJob::query()
            ->where('story_id', $storyId)
            ->whereNotNull('batch_id')
            ->distinct()
            ->pluck('batch_id');

        if ($ids->isEmpty()) {
            return [];
        }

        return DB::connection(config('queue.batching.database'))
            ->table(config('queue.batching.table', 'job_batches'))
            ->whereIn('id', $ids)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($batch): array => [
                'id' => $batch->id,
                'name' => $batch->name,
                'total' => (int) $batch->total_jobs,
                'pending' => (int) $batch->pending_jobs,
                'failed' => (int) $batch->failed_jobs,
                // Jobs that have actually RUN, which is not total-minus-pending.
                //
                // Laravel's incrementFailedJobs() does not decrement
                // pending_jobs — a failed job stays counted as pending so it can
                // be retried into the batch. So a batch of 371 with 44 failures
                // reads as 327 pending-adjusted forever, understating what ran
                // and, worse, never setting finished_at. Adding the failures
                // back is what makes this the number of jobs that happened.
                'processed' => (int) $batch->total_jobs - (int) $batch->pending_jobs + (int) $batch->failed_jobs,
                'percent' => $batch->total_jobs === 0
                    ? 0
                    : (int) floor((((int) $batch->total_jobs - (int) $batch->pending_jobs + (int) $batch->failed_jobs) / (int) $batch->total_jobs) * 100),
                // Whether anything is genuinely still queued, as opposed to a
                // batch record that cannot close.
                //
                // `finished_at === null` is not "in flight". An allowFailures
                // batch whose failures were never retried into it stays open
                // permanently by design, and the page was reporting that as
                // work in progress long after every worker had exited — which
                // is exactly the sort of claim that gets an operator to wait
                // for something that is never going to happen.
                'in_flight' => ((int) $batch->pending_jobs - (int) $batch->failed_jobs) > 0 && $batch->cancelled_at === null,
                'abandoned' => $batch->finished_at === null
                    && $batch->cancelled_at === null
                    && ((int) $batch->pending_jobs - (int) $batch->failed_jobs) === 0
                    && (int) $batch->failed_jobs > 0,
                'cancelled_at' => $batch->cancelled_at ? Carbon::createFromTimestamp($batch->cancelled_at) : null,
                'finished_at' => $batch->finished_at ? Carbon::createFromTimestamp($batch->finished_at) : null,
                'created_at' => Carbon::createFromTimestamp($batch->created_at),
            ])
            ->all();
    }

    /**
     * Which scenes failed, and why — the question a count cannot answer.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function failures(int $storyId, int $limit = 50): array
    {
        $failed = RenderJob::query()
            ->with('scene:id,sequence')
            ->where('story_id', $storyId)
            ->where('status', RenderJobStatus::Failed)
            ->orderBy('scene_id')
            ->limit($limit)
            ->get();

        return $failed->map(fn (RenderJob $job): array => [
            'stage' => $job->stage,
            'scene' => $job->scene?->sequence,
            // One line on the page, the rest on the row. A 200-scene batch that
            // failed the same way 40 times should not need 40 screens.
            'error' => str($job->error ?? 'no message recorded')->limit(300)->toString(),
            'failed_at' => $job->finished_at ?? $job->updated_at,
        ])->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function staleJobs(int $storyId): array
    {
        return RenderJob::query()
            ->with('scene:id,sequence')
            ->where('story_id', $storyId)
            ->stale()
            ->get()
            ->map(fn (RenderJob $job): array => [
                'stage' => $job->stage,
                'scene' => $job->scene?->sequence,
                'quiet_for' => $job->updated_at?->diffForHumans(),
                'started_at' => $job->started_at,
            ])->all();
    }

    /**
     * Per-scene status for the fan-out stages, keyed by scene sequence.
     *
     * Rendered as a grid rather than a list: 200 rows is a scroll, 200 squares
     * is a glance, and the thing an operator is looking for — a red one — is
     * findable in a grid without reading anything.
     *
     * @return array<string, array<int, array{sequence: int, status: string}>>
     */
    private static function sceneGrid(int $storyId): array
    {
        $rows = RenderJob::query()
            ->join('scenes', 'scenes.id', '=', 'render_jobs.scene_id')
            ->where('render_jobs.story_id', $storyId)
            ->whereNotNull('render_jobs.scene_id')
            ->orderBy('scenes.sequence')
            ->get(['render_jobs.stage', 'render_jobs.status', 'scenes.sequence']);

        $grid = [];

        foreach ($rows as $row) {
            $grid[$row->stage->value][] = [
                'sequence' => (int) $row->sequence,
                'status' => $row->status->value,
            ];
        }

        return $grid;
    }
}
