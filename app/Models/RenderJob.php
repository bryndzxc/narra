<?php

namespace App\Models;

use App\Enums\RenderJobStatus;
use App\Enums\RenderStage;
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
        $this->forceFill([
            'status' => RenderJobStatus::Failed,
            'finished_at' => Carbon::now(),
            // Message first, because that is what the progress page shows next
            // to the scene number. The trace is below it for when that is not
            // enough, and both are on the row rather than only in a log file
            // the operator would have to go and find.
            'error' => Str::limit($e->getMessage(), 60000),
            'log' => Str::limit($e->getTraceAsString(), 60000),
        ])->save();
    }

    /**
     * The heartbeat. A long-running job calls this periodically; the operator
     * page reads the resulting updated_at to tell working from hung.
     */
    public function heartbeat(): void
    {
        $this->touch();
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
