@php
    /** @var \App\Models\Story $story */
    $gate = $story->awaitingGate();
@endphp

<x-layouts.render :title="$story->title" :refresh="$overall['active']">
    <div class="row" style="margin-bottom:6px">
        <h1 style="margin:0">{{ $story->title }}</h1>
        <span class="badge {{ $overall['failed'] > 0 ? 'fail' : ($overall['active'] ? 'run' : 'ok') }}">
            {{ $story->status->value }}
        </span>
        @if ($gate)
            <span class="badge warn">{{ $gate->label() }} awaiting operator</span>
        @endif
    </div>
    <p class="muted mono">
        {{ $story->slug }} &middot; {{ $story->scenes()->count() }} scenes &middot;
        ${{ number_format((float) $story->total_cost_usd, 4) }} spent
    </p>

    {{-- The two things worth interrupting an operator for, above everything else. --}}
    @if ($stale)
        <div class="alert warn">
            <strong>{{ count($stale) }} job(s) with a silent heartbeat.</strong>
            <div class="muted" style="margin-top:4px">
                A running job that has not touched its row for
                {{ \App\Models\RenderJob::staleAfterMinutes() }} minutes is almost certainly hung.
                <code>queue:work --timeout</code> is enforced with a pcntl alarm and pcntl does not exist
                in Windows PHP, so nothing else will report this.
            </div>
            <table style="margin-top:8px">
                <thead><tr><th>Stage</th><th>Scene</th><th>Started</th><th>Quiet for</th></tr></thead>
                <tbody>
                @foreach ($stale as $job)
                    <tr>
                        <td>{{ $job['stage']->label() }}</td>
                        <td class="mono">{{ $job['scene'] ?? '—' }}</td>
                        <td class="muted mono">{{ $job['started_at']?->diffForHumans() ?? '—' }}</td>
                        <td class="mono">{{ $job['quiet_for'] }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif

    @if ($failures)
        <div class="alert fail">
            <strong>{{ $overall['failed'] }} failed job(s).</strong>
            <span class="muted">The batch finished the rest of the scenes; the chain did not start.</span>
        </div>
    @endif

    <div class="panel">
        <div class="row">
            <strong>Overall</strong>
            <span class="muted mono">
                {{ $overall['succeeded'] }} succeeded &middot;
                {{ $overall['failed'] }} failed &middot;
                {{ $overall['running'] }} running &middot;
                {{ $overall['queued'] }} queued
            </span>
            <span class="right mono">{{ $overall['percent'] }}%</span>
        </div>
        <div class="bar" style="margin-top:10px">
            <span class="done" style="width: {{ $overall['total'] === 0 ? 0 : floor($overall['succeeded'] / $overall['total'] * 100) }}%"></span>
            <span class="bad" style="width: {{ $overall['total'] === 0 ? 0 : floor($overall['failed'] / $overall['total'] * 100) }}%"></span>
            <span class="busy" style="width: {{ $overall['total'] === 0 ? 0 : floor($overall['running'] / $overall['total'] * 100) }}%"></span>
        </div>
    </div>

    <h2>Stages</h2>
    <div class="panel" style="padding:0">
        <table>
            <thead>
            <tr>
                <th>Stage</th>
                <th style="width:180px">Progress</th>
                <th>Done</th>
                <th>Failed</th>
                <th>Running</th>
                <th>Duration</th>
            </tr>
            </thead>
            <tbody>
            @foreach ($stages as $stage)
                <tr>
                    <td>
                        {{ $stage['stage']->label() }}
                        @if ($stage['stage']->isPaid())
                            <span class="badge warn" title="This stage spends money">paid</span>
                        @endif
                        @if ($stage['stale'])
                            <span class="badge warn">no heartbeat</span>
                        @elseif ($stage['running'] > 0)
                            <span class="badge run">running</span>
                        @endif
                    </td>
                    <td>
                        <div class="bar">
                            <span class="done" style="width: {{ floor($stage['succeeded'] / max(1, $stage['total']) * 100) }}%"></span>
                            <span class="bad" style="width: {{ floor($stage['failed'] / max(1, $stage['total']) * 100) }}%"></span>
                            <span class="busy" style="width: {{ floor($stage['running'] / max(1, $stage['total']) * 100) }}%"></span>
                        </div>
                    </td>
                    <td class="mono">{{ $stage['succeeded'] }}/{{ $stage['total'] }}</td>
                    <td class="mono {{ $stage['failed'] > 0 ? '' : 'muted' }}">{{ $stage['failed'] }}</td>
                    <td class="mono muted">
                        {{ $stage['running'] }}@if ($stage['running'] > 0 && $stage['quiet_for'] !== null)
                            <span title="Seconds since the last heartbeat"> ({{ $stage['quiet_for'] }}s quiet)</span>
                        @endif
                    </td>
                    <td class="mono muted">
                        @if ($stage['started_at'] && $stage['finished_at'])
                            {{ number_format($stage['finished_at']->diffInSeconds($stage['started_at'], true), 1) }}s
                        @elseif ($stage['started_at'])
                            {{ number_format($stage['started_at']->diffInSeconds(now(), true), 0) }}s so far
                        @else
                            &mdash;
                        @endif
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>

    @foreach ($scene_grid as $stageValue => $cells)
        @php($stageEnum = \App\Enums\RenderStage::from($stageValue))
        <h2>{{ $stageEnum->label() }} &mdash; {{ count($cells) }} scenes</h2>
        <div class="panel">
            <div class="grid">
                @foreach ($cells as $cell)
                    <div class="cell {{ $cell['status'] }}"
                         title="scene {{ $cell['sequence'] }} &mdash; {{ $cell['status'] }}"></div>
                @endforeach
            </div>
            <div class="legend">
                <span class="l-ok">done</span>
                <span class="l-run">running</span>
                <span class="l-fail">failed</span>
                <span class="l-queued">queued</span>
            </div>
        </div>
    @endforeach

    @if ($failures)
        <h2>Failures</h2>
        <div class="panel" style="padding:0">
            <table>
                <thead><tr><th style="width:150px">Stage</th><th style="width:70px">Scene</th><th>Error</th></tr></thead>
                <tbody>
                @foreach ($failures as $failure)
                    <tr>
                        <td>{{ $failure['stage']->label() }}</td>
                        <td class="mono">{{ $failure['scene'] ?? '—' }}</td>
                        <td>
                            <div class="muted mono">{{ $failure['failed_at']?->diffForHumans() }}</div>
                            <pre class="err">{{ $failure['error'] }}</pre>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        @if ($overall['failed'] > count($failures))
            <p class="muted">Showing {{ count($failures) }} of {{ $overall['failed'] }} failures.</p>
        @endif
    @endif

    <h2>Batches</h2>
    @if ($batches)
        <div class="panel" style="padding:0">
            <table>
                <thead>
                <tr><th>Name</th><th style="width:160px">Progress</th><th>Processed</th><th>Failed</th><th>Finished</th></tr>
                </thead>
                <tbody>
                @foreach ($batches as $batch)
                    <tr>
                        <td>
                            {{ $batch['name'] }}
                            <div class="muted mono">{{ $batch['id'] }}</div>
                        </td>
                        <td>
                            <div class="bar"><span class="done" style="width: {{ $batch['percent'] }}%"></span></div>
                        </td>
                        <td class="mono">{{ $batch['processed'] }}/{{ $batch['total'] }}</td>
                        <td class="mono {{ $batch['failed'] > 0 ? '' : 'muted' }}">{{ $batch['failed'] }}</td>
                        <td class="muted mono">
                            @if ($batch['cancelled_at'])
                                cancelled {{ $batch['cancelled_at']->diffForHumans() }}
                            @else
                                {{ $batch['finished_at']?->diffForHumans() ?? 'in flight' }}
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @else
        <div class="panel muted">No batches recorded for this story.</div>
    @endif

    @if (! $overall['active'] && $overall['total'] > 0 && $overall['failed'] === 0 && $story->status->value === 'rendered')
        <div class="alert warn" style="margin-top:14px">
            <strong>Gate 3.</strong> The render is finished and waiting for a human to watch it.
            Nothing advances past this point on its own.
        </div>
    @endif

    <p class="muted mono" style="margin-top:20px">generated {{ $generated_at->format('H:i:s') }}</p>
</x-layouts.render>
