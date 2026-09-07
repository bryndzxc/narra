{{--
    Order on this page is an argument, not a layout preference.

    The alarm first and full-bleed, then what needs a decision, then what is
    moving, then money. A stranded queue outranks a gate waiting for review
    because the gate will still be there in an hour and the stalled run will
    still be stalled — and the failure this console was built around is
    precisely a page that looked calm while 152 scenes sat in Redis with
    nothing listening.
--}}
<div wire:poll.15s>
    @if (session('notice'))
        <div class="alert ok">{{ session('notice') }}</div>
    @endif

    {{-- ── 1. Has anything stopped ─────────────────────────────────────── --}}

    @php
        $troubledQueues = $this->troubledQueues();

        // The band's severity comes from the worst state present. Stranded and
        // stale are alarms — the pipeline has stopped, or the next dispatch is
        // refused. Absent is a warning: the job queues and waits, nothing is
        // lost, and nothing happens. Never merged, because they want opposite
        // reactions.
        $alarms = collect($troubledQueues)->filter(
            fn (array $q): bool => in_array($q['state'], [
                \App\Support\WorkerHealth::STRANDED,
                \App\Support\WorkerHealth::NOT_CONSUMING,
                \App\Support\WorkerHealth::STALE,
            ], true)
        );

        /*
         * The queue -> service map used to be written out here AND in
         * components/worker-health.blade.php, and this copy additionally
         * invented a name for anything it did not hold:
         *
         *     'Narra'.ucfirst($queue)
         *
         * Which is right for the three queues that exist, and that is what made
         * it the worst of the options available — rename a queue and the band
         * prints a confident pasteable command naming a service that is not
         * there. Both copies are gone; App\Support\WorkerServices owns it and
         * returns null rather than a guess, and the band shows no command at
         * all for a queue nothing is mapped to.
         *
         * `Restart-Service` and not `nssm start`: nssm is not on PATH on this
         * machine, and `start` does nothing to a service that is running and
         * taking nothing.
         */
        $fix = fn (array $row): ?string => $row['state'] === \App\Support\WorkerHealth::STALE
            ? 'php artisan queue:restart'
            : \App\Support\WorkerServices::restartCommand($row['queue']);

        // Worst first: stranded means the pipeline has stopped right now, stale
        // refuses the next dispatch, absent is a note about an empty queue.
        // `troubledQueues()` is already in that order, so grouping preserves it.
        $byState = collect($troubledQueues)->groupBy('state');
    @endphp

    @if ($troubledQueues !== [])
        {{--
            ONE BLOCK PER STATE, and the state is said once.

            This band was ~200px tall because it printed the whole message per
            queue: three stranded queues meant three copies of the same thirty
            words with a different name in each. That is not three times as
            loud — it is one message nobody finishes, and it pushed the health
            table off the first screen.

            So the shape is: the queues in a state are NAMED together, the
            explanation is given once from `WorkerHealth`'s `advice` (which is
            the same string for every queue in that state, which is why it can
            be), and the commands follow. Stale takes exactly one command for
            all of them — `queue:restart` is a cache flag the whole machine
            reads — while stranded and absent need one per service, so those are
            listed compactly rather than folded into prose.

            Nothing is dropped: every queue is named, every pending count is
            printed, every command is pasteable, and the health table beside it
            carries live/waiting/uptime per queue.

            Never collapsed ACROSS states. Stale, stranded and absent want
            opposite reactions, and merging them would be the "calmer than the
            truth" edit dressed as tidying.
        --}}
        <div @class(['band', 'bleed', 'warn' => $alarms->isEmpty()])>
            <div class="inner">
                <div class="msg">
                    @foreach ($byState as $state => $queues)
                        @php($names = $queues->pluck('queue')->all())
                        @php($single = count($names) === 1)

                        <div @class(['mt-7' => ! $loop->first])>
                            <h2>
                                @if ($loop->first)
                                    <svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                         stroke-width="2.3" aria-hidden="true" class="warnicon">
                                        <path d="M12 3.5 2.5 20h19L12 3.5Z"></path><path d="M12 9.5v5M12 17.2v.1"></path>
                                    </svg>
                                @endif

                                @if ($state === \App\Support\WorkerHealth::STRANDED)
                                    {{-- The count that matters is the JOBS, not the queues:
                                         it is how much work has stopped. --}}
                                    {{ number_format($queues->sum('pending')) }} job(s) stranded across
                                    {{ $single ? 'one queue' : count($names).' queues' }} &mdash;
                                    {{ $queues->map(fn (array $q): string => $q['queue'].' ('.$q['pending'].')')->join(', ', ' and ') }}.
                                    The pipeline has stopped.
                                @elseif ($state === \App\Support\WorkerHealth::NOT_CONSUMING)
                                    {{-- The jobs are the count that matters here for the
                                         same reason as stranded: it is how much work has
                                         stopped. What differs is that somebody IS on the
                                         queue, which is why the sentence has to say so —
                                         the health table beside it shows a live worker
                                         and a fresh heartbeat and looks fine. --}}
                                    {{ number_format($queues->sum('pending')) }} job(s) waiting on
                                    {{ $single ? 'one queue' : count($names).' queues' }} whose worker is
                                    polling and taking nothing &mdash;
                                    {{ $queues->map(fn (array $q): string => $q['queue'].' ('.$q['pending'].')')->join(', ', ' and ') }}.
                                    The pipeline has stopped.
                                @elseif ($state === \App\Support\WorkerHealth::STALE)
                                    {{ count($names) }} {{ \Illuminate\Support\Str::plural('worker', count($names)) }}
                                    {{ $single ? 'is' : 'are' }} stale &mdash; {{ implode(', ', $names) }}.
                                @else
                                    Nothing is listening on
                                    {{ $single ? 'one queue' : count($names).' queues' }} &mdash;
                                    {{ implode(', ', $names) }}.
                                @endif
                            </h2>

                            {{-- Said once. `advice` is per-STATE by construction — it is
                                 the half of WorkerHealth's message with no queue name in
                                 it — so one copy is the whole truth for all of them. --}}
                            <p>{{ $queues->first()['advice'] }}</p>

                            @if ($state === \App\Support\WorkerHealth::STALE)
                                {{-- One command covers every stale worker on the machine. --}}
                                <code class="cmd">php artisan queue:restart</code>
                                <p class="small mt-2">
                                    A cache flag, not a signal &mdash; a worker notices it between jobs, so one
                                    mid-encode takes as long as that job takes. Under NSSM the service restarts
                                    once it exits; started by hand it does not come back.
                                </p>
                            @else
                                {{-- One service per queue, so one command each. Stacked
                                     without prose between them: the repetition that was
                                     worth removing was the explanation, not the fix. --}}
                                @foreach ($queues as $row)
                                    @php($command = $fix($row))

                                    @if ($command === null)
                                        {{-- No service mapped, so no command. Said rather
                                             than guessed: the fallback this replaces would
                                             have printed a plausible name for a service
                                             that does not exist. --}}
                                        <p class="small">
                                            Nothing is mapped to &ldquo;{{ $row['queue'] }}&rdquo;, so there is
                                            no restart command for it here.
                                        </p>
                                    @else
                                        <code class="cmd">{{ $command }}</code>
                                    @endif
                                @endforeach
                                <p class="small mt-2">
                                    Needs an elevated prompt. Or start {{ $single ? 'it' : 'them' }} by hand
                                    &mdash; but a hand-started worker exits at <code>--max-time</code> and does
                                    not come back, which is one way jobs get here. See
                                    <code>docs/queue-workers.md</code> for the sized command.
                                </p>
                            @endif
                        </div>
                    @endforeach
                </div>

                {{-- Every queue, whatever its state. The alarm is about the ones
                     that are wrong; the question it raises is about all of them. --}}
                <div class="scrollx">
                    <table>
                        <thead>
                        <tr><th>Queue</th><th>State</th><th>Live</th><th>PID</th><th>Waiting</th><th>Oldest up</th></tr>
                        </thead>
                        <tbody>
                        @foreach ($this->workers() as $row)
                            <tr>
                                <td>{{ $row['queue'] }}</td>
                                {{-- Through label(): this cell printed the constant, and
                                     `not_consuming` is a value rather than a sentence. The
                                     wording is shared with the badges so one state does
                                     not read as two. --}}
                                <td><span class="state">{{ \App\Support\WorkerHealth::label($row['state']) }}</span></td>
                                <td>{{ $row['live'] }}{{ $row['stale'] ? ' ('.$row['stale'].' stale)' : '' }}</td>
                                {{-- The one fact on this panel that can be checked
                                     against the machine rather than against our own
                                     bookkeeping. --}}
                                <td>{{ $row['pids'] === [] ? '—' : implode(', ', $row['pids']) }}</td>
                                {{-- An unreadable depth is not an empty one. Without the
                                     number an idle queue and a stalled one look the same
                                     from here. --}}
                                <td title="{{ $row['pending'] === null ? 'The queue could not be read. That is not the same as empty.' : '' }}">
                                    {{ $row['pending'] === null ? 'unreadable' : $row['pending'] }}
                                </td>
                                <td>{{ $row['oldest_boot'] ?? '—' }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    @else
        @php($unreadable = collect($this->workers())->filter(fn (array $w): bool => $w['pending'] === null))

        @if ($unreadable->isNotEmpty())
            {{--
                NOT the green box.

                This block used to be one `.alert.ok` headed "Nothing is
                stranded, nothing has failed, and every heartbeat is current",
                with the unreadable-depth caveat as a smaller line inside it. A
                depth that could not be read is a check that did not RUN, and
                this codebase's rule is that those are failures rather than
                passes — so putting it inside a green panel whose first line
                declares the all-clear is absence read as agreement, drawn in
                the most literal way available.

                It is its own warning now, and the all-clear is only shown when
                everything actually is clear. The two states could not be
                distinguished before: the green box rendered identically
                whether the queues were fine or unknowable.
            --}}
            <div class="alert warn">
                <strong>
                    {{ $unreadable->pluck('queue')->join(', ', ' and ') }}
                    &mdash; {{ $unreadable->count() === 1 ? 'this queue&rsquo;s depth' : 'these queues&rsquo; depths' }}
                    could not be read.
                </strong>
                <div class="mt-1">
                    An idle queue and a stalled one look the same from here, so this panel cannot tell you
                    whether anything is waiting. Everything else below is current: nothing is stranded that
                    we can see, nothing has failed, and every heartbeat that exists is fresh &mdash; but
                    &ldquo;that we can see&rdquo; is doing real work in that sentence.
                </div>
            </div>
        @else
            <div class="alert ok">
                <strong>Nothing is stranded, nothing has failed, and every heartbeat is current.</strong>
            </div>
        @endif
    @endif

    {{-- A story-level failure is a different alarm from a queue-level one: the
         queue is fine and the work in it broke. Kept out of the band so the band
         stays about the pipeline being stopped. --}}
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

    <div @class(['dash', 'quiet' => $quiet])>

        {{-- ── 2. What needs a person ───────────────────────────────────
             First in the DOM as well as in the layout, so it is the first thing
             read on a narrow screen too. When the page is quiet this column
             takes the width the other two were using: it is the only section
             anybody can act on, and it should not have to compete with two
             columns of nothing to say so. --}}

        <section class="card needs-col">
            <div class="head">
                <h2>Waiting on you</h2>
                @if ($waitingOnOperator->isNotEmpty())
                    <span class="badge money">{{ $waitingOnOperator->count() }} waiting</span>
                @else
                    <span class="badge ok">clear</span>
                @endif
                <span class="note">none of these crosses a gate</span>
            </div>

            @forelse ($waitingOnOperator as $story)
                @php($n = $next[$story->id])
                @php($gate = $story->awaitingGate())
                @php($commits = $this->spendEstimate($story, $n))

                <div @class(['row-item', 'money' => $commits !== null])>
                    <div class="split">
                        <div class="grow minw">
                            <div class="row mb-1 tight">
                                @if ($gate)
                                    <span class="badge money">Gate {{ $gate->value }} &middot; {{ $gate->name }}</span>
                                @endif
                                <span class="badge">{{ $story->status->value }}</span>
                            </div>

                            <a href="{{ route($n->routeName, $story) }}" class="title">{{ $story->title }}</a>
                            <div class="muted small mt-hair">{{ $n->summary }}</div>
                            <div class="meta-line mono small">
                                {{ $story->scenes_count }} scenes &middot;
                                ${{ number_format((float) $story->total_cost_usd, 4) }} spent
                                @if ($story->evaluationSpend() > 0)
                                    {{-- Logged against this story, excluded from its total on
                                         purpose, and so only visible where it is printed. --}}
                                    &middot; + ${{ number_format($story->evaluationSpend(), 4) }} eval
                                @endif
                            </div>
                        </div>

                        @if ($commits !== null)
                            {{-- Amber, with what it commits inside the label. It NAVIGATES:
                                 the spend is a second, separate press on the page where the
                                 scenes being bought are visible. --}}
                            <a href="{{ route($n->routeName, $story) }}" class="spend">
                                <span class="what">Review the bill</span>
                                <span class="howmuch">commits ${{ number_format($commits, 2) }}</span>
                            </a>
                        @else
                            <a href="{{ route($n->routeName, $story) }}" class="btn">{{ $n->routeLabel }}</a>
                        @endif
                    </div>
                </div>
            @empty
                <div class="row-item muted small">
                    No gate is open. {{ $liveCount }} unfinished
                    {{ \Illuminate\Support\Str::plural('story', $liveCount) }}@if ($inFlight->isNotEmpty()), {{ $inFlight->count() }} of them with work in the queue.@else and nothing in the queue.@endif
                </div>
            @endforelse
        </section>

        {{-- ── 3. What is moving ─────────────────────────────────────────
             Two cards when there is something to show, one line when there is
             not. A heading over an empty container is furniture; the strip below
             says the same thing in a sentence and gives the space back. --}}

        <x-gate-group col="flow">
        @if ($quiet)
            <div class="strip">
                <span class="badge">idle</span>
                <span>
                    Nothing is running, no batch is queued, and every queue is current &mdash; the ordinary
                    state between a dispatch and a gate.
                </span>

                @if ($notMoving->isNotEmpty())
                    {{-- Collapsed, not dropped. These are stories whose next step
                         is a queued job with no batch in evidence: worth finding
                         when you go looking, and not worth more vertical space
                         than the gates that are actually waiting. Anything here
                         that has FAILED is not here — it is an alert at the top
                         of the page, because a failure is not a quiet state. --}}
                    <details class="why">
                        <summary>{{ $notMoving->count() }} not moving</summary>
                        <div class="mt-3">
                            <table>
                                <thead>
                                <tr><th>Story</th><th>Status</th><th>Built</th><th>Last touched</th></tr>
                                </thead>
                                <tbody>
                                @foreach ($notMoving as $story)
                                    @php($row = $progress->get($story->id))
                                    <tr>
                                        <td>
                                            <a href="{{ route($next[$story->id]->routeName, $story) }}">{{ $story->title }}</a>
                                            <div class="muted mono small">{{ $story->slug }}</div>
                                        </td>
                                        <td><span class="badge">{{ $story->status->value }}</span></td>
                                        <td class="mono small muted">
                                            {{ $story->acts()->count() }} acts &middot; {{ $story->scenes_count }} scenes
                                            @if ((float) $story->total_cost_usd > 0)
                                                &middot; ${{ number_format((float) $story->total_cost_usd, 4) }}
                                            @endif
                                        </td>
                                        <td class="mono small muted">
                                            {{ $row['last_activity']?->diffForHumans() ?? $story->updated_at->diffForHumans() }}
                                        </td>
                                    </tr>
                                @endforeach
                                </tbody>
                            </table>
                        </div>
                    </details>
                @endif
            </div>
        @else
        <section class="card">
            <div class="head">
                <h2>In flight</h2>
                <span class="note">stage tallies and the scene grid, from the render page&rsquo;s own reader</span>
            </div>

            @forelse ($inFlight as $story)
                @php($n = $next[$story->id])
                @php($w = $n->workers())
                @php($d = $detail[$story->id])
                @php($claimed = $this->claimed($d['scene_grid'], $w))

                <div class="row-item">
                    <div class="row mb-4 tight">
                        <a href="{{ route('renders.show', $story->slug) }}" class="title">{{ $story->title }}</a>
                        <span class="mono small muted">
                            {{ $story->scenes_count }} scenes &middot; {{ $n->queue }} &middot; {{ $story->format->value }}
                        </span>
                        @if ($w && $w['state'] === \App\Support\WorkerHealth::STRANDED)
                            <span class="badge fail">stranded</span>
                        @elseif ($w && $w['state'] === \App\Support\WorkerHealth::NOT_CONSUMING)
                            {{-- Without this the chain fell through to nothing, and a
                                 story sitting on a queue that has stopped would have lost
                                 its badge on the way past — the one row shape where the
                                 stall is least believable losing the only marking it had. --}}
                            <span class="badge fail">taking nothing</span>
                        @elseif ($w && $w['state'] === \App\Support\WorkerHealth::STALE)
                            <span class="badge fail">stale</span>
                        @elseif ($w && $w['state'] === \App\Support\WorkerHealth::ABSENT)
                            <span class="badge warn">nothing listening</span>
                        @elseif ($w && $w['state'] === \App\Support\WorkerHealth::OK)
                            <span class="badge ok">{{ $w['live'] }} up</span>
                        @endif
                    </div>

                    <div class="stages">
                        @foreach ($d['stages'] as $stage)
                            <div class="stage">
                                <span class="nm">{{ $stage['stage']->label() }}</span>
                                <span class="bar">
                                    <span class="done" style="width: {{ floor($stage['succeeded'] / max(1, $stage['total']) * 100) }}%"></span>
                                    <span class="bad" style="width: {{ floor($stage['failed'] / max(1, $stage['total']) * 100) }}%"></span>
                                    <span class="busy" style="width: {{ floor($stage['running'] / max(1, $stage['total']) * 100) }}%"></span>
                                </span>
                                <span class="n">{{ $stage['succeeded'] }}/{{ $stage['total'] }}</span>
                            </div>
                        @endforeach
                    </div>
                </div>

                @foreach ($d['scene_grid'] as $stage => $cells)
                    <div class="row-item">
                        <div class="row mb-3 wide-gap">
                            <span class="label-inline">{{ $stage }}, scene by scene</span>
                            <span class="legend">
                                <span class="l-ok">{{ collect($cells)->where('status', 'succeeded')->count() }} done</span>
                                <span class="l-run">{{ collect($cells)->where('status', 'running')->count() }} claimed</span>
                                <span class="l-fail">{{ collect($cells)->where('status', 'failed')->count() }} failed</span>
                                <span class="l-queued">{{ collect($cells)->where('status', 'queued')->count() }} queued</span>
                            </span>
                        </div>
                        <div class="cells">
                            @foreach ($cells as $cell)
                                <span class="cell {{ $cell['status'] }}"
                                      title="scene {{ $cell['sequence'] }} — {{ $cell['status'] }}"></span>
                            @endforeach
                        </div>

                        {{--
                            What the blue cells mean, and it is not decoration.

                            A `running` cell means a worker CLAIMED that scene. If the
                            worker is gone the cell keeps saying running forever —
                            nothing re-queues it and nothing marks it failed — so the
                            grid reports work in progress that stopped hours ago. The
                            grid cannot say this on its own: the claim and the worker
                            are different facts, and only putting them side by side
                            tells "being worked on" from "abandoned mid-flight".
                        --}}
                        @if ($loop->last && $claimed['count'] > 0)
                            <p @class(['small', 'mt-3', 'claimnote', 'abandoned' => $claimed['abandoned']])>
                                @if ($claimed['abandoned'])
                                    The {{ $claimed['count'] }} blue
                                    {{ \Illuminate\Support\Str::plural('cell', $claimed['count']) }}
                                    {{ $claimed['count'] === 1 ? 'was' : 'were' }} claimed by a worker that is no
                                    longer listening. They will read as running until something re-queues them.
                                @else
                                    Blue is claimed, not finished: {{ $claimed['count'] }}
                                    {{ \Illuminate\Support\Str::plural('scene', $claimed['count']) }} in a
                                    worker&rsquo;s hands right now.
                                @endif
                            </p>
                        @endif
                    </div>
                @endforeach

                @if ($d['failures'] !== [])
                    <div class="row-item bad">
                        <div class="row mb-3 tight">
                            <strong>{{ count($d['failures']) }} scene(s) failed.</strong>
                            <span class="muted small">the batch finished the rest, and they are not re-billed</span>
                            <a href="{{ route('renders.show', $story->slug) }}" class="btn tiny right">Open the run</a>
                        </div>
                        <table>
                            <thead><tr><th>Scene</th><th>Stage</th><th>Error</th></tr></thead>
                            <tbody>
                            @foreach (array_slice($d['failures'], 0, 5) as $failure)
                                <tr>
                                    <td class="mono">{{ $failure['scene'] ?? '—' }}</td>
                                    <td class="mono muted">{{ $failure['stage']->label() }}</td>
                                    <td class="mono muted">{{ \Illuminate\Support\Str::limit((string) $failure['error'], 120) }}</td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                        @if (count($d['failures']) > 5)
                            <div class="muted small mt-2">
                                {{ count($d['failures']) - 5 }} more on the render page. Retrying is a spend, so it
                                lives there beside the itemised bill.
                            </div>
                        @endif
                    </div>
                @endif
            @empty
                {{-- Reached only when the page is NOT quiet: something is wrong
                     and nothing is running. Worth a card rather than a strip,
                     because "nothing is moving" means something different with
                     an alarm above it than it does on an idle machine. --}}
                <div class="row-item">
                    <p class="muted small m-none">
                        <strong>Nothing is running and no batch is in flight.</strong>
                        With an alarm above, that is worth reading twice: work that should be moving is not.
                    </p>
                </div>
            @endforelse

        </section>

        {{--
            Waiting on a queue with no batch in evidence.

            Its OWN card, not a block inside "In flight". It started inside it,
            and that was wrong in the same direction as the bug it was written
            to fix: a section headed "In flight" whose only content is a table of
            things that are not. These stories answer "a queued job comes next",
            which is not the same claim as "a queued job exists", and the heading
            above a table is part of what the table says.

            Not an alarm. A queue can be perfectly healthy and a story can still
            have been abandoned before anything was dispatched for it, and
            calling that stranded would cry wolf on the band that has to stay
            believable.
        --}}
        @if ($notMoving->isNotEmpty())
            <section class="card">
                <div class="head">
                    <h2>Not moving</h2>
                    <span class="badge warn">{{ $notMoving->count() }}</span>
                    <span class="note">the next step is a queued job; no batch is in evidence</span>
                </div>

                <div class="row-item">
                    <table>
                        <thead>
                        <tr><th>Story</th><th>Status</th><th>Built</th><th>Last touched</th></tr>
                        </thead>
                        <tbody>
                        @foreach ($notMoving as $story)
                            @php($row = $progress->get($story->id))
                            <tr>
                                <td>
                                    <a href="{{ route($next[$story->id]->routeName, $story) }}">{{ $story->title }}</a>
                                    <div class="muted mono small">{{ $story->slug }}</div>
                                </td>
                                <td><span class="badge">{{ $story->status->value }}</span></td>
                                {{-- What it actually has, so "abandoned before it started"
                                     and "died part way" are told apart at a glance. --}}
                                <td class="mono small muted">
                                    {{ $story->acts()->count() }} acts &middot;
                                    {{ $story->scenes_count }} scenes
                                    @if ((float) $story->total_cost_usd > 0)
                                        &middot; ${{ number_format((float) $story->total_cost_usd, 4) }}
                                    @endif
                                </td>
                                <td class="mono small muted">
                                    {{ $row['last_activity']?->diffForHumans() ?? $story->updated_at->diffForHumans() }}
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            </section>
        @endif
        @endif
        </x-gate-group>

        {{-- ── 4. Money and providers ─────────────────────────────────── --}}

        <x-gate-group col="rail">
            <section class="card money">
                <div class="row-item">
                    <div class="label-inline mb-2">This month</div>
                    <div class="figure">${{ number_format($spend->monthVideoSpend, 2) }}</div>
                    <p class="muted small mt-2">
                        Since {{ $spend->since->format('j M') }}. Video spend only &mdash; the same categories the
                        per-story totals are built from.
                        @if ($spend->monthEvaluationSpend > 0)
                            + ${{ number_format($spend->monthEvaluationSpend, 2) }} evaluating the channel: real
                            money, deliberately outside the per-video totals because it belongs to the channel
                            rather than to any one video.
                        @endif
                        All time ${{ number_format($spend->allTimeVideoSpend, 2) }}.
                    </p>
                </div>

                <div class="row-item">
                    @php($top = (float) ($spend->byProvider->max('usd') ?: 1))
                    @forelse ($spend->byProvider as $line)
                        <div class="figures">
                            <span class="nm">{{ $line['provider'] }}</span>
                            <span class="bar"><span class="moneyfill" style="width: {{ (int) round($line['usd'] / $top * 100) }}%"></span></span>
                            <span class="amt">${{ number_format($line['usd'], 2) }}</span>
                        </div>
                        @if ($line['simulated_calls'] > 0)
                            {{-- Named rather than folded in. A stand-in writes a $0.00 row so
                                 the ledger stays complete, and a stage tagged "paid" that a
                                 fake served is the mislabel that let phantom spend read as a
                                 bill. --}}
                            <div class="mono small muted">
                                {{ $line['provider'] }}: {{ $line['simulated_calls'] }} of {{ $line['calls'] }} calls simulated
                            </div>
                        @endif
                    @empty
                        <div class="muted small">Nothing billed this month.</div>
                    @endforelse
                </div>
            </section>

            {{--
                The reasoning is kept and moved, not cut.

                Every card here carried three lines of prose for one number, and
                on a page read daily that is text you learn to scroll past —
                which is how a reader stops seeing the one card that matters.
                The explanation now sits behind a disclosure on the cards whose
                state is fine.

                It stays in the open on any card `isConcerning()` returns true
                for. An unreadable balance is a check that could not run, and
                this file's rule is that those are failures rather than passes;
                a warning folded behind a control the reader has to think to
                open is a warning that got quieter, which is the one edit not on
                the table. Calm states collapse, alarms do not.
            --}}
            <section class="card">
                <div class="head">
                    <h2>Provider balances</h2>
                    <span class="note" title="Only one of these is a balance. Where a vendor publishes none, the figure shown is spend from this app's own ledger and is labelled as spend — a number derived entirely from our own bookkeeping must never sit where a number the vendor vouches for goes.">one is a balance; the rest is spend</span>
                </div>

                @foreach ($balances as $balance)
                    <div @class(['row-item', 'warnfill' => $balance->isConcerning()])>
                        <div class="row mb-1 tight">
                            <span class="mono grow">{{ $balance->provider }}</span>
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
                                    {{-- A gap in this repository rather than an operational
                                         problem, so it is stated rather than alarmed. See
                                         ProviderBalance::isConcerning(). --}}
                                    <span class="badge warn">cannot identify itself</span>
                                    @break
                                @default
                                    <span class="badge">local</span>
                            @endswitch
                        </div>

                        <div class="muted small">{{ $balance->role }}</div>

                        @if ($balance->kind === \App\Support\ProviderBalance::BALANCE)
                            <div class="figure sm">{{ number_format((float) $balance->remaining) }}</div>
                            <div class="muted small">
                                {{ $balance->unit }} left of {{ number_format((float) $balance->limit) }}
                                @if ($balance->tier)
                                    on <span class="mono">{{ $balance->tier }}</span>
                                @endif
                            </div>
                            <div class="bar mt-2">
                                <span class="{{ $balance->isConcerning() ? 'bad' : 'done' }}"
                                      style="width: {{ (int) round((float) $balance->usedFraction() * 100) }}%"></span>
                            </div>
                        @elseif ($balance->kind === \App\Support\ProviderBalance::NO_ENDPOINT)
                            <div class="figure sm">${{ number_format((float) $balance->spendToDate, 2) }}</div>
                            <div class="muted small">spent to date &mdash; this is not a balance</div>
                        @endif

                        @if ($balance->isConcerning())
                            {{-- Open. This card is a check that could not run, or an
                                 allowance about to stop generating mid-story, and
                                 neither gets to hide behind a disclosure. --}}
                            <div class="muted small mt-2">{{ $balance->headline }}</div>
                        @else
                            <details class="why">
                                <summary>why</summary>
                                <div class="muted small mt-2">{{ $balance->headline }}</div>
                            </details>
                        @endif
                    </div>
                @endforeach
            </section>

            @if ($costliest->isNotEmpty())
                <section class="card">
                    <div class="head"><h2>Costliest stories</h2></div>
                    <table>
                        <tbody>
                        @foreach ($costliest as $story)
                            <tr>
                                <td>
                                    <a href="{{ route('stories.show', $story) }}">{{ $story->title }}</a>
                                    <div class="muted mono small">{{ $story->status->value }}</div>
                                </td>
                                <td class="mono tr">
                                    ${{ number_format((float) $story->total_cost_usd, 2) }}
                                    @if ($story->evaluationSpend() > 0)
                                        <div class="muted small">+ ${{ number_format($story->evaluationSpend(), 2) }} eval</div>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </section>
            @endif
        </x-gate-group>
    </div>
</div>
