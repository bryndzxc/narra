@props(['queues' => null, 'compact' => false])

@php
    // The NSSM service behind each queue, so the fix offered is the one that
    // survives a --max-time exit rather than another terminal that will not.
    $services = [
        (string) config('render.queues.text') => 'NarraText',
        (string) config('render.queues.assets') => 'NarraAssets',
        (string) config('render.queues.render') => 'NarraRender',
    ];

    // Default to all three. A caller that names one queue gets one row — used
    // beside a button, where the only queue that matters is the one that button
    // dispatches to.
    $rows = $queues ?? \App\Support\WorkerHealth::all();
    // Stranded first: a queue holding work with nobody on it is the one state
    // here that means the pipeline has stopped RIGHT NOW. Stale refuses future
    // dispatches, which is loud and immediate wherever it fires; absent is a
    // note about an empty queue.
    $worst = collect($rows)->pluck('state')->pipe(fn ($s) => match (true) {
        $s->contains(\App\Support\WorkerHealth::STRANDED) => \App\Support\WorkerHealth::STRANDED,
        $s->contains(\App\Support\WorkerHealth::STALE) => \App\Support\WorkerHealth::STALE,
        $s->contains(\App\Support\WorkerHealth::ABSENT) => \App\Support\WorkerHealth::ABSENT,
        default => \App\Support\WorkerHealth::OK,
    });
@endphp

<div class="panel" @class(['warnfill' => $worst !== \App\Support\WorkerHealth::OK])>
    @unless ($compact)
        <div class="row">
            <div class="grow">
                <label>Queue workers</label>
                <div class="muted small">
                    There is no Horizon on this platform, so this is the only place workers announce
                    themselves. A <strong>stale</strong> worker is refused at dispatch. An
                    <strong>absent</strong> one is not &mdash; the job queues and waits, nothing is lost,
                    and nothing happens. When that queue is also holding jobs it is
                    <strong>stranded</strong>, and the pipeline has stopped.
                </div>
            </div>
        </div>
    @endunless

    <table class="mt-4">
        <thead>
        <tr>
            <th>Queue</th>
            <th>State</th>
            <th>Live</th>
            <th>Waiting</th>
            <th>Oldest up</th>
            <th>What it runs</th>
        </tr>
        </thead>
        <tbody>
        @foreach ($rows as $row)
            <tr>
                <td class="mono">{{ $row['queue'] }}</td>
                <td>
                    @switch ($row['state'])
                        @case (\App\Support\WorkerHealth::STALE)
                            <span class="badge fail">stale</span>
                            @break
                        @case (\App\Support\WorkerHealth::STRANDED)
                            <span class="badge fail">stranded</span>
                            @break
                        @case (\App\Support\WorkerHealth::ABSENT)
                            <span class="badge warn">nothing listening</span>
                            @break
                        @case (\App\Support\WorkerHealth::INLINE)
                            <span class="badge">inline</span>
                            @break
                        @default
                            <span class="badge ok">current</span>
                    @endswitch
                </td>
                <td class="mono">{{ $row['live'] }}{{ $row['stale'] ? ' ('.$row['stale'].' stale)' : '' }}</td>
                {{-- Read from the queue, not from `render_jobs`. A row is opened
                     inside the running job, so a scene still sitting in Redis has
                     no row and the progress page cannot count it. This is the
                     number that tells "nothing left to do" from "nobody doing it". --}}
                <td class="mono {{ ($row['pending'] ?? 0) > 0 ? '' : 'muted' }}">
                    @if ($row['pending'] === null)
                        <span title="The queue could not be read. That is not the same as empty.">unreadable</span>
                    @else
                        {{ $row['pending'] }}
                    @endif
                </td>
                {{-- Uptime is not evidence of staleness — the fingerprint is —
                     but it is the thing to look at when the fingerprint says
                     fine and something is still wrong. A worker up for days has
                     survived every config change made in those days. --}}
                <td class="mono muted">{{ $row['oldest_boot'] ?? '—' }}</td>
                <td class="muted small">{{ $row['role'] }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>

{{--
    ONE ALERT PER STATE, NOT ONE PER QUEUE.

    This used to loop the queues and emit a full alert for each, so three
    workers down meant the same explanation three times over — and the shared
    prose is the long part, ~60 words about `--max-time` and NSSM that is
    identical whichever queue it is attached to. Three copies of it is not
    three times as loud, it is one message nobody finishes reading, and it
    pushes the two facts that DO differ (which queue, how many jobs) apart by
    a paragraph each.

    So: the per-queue facts are kept in full, one line each, with that queue's
    own command. The explanation is said once. Nothing is dropped and no
    number is summarised away — every queue is still named, every pending
    count is still printed, every fix is still pasteable.

    **Grouped by STATE, never across states.** Stale, stranded and absent want
    opposite reactions — stale refuses the next dispatch, stranded means the
    pipeline has stopped right now, absent is a note about an empty queue — so
    collapsing them together would be exactly the "calmer than the truth" edit
    this stylesheet's one rule forbids. Same state, same message: collapse.
    Different state, different alert.

    This is also why it is not the `.alert + .alert` clustering rule doing the
    work. That closes the GAPS between a run of advisories whose content
    genuinely differs — fourteen `style_notes`, one per character. Here the
    content is the same text repeated, which is a different problem and wants
    a different answer.
--}}
@php
    // Severity order, loudest first: stranded means stopped NOW, stale refuses
    // the next dispatch, absent is a queue with nobody on it and nothing in it.
    $order = [
        \App\Support\WorkerHealth::STRANDED,
        \App\Support\WorkerHealth::STALE,
        \App\Support\WorkerHealth::ABSENT,
    ];

    $grouped = collect($rows)
        ->filter(fn (array $row): bool => in_array($row['state'], $order, true))
        ->groupBy('state')
        ->sortBy(fn ($group, string $state): int => array_search($state, $order, true));

    $service = fn (string $queue): string => $services[$queue] ?? 'Narra'.ucfirst($queue);
@endphp

@foreach ($grouped as $state => $queues)
    @if ($state === \App\Support\WorkerHealth::STRANDED)
        {{-- The failure this was written for: an `assets` worker exits at
             --max-time part way through a 270-scene run, and every number on
             the progress page stays true while the pipeline is stopped. The
             page reported a state that was no longer the case, which is the
             whole false-success pattern. Loud, and above the stage table. --}}
        <div class="alert err mt-4">
            @foreach ($queues as $row)
                <div @class(['mt-3' => ! $loop->first])>
                    <strong>{{ $row['pending'] }} job(s) stranded on &ldquo;{{ $row['queue'] }}&rdquo;.</strong>
                    {{ $row['headline'] }}
                    <div class="mono small mt-2">nssm start {{ $service($row['queue']) }}</div>
                </div>
            @endforeach
            <div class="small mt-3">
                Or start {{ $queues->count() > 1 ? 'them' : 'one' }} by hand &mdash; but a hand-started
                worker exits at <code>--max-time</code> and does not come back, which is how the jobs
                got here. See <code>docs/queue-workers.md</code> for the sized command.
            </div>
        </div>
    @elseif ($state === \App\Support\WorkerHealth::STALE)
        <div class="alert err mt-4">
            @foreach ($queues as $row)
                <div @class(['mt-3' => ! $loop->first])>{{ $row['headline'] }}</div>
            @endforeach
            <div class="mono small mt-2">php artisan queue:restart</div>
            <div class="small mt-1">
                That is a cache flag, not a signal &mdash; a worker notices it between jobs, so one
                mid-encode takes as long as that job takes. Under NSSM the service restarts itself
                once it exits; started by hand, it does not come back on its own.
                {{-- One command covers every stale worker on the machine, which is
                     why this alert has a single line of fix and the other two have
                     one per queue. --}}
                @if ($queues->count() > 1)
                    One restart covers all {{ $queues->count() }}.
                @endif
            </div>
        </div>
    @else
        <div class="alert warn mt-4">
            @foreach ($queues as $row)
                <div @class(['mt-3' => ! $loop->first])>
                    {{ $row['headline'] }}
                    <div class="mono small mt-2">nssm start {{ $service($row['queue']) }}</div>
                </div>
            @endforeach
        </div>
    @endif
@endforeach
</div>
