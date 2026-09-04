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

    <table style="margin-top:10px">
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

    @foreach ($rows as $row)
        @if ($row['state'] === \App\Support\WorkerHealth::STALE)
            <div class="alert err" style="margin-top:10px">
                {{ $row['headline'] }}
                <div class="mono small" style="margin-top:6px">php artisan queue:restart</div>
                <div class="small" style="margin-top:4px">
                    That is a cache flag, not a signal &mdash; a worker notices it between jobs, so one
                    mid-encode takes as long as that job takes. Under NSSM the service restarts itself
                    once it exits; started by hand, it does not come back on its own.
                </div>
            </div>
        @elseif ($row['state'] === \App\Support\WorkerHealth::STRANDED)
            {{-- The failure this was written for: an `assets` worker exits at
                 --max-time part way through a 270-scene run, and every number on
                 the progress page stays true while the pipeline is stopped. The
                 page reported a state that was no longer the case, which is the
                 whole false-success pattern. Loud, and above the stage table. --}}
            <div class="alert err" style="margin-top:10px">
                <strong>{{ $row['pending'] }} job(s) stranded on &ldquo;{{ $row['queue'] }}&rdquo;.</strong>
                {{ $row['headline'] }}
                <div class="mono small" style="margin-top:6px">nssm start {{ $services[$row['queue']] ?? 'Narra'.ucfirst($row['queue']) }}</div>
                <div class="small" style="margin-top:4px">
                    Or start one by hand &mdash; but a hand-started worker exits at
                    <code>--max-time</code> and does not come back, which is how the jobs got here.
                    See <code>docs/queue-workers.md</code> for the sized command.
                </div>
            </div>
        @elseif ($row['state'] === \App\Support\WorkerHealth::ABSENT)
            <div class="alert warn" style="margin-top:10px">
                {{ $row['headline'] }}
                <div class="mono small" style="margin-top:6px">nssm start {{ $services[$row['queue']] ?? 'Narra'.ucfirst($row['queue']) }}</div>
            </div>
        @endif
    @endforeach
</div>
