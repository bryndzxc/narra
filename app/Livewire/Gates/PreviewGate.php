<?php

namespace App\Livewire\Gates;

use App\Enums\Gate;
use App\Enums\RenderStage;
use App\Enums\StoryStatus;
use App\Models\RenderJob;
use App\Models\Story;
use App\Support\RenderWorkspace;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Gate 3 — the operator watches the render.
 *
 * There is no automated check that stands in for this. The pipeline can prove
 * the frame count is exact and the subtitles line up to the centisecond, and
 * still hand back 35 minutes where a character changes face at scene 90 or the
 * narration reads as somebody else's country. That is what a human is for.
 *
 * Approving moves to metadata. Rejecting sends it back to render — explicitly,
 * because a re-render is tens of minutes of CPU.
 */
class PreviewGate extends Component
{
    public Story $story;

    public ?string $notice = null;

    public function mount(Story $story): void
    {
        $this->story = $story;
    }

    #[Computed]
    public function canApprove(): bool
    {
        return $this->story->status === StoryStatus::Rendered && $this->videoExists();
    }

    #[Computed]
    public function videoExists(): bool
    {
        return RenderWorkspace::for($this->story)->exists('final.mp4');
    }

    /**
     * What the render actually produced, from the rows the jobs wrote.
     *
     * Shown next to the player because "does it play" is not the only question
     * at this gate — the operator is also checking that the numbers match what
     * they expected to sit through.
     */
    #[Computed]
    public function renderFacts(): array
    {
        $workspace = RenderWorkspace::for($this->story);
        $path = $workspace->path('final.mp4');

        $mux = RenderJob::query()
            ->where('story_id', $this->story->id)
            ->where('stage', RenderStage::Mux)
            ->first();

        $totalMs = (int) $this->story->acts()->sum('duration_ms');

        return [
            'exists' => is_readable($path),
            'bytes' => is_readable($path) ? filesize($path) : 0,
            'duration_ms' => $totalMs,
            'duration_human' => $this->human($totalMs),
            'scenes' => $this->story->scenes()->count(),
            'acts' => $this->story->acts()->count(),
            'mux_log' => $mux?->log,
            'rendered_at' => $mux?->finished_at,
            // The format's own target. A 12-scene fixture is nowhere near it,
            // and saying so beats a green tick that means nothing.
            'in_target_window' => $totalMs >= $this->story->target_duration_min * 60_000
                && $totalMs <= $this->story->target_duration_max * 60_000,
        ];
    }

    /**
     * @return array<int, array{timestamp: string, title: string, start_ms: int}>
     */
    #[Computed]
    public function chapters(): array
    {
        return $this->story->acts
            ->filter(fn ($act): bool => $act->start_ms !== null)
            ->map(fn ($act): array => [
                'timestamp' => $act->chapterTimestamp(),
                'title' => $act->title,
                'start_ms' => (int) $act->start_ms,
            ])->values()->all();
    }

    public function approve(): void
    {
        abort_unless($this->canApprove(), 403, 'There is no finished render to approve.');

        $this->story->approveGate(Gate::Preview);
        $this->story->refresh();

        $this->notice = 'Gate 3 approved. The publish sheet is next.';
    }

    /**
     * Send it back. rendered -> rendering is a legal move and an explicit one;
     * the operator is choosing to spend the encode again.
     */
    public function reject(): void
    {
        abort_unless($this->story->status === StoryStatus::Rendered, 403, 'Nothing to send back.');

        $this->story->transitionTo(StoryStatus::Rendering);
        $this->story->refresh();

        $this->notice = 'Sent back for a re-render. Dispatch it again with `php artisan render:dispatch '
            .$this->story->slug.'` — nothing re-renders on its own.';
    }

    public function render(): View
    {
        return view('livewire.gates.preview-gate');
    }

    private function human(int $ms): string
    {
        $seconds = intdiv($ms, 1000);

        return sprintf('%d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60);
    }
}
