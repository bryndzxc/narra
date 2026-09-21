{{-- Polls because this is the console. Three terminal windows updated
     themselves; a page that replaces them and does not would be a downgrade
     dressed as an upgrade. 15s is slow enough that 25 rows of NextAction is
     cheap and fast enough that a finished batch is noticed.

     IT STOPS WHILE A SWEEP IS BEING CONFIRMED. That confirm shows a list of
     files and a total, read before pressing a button that deletes them; a poll
     underneath it would re-walk the disk every fifteen seconds and redraw the
     figures while somebody is reading them. Nothing on this page is more
     time-critical than that. --}}
<div @if (! $confirmingClear) wire:poll.15s @endif>
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

    {{--
        RECLAIMING THE DISK FINISHED WORK IS STILL HOLDING.

        Last on the page, below the pagination, and that is the position it
        wants: it is housekeeping on videos that are already on YouTube, and
        everything above it is about the next one. It is the only press in this
        console that is about the whole set rather than one story.

        Drawn only when a published story exists, because otherwise it is a
        button that can never do anything on the page whose job is to say what
        there is to do.
    --}}
    @if ($this->publishedCount() > 0)
        <div class="panel mt-5">
            <div class="row">
                <div class="grow">
                    <strong>Clear the working assets of published stories</strong>
                    <div class="muted small mt-1">
                        The stills, the per-scene narration, the reference sheets and the composed
                        thumbnails of every story past Gate 4 &mdash; every published story, not only the
                        {{ $stories->count() }} on this page. Every scene, character, cost and render-job
                        row is kept; the path columns are emptied so nothing claims a file that is gone.
                    </div>
                </div>

                @unless ($confirmingClear)
                    <button type="button"
                            wire:click="askToClearAssets"
                            wire:target="askToClearAssets"
                            wire:loading.attr="disabled">
                        Clear assets of published stories
                    </button>
                @endunless
            </div>

            {{-- Louder than a muted line: it is the record of files that are gone. --}}
            @if ($cleared)
                <div class="alert ok wide mt-4">{{ $cleared }}</div>
            @endif

            {{-- Reading the disk takes a moment on a console holding thousands of
                 stills, and a button that does nothing visible for two seconds is
                 a button somebody presses again. --}}
            <div class="alert run small mt-2 wide" wire:loading wire:target="askToClearAssets">
                <strong>Reading what is on disk.</strong> Nothing is being deleted by this.
            </div>

            @if ($confirmingClear)
                @php($plan = $this->clearPlan())

                <div class="alert warn wide mt-4">
                    @if ($plan['take'] === [])
                        <strong>Nothing to take.</strong>
                        Every published story has already been cleared, or never generated the assets.
                    @else
                        <strong>{{ count($plan['take']) }} published story(s), and this is what goes:</strong>

                        <div class="mt-2">
                            @foreach ($plan['take'] as $entry)
                                <div class="row">
                                    <span class="grow">
                                        {{ $entry['story']->title }}
                                        <span class="muted mono small">{{ $entry['story']->slug }}</span>
                                    </span>
                                    <span class="mono small">{{ number_format($entry['result']['files']) }} files</span>
                                    <span class="mono">{{ \App\Actions\ClearStoryAssets::human($entry['result']['bytes']) }}</span>
                                </div>
                            @endforeach
                        </div>

                        <div class="row mt-4">
                            <strong class="grow">Total</strong>
                            <span class="mono small">{{ number_format($plan['files']) }} files</span>
                            <strong class="mono">{{ \App\Actions\ClearStoryAssets::human($plan['bytes']) }}</strong>
                        </div>
                    @endif

                    @if ($plan['nothing'] !== [])
                        <div class="muted small mt-2">
                            {{ count($plan['nothing']) }} other published story(s) have nothing left to take
                            and are not in this list.
                        </div>
                    @endif

                    {{--
                        Said at the confirm, every time, because it is the
                        difference between this and what somebody looking at a
                        13 GB storage folder is probably hoping for. final.mp4
                        is 76% of the disk and, on this machine, the only copy
                        of thirteen of these videos.
                    --}}
                    <div class="muted small mt-2">
                        <strong>No master is touched.</strong> Deleting one is a decision about whether that
                        video's delivered copy is really on disk and really matches, which is answered per
                        story: <span class="mono">php artisan story:clear-assets &lt;id&gt; --apply
                        --include-final</span>. This press cannot reach them.
                    </div>

                    @if ($plan['take'] !== [])
                        <div class="actions mt-4">
                            <button type="button"
                                    class="primary"
                                    wire:click="clearAssets"
                                    wire:target="clearAssets"
                                    wire:loading.attr="disabled">
                                <span wire:loading.remove wire:target="clearAssets">
                                    Delete {{ \App\Actions\ClearStoryAssets::human($plan['bytes']) }}
                                </span>
                                <span wire:loading wire:target="clearAssets">Deleting &hellip;</span>
                            </button>
                            <button type="button"
                                    wire:click="cancelClearAssets"
                                    wire:target="clearAssets"
                                    wire:loading.attr="disabled">Back</button>
                        </div>

                        <div class="alert run small mt-2 wide" wire:loading wire:target="clearAssets">
                            <strong>Deleting {{ number_format($plan['files']) }} files.</strong>
                            The page waits for it. Nothing is being bought and nothing can be bought by
                            pressing again &mdash; a second sweep finds an empty disk.
                        </div>
                    @else
                        <div class="actions mt-4">
                            <button type="button" wire:click="cancelClearAssets">Back</button>
                        </div>
                    @endif
                </div>
            @endif
        </div>
    @endif
</div>
