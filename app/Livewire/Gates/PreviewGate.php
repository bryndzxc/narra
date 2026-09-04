<?php

namespace App\Livewire\Gates;

use App\Actions\CancelRenderBatch;
use App\Actions\DispatchRenderPipeline;
use App\Enums\Gate;
use App\Enums\OperatorAction;
use App\Enums\RenderStage;
use App\Enums\StoryStatus;
use App\Exceptions\DispatchRefusedException;
use App\Exceptions\GateViolationException;
use App\Models\RenderJob;
use App\Models\Story;
use App\Support\RenderWorkspace;
use App\Support\WorkerHealth;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Throwable;

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

    public ?string $problem = null;

    /**
     * Which press the operator is on, for the two moves that cost something.
     *
     * A render is tens of minutes of CPU and a cancel throws away work already
     * done. Neither is a single click.
     */
    public ?string $confirming = null;

    public function mount(Story $story): void
    {
        $this->story = $story;
    }

    // -- Dispatching and cancelling the render -------------------------------

    /**
     * The queue this gate's buttons dispatch to.
     *
     * Beside the button, because this page is the one that used to print the
     * dispatch command as its own next step and send the operator to a
     * terminal. Replacing the terminal removes the only place they could see
     * whether a worker was alive.
     *
     * @return array{queue: string, role: string, state: string, live: int, stale: int, oldest_boot: ?string, headline: string}
     */
    #[Computed]
    public function workers(): array
    {
        return WorkerHealth::forQueue((string) config('render.queues.render'));
    }

    #[Computed]
    public function canDispatchRender(): bool
    {
        return OperatorAction::DispatchRender->permittedAt($this->story->status);
    }

    #[Computed]
    public function dispatchRefusal(): ?string
    {
        return OperatorAction::DispatchRender->refusalReason($this->story->status);
    }

    #[Computed]
    public function canCancelRender(): bool
    {
        return OperatorAction::CancelRender->permittedAt($this->story->status);
    }

    public function askTo(string $what): void
    {
        $this->problem = null;
        $this->confirming = $what;
    }

    public function cancelConfirmation(): void
    {
        $this->confirming = null;
    }

    /**
     * Queue the render. The press that used to be a command.
     *
     * The dispatch command was the only way to start a render for the whole of
     * Phase 1 and 2, and this page printed that command as its own next step —
     * a gate page whose next action was a terminal. The Action is shared with
     * the command, so the preflight, the refusal and the worker check are the
     * same ones rather than a second copy of them.
     */
    public function dispatchRender(): void
    {
        abort_unless($this->canDispatchRender(), 403, 'This story cannot be rendered from its status.');

        $this->confirming = null;
        $this->problem = null;

        try {
            $result = app(DispatchRenderPipeline::class)->handle($this->story);
        } catch (DispatchRefusedException|GateViolationException $e) {
            // Shown, not thrown. A stale render worker is the refusal that
            // matters here and the operator fixes it in thirty seconds.
            $this->problem = $e->getMessage();

            return;
        } catch (Throwable $e) {
            $this->problem = $e->getMessage();

            return;
        }

        $this->story->refresh();
        $this->resetComputed();

        $warnings = array_column(
            array_filter($result['notes'] ?? [], fn (array $n): bool => $n['level'] !== 'ok'),
            'message',
        );

        $this->notice = sprintf(
            '%d scene clip(s) queued on the "%s" queue (batch %s). The concat, subtitle and mux stages '
            .'are chained off the batch completion callback — nothing polls. This page is safe to leave; '
            .'progress is on the render page.',
            $result['scenes'],
            config('render.queues.render'),
            $result['batch_id'],
        );

        // An absent worker is not a refusal — the jobs queue and wait — but it
        // must not read the same as a healthy dispatch on the page the operator
        // watches instead of the pipeline.
        if ($warnings !== []) {
            $this->problem = implode(' ', $warnings);
        }
    }

    /**
     * Call off an in-flight batch.
     *
     * This app's own refusal text tells an operator at `rendering` to cancel
     * with an Artisan command — so the one documented way out of a stuck render
     * was a terminal, recommended by a page that exists so there would not have
     * to be one.
     */
    public function cancelRender(): void
    {
        abort_unless($this->canCancelRender(), 403, 'Nothing is in flight for this story.');

        $this->confirming = null;
        $this->problem = null;

        try {
            $result = app(CancelRenderBatch::class)->handle($this->story);
        } catch (GateViolationException $e) {
            $this->problem = $e->getMessage();

            return;
        }

        $this->story->refresh();
        $this->resetComputed();

        $this->notice = sprintf(
            'Cancelled %d batch(es) and marked %d job row(s). A job already inside FFmpeg will finish; '
            .'nothing new starts.%s',
            $result['batches'],
            $result['rows'],
            $result['landed'] === null
                ? ''
                : sprintf(
                    $result['reconciled']
                        ? ' Story reconciled to %s — the scenes that did not finish are flagged for retry.'
                        : ' Story moved to %s — a re-run starts from there.',
                    $result['landed']->value,
                ),
        );
    }

    /**
     * Every #[Computed] this page caches, dropped in one place.
     *
     * The Gate 2 page learned this the hard way: three call sites unset their
     * own lists by hand and the lists had already drifted apart.
     */
    private function resetComputed(): void
    {
        unset(
            $this->canApprove,
            $this->videoExists,
            $this->renderFacts,
            $this->chapters,
            $this->workers,
            $this->canDispatchRender,
            $this->dispatchRefusal,
            $this->canCancelRender,
        );
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
     * Send it back, and queue the re-render in the same press.
     *
     * This used to transition the story to `rendering` and then tell the
     * operator to go and run the dispatch command — which left the story at a
     * status meaning "a clip batch is in flight" with no batch in flight, until
     * somebody opened a terminal. A status describing work nobody started is
     * the same defect as a form with no producer.
     *
     * It also checked `status === Rendered` directly while
     * OperatorAction::DispatchRender->callers() named this method as one of its
     * consumers — a claimed caller that consulted nothing, which is the exact
     * drift OperatorAction exists to prevent. It consults it now, and the
     * dispatch performs the transition rather than this method doing it blind.
     */
    public function reject(): void
    {
        abort_unless($this->story->status === StoryStatus::Rendered, 403, 'Nothing to send back.');
        abort_unless(
            $this->canDispatchRender(),
            403,
            (string) OperatorAction::DispatchRender->refusal($this->story->status),
        );

        $this->dispatchRender();

        if ($this->problem === null) {
            $this->notice = 'Sent back for a re-render. '.(string) $this->notice;
        }
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
