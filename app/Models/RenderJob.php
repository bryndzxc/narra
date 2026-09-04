<?php

namespace App\Models;

use App\Enums\RenderJobStatus;
use App\Enums\RenderStage;
use App\Support\WorkerRegistry;
use Database\Factories\RenderJobFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Throwable;

/**
 * One run of one pipeline stage.
 *
 * @property RenderStage $stage
 * @property RenderJobStatus $status
 */
class RenderJob extends Model
{
    /** @use HasFactory<RenderJobFactory> */
    use HasFactory;

    /**
     * How long a running job may go without touching updated_at before the
     * operator UI should treat it as hung.
     *
     * Generous, because a scene clip encode is minutes and a full mux is
     * tens of minutes — but finite, because nothing else on this platform will
     * ever tell us. `queue:work --timeout` is enforced with a pcntl alarm, and
     * pcntl does not exist in Windows PHP, so the flag is silently ineffective:
     * a hung FFmpeg call holds a worker forever with no error and no recovery.
     */
    public static function staleAfterMinutes(): int
    {
        return (int) config('render.stale_after_minutes', 15);
    }

    protected $fillable = [
        'story_id',
        'scene_id',
        'stage',
        'status',
        'batch_id',
        'started_at',
        'finished_at',
        'output_path',
        'log',
        'error',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'stage' => RenderStage::class,
            'status' => RenderJobStatus::class,
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Story, $this> */
    public function story(): BelongsTo
    {
        return $this->belongsTo(Story::class);
    }

    /** @return BelongsTo<Scene, $this> */
    public function scene(): BelongsTo
    {
        return $this->belongsTo(Scene::class);
    }

    /**
     * Open (or reopen) the row for one stage of one story.
     *
     * One row per story/stage/scene, updated in place rather than appended to.
     * Re-running a stage is an explicit operator action and it replaces the
     * previous result; an attempt log would turn a 200-scene batch into
     * hundreds of rows the progress page then has to collapse anyway.
     */
    public static function open(int $storyId, RenderStage $stage, ?int $sceneId = null, ?string $batchId = null): self
    {
        $job = self::query()->updateOrCreate(
            ['story_id' => $storyId, 'stage' => $stage, 'scene_id' => $sceneId],
            [
                'status' => RenderJobStatus::Running,
                'batch_id' => $batchId,
                'started_at' => Carbon::now(),
                'finished_at' => null,
                'error' => null,
                'log' => null,
            ]
        );

        return $job;
    }

    /**
     * Run a SYNCHRONOUS stage inside a row, so it is visible while it runs.
     *
     * The queued stages get their bookkeeping from RenderStageJob. The three
     * text stages do not go through a queue at all — `story:write` and
     * `story:scenes` call their Actions in the console process — and for a long
     * time that meant they wrote no row, which made them invisible on the only
     * page this platform has instead of a Horizon dashboard. An outline that
     * failed, or act scripts that died on act 4 of 6 after billing three Opus
     * calls, left `/renders/{slug}` looking exactly like a story nobody had
     * started. `RenderStage::Outline`, `ActScripts` and `DraftScenes` were all
     * enumerated for a page that could never show them.
     *
     * Synchronous is the reason this is needed, not a reason to skip it: an
     * operator watching a console is one terminal, and the progress page is
     * where anyone else looks — including the same operator tomorrow, asking
     * why act 5 has no script.
     *
     * The closure is handed its own row so it can call `note()` as it goes; a
     * seven-minute act call with nothing between start and finish is
     * indistinguishable from a hung one.
     *
     * @template TReturn
     *
     * @param  \Closure(self): TReturn  $work
     * @return TReturn
     */
    public static function record(int $storyId, RenderStage $stage, \Closure $work): mixed
    {
        $job = self::open($storyId, $stage);

        try {
            $result = $work($job);
        } catch (Throwable $e) {
            // Written before the rethrow, because the row is the thing the
            // operator reads and the exception is going to the console of
            // whoever happens to be watching.
            $job->fail($e);

            throw $e;
        }

        $job->succeed(null, $job->log);

        return $result;
    }

    /**
     * Append a line to the running log, and beat the heart while doing it.
     *
     * Both halves matter and they are the same write. The line is what the page
     * shows ("act 4 of 6, 1,340 words"); touching `updated_at` is what keeps
     * `isStale()` from reporting a working stage as hung. A progress note that
     * did not refresh the heartbeat would make a healthy long stage look dead
     * every time it went quiet between acts.
     */
    public function note(string $line): void
    {
        $this->forceFill([
            'log' => trim(($this->log === null ? '' : $this->log."\n").$line),
        ])->save();
    }

    /**
     * Put the stages a new dispatch is about to run back to `Queued`.
     *
     * **The false success this removes.** `open()` is an updateOrCreate keyed on
     * (story, stage, scene), so there is exactly one row per stage and it
     * survives every dispatch. A stage that succeeded on a previous render and
     * does NOT run in this one keeps its old `succeeded` row — and the progress
     * page, which counts rows by stage with no notion of which dispatch they
     * belong to, reports it as complete.
     *
     * That is not hypothetical. A render whose concat failed showed Subtitles
     * 1/1 and Mux 1/1 "done", with a 506-second mux duration, for stages that
     * could not possibly have run: they are chained off concat, and concat had
     * just failed. The rows were 21 hours old, from a silent fixture render, and
     * the operator page presented them as the current run.
     *
     * Queued rather than deleted: the operator should see that these stages are
     * expected and pending, not that they have vanished. And a row that says
     * `queued` cannot be mistaken for one that says `succeeded`, which is the
     * only property that actually matters here.
     *
     * @param  array<int, RenderStage>  $stages
     * @return int Rows reset.
     */
    public static function queueStages(int $storyId, array $stages): int
    {
        if ($stages === []) {
            return 0;
        }

        return self::query()
            ->where('story_id', $storyId)
            ->whereIn('stage', array_map(fn (RenderStage $s): string => $s->value, $stages))
            ->update([
                'status' => RenderJobStatus::Queued,
                'started_at' => null,
                'finished_at' => null,
                'output_path' => null,
                'error' => null,
                'log' => null,
            ]);
    }

    public function succeed(?string $outputPath = null, ?string $log = null): void
    {
        $this->forceFill([
            'status' => RenderJobStatus::Succeeded,
            'finished_at' => Carbon::now(),
            'output_path' => $outputPath,
            'log' => $log,
        ])->save();
    }

    public function fail(Throwable $e): void
    {
        // Progress notes are KEPT, and the trace goes below them.
        //
        // This used to overwrite `log` outright, which is fine for a stage whose
        // whole story is one FFmpeg call and wrong for one that reports as it
        // goes. Act scripts is six sequential calls: "acts 1 and 2 written, act
        // 3 in flight" is the most useful sentence on the row, and a stack trace
        // that replaced it would answer where the code broke while destroying
        // the answer to how far the story got.
        $progress = trim((string) $this->log);

        $log = $progress === ''
            ? $e->getTraceAsString()
            : $progress."\n\n--- trace ---\n".$e->getTraceAsString();

        $this->forceFill([
            'status' => RenderJobStatus::Failed,
            'finished_at' => Carbon::now(),
            // Message first, because that is what the progress page shows next
            // to the scene number. The trace is below it for when that is not
            // enough, and both are on the row rather than only in a log file
            // the operator would have to go and find.
            'error' => Str::limit($e->getMessage(), 60000),
            'log' => Str::limit($log, 60000),
        ])->save();
    }

    /**
     * The heartbeat. A long-running job calls this periodically; the operator
     * page reads the resulting updated_at to tell working from hung.
     */
    /**
     * One beat, two clocks.
     *
     * `touch()` is the row's own staleness clock — the thing that tells a hung
     * FFmpeg from a slow one, and the only thing that can, because
     * `queue:work --timeout` is enforced with a pcntl alarm and pcntl does not
     * exist in Windows PHP.
     *
     * The registry needs the same beat for a different reason. It is refreshed
     * on `Looping`, which does not fire while a job is running, and on
     * `JobProcessing`, which fires once before it — so a job longer than the
     * registry's 300-second TTL used to age its own worker out. A 40-minute mux
     * and a 270-scene asset run both clear that easily, and the result was the
     * worker-health panel reporting ABSENT for a worker that was mid-encode:
     * the reading an operator takes before authorising a spend, saying nothing
     * was listening while everything was fine.
     *
     * Ticking both from here rather than adding a second timer is deliberate.
     * Two clocks for one fact is two things to keep in step, and this codebase's
     * bugs are mostly two copies of something that stopped agreeing.
     */
    public function heartbeat(): void
    {
        $this->touch();

        WorkerRegistry::touchAnnounced();
    }

    public function isStale(): bool
    {
        return $this->status === RenderJobStatus::Running
            && $this->updated_at !== null
            && $this->updated_at->lt(Carbon::now()->subMinutes(self::staleAfterMinutes()));
    }

    /**
     * Running jobs whose heartbeat has gone quiet — the only way a hung worker
     * becomes visible on this platform.
     *
     * @param  Builder<RenderJob>  $query
     */
    public function scopeStale(Builder $query): void
    {
        $query->where('status', RenderJobStatus::Running)
            ->where('updated_at', '<', Carbon::now()->subMinutes(self::staleAfterMinutes()));
    }

    public function durationSeconds(): ?float
    {
        if ($this->started_at === null || $this->finished_at === null) {
            return null;
        }

        return (float) $this->finished_at->diffInMilliseconds($this->started_at, absolute: true) / 1000;
    }
}
