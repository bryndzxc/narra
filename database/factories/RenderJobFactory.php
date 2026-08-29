<?php

namespace Database\Factories;

use App\Enums\RenderJobStatus;
use App\Enums\RenderStage;
use App\Models\RenderJob;
use App\Models\Story;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<RenderJob>
 */
class RenderJobFactory extends Factory
{
    protected $model = RenderJob::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'story_id' => Story::factory(),
            'scene_id' => null,
            'stage' => RenderStage::SceneClips,
            'status' => RenderJobStatus::Queued,
            'batch_id' => null,
            'started_at' => null,
            'finished_at' => null,
            'output_path' => null,
            'log' => null,
            'error' => null,
        ];
    }

    public function stage(RenderStage $stage): static
    {
        return $this->state(fn (): array => ['stage' => $stage]);
    }

    public function running(): static
    {
        return $this->state(fn (): array => [
            'status' => RenderJobStatus::Running,
            'started_at' => Carbon::now(),
        ]);
    }

    /**
     * Running, but silent for longer than a heartbeat should allow.
     *
     * This is what a hung FFmpeg looks like from the outside, and on Windows it
     * is the only thing that looks like anything: `queue:work --timeout` needs
     * pcntl, which does not exist, so nothing else will ever report it.
     */
    public function stale(): static
    {
        $silentSince = Carbon::now()->subMinutes(RenderJob::staleAfterMinutes() + 5);

        return $this->running()->state(fn (): array => [
            'started_at' => $silentSince,
            'created_at' => $silentSince,
            'updated_at' => $silentSince,
        ]);
    }

    public function succeeded(): static
    {
        return $this->state(fn (): array => [
            'status' => RenderJobStatus::Succeeded,
            'started_at' => Carbon::now()->subMinutes(10),
            'finished_at' => Carbon::now(),
        ]);
    }

    public function failed(string $error = 'FFmpeg exited with code 1.'): static
    {
        return $this->state(fn (): array => [
            'status' => RenderJobStatus::Failed,
            'started_at' => Carbon::now()->subMinutes(2),
            'finished_at' => Carbon::now(),
            'error' => $error,
        ]);
    }

    /** Part of a fan-out batch, which is how the progress page finds it. */
    public function inBatch(string $batchId): static
    {
        return $this->state(fn (): array => ['batch_id' => $batchId]);
    }
}
