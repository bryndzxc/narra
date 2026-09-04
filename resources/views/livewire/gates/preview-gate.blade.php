<div>
    @if ($notice)
        <div class="alert ok">{{ $notice }}</div>
    @endif

    @if ($problem)
        <div class="alert err" style="white-space:pre-line">{{ $problem }}</div>
    @endif

    @php($facts = $this->renderFacts())

    {{-- The dispatch panel, above the player and outside the "is there a file"
         branch. Re-rendering a story that already HAS a file is a real move —
         it is what "send it back" does — so a control that only appeared when
         the file was missing would be missing exactly when it was needed. --}}
    <div class="panel">
        <div class="row">
            <div class="grow">
                <label>The render</label>
                <div class="muted small">
                    CPU, not money. Tens of minutes for 30&ndash;40 minutes of video, fanned out across
                    the render workers, then concat, subtitles and mux chained off the batch completion
                    callback. Nothing polls and nothing re-renders on its own.
                </div>
            </div>
        </div>

        <x-worker-health :queues="[$this->workers()]" :compact="true" />

        @if ($this->dispatchRefusal())
            {{-- Rendered, never swallowed. A panel that simply vanishes when an
                 action is unavailable says nothing where it should say why —
                 the same defect as a form with no producer. --}}
            <div class="alert warn" style="margin-top:10px">
                <strong>The render cannot be dispatched from here.</strong>
                {{ $this->dispatchRefusal() }}
            </div>
        @elseif ($confirming === 'dispatch')
            <div class="alert warn" style="margin-top:10px">
                <strong>{{ $story->scenes()->count() }} scene clip(s)</strong>, then concat, subtitles and
                a full re-encode at the mux. That is the longest single operation in the pipeline.
                @if ($facts['exists'])
                    <br>This story already has a finished render. Dispatching again re-encodes over it.
                @endif
                <div style="margin-top:10px">
                    <button type="button" class="primary" wire:click="dispatchRender">
                        Queue the render
                    </button>
                    <button type="button" wire:click="cancelConfirmation">Back</button>
                </div>
            </div>
        @else
            <div class="actions" style="margin-top:10px">
                <button type="button" class="primary" wire:click="askTo('dispatch')">
                    {{ $facts['exists'] ? 'Render again' : 'Dispatch the render' }}
                </button>
                <a href="{{ route('renders.show', $story->slug) }}" class="small">watch progress</a>
            </div>
        @endif

        @if ($this->canCancelRender())
            @if ($confirming === 'cancel')
                <div class="alert err" style="margin-top:10px">
                    Cancelling marks the batch, and each job checks that before it starts. The one already
                    inside FFmpeg finishes; the rest never begin. Clips already encoded are kept &mdash;
                    scratch is what a re-run reuses.
                    <div style="margin-top:10px">
                        <button type="button" class="danger" wire:click="cancelRender">
                            Cancel the in-flight batch
                        </button>
                        <button type="button" wire:click="cancelConfirmation">Back</button>
                    </div>
                </div>
            @else
                <div class="actions" style="margin-top:10px">
                    <button type="button" class="danger" wire:click="askTo('cancel')">
                        Cancel the in-flight batch
                    </button>
                    <span class="muted small">
                        Lands the story where a re-run starts from. This used to be the one way out of a
                        stuck render, and it was a terminal command.
                    </span>
                </div>
            @endif
        @endif
    </div>

    @if (! $facts['exists'])
        <div class="alert warn">
            No finished render for this story yet.
            <div class="small" style="margin-top:6px">
                Progress shows on the <a href="{{ route('renders.show', $story->slug) }}">render page</a>.
                A render is tens of minutes; nothing here polls for it.
            </div>
        </div>
    @else
        <div class="panel">
            {{-- Range requests are served, so scrubbing through 35 minutes does
                 not re-download the file on every seek. --}}
            <video controls preload="metadata"
                   src="{{ route('stories.video', $story) }}"></video>
        </div>

        <div class="panel">
            <div class="row">
                <div>
                    <label>Runtime</label>
                    <div class="mono">{{ $facts['duration_human'] }}</div>
                </div>
                <div>
                    <label>Target window</label>
                    <div class="mono">
                        {{ $story->target_duration_min }}&ndash;{{ $story->target_duration_max }} min
                        @if ($facts['in_target_window'])
                            <span class="badge ok">in window</span>
                        @else
                            <span class="badge warn">outside</span>
                        @endif
                    </div>
                </div>
                <div>
                    <label>Scenes / acts</label>
                    <div class="mono">{{ $facts['scenes'] }} / {{ $facts['acts'] }}</div>
                </div>
                <div>
                    <label>File</label>
                    <div class="mono">{{ number_format($facts['bytes'] / 1048576, 1) }} MB</div>
                </div>
                <div>
                    <label>Rendered</label>
                    <div class="mono">{{ $facts['rendered_at']?->diffForHumans() ?? '&mdash;' }}</div>
                </div>
            </div>

            @if ($facts['mux_log'])
                <pre class="sheet" style="margin-top:12px">{{ $facts['mux_log'] }}</pre>
            @endif

            @unless ($facts['in_target_window'])
                <div class="muted small" style="margin-top:10px">
                    Outside the 30&ndash;40 minute window is fine for a fixture run. For a real video it
                    matters: watch time drives the revenue in this niche, and over eight minutes is what
                    makes mid-roll ads possible at all.
                </div>
            @endunless
        </div>

        <h2>Chapters &mdash; from the act timings the render produced</h2>
        <div class="panel" style="padding:0">
            <table>
                <thead><tr><th style="width:110px">Start</th><th>Act title</th></tr></thead>
                <tbody>
                @forelse ($this->chapters() as $chapter)
                    <tr>
                        <td class="mono">{{ $chapter['timestamp'] }}</td>
                        <td>{{ $chapter['title'] }}</td>
                    </tr>
                @empty
                    <tr><td colspan="2" class="muted">No act timings. The concat stage fills these in.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>

        <div class="panel">
            <p class="muted small" style="margin-top:0">
                Nothing automated stands in for this gate. The pipeline can prove the frame count is exact
                and the subtitles land to the centisecond and still hand back 35 minutes where a character
                changes face at scene 90, or the narration reads as somebody else's country. Watch it.
            </p>

            <div class="row">
                @if ($this->canApprove())
                    <button class="gate" wire:click="approve"
                            wire:confirm="Approve the render? The publish sheet is next.">
                        Approve Gate 3 &mdash; I watched it
                    </button>
                    <button class="danger" wire:click="reject"
                            wire:confirm="Send this back and queue the re-render now? That is tens of minutes of CPU again.">
                        Send back and re-render
                    </button>
                @else
                    <span class="muted small">
                        This story is <span class="mono">{{ $story->status->value }}</span>, so Gate 3 is not
                        the decision in front of you.
                    </span>
                @endif
            </div>
        </div>
    @endif
</div>
