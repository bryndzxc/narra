<div>
    @if ($notice)
        <div class="alert ok">{{ $notice }}</div>
    @endif

    @php($facts = $this->renderFacts())

    @if (! $facts['exists'])
        <div class="alert warn">
            No finished render for this story yet.
            <div class="mono small" style="margin-top:6px">php artisan render:dispatch {{ $story->slug }}</div>
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
                            wire:confirm="Send this back for a re-render? That is tens of minutes of CPU again.">
                        Send back for a re-render
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
