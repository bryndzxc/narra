<?php

namespace App\Livewire\Gates;

use App\Actions\CancelRenderBatch;
use App\Actions\DispatchRenderPipeline;
use App\Enums\Gate;
use App\Enums\OperatorAction;
use App\Enums\RenderJobStatus;
use App\Enums\RenderStage;
use App\Enums\StoryStatus;
use App\Exceptions\DispatchRefusedException;
use App\Exceptions\GateViolationException;
use App\Models\RenderJob;
use App\Models\Story;
use App\Support\GateVoice;
use App\Support\RenderWorkspace;
use App\Support\WorkerHealth;
use Illuminate\Contracts\View\View;
use Illuminate\Support\CarbonInterface;
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
            $this->voice,
            $this->phase,
            $this->artifact,
            $this->muxState,
            $this->windowBar,
            $this->chapterMarks,
            $this->clipProgress,
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

    /**
     * How this page is allowed to talk about its own decisions.
     *
     * Gate 3 is deliberately NOT in GateVoiceTest's agreement table: this
     * component's `canApprove()` is the status AND a file on disk, while the
     * voice models the STATUS only. That is not drift, it is the split the
     * missing-file state exists for — a story at `rendered` with no artifact is
     * still a story whose decision is Gate 3, and the page has to say so rather
     * than fall silent.
     */
    #[Computed]
    public function voice(): GateVoice
    {
        return GateVoice::for(Gate::Preview, $this->story->status);
    }

    /**
     * Where this story is relative to the gate, in one word.
     *
     * The mock draws two states, `ready` and `running`. Gate 2's mock draws
     * three and the third is `locked` — so Gate 3 was drawn with no settled
     * state, which is where two of this project's three rendered stories
     * actually live. Same defect as Gate 1's, one layer earlier: in the design
     * rather than in the page.
     *
     *   ahead    the render has not happened and is not happening
     *   running  a clip batch is in flight
     *   waiting  the decision is this gate's, now
     *   behind   the gate has been crossed
     */
    #[Computed]
    public function phase(): string
    {
        return match (true) {
            $this->story->status === StoryStatus::Rendering => 'running',
            $this->story->status === StoryStatus::Rendered => 'waiting',
            $this->story->status->rank() > StoryStatus::Rendered->rank() => 'behind',
            default => 'ahead',
        };
    }

    /**
     * The artifact, which is a different question from the status.
     *
     * THE THIRD STATE THE MOCK FOLDS AWAY. `rendered` with no file is not "still
     * encoding": the mux row says the stage finished and the file is not there.
     * That is the false-success shape this project keeps a table of — rows
     * reporting success over an absent artifact — and sending the operator to
     * watch progress that completed hours ago is the page agreeing with it.
     */
    #[Computed]
    public function artifact(): string
    {
        if ($this->videoExists()) {
            return 'ready';
        }

        // A mux row that finished, or a status past the render, both mean a
        // file was supposed to exist. Anything earlier simply has not got there.
        $muxFinished = $this->muxJob()?->status === RenderJobStatus::Succeeded;

        return $muxFinished || $this->story->status->rank() >= StoryStatus::Rendered->rank()
            ? 'missing'
            : 'none';
    }

    /**
     * The last mux, as the row actually records it.
     *
     * The mock paints `EXIT 0` beside the log as a constant. A failed mux is a
     * real state, and a success badge painted over one is this codebase's whole
     * defect class inside a single span.
     *
     * @return array{status: ?RenderJobStatus, label: string, tone: string, log: ?string, error: ?string, finished_at: ?CarbonInterface}
     */
    #[Computed]
    public function muxState(): array
    {
        $job = $this->muxJob();

        $tone = match ($job?->status) {
            RenderJobStatus::Succeeded => 'ok',
            RenderJobStatus::Failed => 'fail',
            RenderJobStatus::Running, RenderJobStatus::Queued => 'run',
            RenderJobStatus::Cancelled => 'warn',
            default => '',
        };

        return [
            'status' => $job?->status,
            'label' => $job === null ? 'never run' : $job->status->value,
            'tone' => $tone,
            'log' => $job?->log,
            'error' => $job?->error,
            'finished_at' => $job?->finished_at,
        ];
    }

    /**
     * The runtime against the story's own target window.
     *
     * THE AXIS IS DERIVED, NOT DRAWN. The mock hardcodes 30 min at 20% and 40
     * min at 67% — a scale of roughly 25.7 to 47 minutes, which is fine for the
     * story it was drawn against and puts `sample-story` at 2:42 somewhere
     * around MINUS 108%. That fixture is parked at `rendered` permanently, so
     * the state the scale cannot express is the one most often on screen.
     *
     * So the axis is the story's window plus half its span at each end, the
     * marker is clamped into it, and — because a clamped marker on its own says
     * "just outside" for anything from 22 seconds to 27 minutes — the distance
     * is stated in words beside it. Not one story in this database is inside the
     * window; `IN WINDOW` is the only window state the mock draws.
     *
     * The verdict itself is unchanged: `in_target_window` still decides, and
     * Gate 3 still reports rather than refuses. The floor is a preference and
     * the operator's call.
     *
     * @return array{percent: float, band_start: float, band_end: float, inside: bool, direction: ?string, distance: ?string, label: string, tone: string}
     */
    #[Computed]
    public function windowBar(): array
    {
        $minMs = $this->story->target_duration_min * 60_000;
        $maxMs = $this->story->target_duration_max * 60_000;
        $actual = (int) $this->story->acts()->sum('duration_ms');

        $span = max($maxMs - $minMs, 60_000);
        $axisStart = $minMs - intdiv($span, 2);
        $axisEnd = $maxMs + intdiv($span, 2);

        $place = static fn (int $ms): float => round(
            max(0.0, min(100.0, ($ms - $axisStart) / ($axisEnd - $axisStart) * 100)),
            2,
        );

        $inside = $actual >= $minMs && $actual <= $maxMs;
        $direction = $inside ? null : ($actual < $minMs ? 'under' : 'over');
        $gap = $inside ? 0 : ($actual < $minMs ? $minMs - $actual : $actual - $maxMs);

        return [
            'percent' => $place($actual),
            'band_start' => $place($minMs),
            'band_end' => $place($maxMs),
            'inside' => $inside,
            'direction' => $direction,
            'distance' => $direction === null ? null : $this->gap($gap).' '.$direction,
            'label' => $inside ? 'in window' : strtoupper((string) $direction),
            'tone' => $inside ? 'ok' : 'warn',
        ];
    }

    /**
     * Act boundaries as positions on the finished runtime.
     *
     * The mock puts these on the video scrubber. Nothing can draw on a native
     * `<video controls>` scrubber, and swapping in a custom player to gain seven
     * tick marks would put this gate's one job — watching the file — behind a
     * pile of JavaScript. They are their own strip under the player instead:
     * same information, same source, no player to go wrong.
     *
     * Clamped for the same reason the runtime marker is. An act that keeps an
     * older `start_ms` through a partial re-render can name a position past the
     * end of the video, and a tick outside its own rail is not a tick.
     *
     * @return array<int, array{percent: float, timestamp: string, title: string}>
     */
    #[Computed]
    public function chapterMarks(): array
    {
        $total = (int) $this->story->acts()->sum('duration_ms');

        if ($total <= 0) {
            return [];
        }

        return array_map(
            static fn (array $chapter): array => [
                'percent' => round(max(0.0, min(100.0, $chapter['start_ms'] / $total * 100)), 2),
                'timestamp' => $chapter['timestamp'],
                'title' => $chapter['title'],
            ],
            $this->chapters(),
        );
    }

    /**
     * Clip encoding, counted against the scenes rather than against the rows.
     *
     * ROW 7 OF THE FALSE-SUCCESS TABLE, AND THE REASON THIS METHOD IS NOT A
     * ONE-LINER. `RenderJob::open()` runs INSIDE the job, so a scene still
     * queued has no row at all — `render_jobs` cannot count a backlog however
     * carefully it is asked. Story 21 reported "118 stills done, nothing
     * failed, no stale heartbeat" while 152 scenes sat in Redis with nothing
     * listening, and every number on that page was true.
     *
     * The denominator is therefore the scene count, which is the one figure that
     * does not come from the rows. `RenderProgress::stages()` already made this
     * call for the render page — "saying 185/185 for a 186-scene story hides
     * that rather than showing it" — and this is the same denominator on the
     * gate.
     *
     * @return array{done: int, total: int, percent: int, unrecorded: int}
     */
    #[Computed]
    public function clipProgress(): array
    {
        $total = $this->story->scenes()->count();

        $done = RenderJob::query()
            ->where('story_id', $this->story->id)
            ->where('stage', RenderStage::SceneClips)
            ->where('status', RenderJobStatus::Succeeded)
            ->count();

        $recorded = RenderJob::query()
            ->where('story_id', $this->story->id)
            ->where('stage', RenderStage::SceneClips)
            ->count();

        return [
            'done' => $done,
            'total' => $total,
            'percent' => $total === 0 ? 0 : (int) floor($done / $total * 100),
            // Scenes with no row of their own. Not "failed" and not "done" —
            // unseen, which is the only honest word for it.
            'unrecorded' => max(0, $total - $recorded),
        ];
    }

    private function muxJob(): ?RenderJob
    {
        return RenderJob::query()
            ->where('story_id', $this->story->id)
            ->where('stage', RenderStage::Mux)
            ->latest('id')
            ->first();
    }

    /** A gap in words, so 22 seconds and 27 minutes do not read the same. */
    private function gap(int $ms): string
    {
        $seconds = (int) round($ms / 1000);

        if ($seconds < 60) {
            return $seconds.' s';
        }

        $minutes = intdiv($seconds, 60);
        $rest = $seconds % 60;

        return $rest === 0 ? $minutes.' min' : $minutes.' min '.$rest.' s';
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
