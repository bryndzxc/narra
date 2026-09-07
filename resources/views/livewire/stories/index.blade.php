{{-- Polls because this is the console. Three terminal windows updated
     themselves; a page that replaces them and does not would be a downgrade
     dressed as an upgrade. 15s is slow enough that 25 rows of NextAction is
     cheap and fast enough that a finished batch is noticed. --}}
<div wire:poll.15s>
    @if (session('notice'))
        <div class="alert ok">{{ session('notice') }}</div>
    @endif

    <x-worker-health :queues="$this->workers()" />

    @if ($stories->isEmpty())
        <div class="panel">
            <p class="muted" style="margin:0 0 10px">
                Nothing here yet. A story starts with a premise &mdash; the one thing the app will not
                write for you.
            </p>
            <a href="{{ route('stories.create') }}" class="primary">New story</a>
            <div class="muted small mt-4">
                The fixture pipeline still has its own door:
                <span class="mono">php artisan render:import sample-story</span>
            </div>
        </div>
    @else
        <div class="row mb-5">
            <div class="grow">
                @if ($needing > 0)
                    <span class="badge money">{{ $needing }} on this page need you</span>
                @else
                    <span class="badge ok">nothing on this page is waiting on you</span>
                @endif
            </div>
            <a href="{{ route('stories.create') }}" class="primary">New story</a>
        </div>

        <div class="panel flush">
            <table>
                <thead>
                <tr>
                    <th>Story</th>
                    <th>Status</th>
                    <th>Waiting on</th>
                    <th>Next</th>
                    <th>Scenes</th>
                    <th>Cost</th>
                    <th>Publish</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($stories as $story)
                    @php($n = $next[$story->id])
                    @php($w = $n->workers())
                    <tr @class(['warnfill' => $n->warnings !== []])>
                        <td>
                            <a href="{{ route('stories.show', $story) }}">{{ $story->title }}</a>
                            <div class="muted mono small">{{ $story->slug }} &middot; {{ $story->format->label() }}</div>
                            @if ($story->isFixture())
                                {{-- Kept on this list — it is the list of what exists — but
                                     marked, so its absence from every "needs you" count is
                                     explained where somebody would notice it. --}}
                                <span class="badge" title="{{ $story->fixture_note }}">fixture</span>
                            @endif
                        </td>

                        <td><span class="badge">{{ $story->status->value }}</span></td>

                        <td>
                            @if ($n->waitingOn === \App\Support\NextAction::OPERATOR)
                                <span class="badge money">you</span>
                            @elseif ($n->waitingOn === \App\Support\NextAction::NOBODY)
                                <span class="badge ok">nobody</span>
                            @else
                                {{-- The queue, and whether it can actually do the work. A
                                     story parked at assets_generating with no worker on
                                     assets looks identical, from the outside, to one that
                                     is working. --}}
                                <span class="mono small">{{ $n->queue }}</span>
                                {{-- STRANDED was missing from this chain and fell through
                                     to no badge at all, which is the state this column
                                     exists for: a story parked at assets_generating on a
                                     queue holding work that nothing is taking. It and
                                     TAKING NOTHING are the two readings where the row
                                     looks most normal and is most wrong. --}}
                                @if ($w && $w['state'] === \App\Support\WorkerHealth::STRANDED)
                                    <span class="badge fail">stranded</span>
                                @elseif ($w && $w['state'] === \App\Support\WorkerHealth::NOT_CONSUMING)
                                    <span class="badge fail">taking nothing</span>
                                @elseif ($w && $w['state'] === \App\Support\WorkerHealth::STALE)
                                    <span class="badge fail">stale</span>
                                @elseif ($w && $w['state'] === \App\Support\WorkerHealth::ABSENT)
                                    <span class="badge warn">nothing listening</span>
                                @elseif ($w && $w['state'] === \App\Support\WorkerHealth::OK)
                                    <span class="badge ok">{{ $w['live'] }} up</span>
                                @endif
                            @endif
                        </td>

                        <td style="max-width:44ch">
                            <a href="{{ route($n->routeName, $n->routeName === 'renders.show' ? $story->slug : $story) }}">
                                {{ $n->actionLabel() ?? $n->routeLabel }}
                            </a>
                            <div class="muted small">{{ $n->summary }}</div>
                            @foreach ($n->warnings as $warning)
                                <div class="small mt-1"><span class="badge fail">!</span> {{ $warning }}</div>
                            @endforeach
                        </td>

                        <td class="mono">{{ $story->scenes_count }}</td>
                        <td class="mono">
                            ${{ number_format((float) $story->total_cost_usd, 4) }}
                            @if ($story->evaluationSpend() > 0)
                                {{-- Evaluation spend is billed against this story and excluded
                                     from its total, so this is the only place it is visible. --}}
                                <div class="muted small">+ ${{ number_format($story->evaluationSpend(), 4) }} eval</div>
                            @endif
                        </td>
                        <td class="muted mono small">
                            @if ($story->target_publish_at)
                                {{ $story->targetPublishAtEastern()?->format('D d M H:i') }} ET<br>
                                {{ $story->targetPublishAtManila()?->format('D d M H:i') }} PHT
                            @else
                                &mdash;
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>

        {{ $stories->links() }}

        <div class="muted small mt-5">
            The <em>Next</em> column names the action, never performs it. Approving a gate is an editorial
            judgement made in front of the thing being judged, so all four live on their own pages.
        </div>
    @endif
</div>
