<?php

namespace App\Livewire;

use App\Actions\EstimateSceneAssets;
use App\Enums\OperatorAction;
use App\Enums\StoryStatus;
use App\Models\Story;
use App\Support\NextAction;
use App\Support\ProviderBalances;
use App\Support\RenderProgress;
use App\Support\SpendSummary;
use App\Support\WorkerHealth;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * The landing page: what needs a person, what is moving, what has stopped.
 *
 * ---------------------------------------------------------------------------
 * WHY THIS IS NOT THE STORIES INDEX WITH A HEADER ON IT
 * ---------------------------------------------------------------------------
 *
 * `/stories` answers "what is there", newest first, twenty-five at a time. It
 * is a list, and a list is the right shape for going and finding a particular
 * video. It is the wrong shape for the question actually asked on opening this
 * app, which is "is anything waiting on me, and has anything stopped" — those
 * two answers can be on page three of a list, and a story that has been stuck
 * for a day looks exactly like one that finished yesterday.
 *
 * ---------------------------------------------------------------------------
 * EVERY NUMBER HERE HAS EXACTLY ONE SOURCE, AND IT IS NOT THIS CLASS
 * ---------------------------------------------------------------------------
 *
 * Nothing on this page is computed for this page:
 *
 *   what a story needs      NextAction::for()      — the stories index reads
 *                                                    the same predicate
 *   queue liveness          WorkerHealth::all()    — which reads the same
 *                                                    registry the dispatch-time
 *                                                    refusal reads
 *   job tallies             RenderProgress::index()— the renders index
 *   per-story spend         stories.total_cost_usd — the gate header, the
 *                                                    stories index, the
 *                                                    render page
 *   month-to-date           SpendSummary           — filtered through the same
 *                                                    CostCategory predicate the
 *                                                    ledger maintains the
 *                                                    denormalised total with
 *
 * That is a deliberate constraint rather than tidiness. Twice now a parallel
 * computation of a figure that already existed has disagreed with the real one
 * — a narration multiplier applied in three places at three different prices,
 * and a cost projection asking config while the container had resolved
 * something else. A summary page is the most tempting place in an application
 * to write the third copy of a number, because the summary "just needs a
 * rough total".
 *
 * WorkerHealth is read ONCE and passed down, so the alarm panel, the per-story
 * rows and the refresh decision cannot disagree about what the queues are
 * doing — the same reason RenderProgress::for() reads it once.
 */
class Dashboard extends Component
{
    /**
     * @return array<int, array{queue: string, role: string, state: string, live: int, stale: int, oldest_boot: ?string, pending: ?int, headline: string}>
     */
    #[Computed]
    public function workers(): array
    {
        return WorkerHealth::all();
    }

    /**
     * Queues that are not doing their job, worst first.
     *
     * STRANDED before STALE before ABSENT, the same ordering the worker-health
     * component uses: stranded means the pipeline has stopped right now, stale
     * refuses future dispatches loudly wherever they happen, and absent is a
     * note about an empty queue.
     *
     * @return array<int, array<string, mixed>>
     */
    #[Computed]
    public function troubledQueues(): array
    {
        $rank = [
            WorkerHealth::STRANDED => 0,
            // Beside stranded rather than below it: both mean the pipeline has
            // stopped right now, and this one is the harder to believe because
            // the health table shows a live worker with a fresh heartbeat.
            WorkerHealth::NOT_CONSUMING => 1,
            WorkerHealth::STALE => 2,
            WorkerHealth::ABSENT => 3,
        ];

        $troubled = array_values(array_filter(
            $this->workers(),
            fn (array $w): bool => isset($rank[$w['state']]),
        ));

        usort($troubled, fn (array $a, array $b): int => $rank[$a['state']] <=> $rank[$b['state']]);

        return $troubled;
    }

    /**
     * Queues whose depth could not be read.
     *
     * Its own question because an unreadable depth is a check that did not RUN,
     * and this codebase treats those as failures rather than passes. It is not a
     * troubled queue — the workers may be perfectly healthy — but it does mean
     * the page cannot tell an idle queue from a stalled one, and a page that
     * cannot say that is not quiet.
     *
     * @return array<int, array<string, mixed>>
     */
    #[Computed]
    public function unreadableDepths(): array
    {
        return array_values(array_filter(
            $this->workers(),
            fn (array $w): bool => $w['pending'] === null,
        ));
    }

    public function render(): View
    {
        /*
         * Every story that is not finished. `published` is terminal — the file
         * is on YouTube and this app does not reach it — so it is the one
         * status that can never need anything, and carrying it would make the
         * page longer every time a video ships.
         */
        $live = Story::query()
            ->withCount('scenes')
            ->whereNot('status', StoryStatus::Published)
            /*
             * Fixtures are not work.
             *
             * Three stories on this machine exist to be measured against rather
             * than published, and every one of them was a PERMANENT resident of
             * a section that means "do something about this": the Phase 0
             * fixture parked at `rendered` sat in "Waiting on you" — the only
             * actionable section on the page — and the two style-preview casts
             * sat in "Not moving" forever.
             *
             * A section that always contains something it should not teaches
             * you to skim it, and you skim it right past the day something real
             * lands there. They keep their pages, their costs and their place
             * on the index; they lose their place in the queue of things to do.
             */
            ->realWork()
            ->orderByDesc('updated_at')
            ->get();

        $next = $live->mapWithKeys(
            fn (Story $story): array => [$story->id => NextAction::for($story)]
        );

        $waitingOnOperator = $live->filter(
            fn (Story $story): bool => $next[$story->id]->waitingOn === NextAction::OPERATOR
        )->values();

        // Job tallies for every story with queue activity. Read once here and
        // used both to partition and to draw — the renders index is the source.
        $progress = RenderProgress::index(50)->keyBy(fn (array $row): int => $row['story']->id);

        /*
         * ── "In flight" has to mean MOVING ──────────────────────────────
         *
         * This used to be `waitingOn === QUEUE`, and that is not the same
         * question. NextAction answers "what KIND of thing is the next step" —
         * a queued job rather than an operator decision — and a story parked at
         * `draft` forever answers that with "the text queue" whether a job was
         * ever dispatched for it or not.
         *
         * So six abandoned drafts that had never been started, four of which
         * had produced literally nothing, were listed under "In flight" on a
         * page whose entire purpose is telling work that is moving from work
         * that has stopped. Every number in the section was true. The heading
         * was false, which is the same defect as a progress page reading "118
         * done, nothing failed" with 152 scenes stranded in Redis.
         *
         * The honest test is evidence that a batch exists: a status that is
         * only ever set by dispatching one, or a `render_jobs` row actually
         * running. Both are facts about work, not about what would come next.
         */
        $inFlight = $live->filter(function (Story $story) use ($progress): bool {
            if (in_array($story->status, [StoryStatus::AssetsGenerating, StoryStatus::Rendering], true)) {
                return true;
            }

            return (int) ($progress->get($story->id)['running'] ?? 0) > 0;
        })->values();

        /*
         * Waiting on a queue with no batch in evidence.
         *
         * Not an alarm — a story whose outline job failed silently is stalled,
         * not stranded, and the queue itself may be perfectly healthy. But it is
         * not progress either, and leaving it in "In flight" was the whole
         * problem. It gets its own section, named for what it is.
         */
        $notMoving = $live->filter(
            fn (Story $story): bool => $next[$story->id]->waitingOn === NextAction::QUEUE
                && ! $inFlight->contains(fn (Story $s): bool => $s->id === $story->id)
        )->values();

        /*
         * A story is "troubled" when something is true and bad regardless of
         * where it is parked: a failed job, or a heartbeat that has gone quiet.
         * Both come from NextAction::warnings(), which is what the stories
         * index marks a row with — so a story flagged here and a story flagged
         * there are flagged by one predicate.
         */
        $troubledStories = $live->filter(
            fn (Story $story): bool => $next[$story->id]->warnings !== []
        )->values();

        /*
         * The full stage/grid/failure picture for what is actually moving.
         *
         * `RenderProgress::for()` is the render page's own reader — the same
         * stage tallies, the same scene grid, the same failure list. Doing it
         * per in-flight story rather than once is affordable because in
         * practice there is one: the pipeline is sequential within a story and
         * the operator runs them one at a time. If that ever stops being true
         * the cost is visible here rather than hidden in a helper.
         */
        $detail = $inFlight->mapWithKeys(
            fn (Story $story): array => [$story->id => RenderProgress::for($story)]
        );

        /*
         * ── The layout is a function of the state, not a constant ────────
         *
         * This page was drawn for the busy case: three columns, an alarm band,
         * a scene grid, wide panels. Most of the time none of that is true, and
         * the busy layout with nothing in it is not a calm page — it is the same
         * containers at the same size holding gaps. "In flight: idle" took a
         * full column to say nothing, and seven abandoned drafts under "Not
         * moving" outweighed the three gates that were the only thing on the
         * page anybody could act on.
         *
         * So when nothing is running and nothing is wrong, the page reflows:
         * the one actionable section takes the room the other two were using,
         * and they shrink to a line that can be expanded.
         *
         * Quiet requires ALL of it. An unreadable depth counts against it even
         * though no queue is troubled, because the honest reading then is "this
         * page cannot tell an idle queue from a stalled one" — which is
         * precisely not a state to lay out as though everything is known.
         */
        $quiet = $inFlight->isEmpty()
            && $this->troubledQueues() === []
            && $this->unreadableDepths() === []
            && $troubledStories->isEmpty();

        return view('livewire.dashboard', [
            'quiet' => $quiet,
            'waitingOnOperator' => $waitingOnOperator,
            'inFlight' => $inFlight,
            'notMoving' => $notMoving,
            'troubledStories' => $troubledStories,
            'next' => $next,
            'progress' => $progress,
            'detail' => $detail,
            'spend' => SpendSummary::forCurrentMonth(),
            'costliest' => SpendSummary::costliestStories(),
            'balances' => ProviderBalances::all(),
            'liveCount' => $live->count(),
        ]);
    }

    /**
     * What the spend on this story would commit, for the label on the button
     * that goes to the page where the spend is actually pressed.
     *
     * The figure comes from `EstimateSceneAssets` — the same Action the Gate 2
     * panel itemises from — so the number on the dashboard and the number on
     * the confirmation screen cannot disagree. Quoting a different total from
     * the one the operator is asked to approve would be a parallel computation
     * of money, which is the most expensive kind this project has had.
     *
     * Null when the story is not standing at a spend, so the ordinary case
     * costs nothing.
     */
    public function spendEstimate(Story $story, NextAction $next): ?float
    {
        if ($next->action !== OperatorAction::RegenerateAssets || $next->waitingOn !== NextAction::OPERATOR) {
            return null;
        }

        return app(EstimateSceneAssets::class)->handle($story)->usdTotal();
    }

    /**
     * Cells the grid will paint blue, and whether anything is still working on
     * them.
     *
     * A `running` cell means a worker claimed that scene. If the worker is gone
     * the cell keeps saying running forever — nothing re-queues it and nothing
     * marks it failed — so the grid reports work in progress that stopped hours
     * ago. That is the false-success shape in its purest form, and the grid
     * cannot say it on its own because the claim and the worker are different
     * facts.
     *
     * @param  array<string, array<int, array{sequence: int, status: string}>>  $grid
     * @return array{count: int, abandoned: bool}
     */
    public function claimed(array $grid, ?array $workers): array
    {
        $running = 0;

        foreach ($grid as $cells) {
            foreach ($cells as $cell) {
                if ($cell['status'] === 'running') {
                    $running++;
                }
            }
        }

        return [
            'count' => $running,
            'abandoned' => $running > 0
                && $workers !== null
                && ! in_array($workers['state'], [WorkerHealth::OK, WorkerHealth::INLINE], true),
        ];
    }

    /**
     * The gate a story is standing at, for grouping.
     *
     * Reads `Story::awaitingGate()`, which is the same method the gate stepper
     * and the story header use. A dashboard that decided for itself which gate
     * a status belongs to would be a fifth copy of that mapping.
     *
     * @param  Collection<int, NextAction>  $next
     * @return array<string, array<int, Story>>
     */
    public function byGate(Collection $stories, Collection $next): array
    {
        $grouped = [];

        foreach ($stories as $story) {
            $gate = $story->awaitingGate();
            $key = $gate === null ? $next[$story->id]->routeLabel : 'Gate '.$gate->value.' — '.$gate->name;
            $grouped[$key][] = $story;
        }

        return $grouped;
    }
}
