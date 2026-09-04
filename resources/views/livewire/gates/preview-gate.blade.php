<div>
    @if ($notice)
        <div class="alert ok wide">{{ $notice }}</div>
    @endif

    @if ($problem)
        <div class="alert err pre-line wide">{{ $problem }}</div>
    @endif

    @php($facts = $this->renderFacts())
    @php($phase = $this->phase())
    @php($artifact = $this->artifact())
    @php($mux = $this->muxState())

    {{--
        A FILE THAT SHOULD BE THERE AND IS NOT LEADS THE PAGE.

        This is the state the mock folds into "still rendering" and it is not
        that: the mux row says the stage finished, or the status is past the
        render, and the artifact is absent. Rows reporting success over a
        missing file is the exact table this project keeps, and the worst
        version of it would be this page sending the operator to watch progress
        that completed hours ago — a second surface agreeing with the first.

        It is an alert and it is first, because a failure is not a quiet state.
    --}}
    @if ($artifact === 'missing')
        <div class="alert fail wide">
            <div class="measure">
                <strong>The story says it rendered and the video is not on disk.</strong>
                Nothing is in flight &mdash; the mux stage is
                <span class="mono">{{ $mux['label'] }}</span>, so this is not a render still running.
                The workspace copy is what Gate 3's player, the purge guard and re-render idempotency
                all read, so a re-render is the way back rather than a re-copy.
            </div>
        </div>
    @endif

    {{--
        A failed mux, said as a failure.

        The mock paints EXIT 0 beside the log unconditionally. The badge below
        comes from the row; this alert is what a badge alone cannot do, which is
        put the error where somebody reading top-down will hit it.
    --}}
    @if ($mux['status'] === \App\Enums\RenderJobStatus::Failed)
        <div class="alert fail wide">
            <div class="measure">
                <strong>The mux failed.</strong>
                {{ $mux['error'] ?: 'The row records a failure with no message.' }}
            </div>
        </div>
    @endif

    {{--
        In flight. A banner rather than a panel, because it is a state of the
        whole page and not one of its parts.

        The count is out of the SCENES, never out of the rows. `RenderJob::open()`
        runs inside the job, so a scene still queued has no row — story 21 read
        "118 done, nothing failed" with 152 scenes sitting in Redis and nothing
        listening. Scenes with no row are named as unseen rather than folded into
        either side.
    --}}
    @if ($phase === 'running')
        @php($clips = $this->clipProgress())

        {{-- The NEUTRAL alert, deliberately. Nothing is wrong while a render is in
             flight, and this console reserves its status colours for state that
             wants a reaction. The run colour is present where it means
             something — the progress bar's own fill. --}}
        <div class="alert wide">
            <div class="row">
                <div class="grow measure">
                    <strong>No finished render for this story yet &mdash;
                    {{ $clips['total'] }} clips are encoding.</strong>
                    <div class="small mt-1">
                        A render is tens of minutes. Nothing on this page polls for it; progress is on
                        the <a href="{{ route('renders.show', $story->slug) }}">render page</a>, which does.
                    </div>
                </div>
                <div class="tr">
                    <div class="bar" style="width:220px">
                        <span class="busy" style="width:{{ $clips['percent'] }}%"></span>
                    </div>
                    <div class="mono small mt-1">{{ $clips['done'] }} / {{ $clips['total'] }} clips</div>
                </div>
            </div>

            @if ($clips['unrecorded'] > 0)
                {{-- Said out loud. A queued scene has no row, so this many are
                     neither done nor failed — they are unseen, and reporting
                     them as anything else is the false success this counter
                     exists to avoid. --}}
                <div class="small mt-2">
                    <span class="mono">{{ $clips['unrecorded'] }}</span> scene(s) have no clip row at all
                    yet. A job writes its row when it starts, so those are queued and unseen rather than
                    done or failed &mdash; which is why the denominator here is the scene count and not
                    the row count.
                </div>
            @endif
        </div>
    @endif

    {{--
        The body is a function of state.

        `ahead` and `behind` are the two states the mock does not draw, and
        between them they are where most stories are. Neither has a decision at
        this gate, so neither gets the room a decision needs: the strip says
        where the story is, the player and the facts stay in full, and only the
        approve/send-back pair goes.
    --}}
    @php($quiet = in_array($phase, ['ahead', 'behind'], true))

    @if ($quiet)
        <div class="strip">
            {{-- THE ONLY ONE OF THE FOUR THAT WAS RIGHT, and it was right by
                 hand: `phase()` is rank-based, so both sentences were true
                 wherever they rendered. It is the mechanism now for the reason
                 GateVoice exists at all — one instance fixed by hand is not a
                 mechanism, and the other three each got this wrong in their own
                 way. The wording is unchanged, because this is the wording the
                 shared clause was written from. --}}
            @if ($phase === 'behind')
                <span><strong>{{ $this->voice()->standing() }}</strong> The render was watched and
                      approved; the file below is the one that was signed off.</span>
            @else
                <span><strong>{{ $this->voice()->standing() }}</strong> There is no render to watch
                      yet, and no earlier one for this story either.</span>
            @endif

            <span class="badge {{ $mux['tone'] }}">mux {{ $mux['label'] }}</span>

            <a href="{{ route('renders.show', $story->slug) }}">Render progress</a>

            <details class="why">
                <summary>What can still happen here</summary>
                <div class="small muted mt-2">
                    The story is at <span class="mono">{{ $story->status->value }}</span>.
                    @if ($this->dispatchRefusal())
                        Re-rendering is not available: {{ $this->dispatchRefusal() }}
                    @else
                        A re-render is still legal from here and costs CPU, not money &mdash; the panel
                        below dispatches it.
                    @endif
                </div>
            </details>
        </div>
    @endif

    <div class="previewcols">
        <div class="col">
            {{-- The player. Kept in every state that has a file, including past
                 the gate: watching the render does not stop being useful once
                 it has been approved, and only the DECISION collapses. --}}
            @if ($artifact === 'ready')
                <div class="panel flush">
                    {{-- Range requests are served, so scrubbing through 35 minutes
                         does not re-download the file on every seek. --}}
                    <video controls preload="metadata" src="{{ route('stories.video', $story) }}"></video>

                    {{-- The act boundaries, as their own rail under the player.
                         The mock draws them on the scrubber; nothing can draw on
                         a native <video controls> scrubber, and swapping in a
                         custom player to gain seven ticks would put this gate's
                         one job behind a pile of JavaScript. --}}
                    @if ($this->chapterMarks())
                        <div class="actsrail" title="Act boundaries — where the chapters come from">
                            @foreach ($this->chapterMarks() as $mark)
                                <span class="tick" style="left:{{ $mark['percent'] }}%"
                                      title="{{ $mark['timestamp'] }} — {{ $mark['title'] }}"></span>
                            @endforeach
                        </div>
                    @endif

                    <p class="muted small pad">
                        The amber marks are the act boundaries the chapters come from. They are under the
                        player rather than on the scrubber because a native player has no scrubber to
                        draw on, and this gate's one job is watching the file.
                    </p>
                </div>
            @elseif ($artifact === 'none')
                <div class="panel">
                    <div class="muted">
                        <strong>No file to watch yet.</strong>
                        <div class="small mt-2">
                            There is no earlier render for this story either, so there is nothing here to
                            show in the meantime. The player appears when the mux writes the file.
                        </div>
                    </div>
                </div>
            @endif

            {{-- The facts. Beside the player rather than under it, because "does
                 it play" is not the only question at this gate — the operator is
                 also checking the numbers match what they expected to sit
                 through. --}}
            <div class="panel">
                @php($window = $this->windowBar())

                <div class="facts">
                    <div>
                        <label>Runtime</label>
                        <div class="big mono">{{ $facts['duration_human'] }}</div>
                    </div>

                    <div class="span2">
                        <label>Target window &mdash; {{ $story->target_duration_min }} to {{ $story->target_duration_max }} min</label>
                        {{-- The axis is derived from this story's own window, so
                             a 2:42 fixture and a 40:36 video are both on a scale
                             that can hold them. The marker is clamped; the
                             distance below is what stops a clamp reading as
                             "just outside" for anything from 22 seconds to 27
                             minutes. --}}
                        <div class="windowbar">
                            <span class="axis"></span>
                            <span class="zone"
                                  style="left:{{ $window['band_start'] }}%;width:{{ $window['band_end'] - $window['band_start'] }}%"></span>
                            <span class="at {{ $window['tone'] }}" style="left:{{ $window['percent'] }}%"></span>
                        </div>
                        <span class="badge {{ $window['tone'] }}">{{ $window['label'] }}</span>
                        @if ($window['distance'])
                            <span class="mono small">{{ $window['distance'] }}</span>
                        @endif
                    </div>

                    <div>
                        <label>Scenes / acts</label>
                        <div class="big mono">{{ $facts['scenes'] }} / {{ $facts['acts'] }}</div>
                    </div>

                    <div>
                        <label>File</label>
                        <div class="big mono">
                            {{ $facts['exists'] ? number_format($facts['bytes'] / 1048576, 1).' MB' : '—' }}
                        </div>
                        <div class="muted small mono">
                            {{ $facts['rendered_at']?->diffForHumans() ?? 'not written yet' }}
                        </div>
                    </div>
                </div>

                <div class="muted small mt-4 measure">
                    Watch time drives the revenue in this niche, and over eight minutes is what makes
                    mid-roll ads possible at all. The floor is a preference, not a rule: nothing here
                    refuses a short render, it is reported and the call is yours.
                </div>
            </div>

            {{-- The mux log, with a badge that comes from the row. --}}
            @if ($mux['log'] || $mux['status'])
                <div class="panel flush">
                    <div class="panelhead">
                        <h2>Mux log</h2>
                        <span class="muted small">the last operation, verbatim</span>
                        <span class="badge {{ $mux['tone'] }} right">{{ $mux['label'] }}</span>
                    </div>
                    <pre class="sheet">{{ $mux['log'] ?: 'The row carries no log.' }}</pre>
                </div>
            @endif
        </div>

        <div class="col">
            {{-- Chapters, from the act timings the render produced. --}}
            <div class="panel flush">
                <div class="panelhead">
                    <h2>Chapters</h2>
                    <span class="muted small">from the act timings the render produced</span>
                </div>
                @if ($this->chapters())
                    <table>
                        <thead><tr><th style="width:82px">Start</th><th>Act title</th></tr></thead>
                        <tbody>
                        @foreach ($this->chapters() as $chapter)
                            <tr>
                                <td class="mono">{{ $chapter['timestamp'] }}</td>
                                <td>{{ $chapter['title'] }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                @else
                    <p class="muted small pad">
                        No act timings. The concat stage fills these in, and Gate 4 cannot draft a chapter
                        list until it has.
                    </p>
                @endif
            </div>

            {{-- The render. Outside the "is there a file" branch, because
                 re-rendering a story that already HAS a file is a real move —
                 it is what "send it back" does — so a control that only
                 appeared when the file was missing would be missing exactly
                 when it was needed. --}}
            <div class="panel">
                <div class="panelhead">
                    <h2>The render</h2>
                    <span class="badge {{ $this->workers()['state'] === \App\Support\WorkerHealth::OK ? 'ok' : 'warn' }} right">
                        {{ $this->workers()['state'] }}
                    </span>
                </div>

                <div class="muted small measure">
                    CPU, not money. Tens of minutes for 30&ndash;40 minutes of video, fanned out across
                    the render workers, then concat, subtitles and mux chained off the batch completion
                    callback. Nothing polls and nothing re-renders on its own.
                </div>

                <x-worker-health :queues="[$this->workers()]" :compact="true" />

                @if ($this->dispatchRefusal())
                    {{-- Rendered, never swallowed. A panel that simply vanishes when an
                         action is unavailable says nothing where it should say why —
                         the same defect as a form with no producer. --}}
                    <div class="alert warn wide mt-4">
                        <strong>The render cannot be dispatched from here.</strong>
                        {{ $this->dispatchRefusal() }}
                    </div>
                @elseif ($confirming === 'dispatch')
                    <div class="alert warn wide mt-4">
                        <strong>{{ $story->scenes()->count() }} scene clip(s)</strong>, then concat, subtitles and
                        a full re-encode at the mux. That is the longest single operation in the pipeline.
                        @if ($facts['exists'])
                            <br>This story already has a finished render. Dispatching again re-encodes over it.
                        @endif
                        <div class="mt-4">
                            <button type="button" class="primary" wire:click="dispatchRender">
                                Queue the render
                            </button>
                            <button type="button" wire:click="cancelConfirmation">Back</button>
                        </div>
                    </div>
                @else
                    <div class="actions mt-4">
                        <button type="button" class="primary" wire:click="askTo('dispatch')">
                            {{ $facts['exists'] ? 'Render again' : 'Dispatch the render' }}
                        </button>
                        <a href="{{ route('renders.show', $story->slug) }}" class="small">watch progress</a>
                    </div>
                @endif

                @if ($this->canCancelRender())
                    @if ($confirming === 'cancel')
                        <div class="alert err wide mt-4">
                            Cancelling marks the batch, and each job checks that before it starts. The one already
                            inside FFmpeg finishes; the rest never begin. Clips already encoded are kept &mdash;
                            scratch is what a re-run reuses.
                            <div class="mt-4">
                                <button type="button" class="danger" wire:click="cancelRender">
                                    Cancel the in-flight batch
                                </button>
                                <button type="button" wire:click="cancelConfirmation">Back</button>
                            </div>
                        </div>
                    @else
                        <div class="actions mt-4">
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

            {{-- The gate itself. Only rendered where it is the decision — past
                 it, the strip above says so instead of a disabled button pair
                 keeping the room a decision needs. --}}
            @unless ($quiet)
                <div class="panel gatecall">
                    <strong>Nothing automated stands in for this gate.</strong>
                    <p class="muted small measure">
                        The pipeline can prove the frame count is exact and the subtitles land to the
                        centisecond, and still hand back 38 minutes where a character changes face at
                        scene 90, or the narration reads as somebody else's country. Watch it.
                    </p>

                    @if ($this->canApprove())
                        <div class="actions">
                            <button class="gate" wire:click="approve"
                                    wire:confirm="Approve the render? The publish sheet is next.">
                                Approve Gate 3 &mdash; I watched it
                            </button>
                            <button class="danger" wire:click="reject"
                                    wire:confirm="Send this back and queue the re-render now? That is tens of minutes of CPU again.">
                                Send back and re-render
                            </button>
                        </div>
                        <div class="muted small mt-2">
                            Sending back queues the re-render immediately &mdash; tens of minutes of CPU
                            again, and no money. Approving opens the publish sheet.
                        </div>
                    @else
                        <div class="muted small mt-2">
                            @if ($artifact !== 'ready')
                                There is no file to watch. The button turns on when the mux writes it &mdash;
                                approving a render nobody has seen is the one thing this gate exists to
                                prevent.
                            @else
                                This story is <span class="mono">{{ $story->status->value }}</span>, so Gate 3
                                is not the decision in front of you.
                            @endif
                        </div>
                    @endif
                </div>
            @endunless
        </div>
    </div>
</div>
