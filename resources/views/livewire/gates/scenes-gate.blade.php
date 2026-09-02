<div>
    @if ($notice)
        <div class="alert ok">{{ $notice }}</div>
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
        --}}
        <div class="alert warn">
            Scenes are locked &mdash; Gate 2 is approved and paid assets may exist for these scenes.

            @if ($this->canReopen())
                Reopening deletes nothing. Only a scene whose narration or image prompt you actually
                change is regenerated when you approve again; everything else keeps what it already
                paid for.
                <div style="margin-top:8px">
                    <button wire:click="reopen"
                            wire:confirm="Reopen Gate 2? Nothing is deleted now. When you approve again, only the scenes you actually changed are regenerated.">
                        Reopen Gate 2
                    </button>
                </div>
            @else
                <div class="small" style="margin-top:6px">
                    Gate 2 cannot be reopened from
                    <span class="mono">{{ $story->status->value }}</span>: {{ $this->reopenRefusal() }}
                </div>
            @endif
        </div>
    @endif

    @if ($story->reopened_from !== null)
        <div class="alert warn small">
            Reopened from <span class="mono">{{ $story->reopened_from->value }}</span>. Every asset
            generated before the reopen is still on disk and still counted as paid for.
        </div>
    @endif

    @foreach ($this->warnings() as $warning)
        <div class="alert warn small">{{ $warning }}</div>
    @endforeach

    {{--
        The character sheet sub-step, above the approval block rather than below
        it. It is the thing that must happen BEFORE the gate opens: approving
        authorises 150-250 stills and a still cannot be generated for a scene
        whose characters have no approved face, so the gate refuses while any
        are missing. An operator who meets the refusal without having been shown
        the step first has been ambushed by it.
    --}}
    <div class="alert {{ $this->castReady() ? 'ok' : 'warn' }}">
        <div class="row">
            <div style="flex:1">
                <strong>Character sheets</strong>
                <div class="small" style="margin-top:4px">
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
            </div>
            <div>
                <a href="{{ route('stories.characters', $story) }}">
                    <button class="{{ $this->castReady() ? '' : 'gate' }}">
                        {{ $this->castReady() ? 'Review sheets' : 'Generate sheets' }}
                    </button>
                </a>
            </div>
        </div>
    </div>

    @if ($this->canApprove())
        <div class="alert money">
            <strong>This is the last free gate.</strong>
            <div class="small" style="margin-top:6px">
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

            <div style="margin-top:10px">
                @if ($confirmingApproval)
                    {{--
                        The itemised list, not a generic warning. After a reopen
                        the honest answer is usually "three stills and a
                        re-render", and an operator shown "250 images" instead
                        learns to stop reading the confirmation.
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

            <div class="small muted" style="margin-top:8px">
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

        {{--
            The banner that was missing.

            A run can be served entirely by stand-ins while every figure on the
            page reads like a bill. That is not hypothetical: 186 flat-fill PNGs
            were generated and $8.12 was written to the ledger because
            PROVIDER_IMAGE_GENERATOR was absent from .env, the projection read
            config rather than the container, and the only signal on screen was
            a lowercase "fake" in a table column. The provider is now asked of
            the object that will actually run, and it says so in words.
        --}}
        @if ($estimate->hasSimulatedStage())
            <div class="alert warn">
                <strong>These are not real assets.</strong>
                <div class="small" style="margin-top:4px">
                    {{ ucfirst(implode(' and ', $estimate->simulatedStages)) }}
                    {{ count($estimate->simulatedStages) === 1 ? 'is' : 'are' }} served by a
                    <span class="mono">fake</span> provider: a flat-fill PNG and a silent WAV, generated
                    locally. <strong>No vendor is contacted and nothing is billed</strong> &mdash; the
                    total below is $0.00 for that reason, not because it is cheap.
                    @if ($estimate->isEntirelySimulated())
                        Every stage is simulated, so this run produces a complete video made of
                        placeholders.
                    @endif
                    <div style="margin-top:6px">
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

        <div class="alert money">
            <div class="row">
                <div style="flex:1">
                    <strong>Generate assets</strong>
                    <div class="small" style="margin-top:4px">
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
                </div>
                <div style="text-align:right">
                    <div class="mono" style="font-size:1.4em">${{ number_format($estimate->usdTotal(), 2) }}</div>
                    <div class="small muted">{{ $estimate->jobsTotal() }} job(s)</div>
                </div>
            </div>

            @if ($estimate->billsAnything())
                <table class="small" style="margin-top:10px; width:100%">
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
                    <div class="small" style="margin-top:6px">
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
                    <div class="small muted" style="margin-top:6px">
                        These rates are <strong>declared in config, not billed back by the provider</strong>
                        &mdash; no image API returns a cost on the response. Reconcile once against the
                        real usage page and correct <span class="mono">config/providers.php</span>.
                    </div>
                @endif
            @endif

            <div style="margin-top:10px">
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
                    <a href="{{ route('renders.show', $story->slug) }}" class="small" style="margin-left:8px">
                        watch progress
                    </a>
                @endif
            </div>
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
                <div class="small" style="margin-top:4px">{{ $this->assetGenerationRefusal() }}</div>
                <div class="small muted" style="margin-top:6px">
                    The story is at <span class="mono">{{ $story->status->value }}</span>. This is the
                    same sentence <span class="mono">php artisan assets:generate</span> prints, from the
                    same predicate &mdash; the page and the command decide it together so they cannot
                    drift apart.
                </div>
            </div>
        @endif
    @endif

    {{--
        Failed scenes, listed on the page that retries them.

        The batch policy is that three failures out of 186 flag for retry rather
        than failing the video — which is only true if the three are findable.
        Pressing the button again re-dispatches exactly these and nothing else.
    --}}
    @if ($this->failedScenes()->isNotEmpty())
        <div class="alert warn">
            <strong>{{ $this->failedScenes()->count() }} scene(s) failed asset generation.</strong>
            <div class="small" style="margin-top:4px">
                The rest of the story is unaffected and is not re-billed. The button above re-runs
                only these.
            </div>
            <table class="small" style="margin-top:8px; width:100%">
                <thead>
                    <tr><th style="width:60px">Scene</th><th style="width:140px">Stage</th><th>Error</th></tr>
                </thead>
                <tbody>
                    @foreach ($this->failedScenes() as $failure)
                        <tr>
                            <td class="mono">{{ $failure['sequence'] }}</td>
                            <td class="mono">{{ $failure['stage'] }}</td>
                            <td class="mono" style="word-break:break-word">{{ $failure['error'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    <h2>Scenes &mdash; {{ $scenes->total() }}</h2>

    <div class="panel">
        @forelse ($scenes as $scene)
            @if ($editing === $scene->id)
                {{-- Edit form, inline: the operator is comparing against the scene above and below. --}}
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
                            <textarea wire:model="imagePrompt" rows="3"></textarea>
                            <div class="muted small" style="margin-top:4px">
                                Reaches a paid API and, slugged, the filesystem. Keep character descriptions
                                consistent with the locked seeds &mdash; drift here is what makes a face change
                                at scene 90.
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

                            <div class="checks" style="margin-top:18px">
                                <label><input type="checkbox" wire:model="isHook"> <span>Opening hook</span></label>
                                <label><input type="checkbox" wire:model="isThumbnailCandidate"> <span>Thumbnail candidate</span></label>
                            </div>
                        </div>

                        <div class="row" style="margin-top:8px">
                            <button class="primary" wire:click="saveScene">Save scene</button>
                            <button wire:click="cancelEdit">Cancel</button>
                        </div>
                    </div>
                </div>
            @else
                <div class="scene">
                    <span class="seq">{{ $scene->sequence }}</span>

                    @if ($scene->image_path)
                        <img class="still" loading="lazy"
                             src="{{ route('stories.still', ['story' => $story, 'scene' => $scene]) }}"
                             alt="scene {{ $scene->sequence }}">
                    @else
                        <div class="still"></div>
                    @endif

                    <div class="body">
                        <div class="row small" style="gap:8px; margin-bottom:4px">
                            <span class="badge">act {{ $scene->act->sequence }}</span>
                            <span class="badge">{{ $scene->motion_preset->value }}</span>
                            @if ($scene->is_hook) <span class="badge run">hook</span> @endif
                            @if ($scene->is_thumbnail_candidate) <span class="badge money">thumbnail</span> @endif
                            <span class="muted mono">{{ number_format(($scene->duration_ms ?? 0) / 1000, 1) }}s</span>
                        </div>

                        <p class="narration">{{ $scene->narration_text }}</p>
                        <p class="prompt">{{ $scene->image_prompt ?: 'no image prompt' }}</p>
                    </div>

                    @if ($this->editable())
                        <div class="actions">
                            <button class="tiny" wire:click="edit({{ $scene->id }})">edit</button>
                            <button class="tiny" wire:click="move({{ $scene->id }}, -1)">&uarr;</button>
                            <button class="tiny" wire:click="move({{ $scene->id }}, 1)">&darr;</button>
                            <button class="tiny danger" wire:click="deleteScene({{ $scene->id }})"
                                    wire:confirm="Delete scene {{ $scene->sequence }}? The scenes after it are renumbered.">
                                del
                            </button>
                        </div>
                    @endif
                </div>
            @endif
        @empty
            <p class="muted">No scenes drafted yet.</p>
        @endforelse
    </div>

    {{ $scenes->links() }}
</div>
