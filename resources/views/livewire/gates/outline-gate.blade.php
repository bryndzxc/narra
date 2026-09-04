<div>
    @if ($saved)
        <div class="alert ok wide">{{ $saved }}</div>
    @endif

    @if ($problem)
        <div class="alert err pre-line wide">{{ $problem }}</div>
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

                <div class="muted small mt-1" style="max-width:78ch">
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

                <table class="mt-4">
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
                    <div class="alert warn wide mt-4">
                        <strong>{{ $estimate['calls'] }} billed call(s)</strong> queued on the
                        <span class="mono">{{ $this->workers()['queue'] }}</span> queue.
                        @if ($this->workers()['state'] === \App\Support\WorkerHealth::ABSENT)
                            Nothing is listening on it right now — the jobs will wait and nothing is lost,
                            but nothing happens until a worker starts.
                        @endif
                        <div class="mt-4">
                            <button type="button" class="primary" wire:click="write">
                                Queue it — {{ $estimate['calls'] }} call(s)
                            </button>
                            <button type="button" wire:click="cancelWrite">Back</button>
                        </div>
                    </div>
                @else
                    <div class="actions mt-4">
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
        <div class="alert warn wide">
            <strong>The script cannot be written from here.</strong> {{ $this->writeRefusal() }}
        </div>
    @endif

    {{--
        THE RATE THE SCRIPT IS SIZED TO, AND WHERE THE NUMBER CAME FROM.

        THE GAP. The word target moved from the fallback 160 to the measured 197
        and `sized_against_wpm` freezes whatever each story was written to — so
        story 9 has a 5,600-word target and a story written today has 6,895, and
        nothing on any page said why. A figure that is right and unexplained
        reads as a figure that is wrong, and two of them side by side read as a
        bug somebody should go and find.

        It sits directly under the money panel because that is where the target
        is DECIDED: the panel above quotes the bill, this says what the bill
        buys and at what rate, before it is authorised.

        THREE STATES, AND UNKNOWN IS NOT HIDDEN. The column is nullable so that
        "nobody recorded this" and "this was 160" cannot be the same value, and
        a page that resolved a null to today's rate — or that quietly dropped
        the row — would put that distinction straight back. A script with no
        rate on record says so, and prints NO target: computing one from today's
        rate and setting it beside the written word count would compare a script
        against a budget it never had, which is the exact false comparison the
        column was added to prevent.

        The group elides only where there is nothing at all to say — nothing
        written and nothing writable. `sample-story` is that case and is parked
        at `rendered` permanently.
    --}}
    <x-gate-group>
        @if ($this->hasSizingToShow())
            @php($sizing = $this->sizing())

            <div class="panel">
                @if ($sizing['state'] === 'unknown')
                    <label>Script length &mdash; the rate it was sized to is not on record</label>
                @else
                    <label>Script length &mdash; sized at
                        <span class="mono">{{ $sizing['wpm'] }}</span> words per minute</label>
                @endif

                <div class="muted small mt-1 measure">
                    @if ($sizing['state'] === 'unknown')
                        <strong>Unknown, not assumed.</strong>
                        This script was written before the rate was recorded, so there is no target to
                        hold it to. Showing today's would compare it against a budget it was never
                        written to, which is the one thing this record exists to prevent.
                    @else
                        {{ $this->voice()->sizingFixed() }}

                        @if ($sizing['measured'])
                            {{-- The provenance is data, not a claim: NarrationPace
                                 knows whether this pair was measured and on what. --}}
                            Measured on
                            <span class="mono">{{ $sizing['voice'] }}</span>
                            reading <span class="mono">{{ $story->locale_profile }}</span>@if ($sizing['measured_on']),
                            {{ $sizing['measured_on'] }}@endif.
                        @else
                            <strong>Not measured.</strong> No narration by
                            <span class="mono">{{ $sizing['voice'] ?? 'this narrator' }}</span>
                            on <span class="mono">{{ $story->locale_profile }}</span> has been timed, so this
                            is the best rate on record for the setting rather than for the voice. The
                            first run establishes the real one.
                        @endif
                    @endif
                </div>

                <table class="mt-4">
                    <tbody>
                    @if ($sizing['target'] !== null)
                        <tr>
                            <td>Word target</td>
                            <td class="mono">{{ number_format($sizing['target']) }}</td>
                            <td class="muted small">
                                {{ number_format($sizing['per_act']) }} across {{ $sizing['acts'] }} act(s).
                            </td>
                        </tr>
                    @endif
                    @if ($sizing['written'] > 0)
                        <tr>
                            <td>Written</td>
                            <td class="mono">{{ number_format($sizing['written']) }}</td>
                            <td class="muted small">
                                @if ($sizing['target'] === null)
                                    No target on record to compare it against.
                                @else
                                    {{-- The gap between asked and written is generation
                                         variance and is nothing to do with the rate.
                                         Story 21 overshot its target by 44%. --}}
                                    {{ $sizing['written'] >= $sizing['target'] ? '+' : '' }}{{ number_format(($sizing['written'] - $sizing['target']) / max(1, $sizing['target']) * 100, 1) }}%
                                    against the target it was written to.
                                @endif
                            </td>
                        </tr>
                    @endif
                    <tr>
                        <td>{{ $sizing['projected'] ? 'Runtime if written to target' : 'Runtime' }}</td>
                        <td class="mono">{{ number_format($sizing['minutes'], 1) }} min</td>
                        <td class="muted small">
                            <span class="badge {{ $sizing['in_window'] ? 'ok' : 'warn' }}">
                                {{ $sizing['in_window'] ? 'in window' : 'outside the window' }}
                            </span>
                            {{ $story->target_duration_min }}&ndash;{{ $story->target_duration_max }} min.
                            {{-- Reported, never refused. The floor is a preference
                                 and the operator decides, the same split Gate 3
                                 keeps for the finished render. --}}
                            Reported, not enforced &mdash; the decision is yours.
                        </td>
                    </tr>

                    {{-- THE SECOND NUMBER. The row above is the design point —
                         what this runs to IF the writer hits the target. It
                         mostly does not: across five measured stories the fitted
                         response to the target is +0.30, so an act comes back at
                         ~1,100 words whatever it was asked for.

                         Both are shown, and the projection carries its own
                         provenance in the cell rather than in a footnote,
                         because one measured act is a projection and not a
                         forecast. Same reason `sized_against_wpm` is null rather
                         than 160 when nothing was recorded: a number without its
                         provenance is a number the next reader cannot weigh. --}}
                    @if ($sizing['projected'])
                        <tr>
                            <td>Projected runtime</td>
                            <td class="mono">{{ number_format($sizing['projected']['minutes'], 1) }} min</td>
                            <td class="muted small">
                                <span class="badge {{ $sizing['projected']['in_window'] ? 'ok' : 'warn' }}">
                                    {{ $sizing['projected']['in_window'] ? 'in window' : 'outside the window' }}
                                </span>
                                {{ number_format($sizing['projected']['words']) }} words at the
                                <span class="mono">{{ number_format($sizing['projected']['per_act']) }}</span>
                                words an act the writer actually returns.
                                <strong>A projection from one measured act</strong>@if ($sizing['projected']['measured_on'])
                                &mdash; {{ $sizing['projected']['measured_on'] }}@endif.
                            </td>
                        </tr>
                        <tr>
                            <td>Word target</td>
                            <td class="mono">advisory</td>
                            <td class="muted small">
                                The prompt states the target and the writer largely ignores it: the
                                fitted response across five stories is
                                <span class="mono">+{{ number_format($sizing['projected']['slope'], 2) }}</span>,
                                so a hundred more words asked buys about thirty.
                                <strong>The act count is the lever that moves runtime</strong>, because it
                                multiplies a length the prompt cannot argue with.
                            </td>
                        </tr>
                    @endif
                    </tbody>
                </table>
            </div>
        @endif
    </x-gate-group>

    {{--
        THE LAYOUT IS A FUNCTION OF STATE, NOT A CONSTANT.

        Drawn for the editable case — a write panel, a premise the operator is
        typing into, a spine to fix — and a story past Gate 1 has none of those.
        The busy layout with nothing in it is not a calm page: it is the same
        containers at the same size holding gaps, and empty ones compete with
        the one thing that can still be acted on.

        Nothing is dropped and nothing is quietened: the advisories keep their
        own alerts at full width, the outline stays readable in full, and the
        reopen — the one action that DOES exist here — stays a button on the
        line rather than going behind the disclosure.
    --}}
    @php($quiet = ! $this->canWrite() && ! $this->canApprove() && ! $this->editable())

    @if ($quiet)
        <div class="strip">
            {{-- Through the voice, though this one was already right. Gate 1 is
                 the only gate that CANNOT carry the position defect Gate 4
                 shipped: nothing precedes `draft`, so "not editable" and "past
                 this gate" are the same set here by accident of where it sits
                 in the lifecycle. That is a property of the lifecycle, not of
                 this template, and a hand-written sentence that is true for a
                 reason outside itself is one condition change from being the
                 same defect. --}}
            <span><strong>{{ $this->voice()->standing() }}</strong> The outline is read-only and the
                  act scripts were written against it.</span>

            @if ($this->canReopen())
                <button wire:click="reopen"
                        wire:confirm="Reopen Gate 1? Scripts written against this outline stay on record.">
                    Reopen Gate 1
                </button>
            @else
                <span>Reopening is not available: {{ $this->reopenRefusal() }}</span>
            @endif

            <details class="why">
                <summary>What reopening would cost</summary>
                <div class="small muted mt-2">
                    The scripts do not disappear and nothing is deleted. Every act written against
                    this outline stays on record, and regenerating them is a paid text call each —
                    which is why the reopen is explicit rather than implied by editing.
                </div>
            </details>
        </div>
    @endif

    @if (! $quiet && ! $this->editable())
        {{-- The reopen is offered only where it works. This block used to show
             the button at every locked status — nine of them — while
             `scripted -> outlined` is legal from one. --}}
        <div class="alert warn wide">
            {{-- `wide` is the surface; the prose keeps its own measure, or this
                 is one sentence across a 1770px viewport. --}}
            <div class="measure">
            {{-- Through the voice for the same reason as the strip above: this
                 sentence is right wherever it renders, and it is right because
                 of where Gate 1 sits in the lifecycle rather than because of
                 anything in this file. --}}
            The outline is locked &mdash; {{ $this->voice()->approved() }} and the act scripts are
            written against it. Reopening is legal and explicit; the scripts do not disappear, but
            regenerating them costs money from Phase 2 onward.
            </div>
            <div class="mt-3">
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

    {{--
        THE THREE ADVISORY GROUPS LEAD, AND THEY ARE A ROW.

        Spine problems, locale terms and structural warnings, side by side,
        above the premise. They were stacked BELOW the premise panel and under
        the spine heading, so the reader met a 4-row textarea before the two
        things that are cheap to fix at this gate and expensive at Gate 3.

        `x-gate-row` and `x-gate-group`, never a hand-written `.gatecols` with
        hand-written wrappers. A story routinely has findings in two of the
        three, and a fixed row still took a `1fr` track for the empty one and
        pushed the other two right — `.dash.quiet`'s defect at the width of a
        column. A group with an empty slot renders no element and the grid cuts
        no track for it, so the void is unreachable rather than something an
        author remembers to prevent. The row's own condition is gone with it: it
        was a hand-written restatement of the three inside it, and a fourth
        group would have made the copies disagree.

        Every clause here that names an action comes from GateVoice. On a story
        at `scenes_drafted` these three panels offered three decisions that do
        not exist — "cheaper to fix here", "judging these is yours" — one screen
        away from a strip saying reopening is not available.
    --}}
    <x-gate-row>
        <x-gate-group>
            @if ($this->spineReview()['problems'])
                <div class="alert fail wide">
                    <strong>The outline is missing part of its structure.</strong>
                    <ul class="small indent">
                        @foreach ($this->spineReview()['problems'] as $problem)
                            <li>{{ $problem }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </x-gate-group>

        <x-gate-group>
            {{-- Wrong for the setting, but with a legitimate reading, so the
                 stage was paid for and kept. Computed for two phases and
                 printed only by `story:write` — the one place it could be acted
                 on was a terminal. --}}
            @if ($this->localeWarnings())
                <div class="alert warn wide">
                    <strong>{{ count($this->localeWarnings()) }} term(s) read wrong for {{ $this->localeLabel() }}.</strong>
                    <ul class="small indent">
                        @foreach ($this->localeWarnings() as $hit)
                            <li>
                                Act {{ $hit['act'] }} &mdash; <code>{{ $hit['term'] }}</code>
                                <span class="muted small">&hellip;{{ $hit['context'] }}&hellip;</span>
                            </li>
                        @endforeach
                    </ul>
                    <div class="muted small mt-2">
                        None of these block anything. Each has a legitimate reading, which is why the
                        stage was not failed &mdash; the unambiguous terms are refused before an act
                        is ever stored. {{ $this->voice()->judgement() }}
                    </div>
                </div>
            @endif
        </x-gate-group>

        <x-gate-group>
            @if ($this->spineReview()['warnings'])
                <div class="alert warn wide">
                    <strong>Structural warnings.</strong>
                    <ul class="small indent">
                        @foreach ($this->spineReview()['warnings'] as $warning)
                            <li>{{ $warning }}</li>
                        @endforeach
                    </ul>
                    <div class="muted small mt-2">
                        {{ $this->voice()->blocksApproval() }} They are the ways this format is
                        actually written wrong, and {{ $this->voice()->fixHere() }}
                    </div>
                </div>
            @endif
        </x-gate-group>
    </x-gate-row>

    <div class="panel">
        {{-- The setting, read-only. Chosen at creation, and every act on this
             page was written against it — so it is shown rather than edited. --}}
        <div class="muted small mb-5">
            Setting: <strong>{{ $this->localeLabel() }}</strong>
            &middot; fixed at creation, because the outline and the acts were generated against it.
        </div>

        {{-- TWO-UP. Premise and cast age are the pair the operator writes
             together, and stacked they are two short textareas above a column
             of whitespace on any real screen. --}}
        <div class="twoup">
        <div class="field">
            <label for="premise">Premise</label>
            <textarea id="premise" wire:model="premise" rows="4"
                      @disabled(! $this->editable())></textarea>
            @error('premise') <div class="error">{{ $message }}</div> @enderror
            <div class="muted small mt-2">
                Everything downstream is generated from this: the acts, then 5,500&ndash;8,000 words of
                script, then 150&ndash;250 stills. It is the cheapest thing here to change.
            </div>
        </div>

        {{-- Read once, by the character extraction that runs when the scene draft
             is dispatched from Gate 2 — so this gate is the last place it is free
             to state. After that, changing it means reopening Gate 1 and
             re-extracting, which rewrites every description the scene prompts
             were built from. --}}
        <div class="field">
            <label for="cast-age">
                Cast age range
                <span class="muted small">optional</span>
            </label>
            <textarea id="cast-age" wire:model="castAgeProfile" rows="2"
                      placeholder="Spouses in their late twenties and thirties. Workplace and marriage settings. No elderly characters carrying plot."
                      @disabled(! $this->editable())></textarea>
            @error('castAgeProfile') <div class="error">{{ $message }}</div> @enderror
            <div class="muted small mt-2">
                Steers the ages the script does not state outright. Where the script does state one,
                the script wins &mdash; a picture that contradicts the narration is worse than one
                outside the intended range. The art style cannot carry this: one style line is shared
                by every story, so it can describe how age is drawn but never who is in this one.
            </div>
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
            <div class="muted small mt-1">
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
            <div class="row mb-4">
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
                <div class="muted small mt-2">
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
                <div class="muted small mt-2">
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
