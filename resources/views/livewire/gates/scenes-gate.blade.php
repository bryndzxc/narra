<div>
    @php($quiet = ! $this->canApprove() && ! $this->canGenerateAssets())

    @if ($notice)
        <div class="alert ok">{{ $notice }}</div>
    @endif

    @if ($problem)
        <div class="alert err pre-line">{{ $problem }}</div>
    @endif

    {{--
        The failure comes before the locked banner, and only when there is no
        decision to make.

        Locked is the EXPECTED state for a story past Gate 2 — it is what going
        well looks like — while a scene that never got its assets is the one
        thing on the page reporting something wrong. Putting the expected state
        above the broken one is the same mistake as a section that always holds
        something it should not: it teaches you to read past the top of the page.

        In the busy layout it stays under the decision row, because the button
        that re-dispatches these is up there and a failure separated from its
        retry is the worse trade.
    --}}
    @if ($quiet)
        @include('livewire.gates.partials.failed-scenes', [
            'failures' => $this->failedScenes(),
            'retryable' => false,
        ])
    @endif

    {{-- The scene draft. `story:scenes` held the only copy of this, so a story
         that passed Gate 1 in the browser could only be cut into scenes from a
         terminal — and this page showed an empty list until somebody did. --}}
    @if ($this->canDraftScenes())
        <div class="panel money">
            <label>{{ $story->scenes()->exists() ? 'Draft the scenes again' : 'Extract the cast and draft the scenes' }}</label>

            <div class="muted small mt-1" style="max-width:78ch">
                The cast is extracted first and always: a character description is pasted verbatim into
                every image prompt, so a scene drafted before the cast exists has to invent one — and an
                invented description is a face that drifts across 150&ndash;250 stills. Text only. Nothing
                here generates an image or a second of audio.
            </div>

            <x-worker-health :queues="[$this->textWorkers()]" :compact="true" />

            @if ($confirmingDraft)
                <div class="alert warn mt-4">
                    @if ($story->scenes()->exists())
                        <strong>This replaces the {{ $story->scenes()->count() }} scene(s) already
                        drafted</strong>, including every edit made to them on this page. The act scripts
                        are untouched.
                    @else
                        Billed calls against the script writer: one for the cast, then the acts are cut
                        into scenes. One cost row each.
                    @endif
                    <div class="mt-4">
                        <button type="button" class="primary"
                                wire:click="draftScenes({{ $story->scenes()->exists() ? 'true' : 'false' }})">
                            {{ $story->scenes()->exists() ? 'Replace the scenes' : 'Queue the draft' }}
                        </button>
                        <button type="button" wire:click="cancelDraft">Back</button>
                    </div>
                </div>
            @else
                <div class="actions mt-4">
                    <button type="button" class="primary" wire:click="askToDraft">
                        {{ $story->scenes()->exists() ? 'Re-draft the scenes' : 'Draft the scenes' }}
                    </button>
                    <span class="muted small">Shows what it replaces first. Nothing is queued by this press.</span>
                </div>
            @endif
        </div>
    @elseif ($this->draftRefusal() && ! $story->scenes()->exists())
        {{-- Only when there are no scenes. Once there are, the refusal is
             answering a question nobody is asking, and a page full of
             explanations for things you did not try to do is noise. --}}
        <div class="alert warn">
            <strong>The scenes cannot be drafted from here.</strong> {{ $this->draftRefusal() }}
        </div>
    @endif

    {{--
        Both refusals reach the operator as text on the page that fixes them.
        `approve()` and `generateAssets()` catch MissingCharacterReferenceException
        and addError() rather than letting it become a stack trace — which only
        works if somebody renders it, and for a long time nobody did.
    --}}
    @error('approval') <div class="alert warn">{{ $message }}</div> @enderror
    @error('generation') <div class="alert warn">{{ $message }}</div> @enderror

    @if (! $this->editable())
        {{--
            Two separate questions, deliberately not one. "Are the scenes
            locked" is not "may this gate be reopened": the first is true for
            seven statuses and the second for five. Driving the button off the
            first is what offered an operator at `rendered` a move the state
            machine had never been given.

            The two-up layout is the redesign's, and the colour is not: the mock
            painted this in the alarm gradient, which this console reserves for
            a stopped pipeline. An approved gate is a story going correctly, so
            it stays a warning — already the loudest thing short of the band.
        --}}
        <div class="alert warn wide">
            <div class="lockedgate">
                <div>
                    <strong>Scenes are locked &mdash; Gate 2 is approved and paid assets may exist for
                    these scenes.</strong>

                    @if ($this->canReopen())
                        <div class="small mt-1">
                            Reopening deletes nothing. Only a scene whose narration or image prompt you
                            actually change is regenerated when you approve again; everything else keeps
                            what it already paid for.
                        </div>
                        <div class="mt-3">
                            <button wire:click="reopen"
                                    wire:confirm="Reopen Gate 2? Nothing is deleted now. When you approve again, only the scenes you actually changed are regenerated.">
                                Reopen Gate 2
                            </button>
                        </div>
                    @else
                        <div class="small mt-2">
                            Gate 2 cannot be reopened from
                            <span class="mono">{{ $story->status->value }}</span>: {{ $this->reopenRefusal() }}
                        </div>
                    @endif
                </div>

                {{-- What editing would cost, from the same estimator the spend
                     button itemises with. Rates, not a total: what a reopen
                     costs depends on which scenes get touched, and that has not
                     happened yet. --}}
                @if ($this->canReopen())
                    @php($rates = $this->assetEstimate())
                    <div class="cost">
                        <label>What editing costs after a reopen</label>
                        <div class="rates small">
                            <span class="amt">${{ number_format($rates->usdPerImage, 4) }}</span>
                            <span>per scene whose image prompt changes &mdash; the still is bought again.</span>
                            <span class="amt">${{ number_format($rates->usdPerThousandSpeechCharacters, 4) }}/1k</span>
                            <span>per 1,000 characters of narration that changes &mdash; the audio and its
                                  word timings are bought again.</span>
                            <span class="amt">$0.0000</span>
                            <span>for reordering, motion, hook and thumbnail flags. None of those touch a
                                  provider.</span>
                        </div>
                        <div class="small muted mt-2">
                            Every row below that already has assets carries a <span class="mono">paid</span>
                            marker, so the cost of touching it is visible before you touch it.
                        </div>
                    </div>
                @endif
            </div>
        </div>
    @endif

    @if ($story->reopened_from !== null)
        <div class="alert warn small">
            Reopened from <span class="mono">{{ $story->reopened_from->value }}</span>. Every asset
            generated before the reopen is still on disk and still counted as paid for.
        </div>
    @endif

    {{--
        The banner that was missing, and it stays full width above the columns.

        A run can be served entirely by stand-ins while every figure on the page
        reads like a bill. That is not hypothetical: 186 flat-fill PNGs were
        generated and $8.12 was written to the ledger because
        PROVIDER_IMAGE_GENERATOR was absent from .env, the projection read config
        rather than the container, and the only signal on screen was a lowercase
        "fake" in a table column. Putting it in a column beside two other panels
        would be the same mistake with better spacing.
    --}}
    @if ($this->canGenerateAssets() && $this->assetEstimate()->hasSimulatedStage())
        @php($simulated = $this->assetEstimate())
        <div class="alert warn">
            <strong>These are not real assets.</strong>
            <div class="small mt-1">
                {{ ucfirst(implode(' and ', $simulated->simulatedStages)) }}
                {{ count($simulated->simulatedStages) === 1 ? 'is' : 'are' }} served by a
                <span class="mono">fake</span> provider: a flat-fill PNG and a silent WAV, generated
                locally. <strong>No vendor is contacted and nothing is billed</strong> &mdash; the
                total below is $0.00 for that reason, not because it is cheap.
                @if ($simulated->isEntirelySimulated())
                    Every stage is simulated, so this run produces a complete video made of
                    placeholders.
                @endif
                <div class="mt-2">
                    Set <span class="mono">PROVIDER_IMAGE_GENERATOR</span>,
                    <span class="mono">PROVIDER_SPEECH_SYNTHESIZER</span> and
                    <span class="mono">PROVIDER_TRANSCRIBER</span> in
                    <span class="mono">.env</span> before spending. Note the scene-still key is
                    <em>not</em> <span class="mono">PROVIDER_REFERENCE_IMAGE_GENERATOR</span>,
                    which only governs character sheets.
                </div>
            </div>
        </div>
    @endif

    {{--
        The body is a function of state, not a constant.

        Drawn for the busy case this is three decision panels and a scene list.
        On a story that is past Gate 2 none of those decisions exist: there is
        nothing to approve and nothing to authorise, and the row rendered three
        near-empty panels as three islands with voids between them while the one
        actionable thing — a scene that failed asset generation — sat in a narrow
        box two rows further down.

        That is `.dash.quiet`'s defect reintroduced here, and the fix is the
        same. When there is no decision to make, the failure takes the top of the
        page at full width, the advisories widen instead of narrowing, and what
        is NOT available collapses to a line with a disclosure.

        Nothing is dropped and nothing is quietened: the refusal keeps its own
        sentence in the strip rather than going behind the disclosure, and the
        advisories are louder here than they were as a third of a row.
    --}}
    @if ($quiet)
        @include('livewire.gates.partials.advisories', [
            'warnings' => $this->warnings(),
            'voice' => $this->voice(),
            'subject' => 'these scenes',
            'wide' => true,
        ])

    <div class="strip">
        {{-- THE SECOND LIVE INSTANCE OF GATE 4'S DEFECT, found by sweeping all
             four gates rather than by fixing the one that was reported. This
             read "Gate 2 is behind this story" at `draft`, `outlined` and
             `scripted` — three statuses before a scene exists to approve —
             because the quiet condition above is a capability pair and a
             capability pair is false on both sides of a gate. The second
             sentence is true either way and is unchanged. --}}
        <span><strong>{{ $this->voice()->standing() }}</strong> Nothing to approve and nothing to authorise.</span>

        @if ($this->assetGenerationRefusal())
            {{-- The refusal stays on the line, not behind the disclosure. It is
                 the sentence that says why there is no button, and a refusal
                 that has to be opened to be read has been made quieter. --}}
            <span>{{ $this->assetGenerationRefusal() }}</span>
        @endif

        <span class="badge {{ $this->castReady() ? 'ok' : 'warn' }}">
            {{ $this->castReady()
                ? 'character sheets approved'
                : $this->castMissing()->count().' character(s) without a reference' }}
        </span>

        <a href="{{ route('stories.characters', $story) }}">Review sheets</a>

        <details class="why">
            <summary>What this status means</summary>
            <div class="small muted mt-2">
                The story is at <span class="mono">{{ $story->status->value }}</span>. The sentence
                above is the same one <span class="mono">php artisan assets:generate</span> prints,
                from the same predicate &mdash; the page and the command decide it together so they
                cannot drift apart.
            </div>
        </details>
    </div>
    @else

    {{--
        The three decisions, above the 168 rows rather than under them.

        What is wrong with the scenes, whether the cast has faces, and what
        approving costs. Stacked, the spend panel sat below the fold on any
        story carrying more than a couple of advisories — so the one screen in
        this app where money is authorised opened on a column of amber.
    --}}
    <x-gate-row>
        {{--
            Column 1. The money. Either the gate that unlocks the spend, or the
            spend itself — never both, because they are never both available.

            The decisions come FIRST in the document, not just leftmost in the
            grid. `.gatecols` collapses to one column at 1180px, and with the
            advisories first a narrow screen re-created the exact fault this row
            was built to fix: the page opening on a column of amber with the
            spend panel below the fold. Same reasoning as the dashboard's quiet
            layout, and asserted the same way in ScenesGateLayoutTest.
        --}}
        <x-gate-group>
            @if ($this->canApprove())
                <div class="alert money">
                    <strong>This is the last free gate.</strong>
                    <div class="small mt-2">
                        Approving authorises paid generation for
                        <span class="mono">{{ $this->costPreview()['images'] }}</span> images,
                        <span class="mono">{{ $this->costPreview()['narrations'] }}</span> narrations and
                        <span class="mono">{{ $this->costPreview()['transcriptions'] }}</span> transcriptions
                        out of <span class="mono">{{ $this->costPreview()['scenes'] }}</span> scenes.
                        Images alone are roughly 70% of a video's cost.

                        @if ($this->costPreview()['preserved'] > 0)
                            <span class="mono">{{ $this->costPreview()['preserved'] }}</span> scene(s) keep the
                            assets they already have and are not billed again.
                        @endif
                    </div>

                    <div class="mt-4">
                        @if ($confirmingApproval)
                            {{--
                                The itemised list, not a generic warning. After a
                                reopen the honest answer is usually "three stills
                                and a re-render", and an operator shown "250
                                images" instead learns to stop reading the
                                confirmation.
                            --}}
                            <ul class="small" style="margin:0 0 8px 18px">
                                @forelse ($this->changes()->summary() as $line)
                                    <li>{{ $line }}</li>
                                @empty
                                    <li>Nothing has changed. Approving regenerates nothing and bills nothing.</li>
                                @endforelse
                            </ul>
                            <button class="gate" wire:click="approve">Yes &mdash; approve Gate 2 and unlock spending</button>
                            <button wire:click="cancelApproval">Cancel</button>
                        @else
                            <button class="gate" wire:click="askToApprove">Approve Gate 2</button>
                        @endif
                    </div>

                    <div class="small muted mt-3">
                        Approving unlocks the spend. It does not start it &mdash; generating the assets is a
                        separate press below, with the bill itemised first.
                    </div>
                </div>
            @endif

            {{--
                The spend, deliberately a second button rather than part of the gate.

                Approving scenes is a quality decision; authorising 186 stills is a
                money decision, and one click that quietly did both would make them look
                the same. The decisive reason is retry: this is not a gate crossing, so
                it can be pressed again. Five failed stills are re-dispatched by pressing
                it a second time, where folding it into the gate would mean reopening
                Gate 2 and risking regeneration of the other 181 to fix five.
            --}}
            @if ($this->canGenerateAssets())
                @php($estimate = $this->assetEstimate())

                <div class="alert money">
                    <div class="row">
                        <div class="grow">
                            <strong>Generate assets</strong>
                        </div>
                        <div class="tr">
                            <div class="mono" style="font-size:1.4em">${{ number_format($estimate->usdTotal(), 2) }}</div>
                            <div class="small muted">{{ $estimate->jobsTotal() }} job(s)</div>
                        </div>
                    </div>

                    <div class="small mt-1">
                        @if ($estimate->billsAnything())
                            <span class="mono">{{ $estimate->scenesPending() }}</span> of
                            <span class="mono">{{ $estimate->scenesTotal }}</span> scenes still need
                            paid assets. Stills are the line that matters &mdash; roughly 70% of a
                            video's cost, and every one of them is generated against the approved
                            character references rather than from a description.
                        @else
                            Every scene has its still, its narration and its word timings. Nothing is
                            outstanding, so pressing this queues nothing and bills nothing.
                        @endif
                    </div>

                    @if ($estimate->billsAnything())
                        <table class="small mt-4" style="width:100%">
                            <tbody>
                                <tr>
                                    <td>Stills</td>
                                    <td class="mono">{{ $estimate->imagesPending }}</td>
                                    <td class="mono">&times; ${{ number_format($estimate->usdPerImage, 4) }}</td>
                                    <td class="mono">${{ number_format($estimate->usdImages(), 4) }}</td>
                                    <td class="muted">{{ $estimate->imageProvider }}{{ $estimate->imageModel ? ' · '.$estimate->imageModel : '' }}</td>
                                </tr>
                                <tr>
                                    <td>Narration</td>
                                    <td class="mono">{{ $estimate->narrationsPending }}</td>
                                    <td class="mono">{{ number_format($estimate->speechCharacters) }} chars &times; ${{ number_format($estimate->usdPerThousandSpeechCharacters, 4) }}/1k</td>
                                    <td class="mono">${{ number_format($estimate->usdNarration(), 4) }}</td>
                                    <td class="muted">{{ $estimate->speechProvider }}</td>
                                </tr>
                                <tr>
                                    <td>Word timings</td>
                                    <td class="mono">{{ $estimate->transcriptionsPending }}</td>
                                    <td class="mono">~{{ number_format($estimate->projectedAudioMinutes(), 1) }} min &times; ${{ number_format($estimate->usdPerTranscribedMinute, 4) }}</td>
                                    <td class="mono">${{ number_format($estimate->usdTranscription(), 4) }}</td>
                                    <td class="muted">{{ $estimate->transcriberProvider }}</td>
                                </tr>
                            </tbody>
                        </table>

                        @if ($estimate->preserved() > 0)
                            <div class="small mt-2">
                                <span class="mono">{{ $estimate->preserved() }}</span> scene(s) keep the assets
                                they already have and are not billed again.
                            </div>
                        @endif

                        @if ($estimate->rateIsDeclared)
                            {{--
                                Said on every run, not once in a config comment. No image
                                vendor returns a cost field on a generation response, so
                                every figure above is declared rather than observed. An
                                estimate the operator knows to reconcile is worth more
                                than one they trust.
                            --}}
                            <div class="small muted mt-2">
                                These rates are <strong>declared in config, not billed back by the provider</strong>
                                &mdash; no image API returns a cost on the response. Reconcile once against the
                                real usage page and correct <span class="mono">config/providers.php</span>.
                            </div>
                        @endif
                    @endif

                    <div class="mt-4">
                        @if ($confirmingGeneration)
                            <ul class="small" style="margin:0 0 8px 18px">
                                @forelse ($estimate->summary() as $line)
                                    <li>{{ $line }}</li>
                                @empty
                                    <li>Nothing outstanding. This queues no jobs and bills nothing.</li>
                                @endforelse
                            </ul>
                            <button class="gate" wire:click="generateAssets">
                                @if ($estimate->hasSimulatedStage())
                                    Yes &mdash; generate placeholders (no vendor, $0.00)
                                @else
                                    Yes &mdash; spend ${{ number_format($estimate->usdTotal(), 2) }}
                                @endif
                            </button>
                            <button wire:click="cancelGeneration">Cancel</button>
                        @else
                            <button class="gate" wire:click="askToGenerate">
                                {{ $this->failedScenes()->isNotEmpty() ? 'Retry failed scenes' : 'Generate assets' }}
                            </button>
                            {{--
                                THE FREE HALF, BESIDE THE PAID ONE.

                                Every question this runs is also run by the button to
                                its left, so nothing can be skipped by not pressing it.
                                What it adds is asking them WITHOUT committing — which
                                is what `narration:preflight` was for, and that command
                                has had no caller in the app since it was written. A
                                guard reachable only from a terminal, guarding the money
                                button, on the app built so an operator would not need a
                                terminal.

                                Story 23 is what that cost: dispatched with a null
                                voice_id, 257 identical per-scene failures, 256 stills
                                already bought.
                            --}}
                            <button wire:click="checkReadiness" wire:loading.attr="disabled">
                                Check without spending
                            </button>
                            <a href="{{ route('renders.show', $story->slug) }}" class="small" style="margin-left:8px">
                                watch progress
                            </a>

                            <div wire:loading wire:target="checkReadiness" class="alert run mt-3">
                                Asking the providers &mdash; the narrator list and the narration allowance are
                                read from the vendor, so this takes a moment. Nothing is being queued.
                            </div>
                        @endif
                    </div>

                    {{--
                        WHAT THE CHECKS SAID.

                        Only after `checkReadiness()`, never after a dispatch: the money
                        press reports what it QUEUED, and folding a readiness readout
                        into it would make a run look like a check.

                        The `ok` lines are kept rather than filtered to the problems, and
                        that is the point of running it — "narration fits: 21,350 credits
                        needed, 27,953 remaining" is a number an operator wants BEFORE
                        pressing, and it is not visible anywhere else in the console.
                    --}}
                    @if ($readinessNotes !== [])
                        <div class="mt-4">
                            @foreach ($readinessNotes as $note)
                                <div @class([
                                    'alert',
                                    'wide',
                                    'warn' => $note['level'] !== 'ok',
                                    'ok' => $note['level'] === 'ok',
                                    'mt-2' => ! $loop->first,
                                ])>{{ $note['message'] }}</div>
                            @endforeach
                            <div class="small muted mt-2">
                                Checked, not queued. Nothing was billed and nothing was dispatched.
                            </div>
                        </div>
                    @endif
                </div>
            @else
                {{--
                    Why the button is not here.

                    `assetGenerationRefusal()` was computed on this component from the
                    day OperatorAction was written and rendered nowhere, so the whole
                    panel simply vanished when the action was unavailable and the page
                    said nothing at all. An operator looking for the button they used
                    last week found blank space — which reads as a bug in the page
                    rather than as a state of the story, and is the same false-success
                    shape as a stage that never ran leaving no failure row.

                    Every refusal from OperatorAction names the next action, which is
                    the entire reason that enum exists. This is where the sentence goes.
                --}}
                @if ($this->assetGenerationRefusal())
                    <div class="alert">
                        <strong>Asset generation is not available here.</strong>
                        <div class="small mt-1">{{ $this->assetGenerationRefusal() }}</div>
                        <div class="small muted mt-2">
                            The story is at <span class="mono">{{ $story->status->value }}</span>. This is the
                            same sentence <span class="mono">php artisan assets:generate</span> prints, from the
                            same predicate &mdash; the page and the command decide it together so they cannot
                            drift apart.
                        </div>
                    </div>
                @endif
            @endif
        </x-gate-group>
        {{--
            Column 2. The character sheet sub-step, beside the approval rather
            than below it. It is the thing that must happen BEFORE the gate
            opens: approving authorises 150-250 stills and a still cannot be
            generated for a scene whose characters have no approved face, so the
            gate refuses while any are missing. An operator who meets the
            refusal without having been shown the step first has been ambushed
            by it.
        --}}
        <div class="alert {{ $this->castReady() ? 'ok' : 'warn' }}">
            <strong>Character sheets</strong>
            <div class="small mt-1">
                @if ($this->castReady())
                    Every character who appears in a scene has an approved reference image. Their
                    stills will be generated against it rather than from their description.
                @else
                    <span class="mono">{{ $this->castMissing()->count() }}</span> character(s)
                    appear in scenes with no reference picked
                    (<span class="mono">{{ $this->castMissing()->pluck('name')->implode(', ') }}</span>).
                    <strong>Gate 2 will not open until they have one</strong> &mdash; a scene
                    featuring a character with no reference is refused outright rather than drawn
                    from the description, because a face drawn from text looks right on its own
                    and drifts across the video.
                @endif
            </div>
            <div class="mt-3">
                <a href="{{ route('stories.characters', $story) }}">
                    <button class="{{ $this->castReady() ? '' : 'gate' }}">
                        {{ $this->castReady() ? 'Review sheets' : 'Generate sheets' }}
                    </button>
                </a>
            </div>
        </div>

        @include('livewire.gates.partials.advisories', [
            'warnings' => $this->warnings(),
            'voice' => $this->voice(),
            'subject' => 'these scenes',
        ])
    </x-gate-row>

    @include('livewire.gates.partials.failed-scenes', [
            'failures' => $this->failedScenes(),
            'retryable' => true,
        ])
    @endif

    {{--
        The free path, next to the paid one, with the difference stated.

        These two buttons look alike and differ by a month of TTS credits.
        `needsTranscription` and `needsNarration` overlap heavily — narration
        provenance moving stales both — so on the story this was written for,
        "retry the 181 failed alignments" through the button above would also
        have re-billed 69 narrations. There is no flag arrangement that makes
        that safe to get wrong, which is why it is a separate button reaching a
        separate dispatcher that can construct exactly one job class.
    --}}
    @if ($this->canAlignTimings() && $this->pendingTimings() > 0)
        <div class="panel">
            <label>Word timings only &mdash; free</label>

            <div class="muted small mt-1" style="max-width:78ch">
                {{ $this->pendingTimings() }} scene(s) have narration audio and no usable word timings.
                Alignment runs locally through WhisperX: no vendor is contacted and nothing is billed.
                @if ($this->narrationsTheAssetButtonWouldRebill() > 0)
                    <br><br>
                    <strong>&ldquo;Generate assets&rdquo; would also re-bill
                    {{ $this->narrationsTheAssetButtonWouldRebill() }} narration(s).</strong>
                    This button cannot: it reaches a dispatcher whose only reachable job class is the
                    aligner, so that is a property of the code rather than a promise about it.
                @endif
            </div>

            @if ($confirmingAlignment)
                <div class="alert warn mt-4">
                    {{ $this->pendingTimings() }} alignment job(s) on the
                    <span class="mono">{{ config('render.queues.assets') }}</span> queue. Free, local, and
                    slow &mdash; roughly seven seconds per scene once the model is warm.
                    <div class="mt-4">
                        <button type="button" class="primary" wire:click="alignTimings">
                            Queue {{ $this->pendingTimings() }} alignment(s)
                        </button>
                        <button type="button" wire:click="cancelAlignment">Back</button>
                    </div>
                </div>
            @else
                <div class="actions mt-4">
                    <button type="button" wire:click="askToAlign">
                        Re-run word timings only
                    </button>
                    <span class="muted small">Nothing here can bill TTS.</span>
                </div>
            @endif
        </div>
    @endif

    <div class="panel flush">
        <div class="scenehead">
            <h2>Scenes</h2>
            <span class="badge">{{ $scenes->total() }}</span>
            <span class="mono muted small">
                {{ $scenes->firstItem() ?? 0 }}&ndash;{{ $scenes->lastItem() ?? 0 }}
            </span>

            {{-- What the rows show of the prompt. View state only: nothing
                 downstream of this page reads it. --}}
            <span class="seg">
                <span class="lbl">prompt</span>
                <button type="button" wire:click="showFrameOnly"
                        class="{{ $promptMode === 'frame' ? 'on' : '' }}">Frame only</button>
                <button type="button" wire:click="showFullPrompt"
                        class="{{ $promptMode === 'full' ? 'on' : '' }}">Full text</button>
            </span>
        </div>

        {{--
            The style block, stated once because it is written once.

            `scenes.image_prompt` holds the ASSEMBLED prompt — the frame, then
            the verbatim cast block, then the art style and the constraints —
            because that is what ImagePromptBuilder::build() returns and what
            DraftScenes saves. The old page printed all of it on every row, so
            the same few hundred words repeated once per scene and buried the
            one section that actually differs.
        --}}
        @if ($this->styleBlock()['words'] > 0)
            @php($style = $this->styleBlock())
            <div class="styleblock">
                <span class="badge {{ $style['matches_config'] ? 'run' : 'warn' }}">style block</span>
                <div class="grow">
                    <div class="small">
                        <strong>{{ number_format($style['words']) }} words, byte-identical on all
                        {{ number_format($style['scenes']) }} of this story's prompts.</strong>
                        Shown once here instead of {{ number_format($style['scenes']) }} times. The rows
                        below carry the frame &mdash; the part that differs &mdash; and name the cast
                        whose descriptions are spliced in with it.
                    </div>

                    {{--
                        Read out of the prompts, then compared against config —
                        never the other way round. `GenerateSceneImage` sends
                        `image_prompt` verbatim and nothing re-appends the style
                        at dispatch, so on a story drafted before a retune the
                        configured value describes the NEXT story rather than
                        this one. Asserting it here would put a false sentence
                        on the screen where 168 stills are authorised.
                    --}}
                    @if (! $style['configured'])
                        <div class="small muted mt-1">
                            <span class="mono">config/scenes.php</span> declares no art style, so there is
                            nothing to compare this against.
                        </div>
                    @elseif ($style['matches_config'])
                        <div class="small muted mt-1">
                            It matches the style currently declared in
                            <span class="mono">config/scenes.php</span>, so these prompts and the next
                            story's would be generated in the same look.
                        </div>
                    @else
                        <div class="small mt-1">
                            <strong>It is not the style currently declared in
                            <span class="mono">config/scenes.php</span>.</strong>
                            These prompts were drafted under an earlier look and they are sent verbatim
                            &mdash; nothing re-applies the style at dispatch &mdash; so approving buys
                            stills in the OLD style. Re-drafting the scenes is free and would pick up the
                            current one. Read this next to the reference sheets: a story whose stills and
                            whose faces were drawn under different styles comes back in two looks.
                        </div>
                    @endif

                    @if ($promptMode === 'full')
                        <p class="full">{{ $style['text'] }}</p>
                    @endif
                </div>
            </div>
        @endif

        <div class="scenetable">
            <div class="thead">
                <span>#</span>
                <span>Still</span>
                <span>Act &middot; motion</span>
                <span>Narration</span>
                <span>{{ $promptMode === 'full' ? 'Image prompt (stored, whole)' : 'Frame' }}</span>
                <span class="acts">{{ $this->editable() ? 'Edit' : '' }}</span>
            </div>

            @forelse ($scenes as $scene)
                @if ($editing === $scene->id)
                    {{-- Edit form, inline: the operator is comparing against the scene above and below. --}}
                    <div class="scenerow">
                        <div class="editing">
                            <div class="scene" style="background: color-mix(in srgb, var(--run) 6%, transparent)">
                                <span class="seq">{{ $scene->sequence }}</span>
                                <div class="body">
                                    <div class="field">
                                        <label>Narration</label>
                                        <textarea wire:model="narration" rows="3"></textarea>
                                        @error('narration') <div class="error">{{ $message }}</div> @enderror
                                    </div>

                                    <div class="field">
                                        <label>Image prompt</label>
                                        <textarea wire:model="imagePrompt" rows="6"></textarea>
                                        <div class="muted small mt-1">
                                            The whole stored prompt, including the cast block and the style
                                            block the rows summarise. Reaches a paid API and, slugged, the
                                            filesystem. Keep character descriptions consistent with the
                                            locked seeds &mdash; drift here is what makes a face change at
                                            scene 90.
                                        </div>
                                    </div>

                                    <div class="row">
                                        <div style="width:180px">
                                            <label>Motion</label>
                                            <select wire:model="motion">
                                                @foreach ($motions as $preset)
                                                    <option value="{{ $preset->value }}">{{ $preset->value }}</option>
                                                @endforeach
                                            </select>
                                        </div>

                                        <div class="checks mt-8">
                                            <label><input type="checkbox" wire:model="isHook"> <span>Opening hook</span></label>
                                            <label><input type="checkbox" wire:model="isThumbnailCandidate"> <span>Thumbnail candidate</span></label>
                                        </div>
                                    </div>

                                    <div class="row mt-3">
                                        <button class="primary" wire:click="saveScene">Save scene</button>
                                        <button wire:click="cancelEdit">Cancel</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                @else
                    <div class="scenerow">
                        <span class="seq">{{ $scene->sequence }}</span>

                        <div>
                            @if ($scene->image_path)
                                <img class="still dense" loading="lazy"
                                     src="{{ route('stories.still', ['story' => $story, 'scene' => $scene]) }}"
                                     alt="scene {{ $scene->sequence }}">
                            @else
                                <span class="still dense"></span>
                            @endif
                        </div>

                        <div class="meta">
                            <span class="badge">act {{ $scene->act->sequence }}</span>
                            <span class="badge">{{ $scene->motion_preset->value }}</span>
                            @if ($scene->is_hook) <span class="badge run">hook</span> @endif
                            @if ($scene->is_thumbnail_candidate) <span class="badge money">thumbnail</span> @endif
                            @if ($this->isPaidFor($scene))
                                {{-- A marker, not a price. Which scenes cost money to edit is
                                     known exactly; what one of them costs is not, because
                                     narration is priced per character across the whole story
                                     and no image vendor returns a cost on a response. --}}
                                <span class="badge warn"
                                      title="Assets for this scene exist and were paid for. Editing it means paying again.">paid</span>
                            @endif
                            @if ($scene->status === \App\Enums\SceneStatus::Failed)
                                {{-- The failure, on the row it happened to. It was only ever in
                                     the table above, so scrolling the list gave no sign which
                                     scene the story had stopped on. --}}
                                <span class="badge fail">failed</span>
                            @endif
                            @if (! $this->editable())
                                {{-- LEFT of the controls column, not in it.
                                     When this lived in the last column it was the first thing
                                     to scroll out of view — the one marker saying the row
                                     cannot be edited, hidden by a horizontal scrollbar. --}}
                                <span class="badge readonly">read-only</span>
                            @endif
                            <span class="dur">{{ number_format(($scene->duration_ms ?? 0) / 1000, 1) }}s</span>
                        </div>

                        <p class="narration">{{ $scene->narration_text }}</p>

                        <div>
                            <p class="prompt">
                                @if ($scene->image_prompt)
                                    {{ $promptMode === 'full' ? $scene->image_prompt : $this->frameOf($scene) }}
                                @else
                                    no image prompt
                                @endif
                            </p>

                            @if ($promptMode === 'frame' && $scene->image_prompt)
                                <div class="chips">
                                    @foreach ($scene->characters as $character)
                                        <span class="badge run"
                                              title="{{ $character->name }}'s stored description is pasted verbatim into this prompt.">+ {{ $character->name }}</span>
                                    @endforeach
                                    @if ($this->styleBlock()['words'] > 0)
                                        <span class="badge"
                                              title="{{ number_format($this->styleBlock()['words']) }} words, byte-identical on every prompt in this story.">+ style block</span>
                                    @endif
                                </div>
                            @endif
                        </div>

                        <div class="acts">
                            @if ($this->editable())
                                <button class="tiny" wire:click="edit({{ $scene->id }})">edit</button>
                                <button class="tiny" wire:click="move({{ $scene->id }}, -1)">&uarr;</button>
                                <button class="tiny" wire:click="move({{ $scene->id }}, 1)">&darr;</button>
                                <button class="tiny danger" wire:click="deleteScene({{ $scene->id }})"
                                        wire:confirm="Delete scene {{ $scene->sequence }}? The scenes after it are renumbered.">
                                    del
                                </button>
                            @endif
                        </div>
                    </div>
                @endif
            @empty
                <p class="muted" style="padding:18px">No scenes drafted yet.</p>
            @endforelse
        </div>
    </div>

    {{ $scenes->links() }}
</div>
