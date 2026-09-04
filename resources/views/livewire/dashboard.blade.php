{{--
    Order on this page is an argument, not a layout preference.

    Alarms first, then what needs a decision, then what is moving, then money.
    A stranded queue outranks a gate waiting for review because the gate will
    still be there in an hour and the stalled run will still be stalled — and
    the failure this console was built around is precisely a page that looked
    calm while 152 scenes sat in Redis with nothing listening.
--}}
<div wire:poll.15s>
    @if (session('notice'))
        <div class="alert ok">{{ session('notice') }}</div>
    @endif

    {{-- ── 1. Has anything stopped ─────────────────────────────────────── --}}

    @php($troubledQueues = $this->troubledQueues())

    @if ($troubledQueues !== [] || $troubledStories->isNotEmpty())
        <h2 class="flush-top">Stopped</h2>

        {{-- The queue alarms, in the component that owns them. Not restated
             here: a second rendering of the same state is a second thing to
             keep in step, and this panel already carries the fix for each
             state as a command you can paste. --}}
        <x-worker-health :queues="$troubledQueues" :compact="true" />

        @foreach ($troubledStories as $story)
            @php($n = $next[$story->id])
            <div class="alert err">
                <strong>{{ $story->title }}</strong>
                <span class="badge">{{ $story->status->value }}</span>
                @foreach ($n->warnings as $warning)
                    <div class="mt-1">{{ $warning }}</div>
                @endforeach
                <div class="actions mt-4">
                    <a href="{{ route('renders.show', $story->slug) }}" class="btn tiny">Render progress</a>
                    <a href="{{ route('stories.show', $story) }}" class="btn tiny">Open story</a>
                </div>
            </div>
        @endforeach
    @else
        <div class="alert ok">
            Nothing is stranded, nothing has failed, and every heartbeat is current.
            @php($unreadable = collect($this->workers())->firstWhere('pending', null))
            @if ($unreadable)
                <div class="mt-1">
                    Except that <span class="mono">{{ $unreadable['queue'] }}</span>&rsquo;s depth could
                    not be read, so an idle queue and a stalled one look the same from here.
                </div>
            @endif
        </div>
    @endif

    {{-- ── 2. What needs a person ──────────────────────────────────────── --}}

    <h2>Waiting on you</h2>

    @if ($waitingOnOperator->isEmpty())
        <div class="panel muted">
            <p class="muted m-none">
                No gate is open. {{ $liveCount }} unfinished
                {{ \Illuminate\Support\Str::plural('story', $liveCount) }}
                @if ($inFlight->isNotEmpty())
                    &mdash; {{ $inFlight->count() }} of them with work in the queue.
                @else
                    and nothing in the queue.
                @endif
            </p>
        </div>
    @else
        @foreach ($this->byGate($waitingOnOperator, $next) as $gateLabel => $stories)
            <div class="panel">
                <div class="row mb-5">
                    <label class="m-none">{{ $gateLabel }}</label>
                    <span class="badge money">{{ count($stories) }} waiting</span>
                </div>

                @foreach ($stories as $story)
                    @php($n = $next[$story->id])
                    <div class="needs">
                        <div class="grow">
                            <a href="{{ route($n->routeName, $story) }}"><strong>{{ $story->title }}</strong></a>
                            <span class="badge">{{ $story->status->value }}</span>
                            <div class="muted small">{{ $n->summary }}</div>
                            <div class="meta-line mono small">
                                {{ $story->scenes_count }} scenes &middot;
                                ${{ number_format((float) $story->total_cost_usd, 4) }} spent
                                @if ($story->evaluationSpend() > 0)
                                    {{-- Logged against this story, excluded from its total on
                                         purpose, and therefore only visible where it is
                                         printed explicitly. --}}
                                    &middot; + ${{ number_format($story->evaluationSpend(), 4) }} eval
                                @endif
                            </div>
                        </div>
                        <a href="{{ route($n->routeName, $story) }}" class="btn">{{ $n->routeLabel }}</a>
                    </div>
                @endforeach
            </div>
        @endforeach

        <div class="muted small mb-7">
            These link to the gate; none of them crosses one. Approving is an editorial judgement made
            in front of the thing being judged, so all four live on their own pages.
        </div>
    @endif

    {{-- ── 3. What is moving ───────────────────────────────────────────── --}}

    <h2>In flight</h2>

    @if ($inFlight->isEmpty())
        <div class="panel muted">
            <p class="muted m-none">Nothing is queued or running.</p>
        </div>
    @else
        <div class="panel flush">
            <table>
                <thead>
                <tr>
                    <th>Story</th>
                    <th>Status</th>
                    <th>Queue</th>
                    <th style="width:200px">Jobs</th>
                    <th>What it is doing</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($inFlight as $story)
                    @php($n = $next[$story->id])
                    @php($w = $n->workers())
                    @php($row = $progress->get($story->id))
                    <tr @class(['warnfill' => $n->warnings !== []])>
                        <td>
                            <a href="{{ route('renders.show', $story->slug) }}">{{ $story->title }}</a>
                            <div class="muted mono small">{{ $story->scenes_count }} scenes</div>
                        </td>
                        <td><span class="badge run">{{ $story->status->value }}</span></td>
                        <td>
                            <span class="mono small">{{ $n->queue }}</span>
                            {{-- The same three states the refusal reads. A story
                                 parked at assets_generating with no worker on
                                 assets looks identical, from the outside, to one
                                 that is working. --}}
                            @if ($w && $w['state'] === \App\Support\WorkerHealth::STRANDED)
                                <span class="badge fail">stranded</span>
                            @elseif ($w && $w['state'] === \App\Support\WorkerHealth::STALE)
                                <span class="badge fail">stale</span>
                            @elseif ($w && $w['state'] === \App\Support\WorkerHealth::ABSENT)
                                <span class="badge warn">nothing listening</span>
                            @elseif ($w && $w['state'] === \App\Support\WorkerHealth::OK)
                                <span class="badge ok">{{ $w['live'] }} up</span>
                            @endif
                        </td>
                        <td>
                            @if ($row)
                                <div class="bar">
                                    <span class="done" style="width: {{ $row['percent'] }}%"></span>
                                    @if ($row['failed'] > 0)
                                        <span class="bad" style="width: {{ (int) floor($row['failed'] / max(1, $row['jobs']) * 100) }}%"></span>
                                    @endif
                                </div>
                                <div class="muted mono small">{{ $row['succeeded'] }}/{{ $row['jobs'] }} done</div>
                            @else
                                <span class="muted small">no jobs recorded yet</span>
                            @endif
                        </td>
                        <td class="muted small" style="max-width:46ch">{{ $n->summary }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif

    {{-- ── 4. Money ────────────────────────────────────────────────────── --}}

    <h2>Spend</h2>

    <div class="cards">
        <div class="panel money card">
            <label>This month</label>
            <div class="figure">${{ number_format($spend->monthVideoSpend, 2) }}</div>
            <div class="muted small">
                Since {{ $spend->since->format('j M') }}. Video spend only &mdash; the same set of
                categories the per-story totals are built from.
            </div>
            @if ($spend->monthEvaluationSpend > 0)
                <div class="muted small mt-2">
                    + ${{ number_format($spend->monthEvaluationSpend, 2) }} evaluating the channel
                    (style previews and bake-offs). Real money, deliberately outside the per-video
                    totals because it belongs to the channel rather than to any one video.
                </div>
            @endif
        </div>

        <div class="panel card">
            <label>All time</label>
            <div class="figure">${{ number_format($spend->allTimeVideoSpend, 2) }}</div>
            <div class="muted small">
                @if ($spend->allTimeEvaluationSpend > 0)
                    + ${{ number_format($spend->allTimeEvaluationSpend, 2) }} evaluation.
                @endif
                Every paid call writes a row; nothing here is estimated.
            </div>
        </div>
    </div>

    <div class="two-up">
        <div class="panel flush">
            <table>
                <thead><tr><th colspan="3">This month by provider</th></tr></thead>
                <tbody>
                @forelse ($spend->byProvider as $line)
                    <tr>
                        <td class="mono">{{ $line['provider'] }}</td>
                        <td class="mono">${{ number_format($line['usd'], 4) }}</td>
                        <td class="muted small">
                            {{ $line['calls'] }} call(s)
                            @if ($line['simulated_calls'] > 0)
                                {{-- Named rather than folded in. A stand-in run writes a
                                     $0.00 row so the ledger stays complete, and a stage
                                     tagged "paid" that was served by a fake is the mislabel
                                     that let $8.12 of phantom spend read as a bill. --}}
                                <span class="badge">{{ $line['simulated_calls'] }} simulated</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td class="muted small">Nothing billed this month.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>

        <div class="panel flush">
            <table>
                <thead><tr><th colspan="2">Costliest stories</th></tr></thead>
                <tbody>
                @forelse ($costliest as $story)
                    <tr>
                        <td>
                            <a href="{{ route('stories.show', $story) }}">{{ $story->title }}</a>
                            <div class="muted mono small">{{ $story->status->value }}</div>
                        </td>
                        <td class="mono">
                            ${{ number_format((float) $story->total_cost_usd, 2) }}
                            @if ($story->evaluationSpend() > 0)
                                <div class="muted small">+ ${{ number_format($story->evaluationSpend(), 2) }} eval</div>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td class="muted small">No story has cost anything yet.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- ── 5. Provider balances ────────────────────────────────────────── --}}

    <h2>Provider balances</h2>

    <p class="muted small">
        Only one of these is a balance. Where a vendor publishes no such endpoint the figure shown is
        <strong>spend from this app&rsquo;s own ledger</strong>, and it is labelled as spend &mdash; a
        number derived entirely from our own bookkeeping must never appear where a number the vendor
        vouches for goes.
    </p>

    <div class="cards">
        @foreach ($balances as $balance)
            <div @class([
                'panel' => true,
                'card' => true,
                'warnfill' => $balance->isConcerning(),
            ])>
                <div class="row mb-2">
                    <label class="m-none">{{ $balance->provider }}</label>
                    @switch ($balance->kind)
                        @case (\App\Support\ProviderBalance::BALANCE)
                            <span class="badge ok">balance</span>
                            @break
                        @case (\App\Support\ProviderBalance::UNREADABLE)
                            <span class="badge fail">could not read</span>
                            @break
                        @case (\App\Support\ProviderBalance::NO_ENDPOINT)
                            <span class="badge warn">spend, not a balance</span>
                            @break
                        @case (\App\Support\ProviderBalance::SIMULATED)
                            <span class="badge">stand-in</span>
                            @break
                        @case (\App\Support\ProviderBalance::UNNAMEABLE)
                            {{-- A gap in this repository rather than an
                                 operational problem, so it is stated rather
                                 than alarmed. See ProviderBalance::isConcerning(). --}}
                            <span class="badge warn">cannot identify itself</span>
                            @break
                        @default
                            <span class="badge">local</span>
                    @endswitch
                </div>

                <div class="muted small mb-4">{{ $balance->role }}</div>

                @if ($balance->kind === \App\Support\ProviderBalance::BALANCE)
                    <div class="figure">{{ number_format((float) $balance->remaining) }}</div>
                    <div class="muted small">
                        {{ $balance->unit }} left of {{ number_format((float) $balance->limit) }}
                        @if ($balance->tier)
                            on <span class="mono">{{ $balance->tier }}</span>
                        @endif
                    </div>
                    <div class="bar mt-4">
                        <span class="{{ $balance->isConcerning() ? 'bad' : 'done' }}"
                              style="width: {{ (int) round((float) $balance->usedFraction() * 100) }}%"></span>
                    </div>
                @elseif ($balance->kind === \App\Support\ProviderBalance::NO_ENDPOINT)
                    <div class="figure">${{ number_format((float) $balance->spendToDate, 2) }}</div>
                    <div class="muted small">spent to date &mdash; this is not a balance</div>
                @endif

                <div class="muted small mt-4">{{ $balance->headline }}</div>
            </div>
        @endforeach
    </div>
</div>
