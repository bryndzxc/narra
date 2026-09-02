<div>
    @if ($notice)
        <div class="alert ok">{{ $notice }}</div>
    @endif

    @if ($problem)
        <div class="alert fail">{{ $problem }}</div>
    @endif

    {{--
        The money block, first and unmissable. This is the first screen in the
        project behind which real money moves, and the rule it follows is that
        the bill is read before the button is pressed rather than found in the
        ledger afterwards.
    --}}
    <div class="alert money">
        <strong>Character reference sheets &mdash; the first paid assets in this video.</strong>

        <div class="small" style="margin-top:8px">
            Generated from the description already stored for each character, so nothing has to be
            written or uploaded here. A sheet is
            <span class="mono">{{ $this->estimate()->imagesPerSheet() }}</span> candidate images at
            <span class="mono">${{ number_format($this->estimate()->usdPerImage, 4) }}</span> each &mdash;
            <span class="mono">${{ number_format($this->estimate()->usdPerSheet(), 4) }}</span> per character.
        </div>

        <div class="small" style="margin-top:6px">
            @if ($this->estimate()->billsAnything())
                <span class="mono">{{ $this->estimate()->pendingCount() }}</span> of
                <span class="mono">{{ $this->estimate()->castSize }}</span> characters still need a face.
                Generating all of them is
                <span class="mono">{{ $this->estimate()->imagesTotal() }}</span> images,
                <strong class="mono">${{ number_format($this->estimate()->usdTotal(), 4) }}</strong>,
                and unblocks <span class="mono">{{ $this->estimate()->scenesUnblocked }}</span> of
                <span class="mono">{{ $this->estimate()->scenesTotal }}</span> scenes.
            @else
                Every character who appears in a scene has a face on file. Nothing here will bill.
            @endif
        </div>

        <div class="muted small" style="margin-top:8px">
            Provider <span class="mono">{{ $this->estimate()->provider }}</span>@if ($this->estimate()->model),
            model <span class="mono">{{ $this->estimate()->model }}</span>@endif.

            @if ($this->estimate()->rateIsDeclared)
                {{--
                    Said plainly rather than buried. Neither candidate provider
                    publishes a verifiable per-image rate and this one returns no
                    cost field on the generation response, so every figure above
                    is declared in config rather than observed. An operator who
                    knows that reconciles it against the real usage page; one who
                    does not, trusts it.
                --}}
                This price is <strong>declared in config, not returned by the provider</strong> &mdash;
                the API reports no cost on its response. Check it once against your usage page and
                correct <span class="mono">providers.elevenlabs.pricing</span> if it differs.
            @endif
        </div>
    </div>

    @unless ($this->canGenerate())
        <div class="alert warn small">
            Sheets cannot be generated from <span class="mono">{{ $story->status->value }}</span>.
            They are a Gate 2 decision, so the scenes they will be used on have to be drafted first.
        </div>
    @endunless

    @if (! $this->readiness()['ready'])
        <div class="alert warn small">
            <strong>Gate 2 will not open yet.</strong>
            <span class="mono">{{ $this->readiness()['missing']->count() }}</span> character(s) appear in
            scenes with no reference picked
            (<span class="mono">{{ $this->readiness()['missing']->pluck('name')->implode(', ') }}</span>),
            blocking <span class="mono">{{ $this->readiness()['scenes_blocked'] }}</span> scenes.
            A scene featuring a character with no reference is refused outright rather than generated
            from the description &mdash; a face drawn from text looks right on its own and drifts
            across the video, and the drift is not visible until every still has been paid for.
        </div>
    @endif

    @if ($this->readiness()['unused']->isNotEmpty())
        <div class="alert warn small">
            <span class="mono">{{ $this->readiness()['unused']->pluck('name')->implode(', ') }}</span>
            {{ $this->readiness()['unused']->count() === 1 ? 'is' : 'are' }} in the cast but in no
            frame, so no sheet is required. Usually a sign the scene drafts quietly stopped using
            somebody the outline thought was in the story.
        </div>
    @endif

    <h2>Cast &mdash; {{ $this->cast()->count() }}</h2>

    @forelse ($this->cast() as $character)
        <div class="panel" style="margin-bottom:14px">
            <div class="row" style="gap:10px; align-items:flex-start">
                <div style="flex:1">
                    <div class="row small" style="gap:8px">
                        <strong>{{ $character->name }}</strong>

                        @if ($character->hasUsableReference())
                            <span class="badge ok">face locked</span>
                        @elseif ($character->references->isNotEmpty())
                            <span class="badge warn">pick one</span>
                        @else
                            <span class="badge">no sheet</span>
                        @endif

                        <span class="badge">{{ $character->scenes_count }} scene(s)</span>

                        @if ($character->seed !== null)
                            <span class="muted mono">seed {{ $character->seed }}</span>
                        @endif
                    </div>

                    <p class="small muted" style="margin:6px 0 0">{{ $character->description }}</p>

                    @if (trim((string) $character->style_notes) !== '')
                        <p class="small muted" style="margin:2px 0 0">{{ $character->style_notes }}</p>
                    @endif
                </div>

                <div class="actions">
                    @if ($this->canGenerate())
                        @if ($confirming === $character->id)
                            {{--
                                The itemised confirmation, not a generic "are you
                                sure". An operator shown the same aggregate
                                warning every time stops reading it.
                            --}}
                            <div class="small" style="text-align:right">
                                <div>
                                    {{ $this->estimate()->imagesPerSheet() }} images,
                                    <strong class="mono">${{ number_format($this->estimate()->usdPerSheet(), 4) }}</strong>
                                </div>
                                <div class="muted" style="margin-bottom:6px">
                                    Every candidate is billed, including ones you discard.
                                </div>
                                <button class="gate" wire:click="generate({{ $character->id }})">
                                    Yes &mdash; spend it
                                </button>
                                <button wire:click="cancel">Cancel</button>
                            </div>
                        @else
                            <button class="gate" wire:click="askToGenerate({{ $character->id }})">
                                {{ $character->references->isEmpty() ? 'Generate sheet' : 'Regenerate' }}
                            </button>
                        @endif
                    @endif
                </div>
            </div>

            @if ($character->references->isNotEmpty())
                <div class="row" style="flex-wrap:wrap; gap:10px; margin-top:12px">
                    @foreach ($character->references as $reference)
                        <div style="width:150px">
                            @if ($reference->isUsable())
                                <img loading="lazy"
                                     style="width:150px; height:150px; object-fit:cover; border-radius:6px;
                                            border:2px solid {{ $reference->isSelected() ? 'var(--ok)' : 'transparent' }};
                                            background: var(--panel-2)"
                                     src="{{ route('stories.characters.candidate', ['story' => $story, 'reference' => $reference]) }}"
                                     alt="{{ $character->name }} candidate {{ $reference->sequence }}">
                            @else
                                <div style="width:150px; height:150px; border-radius:6px; background: var(--panel-2);
                                            display:flex; align-items:center; justify-content:center"
                                     class="small muted">
                                    {{ $reference->status->value }}
                                </div>
                            @endif

                            <div class="small" style="margin-top:4px">
                                <span class="muted mono">b{{ $reference->batch }}&middot;{{ $reference->sequence }}</span>
                                <span class="muted mono">${{ number_format((float) $reference->usd_cost, 4) }}</span>
                            </div>

                            @if ($reference->isSelected())
                                <div class="small" style="color:var(--ok)">chosen</div>
                            @elseif ($reference->isUsable())
                                {{-- Free, and re-doable for nothing: every candidate stays on disk. --}}
                                <button class="tiny" wire:click="select({{ $reference->id }})">use this</button>
                            @endif

                            @if ($reference->error)
                                <div class="small" style="color:var(--fail)" title="{{ $reference->error }}">
                                    {{ \Illuminate\Support\Str::limit($reference->error, 60) }}
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    @empty
        <p class="muted">
            No characters extracted for this story yet. The cast is extracted before scenes are
            drafted &mdash; a scene drafted before the cast exists has to invent a description for
            whoever is in it.
        </p>
    @endforelse
</div>
