@php
    /** @var \App\Models\Story $story */
    $gate = $story->awaitingGate();

    // `active` is computed from `render_jobs`, and a row is only opened once a
    // job STARTS — so a queue full of jobs nobody is running reads as inactive.
    // That is exactly how a --max-time exit mid-batch looked: every row done,
    // the footer saying "Nothing running", and 152 scenes waiting in Redis.
    $working = $overall['active'] || $queue_depth > 0;
@endphp

<x-layouts.render :title="$story->title" :refresh="$working">
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
        @if ($story->evaluationSpend() > 0)
            {{-- Kept out of the total on purpose: a style preview or a bake-off
                 borrowed this cast to test the channel and is not part of this
                 video. Shown anyway, because spend that is logged and nowhere
                 on screen is the shape this project keeps mistaking for fine. --}}
            &middot; <span class="muted">+ ${{ number_format($story->evaluationSpend(), 4) }} evaluation</span>
        @endif
    </p>

    {{-- A stage sitting at `queued` reads as "about to run". Whether that is
         true depends entirely on something this page could not see until now.
         Rows 4 and 5 of the false-success table are both this.

         Passed in rather than recomputed, so the panel and the refresh decision
         above are reading the same answer. --}}
    <x-worker-health :queues="$workers" />

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
                        {{-- What this stage COST, not what it is capable of costing.
                             The enum's isPaid() says a stage can spend money; only the
                             ledger says whether this run did. Tagging a stand-in run
                             "paid" is the mislabel that let phantom spend read as a bill. --}}
                        @if ($stage['billed_calls'] > 0)
                            <span class="badge warn" title="Real money, from cost_entries">
                                paid ${{ number_format((float) $stage['usd'], 4) }}
                            </span>
                        @elseif ($stage['simulated_calls'] > 0)
                            <span class="badge" title="Served by a stand-in: no vendor contacted, nothing billed">
                                simulated $0.00
                            </span>
                        @elseif ($stage['stage']->isPaid())
                            <span class="badge muted" title="This stage can spend money; nothing recorded yet">
                                billable
                            </span>
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
                    <td class="mono">
                        {{ $stage['succeeded'] }}/{{ $stage['total'] }}
                        @if ($stage['scene_count'] !== null && $stage['total'] < $stage['scene_count'])
                            {{-- One job per scene is the design, so a lower total means
                                 some scenes never ran this stage. Shown rather than hidden:
                                 "185/185" against a 186-scene story is the question this
                                 answers before it gets asked. --}}
                            <span class="muted" title="This story has {{ $stage['scene_count'] }} scenes; {{ $stage['scene_count'] - $stage['total'] }} never ran a job for this stage">
                                of {{ $stage['scene_count'] }} scenes
                            </span>
                        @endif
                    </td>
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
                            @elseif ($batch['finished_at'])
                                {{ $batch['finished_at']->diffForHumans() }}
                            @elseif ($batch['abandoned'])
                                {{-- Every job ran; the record cannot close because Laravel
                                     keeps failed jobs counted as pending so they can be
                                     retried into the batch. Nothing is queued. Saying
                                     "in flight" here had an operator waiting on workers
                                     that had already exited. --}}
                                <span title="All {{ $batch['total'] }} jobs ran; {{ $batch['failed'] }} failed and were never retried into this batch, so it never closes. Nothing is queued.">
                                    closed with failures
                                </span>
                            @else
                                in flight
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
