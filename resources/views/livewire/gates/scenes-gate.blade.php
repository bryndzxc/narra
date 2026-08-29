<div>
    @if ($notice)
        <div class="alert ok">{{ $notice }}</div>
    @endif

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
