<?php

namespace App\Livewire;

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
            WorkerHealth::STALE => 1,
            WorkerHealth::ABSENT => 2,
        ];

        $troubled = array_values(array_filter(
            $this->workers(),
            fn (array $w): bool => isset($rank[$w['state']]),
        ));

        usort($troubled, fn (array $a, array $b): int => $rank[$a['state']] <=> $rank[$b['state']]);

        return $troubled;
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
            ->orderByDesc('updated_at')
            ->get();

        $next = $live->mapWithKeys(
            fn (Story $story): array => [$story->id => NextAction::for($story)]
        );

        $waitingOnOperator = $live->filter(
            fn (Story $story): bool => $next[$story->id]->waitingOn === NextAction::OPERATOR
        )->values();

        $inFlight = $live->filter(
            fn (Story $story): bool => $next[$story->id]->waitingOn === NextAction::QUEUE
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

        // Job tallies for the in-flight rows, keyed by story. Read from the
        // renders index rather than recounted here.
        $progress = RenderProgress::index(50)->keyBy(fn (array $row): int => $row['story']->id);

        return view('livewire.dashboard', [
            'waitingOnOperator' => $waitingOnOperator,
            'inFlight' => $inFlight,
            'troubledStories' => $troubledStories,
            'next' => $next,
            'progress' => $progress,
            'spend' => SpendSummary::forCurrentMonth(),
            'costliest' => SpendSummary::costliestStories(),
            'balances' => ProviderBalances::all(),
            'liveCount' => $live->count(),
        ]);
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
