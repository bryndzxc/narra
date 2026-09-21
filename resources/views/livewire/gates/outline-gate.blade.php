<div>
    @if ($saved)
        <div class="alert ok wide">{{ $saved }}</div>
    @endif

    @if ($problem)
        <div class="alert err pre-line wide">{{ $problem }}</div>
    @endif

    {{-- The last outline or act-script run, when it failed. --}}
    @foreach ($this->stageFailures() as $failure)
        <div class="alert err wide">
            <strong>{{ $failure['stage'] }} failed</strong> <span class="muted mono small">{{ $failure['at'] }}</span>
            <div class="pre-line mt-1">{{ $failure['error'] }}</div>
            @if ($failure['remedy']->known)
                <p><strong>Repair:</strong> {{ $failure['remedy']->text }}</p>
                @if ($failure['remedy']->unmeasured)
                    <p><strong>Not measured:</strong> {{ $failure['remedy']->unmeasured }}</p>
                @endif
            @else
                <p><strong>No known repair.</strong></p>
            @endif
        </div>
    @endforeach

    {{-- Every outline call that stopped at its ceiling, from the ledger, so a
         successful re-run cannot erase it. The ceiling is not raised: see the
         standing position in CLAUDE.md. --}}
    @if ($this->outlineTruncations() !== [])
        <div class="alert err wide">
            <strong>The outline hit its output ceiling {{ count($this->outlineTruncations()) }} time(s) on this story.</strong>
            @foreach ($this->outlineTruncations() as $t)
                <div class="mono small">{{ $t['at'] }} &middot; {{ number_format($t['output']) }} output tokens &middot; effort {{ $t['effort'] ?? 'not recorded' }} &middot; ${{ $t['usd'] }}</div>
            @endforeach
            <p>
                The ceiling stays where it is: a truncation is billed at the ceiling, and headroom would hide
                the next one. The outline text grows with every spine field and the reasoning at medium
                effort is all-or-nothing; the lever left is effort <span class="mono">low</span>, which
                nothing has measured. Record this before the next attempt.
            </p>
        </div>
    @endif

    {{--
        PREMISES FROM AN IDEA. Draft only, single narrative, before the outline.

        Every candidate is shown with its checks — refusals in red, Gate 1
        warnings in amber — and none is dropped for failing: what the generator
        does badly is information the operator asked to see. The checks are
        computed from the stored fields when the page is read.
    --}}
    @if ($this->canWritePremises())
        @php($premiseState = $this->premiseState())
        @php($roll = $this->premiseRoll())

        <div class="panel money" @if (in_array($premiseState['state'], ['queued', 'running'], true)) wire:poll.5s @endif>
            <label for="idea">Write premises from an idea</label>
            <div class="muted small mt-1" style="max-width:78ch">
                Three premises per roll, one billed call. Each follows the genre's opening &mdash; the
                betrayal done in public, the justification said to the narrator's face, something only the
                narrator can produce in person &mdash; and is checked before you see it. Pick one and it
                becomes the premise; the outline is the next press.
            </div>
            <textarea id="idea" rows="2" class="mt-3" wire:model.blur="idea"
                      placeholder="My CEO wife cheated with her deputy&hellip;"></textarea>
            @error('idea') <div class="alert err wide mt-3">{{ $message }}</div> @enderror

            <table class="mt-3">
                <tbody>
                @foreach (app(\App\Support\ModelRoster::class)->lines(['generate_premises']) as $line)
                    <tr><td class="mono small muted">{{ $line }}</td></tr>
                @endforeach
                </tbody>
            </table>

            @if ($premiseState['state'] === 'queued' || $premiseState['state'] === 'running')
                <div class="alert run wide mt-4">
                    {{ $premiseState['state'] === 'queued' ? 'Queued' : 'Writing' }}: three premises. This panel
                    checks every five seconds.
                </div>
            @elseif ($premiseState['state'] === 'failed')
                <div class="alert err wide mt-4">
                    <strong>The last roll failed.</strong> <span class="pre-line">{{ $premiseState['error'] }}</span>
                    @if ($premiseState['remedy']?->known)
                        <p><strong>Repair:</strong> {{ $premiseState['remedy']->text }}</p>
                        @if ($premiseState['remedy']->unmeasured)
                            <p><strong>Not measured:</strong> {{ $premiseState['remedy']->unmeasured }}</p>
                        @endif
                    @else
                        <p><strong>No known repair.</strong></p>
                    @endif
                </div>
            @endif

            @if ($confirmingPremises)
                <div class="alert warn wide mt-4">
                    <strong>One billed call</strong> on the <span class="mono">{{ $this->workers()['queue'] }}</span> queue.
                    @if ($this->workers()['state'] === \App\Support\WorkerHealth::ABSENT)
                        Nothing is listening on it right now &mdash; the job will wait and nothing is lost, but
                        nothing happens until a worker starts.
                    @endif
                    <div class="mt-4">
                        <button type="button" class="primary" wire:click="writePremises">Queue it &mdash; 1 call</button>
                        <button type="button" wire:click="cancelPremises">Back</button>
                    </div>
                </div>
            @else
                <div class="actions mt-4">
                    <button type="button" class="primary" wire:click="askToWritePremises"
                            @disabled(in_array($premiseState['state'], ['queued', 'running'], true))>
                        {{ $roll ? 'Write three more' : 'Write three premises' }}
                    </button>
                    <span class="muted small">Shows the bill first. Nothing is queued by this press.</span>
                </div>
            @endif
        </div>

        @if ($roll)
            {{-- The one line the operator asked never to miss: an idea that
                 needed translating, and what it became. The generator's own
                 report first; the idea's own words as a second reading when
                 the generator did not report one. --}}
            @if ($roll['revenge_shaped'])
                <div class="alert warn wide">
                    <strong>Your idea was revenge-shaped, and became a refusal.</strong>
                    {{ $roll['translation'] !== '' ? $roll['translation'] : 'The generator did not say what it became — read the endings below.' }}
                </div>
            @elseif ($roll['revenge_markers'] !== [])
                <div class="alert warn wide">
                    <strong>Your idea reads as revenge-shaped</strong>
                    ("{{ implode('", "', $roll['revenge_markers']) }}"), and the generator did not say it
                    translated it. This genre pays off in a departure and a refusal, not revenge &mdash; read
                    the endings below before picking one.
                </div>
            @endif

            @if (count($roll['candidates']) < $roll['requested'])
                <div class="alert warn wide">
                    Asked for {{ $roll['requested'] }} premises and got {{ count($roll['candidates']) }}. Kept,
                    not refused: each one was paid for.
                </div>
            @endif

            @foreach ($roll['candidates'] as $entry)
                @php($c = $entry['candidate'])
                @php($checks = $entry['checks'])
                <div class="panel">
                    <div class="row">
                        <label class="grow">Premise {{ $entry['index'] + 1 }}</label>
                        @if ($entry['chosen'])
                            <span class="badge ok">this story's premise</span>
                        @elseif ($checks['problems'] === [] && $checks['warnings'] === [])
                            <span class="badge ok">passed every check</span>
                        @else
                            <span class="badge warn">{{ count($checks['problems']) + count($checks['warnings']) }} finding(s)</span>
                        @endif
                    </div>

                    <p class="measure">{{ $c->premise }}</p>

                    @foreach ($checks['problems'] as $problem)
                        <div class="alert err wide">{{ $problem }}</div>
                    @endforeach
                    @foreach ($checks['warnings'] as $warning)
                        <div class="alert warn wide">{{ $warning }}</div>
                    @endforeach

                    <details class="mt-3">
                        <summary class="small">The cast and the answers it was checked on</summary>
                        <table class="mt-3">
                            <tbody>
                            @foreach ($c->cast as $member)
                                <tr>
                                    <td>{{ $member->name }}</td>
                                    <td class="mono small">{{ $member->role?->label() ?? 'no role' }}</td>
                                    <td class="small muted">{{ $member->relationship }}</td>
                                </tr>
                            @endforeach
                            @foreach ($c->fields as $field => $value)
                                <tr>
                                    <td class="mono small">{{ $field }}</td>
                                    <td colspan="2" class="small">{{ $value !== '' ? $value : '—' }}</td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                        <div class="muted small mt-3">Checked: {{ implode('; ', $checks['ran']) }}.</div>
                    </details>

                    @unless ($entry['chosen'])
                        <div class="actions mt-4">
                            <button type="button" wire:click="usePremise({{ $entry['index'] }})">Use this premise</button>
                            <span class="muted small">Replaces the premise below. Nothing is billed.</span>
                        </div>
                    @endunless
                </div>
            @endforeach
        @endif
    @elseif ($this->premiseRefusal())
        <div class="muted small">{{ $this->premiseRefusal() }}</div>
    @endif

    {{-- The writing panel. This stage was `story:write` and nothing else for
         the whole of Phase 2 — the one part of the pipeline with no way into it
         except a terminal, on the page where its output is reviewed. --}}
    @if ($this->canWrite())
        @php($estimate = $this->writeEstimate())
        @php($missing = $this->unwrittenActs())

        @if ($estimate['calls'] > 0)
            <div class="panel money">
                <label>{{ $estimate['outline'] ? 'Write the outline' : 'Write the act scripts' }}</label>

                <div class="muted small mt-1" style="max-width:78ch">
                    @if ($estimate['outline'])
                        {{-- One press, one decision. This used to queue every act
                             behind the outline, so the cast and the spine were
                             read after the acts had been bought against them. --}}
                        Nothing has been written yet. This writes the outline alone &mdash; its cast and
                        its spine &mdash; so both are read here before any act is paid for. The acts are
                        the next press.
                    @else
                        {{-- The resume case, and the reason this is not a bare
                             "regenerate". Six sequential calls that die on act 4
                             have already billed three. --}}
                        Act(s) <span class="mono">{{ implode(', ', $missing) }}</span> have no script.
                        Everything already written is kept — only the empty acts are queued, so a run that
                        died partway does not cost the whole story again.
                    @endif
                </div>

                @if ($estimate['outline'] && $this->canChooseEnding())
                    {{-- Chosen here, before the outline, because the outline
                         writes the fields the chosen ending needs. Saved as it
                         is picked; the outline press refuses until it is. --}}
                    <div class="mt-4">
                        <x-ending-picker model="ending"
                                         :recent="$this->recentEndings()['rows']"
                                         :streak="$this->recentEndings()['streak']" />
                    </div>
                @endif

                {{-- Its own condition, not the ending's. The ending is fixed
                     once acts exist; this is read by the act writer too, and
                     the act writer replaces each summary with its own — so it
                     is still choosable on the second press, where the panel
                     above is writing the acts rather than the outline. --}}
                @if ($this->canChoosePartnerEndState())
                    <div class="mt-4">
                        <x-partner-end-state-picker model="partnerEndState"
                                                    :recent="$this->recentPartnerEndStates()"
                                                    :required="$this->partnerEndStateRequired()"
                                                    :partner="$this->chosenPartnerName()" />
                    </div>
                @endif

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
                            {{ $estimate['outline'] ? 'Write the outline' : 'Write the act(s)' }}
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

    {{-- Writing the outline AGAIN. The second Gate 1 finding whose repair was
         terminal-only: a story with no scripts and a wrong outline is a $0.28
         fix, and until now the only way to make it was a bootstrap script,
         because `story:write` and the press above both keep an outline that
         exists. Separate panel from the one above because at `outlined` they
         spend different money on different things. --}}
    @if ($this->canReOutline())
        @php($destroys = $this->reOutlineCost())

        <div class="panel money">
            <label>Write the outline again</label>

            <div class="muted small mt-1" style="max-width:78ch">
                No act has a script yet, so this outline is still a plan. Writing it again replaces the
                whole of it: <strong>{{ $destroys['acts'] }} act(s)</strong> with their summaries and
                beats, <strong>{{ $destroys['cast'] }} cast row(s)</strong>, and
                <strong>{{ $destroys['spine'] }} spine field(s)</strong>. Anything you have edited on this
                page is included in that. The acts are deleted by the call that succeeds, so a call that
                fails leaves what is here standing.
            </div>

            <x-worker-health :queues="[$this->workers()]" :compact="true" />

            <table class="mt-4">
                <tbody>
                <tr>
                    <td>Billed calls</td>
                    <td class="mono">1</td>
                    <td class="muted small">The outline only. The acts are a second press.</td>
                </tr>
                </tbody>
            </table>

            {{-- The cast question, asked rather than answered silently.
                 Story 39 was re-outlined with this released by default — not
                 by anybody's choice, but because the predicate that pins a
                 cast went inert the moment a story had acts — and came back
                 having demoted the partner the operator had chosen an end
                 state for and invented a stranger in her place. Both settings
                 are real needs; only one of them can be the default, and the
                 other one has to be a sentence somebody reads. --}}
            <div class="mt-4">
                <label>
                    <input type="checkbox" wire:model.live="keepCast">
                    <span>Keep the cast on this story</span>
                </label>

                <div class="muted small mt-1" style="max-width:78ch">
                    @if ($keepCast)
                        The {{ $destroys['cast'] }} people below come back under the same names in the
                        same roles@if ($destroys['partner'])&nbsp;&mdash; including
                        <span class="mono">{{ $destroys['partner'] }}</span> as the partner@endif. The
                        outline may add someone and may rewrite a relationship line; it is refused, before
                        anything is stored, if it drops a person or changes a role.
                    @else
                        <strong>The cast is released.</strong> The outline writes its own from the premise,
                        and whoever is on this story now may not come back under the same name, the same
                        role, or at all. Turn this off only when the cast is the thing you are repairing.
                    @endif
                </div>
            </div>

            @if ($confirmingReOutline)
                <div class="alert warn wide mt-4">
                    <strong>1 billed call</strong> queued on the
                    <span class="mono">{{ $this->workers()['queue'] }}</span> queue, replacing
                    {{ $destroys['acts'] }} act(s) and the spine they were written with.
                    @unless ($keepCast)
                        <strong>The cast is released</strong>, so the people on this story now are not
                        held.
                    @endunless
                    @if ($this->workers()['state'] === \App\Support\WorkerHealth::ABSENT)
                        Nothing is listening on that queue right now — the job will wait and nothing is
                        lost, but nothing happens until a worker starts.
                    @endif
                    <div class="mt-4">
                        <button type="button" class="primary" wire:click="reOutline">
                            Queue it — replace the outline
                        </button>
                        <button type="button" wire:click="cancelReOutline">Back</button>
                    </div>
                </div>
            @else
                <div class="actions mt-4">
                    <button type="button" wire:click="askToReOutline">Write the outline again</button>
                    <span class="muted small">Shows what it replaces first. Nothing is queued by this press.</span>
                </div>
            @endif
        </div>
    @elseif ($this->reOutlineRefusal())
        {{-- Said, not swallowed, for the reason the write refusal is: a panel
             that vanishes is a page saying nothing where it should say why. --}}
        <div class="alert warn wide">
            <strong>This outline cannot be replaced from here.</strong> {{ $this->reOutlineRefusal() }}
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
                    {{-- The card's own header bar, as the design has it: the
                         subject in the alert's own ink, and how many. The ink
                         comes from `.alert.fail > .alerthead h2` rather than
                         from a class written here, so a refusal cannot end up
                         headed in warning amber. --}}
                    <div class="alerthead">
                        <svg class="ico" width="15" height="15" viewBox="0 0 24 24" fill="none"
                             stroke="var(--fail)" stroke-width="2.4" aria-hidden="true">
                            <path d="M12 3.5 2.5 20h19L12 3.5Z"></path>
                            <path d="M12 9.5v5M12 17.2v.1"></path>
                        </svg>
                        <h2>The outline is missing part of its structure</h2>
                        <span class="count">{{ count($this->spineReview()['problems']) }}</span>
                    </div>
                    <ul class="small indent">
                        @foreach ($this->spineReview()['problems'] as $problem)
                            <li>{{ $problem }}</li>
                        @endforeach
                    </ul>
                    {{-- The card had no closing note at all, while both of its
                         neighbours had one — so the loudest advisory on the page
                         was the only one that never said what it costs to act on
                         it. Through the voice, because both halves of that
                         sentence name a decision. --}}
                    <div class="muted small mt-2">
                        {{ $this->voice()->blocksApproval() }} {{ $this->voice()->fixHere() }}
                    </div>
                </div>
            @endif
        </x-gate-group>

        <x-gate-group>
            {{-- DENIED terms the outline or an act was KEPT with. These used to
                 refuse the stage after it was billed; now the phrase is here
                 and the operator judges it. Loud on purpose, in the refusal's
                 colour, above the warned terms: it replaced a refusal and may
                 not be quieter than one. --}}
            @if ($this->localeDenied())
                <div class="alert err wide">
                    <div class="alerthead">
                        <h2>
                            {{ count($this->localeDenied()) }} term(s) the {{ $this->localeLabel() }} denylist names{{ $this->localeDeniedInScripts()
                                ? ', '.count($this->localeDeniedInScripts()).' of them in an act script'
                                : ', kept for your judgement' }}
                        </h2>
                    </div>
                    <div class="localehits">
                        @foreach ($this->localeDenied() as $hit)
                            <div class="hit">
                                <span class="at">{{ $hit['act'] === null ? 'outline' : 'act '.$hit['act'] }}</span>
                                <span class="minw">
                                    <code>{{ $hit['term'] }}</code>
                                    <span class="small">in the {{ $hit['where'] }}</span>
                                    <span class="muted small">&hellip;{{ $hit['context'] }}&hellip;</span>
                                </span>
                            </div>
                        @endforeach
                    </div>
                    {{-- A script term is not a judgement, and this used to say it
                         was ("if this one does, leave it"): scene drafting refuses
                         a denied term in a frame and draws frames from the script,
                         so keeping one guaranteed a paid refusal a stage later.
                         Story 38, act 4, twice. The two rules were decided the same
                         day and never read together; see CLAUDE.md. --}}
                    @if ($this->localeDeniedInScripts())
                        <p class="mt-2">
                            <strong>A denied term in an act script is refused at scene drafting.</strong>
                            Scenes are drawn from the script and scene drafting refuses a denied term in a
                            frame, after the scene calls are billed: story 38's act 4 was refused twice, about
                            $0.73, for one sentence. It is also narration, read aloud.
                            {{ $this->voice()->removeBeforeApproving() }}
                            A script is not editable on this page; rewriting that act on its own replaces it
                            (<code>story:write --acts-only=N</code>).
                        </p>
                    @endif
                    @if (count($this->localeDeniedInScripts()) < count($this->localeDenied()))
                        <div class="muted small mt-2">
                            A term in an outline field is fixed by editing that field on this page. The list
                            says these have no reading in this setting; if one does, it can stay, though the
                            act writer reads these fields and can carry it into a script.
                            {{ $this->voice()->judgement() }}
                        </div>
                    @endif
                </div>
            @endif

            {{-- Wrong for the setting, but with a legitimate reading, so the
                 stage was paid for and kept. Computed for two phases and
                 printed only by `story:write` — the one place it could be acted
                 on was a terminal. --}}
            @if ($this->localeWarnings())
                <div class="alert warn wide">
                    <div class="alerthead">
                        <h2>{{ count($this->localeWarnings()) }} term(s) read wrong for {{ $this->localeLabel() }}</h2>
                    </div>
                    {{-- A fixed act column, not a list. "Act 3 — torch …swept
                         the beam of a torch across the garage…" as one run-on
                         line makes the eye find the term twice: once as the
                         thing flagged, once inside the sentence. Two columns
                         separate the two jobs. --}}
                    <div class="localehits">
                        @foreach ($this->localeWarnings() as $hit)
                            <div class="hit">
                                <span class="at">act {{ $hit['act'] }}</span>
                                <span class="minw">
                                    <code>{{ $hit['term'] }}</code>
                                    <span class="muted small">&hellip;{{ $hit['context'] }}&hellip;</span>
                                </span>
                            </div>
                        @endforeach
                    </div>
                    <div class="muted small mt-2">
                        None of these block anything. Each has a legitimate reading, which is why the
                        stage was not failed. The terms the list treats as unambiguous are kept too,
                        and shown in red above when there are any. {{ $this->voice()->judgement() }}
                    </div>
                </div>
            @endif

            {{-- The narrator wearing somebody else's possessive. Story 36
                 shipped one at 10:19 and nothing in the app could see it: the
                 locale guard reads a denylist and the character guard reads
                 cast descriptions, and neither has an opinion about who "I"
                 is. Rendered beside the locale hits because it is the same
                 kind of finding — a term in the prose, recomputed from the
                 stored text, judged here. --}}
            @if ($this->pointOfViewSlips())
                <div class="alert warn wide">
                    <div class="alerthead">
                        <h2>
                            {{ count($this->pointOfViewSlips()) }} sentence(s) where the narrator says
                            &ldquo;my {{ \App\Support\NarratorPointOfView::borrowedTermFor($this->story) }}&rdquo;
                        </h2>
                    </div>
                    <div class="localehits">
                        @foreach ($this->pointOfViewSlips() as $hit)
                            <div class="hit">
                                <span class="at">{{ $hit['act'] ? 'act '.$hit['act'] : $hit['where'] }}</span>
                                <span class="minw">
                                    <code>{{ $hit['term'] }}</code>
                                    <span class="muted small">&hellip;{{ $hit['context'] }}&hellip;</span>
                                </span>
                            </div>
                        @endforeach
                    </div>
                    <div class="muted small mt-2">
                        This story's cast says the narrator calls the antagonist
                        &ldquo;my {{ \App\Support\NarratorPointOfView::ownTermFor($this->story) }}&rdquo;, so
                        the possessive above belongs to somebody else and the sentence has changed seats
                        mid-way &mdash; usually where the narration is reporting what another character
                        said. Quoted speech is not counted, so a character saying it aloud is fine.
                        A listener has no scrollback: story 36 shipped one of these at 10:19.
                        {{ $this->voice()->judgement() }}
                    </div>
                </div>
            @endif
        </x-gate-group>

        <x-gate-group>
            @if ($this->structuralWarnings())
                <div class="alert warn wide">
                    {{-- The heading names the DECISION, so it comes from the
                         voice: "Worth a look before you approve" beside a strip
                         saying nothing can be approved is the sentence this
                         mechanism exists for. The design draws one story in one
                         state and does not know about the other ten. --}}
                    <div class="alerthead">
                        <h2>{{ $this->voice()->advisoryHeading('this outline') }}</h2>
                        <span class="count">{{ count($this->structuralWarnings()) }}</span>
                    </div>
                    <ul class="small indent">
                        @foreach ($this->structuralWarnings() as $warning)
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

    {{--
        TWO PANELS, NOT ONE PANEL OF TWO FIELDS.

        Premise and cast age are one decision taken twice — what the story is,
        and who is in it — and `.twoup` already put them side by side. What it
        could not do was give either of them a header: they shared one panel, so
        they shared a bottom edge and a single "Setting: …" preamble, and
        neither could carry the note that belongs to it. The setting is a
        property of the PREMISE and states what it is fixed against; "the last
        free place to state it" is a property of the CAST AGE and is a warning,
        which is why it is in warn ink and why it cannot live in a line above
        both.
    --}}
    <div class="twoup">
        <div class="panel flush">
            <div class="panelhead ruled">
                <h2>Premise</h2>
                {{-- The setting, read-only. Chosen at creation, and every act on
                     this page was written against it — so it is shown rather
                     than edited. --}}
                <span class="right muted small">
                    setting: {{ $this->localeLabel() }} &middot; fixed at creation
                </span>
            </div>
            <div class="pad">
                <div class="field">
                    {{-- The panel header is the label. `aria-label` rather
                         than a visually-hidden element: one fewer rule to keep
                         true, and nothing on screen to fall out of step with
                         the heading above it. --}}
                    <textarea id="premise" aria-label="Premise" wire:model="premise" rows="5"
                              @disabled(! $this->editable())></textarea>
                    @error('premise') <div class="error">{{ $message }}</div> @enderror
                    <div class="muted small mt-2">
                        Everything downstream is generated from this: the acts, then
                        5,500&ndash;8,000 words of script, then 150&ndash;250 stills. It is the
                        cheapest thing here to change.
                        {{-- Said because it used not to be true. Until 2026-09-20 the prose
                             really was all the outline got, so the seven answers a picked
                             premise was chosen ON were re-answered from it. --}}
                        @if (is_array($story->premise_spine) && $story->premise_spine !== [] && ! $story->acts()->exists())
                            The outline is also handed the
                            {{ count($story->premise_spine) }} answer(s) the premise you picked was
                            checked on, so it builds on them instead of answering them again from
                            these sentences. Editing the premise here does not rewrite those.
                        @endif
                    </div>
                    {{-- The ending, at every status, so the choice the outline
                         was written to is on the page that reviews it. --}}
                    @if ($story->format === \App\Enums\StoryFormat::Single)
                        <div class="small mt-2">
                            @if ($story->ending !== null)
                                Ending: <strong>{{ $story->ending->label() }}</strong>.
                                <span class="muted">{{ $story->ending->description() }}
                                    {{ $this->canChooseEnding()
                                        ? 'Changeable in the panel above until the outline is written.'
                                        : 'Fixed: the outline was written to it.' }}</span>
                            @elseif ($this->canChooseEnding())
                                Ending: <strong>not chosen.</strong>
                                <span class="muted">The outline is refused until it is; choose it in the panel above.</span>
                            @else
                                Ending: <strong>outlined before the ending was a choice.</strong>
                                <span class="muted">This story was asked for a narrator epilogue{{ trim((string) $story->antagonist_regret) !== '' ? ' and the antagonist\'s chapter after it' : '' }}.</span>
                            @endif
                        </div>

                        {{-- What the two of them are by the end, at every
                             status, for the reason the ending is here: the
                             choice the acts were written to belongs on the
                             page that reviews them. Said only when this story
                             HAS somebody it could be about — a line about a
                             partner on a story with none is a readout about
                             nobody. --}}
                        @if ($this->chosenPartnerName() !== null && $story->ending === \App\Enums\StoryEnding::NewLife)
                            <div class="small mt-2">
                                @if ($story->partner_end_state !== null)
                                    By the end, {{ $this->chosenPartnerName() }} and the narrator are
                                    <strong>{{ mb_strtolower($story->partner_end_state->label()) }}</strong>.
                                    <span class="muted">{{ $this->canChoosePartnerEndState()
                                        ? 'Changeable in the panel above until an act carries a script.'
                                        : 'Fixed: the acts were written to it.' }}</span>
                                @elseif ($this->canChoosePartnerEndState())
                                    By the end, {{ $this->chosenPartnerName() }} and the narrator are
                                    <strong>not chosen.</strong>
                                    <span class="muted">Nothing asked, so the writers pick the word themselves
                                        &mdash; and with nothing to go on they pick the weakest one, which is
                                        "partner". Choose it in the panel above.</span>
                                @else
                                    By the end, {{ $this->chosenPartnerName() }} and the narrator are
                                    <strong>whatever the acts say.</strong>
                                    <span class="muted">This story was written before the choice existed, or its
                                        partner came from the outline rather than the premise. Nothing was asked
                                        and nothing is missing; read the last act's summary.</span>
                                @endif
                            </div>
                        @endif
                    @endif
                </div>
            </div>
        </div>

        {{-- Read once, by the character extraction that runs when the scene draft
             is dispatched from Gate 2 — so this gate is the last place it is free
             to state. After that, changing it means reopening Gate 1 and
             re-extracting, which rewrites every description the scene prompts
             were built from. That is what the header says, in warn ink, because
             it is the only thing on this pair with a deadline. --}}
        <div class="panel flush">
            <div class="panelhead ruled">
                <h2>Cast age range</h2>
                <span class="badge">optional</span>
                <span class="right small warnnote">last free place to state it</span>
            </div>
            <div class="pad">
                <div class="field">
                    <textarea id="cast-age" aria-label="Cast age range" wire:model="castAgeProfile" rows="5"
                              placeholder="Spouses in their late twenties and thirties. Workplace and marriage settings. No elderly characters carrying plot."
                              @disabled(! $this->editable())></textarea>
                    @error('castAgeProfile') <div class="error">{{ $message }}</div> @enderror
                    <div class="muted small mt-2">
                        Steers the ages the script does not state outright. Where the script does
                        state one, the script wins &mdash; a picture that contradicts the narration
                        is worse than one outside the intended range. The art style cannot carry
                        this: one style line is shared by every story, so it can describe how age is
                        drawn but never who is in this one.
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{--
        THE CAST. Every person this story names, declared by the outline before
        the spine. The act writer is handed this list as the people who exist
        and the extractor describes these people and nobody else, so a row here
        is a face that will be picked and paid for, and a row deleted here is a
        person who stays "his cousin". See App\Support\OutlineCast.
    --}}
    @php($castReview = $this->spineReview()['cast'])
    <div class="panel flush">
        <div class="panelhead ruled">
            <div class="sectionhead">
                <h2>Cast</h2>
                <p>
                    Everyone this story names, and nobody else. Witnesses and anyone who appears once
                    stay unnamed &mdash; a named person is a reference sheet and a face held across every
                    still they are in. Budget {{ \App\Support\OutlineCast::maxNamed() }}.
                </p>
            </div>
            <span class="badge">{{ count($cast) }} named</span>
        </div>
        <div class="pad">
            @if ($cast === [])
                <div class="muted small">
                    @if ($this->story->outlined_before_cast)
                        This outline was written before it was asked for a cast.
                    @else
                        No cast yet.
                    @endif
                </div>
            @else
                <div class="scrollx">
                <table>
                    <thead>
                    <tr><th>Name</th><th>Role</th><th>Relationship</th><th></th></tr>
                    </thead>
                    <tbody>
                    @foreach ($cast as $i => $member)
                        @php($review = $castReview[$i] ?? null)
                        <tr wire:key="cast-{{ $i }}">
                            <td>
                                <input type="text" id="cast-name-{{ $i }}" aria-label="Cast row {{ $i + 1 }} name"
                                       wire:model="cast.{{ $i }}.name" @disabled(! $this->editable())>
                                @if ($review && $review['reused'])
                                    <span class="badge warn">used in {{ $review['reused'] }}</span>
                                @endif
                                @if ($review && $review['unnamed_in_scripts'])
                                    <span class="badge warn">named in no act</span>
                                @endif
                                @error("cast.$i.name") <div class="error">{{ $message }}</div> @enderror
                            </td>
                            <td>
                                <select id="cast-role-{{ $i }}" aria-label="Cast row {{ $i + 1 }} role"
                                        wire:model="cast.{{ $i }}.role" @disabled(! $this->editable())>
                                    <option value="">no role</option>
                                    @foreach (\App\Enums\CastRole::cases() as $role)
                                        <option value="{{ $role->value }}">{{ $role->label() }}</option>
                                    @endforeach
                                </select>
                                @error("cast.$i.role") <div class="error">{{ $message }}</div> @enderror
                            </td>
                            <td>
                                <input type="text" class="grow" id="cast-relationship-{{ $i }}"
                                       aria-label="Cast row {{ $i + 1 }} relationship"
                                       wire:model="cast.{{ $i }}.relationship" @disabled(! $this->editable())>
                                @error("cast.$i.relationship") <div class="error">{{ $message }}</div> @enderror
                            </td>
                            <td>
                                @if ($this->editable())
                                    <button type="button" class="tiny danger" wire:click="removeCastMember({{ $i }})">remove</button>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
                </div>
            @endif

            @if ($this->editable())
                <div class="actions mt-4">
                    <button type="button" wire:click="addCastMember">Add a person</button>
                    <span class="muted small">
                        Renaming someone here does not rename them in act scripts already written.
                    </span>
                </div>
            @endif
        </div>
    </div>

    {{--
        The genre spine. Editable here because Gate 1 is the only place it can
        be fixed cheaply: every act-generation call reads these four fields off
        the story, so a cartoon antagonist here becomes 5,500-8,000 words of
        cartoon antagonist and the cost of finding out is the whole pipeline.
    --}}
    <div class="panel flush">
        {{-- The heading reads WITH its explanation rather than above it. A bare
             `h2` here is a page landmark with a 32px top margin and a rule over
             it; this is a panel's own title, and the sentence beside it is the
             reason the panel is worth reading. It was two blocks with an inline
             `margin-top:-6px` dragging the paragraph back under the heading —
             a workaround for the arrangement instead of the arrangement. --}}
        <div class="panelhead ruled">
            <div class="sectionhead">
                <h2>The spine</h2>
                <p>
                    The structure this genre runs on. Every act-generation call reads these fields
                    off the story, so a cartoon antagonist here becomes 5,500&ndash;8,000 words of
                    cartoon antagonist. They say how the narrator is wronged, by whom and with whom;
                    where it comes out and how the narrator is in the room when it does; that they
                    leave, that they are searched for while the accomplice loses, and what they say
                    when the antagonist reaches them afterwards &mdash; with the one private joke
                    the narrator has kept all video finally said out loud.
                </p>
            </div>
        </div>

        <div class="pad">
        {{-- TWO COLUMNS. Seven fields down the left of a 1770px viewport is the
             argument `.twoup` exists for one section up, at seven times the
             size. --}}
        <div class="spinegrid">
        @foreach ($this->spineReview()['spine'] as $key => $field)
            {{-- The state is on the FIELD as well as in the badge. On a grid of
                 seven, a missing field and a filled one are the same shape
                 until the badge is read, and scanning is the whole reason the
                 grid is a grid. `problem` and `flagged` are literal so
                 class-audit can see them. --}}
            <div @class([
                'field',
                'problem' => $field['state'] === 'missing',
                'flagged' => in_array($field['state'], ['thin', 'weak'], true),
            ])>
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
                    @elseif ($field['state'] === 'none')
                        {{-- Not "missing" either. The cast declares no accomplice, so an
                             empty accomplice field is the right answer: a mother-in-law
                             does it with nobody. --}}
                        <span class="badge">no accomplice in the cast</span>
                    @endif
                    @if (! empty($field['exposes']))
                        {{-- Which sentence of his motive the fall brings out in front
                             of her. "He loses" is worth less than what he loses it for. --}}
                        <span class="badge run">exposes &ldquo;{{ $field['exposes'] }}&rdquo;</span>
                    @endif
                    @if (! empty($field['pays_off']))
                        {{-- Which refusal sentence finally says the running thought
                             out loud. --}}
                        <span class="badge run">said aloud in &ldquo;{{ $field['pays_off'] }}&rdquo;</span>
                    @endif
                    @if (! empty($field['answers']))
                        {{-- Which earlier moment the refusal reaches back to. Shown
                             because "it answers something" is worth less at Gate 1
                             than "it answers act 3". --}}
                        <span class="badge run">answers {{ $field['answers'] }}</span>
                    @endif
                    @if (! empty($field['anchored_in']))
                        {{-- Which day of the story her last chance was offered on.
                             The reveal lands as the other side of a day the viewer
                             saw, so the day is named. --}}
                        <span class="badge run">offered on {{ $field['anchored_in'] }}</span>
                    @endif
                    @if (! empty($field['promises']))
                        {{-- Which part of the departure the hook's closing line
                             promises. Same argument as the refusal badge beside it:
                             "it promises something" is worth less than the sentence
                             it promises, and the sentence is short enough to read. --}}
                        <span class="badge run">promises &ldquo;{{ $field['promises'] }}&rdquo;</span>
                    @endif
                    @if (! empty($field['produces']))
                        {{-- Which sentence of the withheld information the narrator
                             produces in person. "They produce something" is worth
                             less than "they produce the 2016 transfer agreement". --}}
                        <span class="badge run">produces &ldquo;{{ $field['produces'] }}&rdquo;</span>
                    @endif
                    @if (! empty($field['says']))
                        {{-- Which sentence of the justification the betrayal scene
                             has her say aloud. "She says something" is worth less
                             than the sentence she says in front of the room. --}}
                        <span class="badge run">says aloud &ldquo;{{ $field['says'] }}&rdquo;</span>
                    @endif
                    @if (! empty($field['found']))
                        {{-- The found shape: she reached them before the exposure.
                             Legal since the reference transcript was read (she finds
                             him and kneels in public); noted so an operator can see
                             which of the two shapes the outline took. --}}
                        <span class="badge">she finds them &mdash; &ldquo;{{ $field['found'] }}&rdquo;</span>
                    @endif
                </label>
                <textarea id="spine-{{ $key }}" wire:model="spine.{{ $key }}" rows="3"
                          @disabled(! $this->editable())></textarea>
                @error("spine.$key") <div class="error">{{ $message }}</div> @enderror
            </div>
        @endforeach
        </div>

        <div class="muted small mt-4">
            The hook is the first thirty seconds and five beats: one sentence of setup, the betrayal
            inside twenty seconds, evidence in exact words, one small cold action, and a closing line
            promising the DEPARTURE. Only that last beat is checked here, against the departure
            itself &mdash; a hook promising revenge on a story whose payoff is a refusal is selling a
            different video. It draws on the antagonist's justification and does not spend it: the
            same line lands twice in this genre, once as bait and once played out.
        </div>

        <div class="muted small mt-4">
            The accomplice has a stake of his own that she does not know, and he talks: in the
            betrayal scene and every escalation act he performs a harmless role &mdash; the old
            friend, the considerate one who offers to apologize &mdash; she defends him, and he wins.
            From the departure on he loses, several times, in public, and his motive comes out in
            front of her. The first reference's accomplice was silent because he had no stake; the
            second's wanted the shares and never stopped talking. His act is built on a role, never
            on orientation or a manner mocked as unmanly &mdash; a generated outline that does is
            refused, and an edit that does is a problem here. The running thought is the narrator's
            private joke: tagged as a thought all video, said aloud once in the refusal.
        </div>

        <div class="muted small mt-4">
            The antagonist's justification is the engine: the infuriating part is the excuse, not the
            villainy. The exposure is the public payoff and it needs witnesses &mdash; the same reveal
            in private is a different and much worse video. The refusal is the private one, and it is
            what viewers wait forty minutes for: the narrator hands back a sentence that was used on
            them earlier, in the same words. Between the two, the narrator has to LEAVE without
            announcing it &mdash; an announced departure cannot be searched for, and the search is
            the next third of the video.
        </div>

        <div class="muted small mt-4">
            The narrator is IN THE ROOM for the exposure &mdash; by their own choice, or because she has
            found where they are and come; either way the scene is theirs. What decides it is
            the withheld information: when a document or a friend can produce it, the writer leaves the
            narrator 800 km away and the payoff arrives as a report &mdash; stories 23 and 28 both hear
            about their own exposure secondhand. When it takes the narrator's own hand, the writer brings
            them back &mdash; story 25's narrator raises his hand at the back of the room in a work
            jacket. The field is checked against the withheld information for the thing only they can
            produce.
        </div>
        </div>
    </div>

    {{-- The re-hook advisory was here, as its own `.alert.warn` with no `wide`,
         three screens below the fold on a seven-act story and drawing at the
         96ch cap. It is a bullet in the structural warnings now, at the top of
         the page where the design has it -- see `structuralWarnings()`, which
         also records why no test had ever rendered this element. --}}
    <div class="sectionhead">
    <h2>Acts &mdash; {{ count($acts) }}</h2>
    <p>
        Each act is one unit of script generation and one YouTube chapter. The title does both jobs.
        The phase is the act structure and is not editable here: escalation through roughly the first
        two thirds, then the departure, then the search and the refusal. Changing one act's phase
        without the ones around it gives the outline two departures or none, so a wrong structure is
        fixed by re-generating the outline.
    </p>

    {{-- A GRID, NOT A STACK. Seven full-width panels at roughly 400px each is
         three screens of scrolling to read an outline the design fits in one
         and a half — on the page whose one job is a single approve decision
         about that outline. --}}
    <div class="actgrid">
    @foreach ($acts as $i => $act)
        {{-- The phase on the card's EDGE as well as in its badge, so the
             direction of an act is legible while scanning seven of them.
             `leaving` and `turning` are literal keys, so class-audit can read
             them — a class built from `$act['phase']` would be invisible to it,
             which is the shape that let `.warnfill` paint nothing for a phase. --}}
        <div @class([
            'panel',
            'actcard',
            'leaving' => $act['phase'] === 'departure',
            'turning' => in_array($act['phase'], ['search', 'refusal'], true),
        ])>
            <div class="alerthead">
                <span class="badge">Act {{ $act['sequence'] }}</span>
                @if ($act['phase'])
                    {{-- The direction of the act, and the one thing the script
                         generator branches on. An escalation act ends worse for
                         the narrator; a search act ends worse for the antagonist. --}}
                    <span class="badge {{ in_array($act['phase'], ['search', 'refusal'], true) ? 'ok' : '' }}"
                          title="{{ $act['beat_label'] }}">{{ $act['phase_label'] }}</span>
                @endif
                {{-- WHEN the act is set, as the outline writer declared it. The
                     refused state is a `fail` badge on the card as well as a
                     problem at the top: story 28's "Act 2 tells the second
                     betrayal in full" was read at Gate 1 and approved, and a
                     sentence in a summary is not a marker while scanning six
                     cards. A null is not badged — it is "not asked", and the
                     unasked warning above says so once. --}}
                @if ($act['timeframe'] === 'prior')
                    <span class="badge fail" title="A prior incident is cited in a sentence inside a present-day act, never staged as the act">set in the past</span>
                @elseif ($act['timeframe'] === 'present')
                    <span class="badge" title="Set in the story's present, as every escalation act must be">present day</span>
                @endif
                @if ($act['sequence'] === 1)
                    <span class="badge run" title="Chapter 1 must start at 00:00">opens the video</span>
                @endif
                {{-- The design's badge, added rather than substituted: the
                     checkbox below is the only producer `acts.is_rehook_written`
                     has, and a column read by an advisory and written by nothing
                     is `target_publish_at` again. This makes the state visible
                     while scanning; the checkbox is still how it is set. --}}
                @if ($act['sequence'] > 1 && ! $act['is_rehook_written'])
                    <span class="badge warn">no re-hook</span>
                @endif
                <span class="right muted mono small">{{ mb_strlen($act['title']) }}/100</span>
            </div>

            <div class="field">
                <label for="act-title-{{ $i }}">Title &mdash; also the chapter title</label>
                <input id="act-title-{{ $i }}" type="text" wire:model="acts.{{ $i }}.title"
                       @disabled(! $this->editable())>
                @error("acts.$i.title") <div class="error">{{ $message }}</div> @enderror
            </div>

            <div class="field">
                <label for="act-summary-{{ $i }}">Summary &mdash; fed to the next act's generation call
                    {{-- The bound, visible before the press rather than only after
                         it. Once the act script exists this text is the WRITER's,
                         not the outline's, and it is what the form validates. --}}
                    <span class="right muted mono small">{{ mb_strlen($act['summary']) }}/{{ \App\Models\Act::SUMMARY_MAX_CHARS }}</span>
                </label>
                <textarea id="act-summary-{{ $i }}" wire:model="acts.{{ $i }}.summary" rows="3"
                          @disabled(! $this->editable())></textarea>
                {{-- Never had one. The one field on this form whose refusal had no
                     renderer was the one that refused story 28's approve. The
                     gate bar now lists every refusal regardless; this is the
                     field-level copy beside the text it is about. --}}
                @error("acts.$i.summary") <div class="error">{{ $message }}</div> @enderror
                <div class="muted small mt-2">
                    This is the mechanism that stops 7,000 words drifting, repeating, or
                    contradicting themselves.
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
                        people who found her excuse reasonable, her face in public. She reaches the
                        narrator in this phase, and the meetings are where it costs her. A search that
                        costs her nothing is a montage, and the refusals it leads to are unearned.
                    @else
                        Every act has to cost more than the one before it, and none of them recovers
                        anything before the exposure. The narrator answers back in every scene the
                        antagonist is in &mdash; one line that lands &mdash; and the act still ends
                        worse off for them on the ledger.
                    @endif
                </div>
                @error("acts.$i.escalation_beat") <div class="error">{{ $message }}</div> @enderror
            </div>

            @if ($act['phase'])
                {{-- Editable where the phase is not: the repair for a refused
                     act is to rewrite the summary as present-day and then say
                     so, and an operator with no way to clear the declaration
                     could not approve without regenerating. --}}
                <div class="field">
                    <label for="act-timeframe-{{ $i }}">When this act is set</label>
                    <select id="act-timeframe-{{ $i }}" wire:model="acts.{{ $i }}.timeframe"
                            @disabled(! $this->editable())>
                        <option value="">not declared</option>
                        @foreach (\App\Enums\ActTimeframe::cases() as $timeframe)
                            <option value="{{ $timeframe->value }}">{{ $timeframe->label() }}</option>
                        @endforeach
                    </select>
                    <div class="muted small mt-2">
                        Every escalation act is set in the story's present. A prior incident is cited
                        in one sentence with its date, and the line the antagonist said years ago is
                        staged at its most recent saying &mdash; story 25 quotes it in the first
                        thirty seconds and stages it at a present-day dinner. An act set in the past
                        is refused here, because the script is written from the summary and nothing
                        else.
                    </div>
                    @error("acts.$i.timeframe") <div class="error">{{ $message }}</div> @enderror
                </div>
            @endif

            @if ($act['sequence'] > 1)
                <div class="checks">
                    <label>
                        <input type="checkbox" wire:model="acts.{{ $i }}.is_rehook_written"
                               @disabled(! $this->editable())>
                        <span>Re-hook written &mdash; this act opens with a line engineered to carry the viewer forward</span>
                    </label>
                </div>
            @endif

            {{-- The chapters the act was written AS: the unit the viewer gets
                 a title and a fresh re-hook in, every ~2.5 minutes, where the
                 act is the unit the writer keeps 7,000 words coherent in. Read
                 only: they are the writer's cut of its own script, and a bad
                 one is fixed by rewriting the act. Rendered only when they
                 exist — an act written before chapters has none, and an empty
                 list under six cards would be the quiet-state defect again. --}}
            @if ($act['chapters'] !== [])
                <div class="field">
                    <label>Chapters &mdash; {{ count($act['chapters']) }} in this act, each with its own re-hook</label>
                    @foreach ($act['chapters'] as $chapter)
                        <div class="muted small">
                            <span class="mono">{{ $chapter['sequence'] }}.</span>
                            {{ $chapter['title'] }}
                            <span class="mono">from sentence {{ $chapter['first_sentence'] }}</span>
                            @if ($chapter['point_of_view'] !== null)
                                <span class="badge run">told by {{ $chapter['point_of_view'] }}</span>
                            @endif
                            @unless ($chapter['has_rehook'])
                                <span class="badge warn">no re-hook</span>
                            @endunless
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    @endforeach
    </div>

    {{--
        THE DECISION, KEPT ON SCREEN.

        Gate 1 is one approve press about a document three screens long, and the
        button was at the bottom of the third screen — so the decision was taken
        from memory, or taken after scrolling back past the thing being decided.

        Rendered only where the decision EXISTS. A sticky bar on a settled story
        would be `.dash.quiet`'s empty container nailed to the bottom of the
        viewport, which is worse than the row it replaces: the strip above
        already says where the story stands and what, if anything, can be done.

        THE NOTE IS NOT A FABRICATED COUNT. The design puts "unsaved changes in
        2 fields" beside Save, and there is no dirty tracking behind it — a
        figure with no producer next to the control it describes is the
        form-with-nothing-behind-it defect, and this file has already paid for
        one of those at Gate 4. What is printed instead is a fact the page
        actually holds: how many structural findings are still open. That
        sentence names a decision, so it comes from the voice.
    --}}
    @if ($this->editable())
        @php($open = count($this->spineReview()['problems']) + count($this->structuralWarnings()))

        <div class="gatebar">
            {{--
                A REFUSED SAVE, SAID WHERE THE PRESS HAPPENED.

                Livewire answers a failed validation with a 200 and a filled
                error bag, and nothing else: no modal, no log, no exception.
                Story 28's Approve was refused for act 4's summary and the page
                re-rendered identical to the one before the press, because the
                only renderer a validation error has is an `@error` beside its
                field — the summary never had one — and that field was three
                screens above this bar.

                So the whole bag is rendered HERE, inside the sticky bar, every
                key. A rule added to saveRules() is on this list by construction;
                a template author cannot forget one. `wide` because it sits
                beside full-width controls; `refused` is a real rule, not a hint.
            --}}
            @if ($errors->any())
                <x-refused-save :fields="$this->refusedFields()"
                                heading="Not saved, and Gate 1 not crossed."
                                :status="$story->status->value" />
            @endif

            <button wire:click="save">Save outline</button>

            @if ($open > 0)
                <span class="note">
                    {{ $this->voice()->countBlocking($open) }}
                    {{ $this->voice()->fixHere() }}
                </span>
            @endif

            @if ($this->canApprove())
                <button class="gate {{ $open > 0 ? '' : 'right' }}" wire:click="approve"
                        wire:confirm="Approve Gate 1? Act scripts get generated against this outline.">
                    Approve Gate 1 &mdash; outline is right
                </button>
            @else
                <span class="{{ $open > 0 ? '' : 'right' }} muted small">Save once to move the story to
                    <span class="mono">outlined</span>, then approve.</span>
            @endif
        </div>
    @endif
</div>
