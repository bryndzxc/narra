<div>
    @if ($saved)
        <div class="alert ok">{{ $saved }}</div>
    @endif

    @if (! $this->editable())
        <div class="alert warn">
            The outline is locked &mdash; Gate 1 has been approved and the act scripts are written
            against it. Reopening is legal and explicit; the scripts do not disappear, but
            regenerating them costs money from Phase 2 onward.
            <div style="margin-top:8px">
                <button wire:click="reopen" wire:confirm="Reopen Gate 1? Scripts written against this outline stay on record.">
                    Reopen Gate 1
                </button>
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
