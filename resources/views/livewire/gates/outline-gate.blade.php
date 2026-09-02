<div>
    @if ($saved)
        <div class="alert ok">{{ $saved }}</div>
    @endif

    @if (! $this->editable())
        {{-- The reopen is offered only where it works. This block used to show
             the button at every locked status — nine of them — while
             `scripted -> outlined` is legal from one. --}}
        <div class="alert warn">
            The outline is locked &mdash; Gate 1 has been approved and the act scripts are written
            against it. Reopening is legal and explicit; the scripts do not disappear, but
            regenerating them costs money from Phase 2 onward.
            <div style="margin-top:8px">
                @if ($this->canReopen())
                    <button wire:click="reopen" wire:confirm="Reopen Gate 1? Scripts written against this outline stay on record.">
                        Reopen Gate 1
                    </button>
                @else
                    <div class="muted small">
                        Reopening Gate 1 is not available: {{ $this->reopenRefusal() }}
                    </div>
                @endif
            </div>
        </div>
    @endif

    <div class="panel">
        <div class="field">
            <label for="premise">Premise</label>
            <textarea id="premise" wire:model="premise" rows="4"
                      @disabled(! $this->editable())></textarea>
            @error('premise') <div class="error">{{ $message }}</div> @enderror
            <div class="muted small" style="margin-top:6px">
                Everything downstream is generated from this: the acts, then 5,500&ndash;8,000 words of
                script, then 150&ndash;250 stills. It is the cheapest thing here to change.
            </div>
        </div>
    </div>

    {{--
        The genre spine. Editable here because Gate 1 is the only place it can
        be fixed cheaply: every act-generation call reads these four fields off
        the story, so a cartoon antagonist here becomes 5,500-8,000 words of
        cartoon antagonist and the cost of finding out is the whole pipeline.
    --}}
    <h2>The spine</h2>
    <p class="muted small" style="margin-top:-6px">
        The structure this genre runs on. Everything below is generated against it.
    </p>

    @if ($this->spineReview()['problems'])
        <div class="alert fail">
            <strong>The outline is missing part of its structure.</strong>
            <ul style="margin:6px 0 0 18px">
                @foreach ($this->spineReview()['problems'] as $problem)
                    <li>{{ $problem }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($this->spineReview()['warnings'])
        <div class="alert warn">
            <strong>Structural warnings.</strong>
            <ul style="margin:6px 0 0 18px">
                @foreach ($this->spineReview()['warnings'] as $warning)
                    <li>{{ $warning }}</li>
                @endforeach
            </ul>
            <div class="muted small" style="margin-top:6px">
                None of these block approval. They are the four ways this format is actually written
                wrong, and they are all cheaper to fix here than at Gate 3.
            </div>
        </div>
    @endif

    <div class="panel">
        @foreach ($this->spineReview()['spine'] as $key => $field)
            <div class="field">
                <label for="spine-{{ $key }}">
                    {{ $field['label'] }}
                    @if ($field['state'] === 'missing')
                        <span class="badge fail">missing</span>
                    @elseif ($field['state'] === 'thin')
                        <span class="badge warn">thin</span>
                    @elseif ($field['state'] === 'weak')
                        <span class="badge warn">check this</span>
                    @endif
                </label>
                <textarea id="spine-{{ $key }}" wire:model="spine.{{ $key }}" rows="3"
                          @disabled(! $this->editable())></textarea>
                @error("spine.$key") <div class="error">{{ $message }}</div> @enderror
            </div>
        @endforeach

        <div class="muted small">
            The antagonist's justification is the engine: the infuriating part is the excuse, not the
            villainy. The exposure is the payoff, and it needs witnesses &mdash; the same reveal in
            private is a different and much worse video.
        </div>
    </div>

    @if ($this->actsMissingRehooks())
        <div class="alert warn">
            <strong>{{ count($this->actsMissingRehooks()) }} act(s) have no re-hook written.</strong>
            <div class="muted small" style="margin-top:4px">
                A 15-second opening hook is not enough over 35 minutes. Every act after the first has to
                open with a line that carries the viewer forward, or the retention graph falls off at
                the act boundary &mdash; which is also exactly where a chapter marker invites them to leave.
            </div>
        </div>
    @endif

    <h2>Acts &mdash; {{ count($acts) }}</h2>
    <p class="muted small" style="margin-top:-6px">
        Each act is one unit of script generation and one YouTube chapter. The title does both jobs.
    </p>

    @foreach ($acts as $i => $act)
        <div class="panel">
            <div class="row" style="margin-bottom:10px">
                <span class="badge">Act {{ $act['sequence'] }}</span>
                @if ($act['sequence'] === 1)
                    <span class="badge run" title="Chapter 1 must start at 00:00">opens the video</span>
                @endif
                <span class="right muted mono small">chapter title, {{ mb_strlen($act['title']) }}/100</span>
            </div>

            <div class="field">
                <label for="act-title-{{ $i }}">Title</label>
                <input id="act-title-{{ $i }}" type="text" wire:model="acts.{{ $i }}.title"
                       @disabled(! $this->editable())>
                @error("acts.$i.title") <div class="error">{{ $message }}</div> @enderror
            </div>

            <div class="field">
                <label for="act-summary-{{ $i }}">Summary</label>
                <textarea id="act-summary-{{ $i }}" wire:model="acts.{{ $i }}.summary" rows="3"
                          @disabled(! $this->editable())></textarea>
                <div class="muted small" style="margin-top:6px">
                    Fed to the next act's generation call. This is the mechanism that stops 7,000 words
                    drifting, repeating, or contradicting themselves.
                </div>
            </div>

            <div class="field">
                <label for="act-beat-{{ $i }}">
                    Escalation beat &mdash; what this act costs the narrator
                </label>
                <textarea id="act-beat-{{ $i }}" wire:model="acts.{{ $i }}.escalation_beat" rows="2"
                          @disabled(! $this->editable())></textarea>
                <div class="muted small" style="margin-top:6px">
                    Every act has to cost more than the one before it, and none of them resolves
                    anything before the exposure. An act where the narrator wins a round has spent the
                    tension the rest of the video runs on.
                </div>
                @error("acts.$i.escalation_beat") <div class="error">{{ $message }}</div> @enderror
            </div>

            @if ($act['sequence'] > 1)
                <div class="checks">
                    <label>
                        <input type="checkbox" wire:model="acts.{{ $i }}.is_rehook_written"
                               @disabled(! $this->editable())>
                        <span>Re-hook written &mdash; this act opens with a line engineered to carry the viewer forward</span>
                    </label>
                </div>
            @endif
        </div>
    @endforeach

    @if ($this->editable())
        <div class="row">
            <button wire:click="save">Save outline</button>

            @if ($this->canApprove())
                <button class="gate" wire:click="approve"
                        wire:confirm="Approve Gate 1? Act scripts get generated against this outline.">
                    Approve Gate 1 &mdash; outline is right
                </button>
            @else
                <span class="muted small">Save once to move the story to <span class="mono">outlined</span>, then approve.</span>
            @endif
        </div>
    @endif
</div>
