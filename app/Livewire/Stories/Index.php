<?php

namespace App\Livewire\Stories;

use App\Livewire\Concerns\PaginatesWithProjectTheme;
use App\Models\Story;
use App\Support\NextAction;
use App\Support\WorkerHealth;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * One page that says what needs the operator.
 *
 * The old index had a "Waiting on" column that showed the gate, or the words
 * "the pipeline" when the story was between gates. That is the status restated
 * rather than an instruction, and it says exactly the same three words whether
 * 186 asset jobs are running normally or 181 of them failed four hours ago.
 *
 * Three things changed, and they are the same thing three times:
 *
 *   - the next action is named, from OperatorAction, so this page and the
 *     button on the gate page cannot disagree about whether it is available;
 *   - the queue each waiting story depends on is checked, so a dead worker is
 *     visible from here instead of being discovered by a story that has looked
 *     like it was "generating" since yesterday;
 *   - a failed or stalled job is surfaced on the row, not only on the render
 *     progress page, which is a page an operator opens after already suspecting
 *     something is wrong.
 *
 * Gate approvals are deliberately not offered here. Crossing a gate is an
 * editorial judgement made in front of the thing being judged, and four
 * one-click approvals on an index would be a generate-and-upload button
 * assembled out of smaller parts.
 */
class Index extends Component
{
    use PaginatesWithProjectTheme, WithPagination;

    /*
     * There is deliberately no "only show what needs me" filter.
     *
     * It was written and removed. NextAction is computed per row in PHP, not in
     * the query, so a filter could only ever hide rows from the page AFTER the
     * paginator had counted them — a page reading "25 of 60" while showing four
     * rows, and a story that needs attention on page 2 invisible from page 1.
     * A paginator that lies about its own contents to save scrolling is a worse
     * trade than scrolling. Rows that need something are marked instead, and
     * the count is stated above the table.
     */

    /**
     * @return array<int, array{queue: string, role: string, state: string, live: int, stale: int, oldest_boot: ?string, headline: string}>
     */
    #[Computed]
    public function workers(): array
    {
        return WorkerHealth::all();
    }

    public function render(): View
    {
        $stories = Story::query()
            ->withCount('scenes')
            ->orderByDesc('updated_at')
            ->paginate(25);

        // Computed per row rather than eager-loaded into one query, because
        // most of what NextAction reads is per-story anyway (a change set, a
        // job tally) and 25 rows is not the scale that justifies denormalising
        // any of it. If this page ever holds hundreds, the fix is a cached
        // column, not a cleverer query.
        $next = $stories->getCollection()->mapWithKeys(
            fn (Story $story): array => [$story->id => NextAction::for($story)]
        );

        return view('livewire.stories.index', [
            'stories' => $stories,
            'next' => $next,
            'needing' => $next->filter(fn (NextAction $n): bool => $this->needsAttention($n))->count(),
        ]);
    }

    /**
     * Whether a row is asking for something.
     *
     * A story waiting on the operator always is. A story waiting on a queue
     * only is when that queue cannot do the work — which is the case this whole
     * page exists to make visible, since a healthy queue needs nothing and an
     * empty one is silent.
     */
    private function needsAttention(NextAction $next): bool
    {
        /*
         * A fixture is never asking for anything.
         *
         * The index KEEPS them — this is the list of what exists, and hiding a
         * story from the place you go to find stories would be a worse trade
         * than any noise it causes. But the "N on this page need you" count and
         * the row marking are the same question the dashboard asks, and a count
         * that can never reach zero is a count nobody reads.
         *
         * Its row still says what it is: see the `fixture` badge in the blade.
         */
        if ($next->story->isFixture()) {
            return false;
        }

        if ($next->waitingOn === NextAction::OPERATOR) {
            return true;
        }

        if ($next->warnings !== []) {
            return true;
        }

        $workers = $next->workers();

        return $workers !== null && ! in_array(
            $workers['state'],
            [WorkerHealth::OK, WorkerHealth::INLINE],
            true,
        );
    }
}
