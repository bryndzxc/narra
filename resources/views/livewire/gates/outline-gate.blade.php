<div>
    @if ($saved)
        <div class="alert ok">{{ $saved }}</div>
    @endif

    @if ($problem)
        <div class="alert err" style="white-space:pre-line">{{ $problem }}</div>
    @endif

    {{-- The writing panel. This stage was `story:write` and nothing else for
         the whole of Phase 2 — the one part of the pipeline with no way into it
         except a terminal, on the page where its output is reviewed. --}}
    @if ($this->canWrite())
        @php($estimate = $this->writeEstimate())
        @php($missing = $this->unwrittenActs())

        @if ($estimate['calls'] > 0)
            <div class="panel money">
                <label>{{ $estimate['outline'] ? 'Write the outline and the act scripts' : 'Finish the act scripts' }}</label>

                <div class="muted small" style="margin-top:4px; max-width:78ch">
                    @if ($estimate['outline'])
                        Nothing has been written yet. The outline comes first, then every act in order —
                        each one written knowing the ones before it, because a single call cannot hold
                        7,000 words of coherent narrative without drifting or contradicting itself.
                    @else
                        {{-- The resume case, and the reason this is not a bare
                             "regenerate". Six sequential calls that die on act 4
                             have already billed three. --}}
                        Act(s) <span class="mono">{{ implode(', ', $missing) }}</span> have no script.
                        Everything already written is kept — only the empty acts are queued, so a run that
                        died partway does not cost the whole story again.
                    @endif
                </div>

                <x-worker-health :queues="[$this->workers()]" :compact="true" />

                <table style="margin-top:10px">
                    <tbody>
                    <tr>
                        <td>Billed calls</td>
                        <td class="mono">{{ $estimate['calls'] }}</td>
                        <td class="muted small">One cost row each. Text only — no image, no audio.</td>
                    </tr>
                    @foreach ($estimate['roster'] as $line)
                        <tr><td colspan="3" class="mono small muted">{{ $line }}</td></tr>
                    @endforeach
                    </tbody>
                </table>

                @if ($confirmingWrite)
                    <div class="alert warn" style="margin-top:10px">
                        <strong>{{ $estimate['calls'] }} billed call(s)</strong> queued on the
                        <span class="mono">{{ $this->workers()['queue'] }}</span> queue.
                        @if ($this->workers()['state'] === \App\Support\WorkerHealth::ABSENT)
                            Nothing is listening on it right now — the jobs will wait and nothing is lost,
                            but nothing happens until a worker starts.
                        @endif
                        <div style="margin-top:10px">
                            <button type="button" class="primary" wire:click="write">
                                Queue it — {{ $estimate['calls'] }} call(s)
                            </button>
                            <button type="button" wire:click="cancelWrite">Back</button>
                        </div>
                    </div>
                @else
                    <div class="actions" style="margin-top:10px">
                        <button type="button" class="primary" wire:click="askToWrite">
                            {{ $estimate['outline'] ? 'Write this story' : 'Write the missing act(s)' }}
                        </button>
                        <span class="muted small">Shows the bill first. Nothing is queued by this press.</span>
                    </div>
                @endif
            </div>
        @endif
    @elseif ($this->writeRefusal())
        {{-- Said, not swallowed. A panel that disappears when an action is
             unavailable is a page saying nothing where it should say why. --}}
        <div class="alert warn">
            <strong>The script cannot be written from here.</strong> {{ $this->writeRefusal() }}
        </div>
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
        {{-- The setting, read-only. Chosen at creation, and every act on this
             page was written against it — so it is shown rather than edited. --}}
        <div class="muted small" style="margin-bottom:12px">
            Setting: <strong>{{ $this->localeLabel() }}</strong>
            &middot; fixed at creation, because the outline and the acts were generated against it.
        </div>

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

        {{-- Read once, by the character extraction that runs when the scene draft
             is dispatched from Gate 2 — so this gate is the last place it is free
             to state. After that, changing it means reopening Gate 1 and
             re-extracting, which rewrites every description the scene prompts
             were built from. --}}
        <div class="field" style="margin-top:16px">
            <label for="cast-age">
                Cast age range
                <span class="muted small">optional</span>
            </label>
            <textarea id="cast-age" wire:model="castAgeProfile" rows="2"
                      placeholder="Spouses in their late twenties and thirties. Workplace and marriage settings. No elderly characters carrying plot."
                      @disabled(! $this->editable())></textarea>
            @error('castAgeProfile') <div class="error">{{ $message }}</div> @enderror
            <div class="muted small" style="margin-top:6px">
                Steers the ages the script does not state outright. Where the script does state one,
                the script wins &mdash; a picture that contradicts the narration is worse than one
                outside the intended range. The art style cannot carry this: one style line is shared
                by every story, so it can describe how age is drawn but never who is in this one.
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
        The structure this genre runs on. Everything below is generated against it. The first four
        say how the narrator is wronged and where it comes out; the last three say that they leave,
        that they are searched for, and what they say when they are found.
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

    {{-- Wrong for the setting, but with a legitimate reading, so the stage was
         paid for and kept. This was computed for two phases and printed only by
         `story:write` — the one place it could be acted on was a terminal. --}}
    @if ($this->localeWarnings())
        <div class="alert warn">
            <strong>{{ count($this->localeWarnings()) }} term(s) read wrong for {{ $this->localeLabel() }}.</strong>
            <ul style="margin:6px 0 0 18px">
                @foreach ($this->localeWarnings() as $hit)
                    <li>
                        Act {{ $hit['act'] }} &mdash; <code>{{ $hit['term'] }}</code>
                        <span class="muted small">&hellip;{{ $hit['context'] }}&hellip;</span>
                    </li>
                @endforeach
            </ul>
            <div class="muted small" style="margin-top:6px">
                None of these block anything. Each has a legitimate reading, which is why the stage
                was not failed &mdash; the unambiguous terms are refused before an act is ever
                stored. Judging these is yours.
            </div>
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
                None of these block approval. They are the ways this format is actually written wrong,
                and they are all cheaper to fix here than at Gate 3.
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
                    @elseif ($field['state'] === 'absent')
                        {{-- Not "missing". This outline predates the field, which is a
                             different thing from an outline that should have one and
                             does not, and the warning above says so once. --}}
                        <span class="badge">not in this outline</span>
                    @endif
                    @if (! empty($field['answers']))
                        {{-- Which earlier moment the refusal reaches back to. Shown
                             because "it answers something" is worth less at Gate 1
                             than "it answers act 3". --}}
                        <span class="badge run">answers {{ $field['answers'] }}</span>
                    @endif
                </label>
                <textarea id="spine-{{ $key }}" wire:model="spine.{{ $key }}" rows="3"
                          @disabled(! $this->editable())></textarea>
                @error("spine.$key") <div class="error">{{ $message }}</div> @enderror
            </div>
        @endforeach

        <div class="muted small">
            The antagonist's justification is the engine: the infuriating part is the excuse, not the
            villainy. The exposure is the public payoff and it needs witnesses &mdash; the same reveal
            in private is a different and much worse video. The refusal is the private one, and it is
            what viewers wait forty minutes for: the narrator hands back a sentence that was used on
            them earlier, in the same words. Between the two, the narrator has to LEAVE without
            announcing it &mdash; an announced departure cannot be searched for, and the search is
            the next third of the video.
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
        The phase is the act structure and is not editable here: escalation through roughly the first
        two thirds, then the departure, then the search and the refusal. Changing one act's phase
        without the ones around it gives the outline two departures or none, so a wrong structure is
        fixed by re-generating the outline.
    </p>

    @foreach ($acts as $i => $act)
        <div class="panel">
            <div class="row" style="margin-bottom:10px">
                <span class="badge">Act {{ $act['sequence'] }}</span>
                @if ($act['phase'])
                    {{-- The direction of the act, and the one thing the script
                         generator branches on. An escalation act ends worse for
                         the narrator; a search act ends worse for the antagonist. --}}
                    <span class="badge {{ in_array($act['phase'], ['search', 'refusal'], true) ? 'ok' : '' }}"
                          title="{{ $act['beat_label'] }}">{{ $act['phase_label'] }}</span>
                @endif
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
                    {{ $act['beat_label'] }}
                </label>
                <textarea id="act-beat-{{ $i }}" wire:model="acts.{{ $i }}.escalation_beat" rows="2"
                          @disabled(! $this->editable())></textarea>
                <div class="muted small" style="margin-top:6px">
                    @if (in_array($act['phase'], ['search', 'refusal'], true))
                        The narrator is already gone, so the cost runs the other way: each attempt has
                        to take more from the antagonist than the last &mdash; money, standing, the
                        people who found her excuse reasonable. A search that costs her nothing is a
                        montage, and the refusals it leads to are unearned.
                    @else
                        Every act has to cost more than the one before it, and none of them resolves
                        anything before the exposure. An act where the narrator wins a round has spent
                        the tension the rest of the video runs on.
                    @endif
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
