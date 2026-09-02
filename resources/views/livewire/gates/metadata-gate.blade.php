<div>
    @if ($notice)
        <div class="alert ok">{{ $notice }}</div>
    @endif

    @if ($problem)
        <div class="alert fail">{{ $problem }}</div>
    @endif

    @php
        $validation = $this->validation();
        $budget = $this->tagBudget();
        $limits = config('youtube.limits');
    @endphp

    @if ($this->stale())
        <div class="alert fail">
            <strong>This sheet describes a render that no longer exists.</strong>
            <div class="small" style="margin-top:6px">
                Gate 2 was reopened on
                <span class="mono">{{ $this->metadata->stale_at?->format('Y-m-d H:i') }}</span>,
                which invalidated the act timings every chapter timestamp below is derived from.
                The text is kept so your own words are not lost &mdash; but the timestamps are for a
                video that is gone, and this sheet is copy-pasted into YouTube, where a wrong chapter
                list is not something this app can take back.

                @if ($this->canRegenerate())
                    A newer render has finished, so the sheet can be rebuilt against it now.
                @else
                    Gate 4 stays closed until the story has been re-rendered and this has been
                    regenerated against the new timings.
                @endif
            </div>

            @if ($this->editable())
                <div style="margin-top:8px">
                    <button wire:click="regenerate"
                            @disabled(! $this->canRegenerate())
                            wire:confirm="Rebuild the chapter list and footer from the current act timings?">
                        Regenerate against the new render
                    </button>
                    @unless ($this->canRegenerate())
                        <span class="small muted" style="margin-left:8px">waiting on a successful re-render</span>
                    @endunless
                </div>
            @endif
        </div>
    @endif

    @if ($validation['blocking'])
        <div class="alert fail">
            <strong>{{ count($validation['blocking']) }} thing(s) block approval.</strong>
            <ul class="small" style="margin:6px 0 0 18px">
                @foreach ($validation['blocking'] as $problem)
                    <li>{{ $problem }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($validation['warnings'])
        <div class="alert warn">
            <ul class="small" style="margin:0 0 0 18px">
                @foreach ($validation['warnings'] as $warning)
                    <li>{{ $warning }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    {{--
        The producer for everything below it.

        This page shipped before it: the form was here, the limits were checked,
        and the only way to fill any of it in was to type it. That gap — a UI
        built in one phase whose producer was due in the next — is the same seam
        this project has now found six times, so the button is not a convenience.
    --}}
    <h2>Draft the sheet</h2>
    <div class="panel">
        @if ($this->drafting())
            <div class="alert warn">
                <strong>Writing the sheet now.</strong>
                <div class="small" style="margin-top:4px">
                    Three calls on the <span class="mono">{{ config('render.queues.text') }}</span> queue.
                    <a href="{{ route('renders.show', $story->slug) }}">Watch it</a>, then reload this page.
                </div>
            </div>
        @elseif ($this->draftBlockers())
            <div class="alert fail">
                <strong>Not yet.</strong>
                <ul class="small" style="margin:6px 0 0 18px">
                    @foreach ($this->draftBlockers() as $blocker)
                        <li>{{ $blocker }}</li>
                    @endforeach
                </ul>
                <div class="small muted" style="margin-top:6px">
                    Checked before the button rather than after the bill. Chapters come from act timings
                    and act timings come from the mux, which is why this stage runs last.
                </div>
            </div>
        @else
            @php $job = $this->lastDraftJob(); @endphp

            @if ($job && $job->status->value === 'failed')
                <div class="alert fail">
                    <strong>The last run failed.</strong>
                    <div class="small mono" style="margin-top:4px">{{ $job->error }}</div>
                </div>
            @elseif ($job && $job->log)
                <div class="alert warn">
                    <strong>Last run trimmed something.</strong>
                    <div class="small" style="margin-top:4px">{{ $job->log }}</div>
                </div>
            @endif

            <table>
                <thead><tr><th>Call</th><th style="width:220px">Model</th></tr></thead>
                <tbody>
                @foreach ($this->draftRoster() as $line)
                    <tr><td colspan="2" class="mono small">{{ $line }}</td></tr>
                @endforeach
                </tbody>
            </table>

            <div class="muted small" style="margin-top:8px">
                Three calls, not one. The titles and the description's opening lines are the judgement
                — they are the promise that gets the click, and in this genre the title states the
                ending. The overlay text and the pinned comment are short copy written against that
                promise. The tags are a keyword list against a hard 500-character budget, which is
                arithmetic. Cents, in total.
            </div>

            @if ($this->editable())
                <div style="margin-top:10px">
                    @if ($confirmingDraft)
                        <div class="small" style="margin-bottom:8px">
                            @if ($this->draftWouldOverwrite())
                                <strong>This replaces the {{ count($titleOptions) }} title variant(s) and the
                                description opening that are already here</strong>, including anything you have
                                edited by hand. The chapter list and footer are rebuilt from the act timings
                                either way.
                            @else
                                Five titles, a description opening, thumbnail text, a pinned comment and the
                                tag list. Nothing is selected for you — picking the title is this gate.
                            @endif
                        </div>
                        <button class="gate" wire:click="draft">
                            Yes &mdash; {{ $this->draftWouldOverwrite() ? 'rewrite the sheet' : 'write the sheet' }}
                        </button>
                        <button wire:click="cancelDraft">Cancel</button>
                    @else
                        <button class="gate" wire:click="askToDraft">
                            {{ $this->draftWouldOverwrite() ? 'Rewrite the sheet' : 'Write the sheet' }}
                        </button>
                    @endif
                </div>
            @endif
        @endif
    </div>

    <h2>Title</h2>
    <div class="panel">
        <div class="field">
            <label for="title">Selected title
                <span class="mono">{{ mb_strlen($titleSelected) }}/{{ $limits['title_hard'] }}</span>
            </label>
            <input id="title" type="text" wire:model.blur="titleSelected" @disabled(! $this->editable())>
            <div class="muted small" style="margin-top:6px">
                Target {{ $limits['title_target'] }} characters. Past that the tail stops being visible in
                search and on mobile, so the emotional hook goes on the left where it survives truncation.
            </div>
        </div>

        @if ($titleOptions)
            <table>
                <thead><tr><th>Variants kept</th><th style="width:150px"></th></tr></thead>
                <tbody>
                @foreach ($titleOptions as $i => $option)
                    <tr>
                        <td>
                            {{ $option }}
                            <span class="muted mono small">{{ mb_strlen($option) }}</span>
                            @if ($option === $titleSelected) <span class="badge ok">picked</span> @endif
                        </td>
                        <td>
                            @if ($this->editable())
                                <button class="tiny" wire:click="chooseTitle({{ $i }})">use</button>
                                <button class="tiny danger" wire:click="removeTitleOption({{ $i }})">remove</button>
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif

        @if ($this->editable())
            <div class="row" style="margin-top:10px">
                <input type="text" class="grow" placeholder="Add a variant worth keeping"
                       wire:model="newTitleOption" wire:keydown.enter="addTitleOption">
                <button wire:click="addTitleOption">Add variant</button>
            </div>
            <div class="muted small" style="margin-top:6px">
                Five variants is the target, and the sheet is drafted with five. Keeping the ones you did
                not pick is what turns this into data on what actually performs &mdash; and nothing above
                picks one for you, because picking it is the gate.
            </div>
        @endif
    </div>

    <h2>Description</h2>
    <div class="panel">
        <div class="field">
            <label for="description">Description
                <span class="mono">{{ number_format(mb_strlen($description)) }}/{{ number_format($limits['description']) }}</span>
            </label>
            <textarea id="description" wire:model.blur="description" rows="14" @disabled(! $this->editable())></textarea>
            <div class="muted small" style="margin-top:6px">
                The opening two or three sentences are the real payload &mdash; they show in search and above
                the fold. Write them as a hook, not a summary. Everything below the chapter list is
                boilerplate.
            </div>
        </div>

        @if ($this->editable())
            <button wire:click="insertChapters">Rebuild chapter list + footer</button>
            <span class="muted small">
                Chapters come from the act timings the render produced. Pressing this twice does not stack
                two lists.
            </span>
        @endif
    </div>

    <h2>Chapters &mdash; derived, not stored</h2>
    <div class="panel" style="padding:0">
        <table>
            <thead><tr><th style="width:110px">Start</th><th>Title</th></tr></thead>
            <tbody>
            @forelse ($this->chapters() as $chapter)
                <tr><td class="mono">{{ $chapter['timestamp'] }}</td><td>{{ $chapter['title'] }}</td></tr>
            @empty
                <tr><td colspan="2" class="muted">No act timings yet, so there are no chapters to write.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    <h2>Tags</h2>
    <div class="panel">
        <div class="field">
            <label for="tags">Tags &mdash; comma or newline separated</label>
            <textarea id="tags" wire:model.blur="tagsInput" rows="3" @disabled(! $this->editable())></textarea>
        </div>

        <div class="row">
            <div class="grow">
                <div class="bar">
                    <span class="{{ $budget['over'] > 0 ? 'bad' : 'done' }}" style="width: {{ $budget['percent'] }}%"></span>
                </div>
            </div>
            <span class="mono {{ $budget['over'] > 0 ? '' : 'muted' }}">
                {{ $budget['used'] }}/{{ $budget['budget'] }} characters
                @if ($budget['over'] > 0) &mdash; {{ $budget['over'] }} over @endif
            </span>
        </div>

        <div class="muted small" style="margin-top:8px">
            {{ count($budget['tags']) }} tags. The budget is enforced rather than silently truncated at
            upload. Worth knowing: tags carry far less weight than the title, the thumbnail and the first
            lines of the description &mdash; write them, do not agonise over them.
        </div>
    </div>

    <h2>Thumbnail</h2>
    <div class="panel">
        <div class="field">
            <label for="thumbtext">Overlay text options &mdash; one per line, 3&ndash;5 words each</label>
            <textarea id="thumbtext" wire:model.blur="thumbnailTextInput" rows="4" @disabled(! $this->editable())></textarea>
            <div class="muted small" style="margin-top:6px">
                Text only. The app does not compose thumbnail images in this phase; it hands you the words
                and the still.
            </div>
        </div>

        <label>Recommended still</label>
        <div class="row">
            @forelse ($this->thumbnailChoices() as $choice)
                <label style="text-transform:none; letter-spacing:0; text-align:center">
                    <input type="radio" wire:model.live="thumbnailSceneId" value="{{ $choice['id'] }}"
                           @disabled(! $this->editable())>
                    <img class="still pick" loading="lazy"
                         src="{{ route('stories.still', ['story' => $story, 'scene' => $choice['id']]) }}"
                         alt="scene {{ $choice['sequence'] }}">
                    <span class="muted mono small">
                        scene {{ $choice['sequence'] }} @if ($choice['flagged']) &starf; @endif
                    </span>
                </label>
            @empty
                <span class="muted small">No scene was flagged as a thumbnail candidate at Gate 2.</span>
            @endforelse
        </div>
    </div>

    <h2>Pinned comment</h2>
    <div class="panel">
        <textarea wire:model.blur="pinnedComment" rows="3" @disabled(! $this->editable())></textarea>
    </div>

    <h2>Scheduled publish</h2>
    <div class="panel">
        <div class="field">
            <label for="publishat">Publish at &mdash; US Eastern</label>
            <input id="publishat" type="datetime-local" wire:model.blur="publishAtEastern"
                   @disabled(! $this->editable())>
            @error('publishAtEastern') <div class="small" style="color:var(--bad)">{{ $message }}</div> @enderror
        </div>

        @if ($this->publishWindow())
            <table>
                <tbody>
                <tr><td style="width:60px">ET</td><td class="mono">{{ $this->publishWindow()['et'] }}</td></tr>
                <tr><td>PHT</td><td class="mono">{{ $this->publishWindow()['pht'] }}</td></tr>
                </tbody>
            </table>
        @endif

        <div class="muted small" style="margin-top:8px">
            Typed in Eastern because that is where the viewers are &mdash; peak is roughly 6&ndash;10 PM ET
            &mdash; and stored in UTC. Both zones are shown back because that window lands in the small
            hours in Manila, which is exactly how a publish time gets fumbled. Nothing here uploads:
            this is the number you type into YouTube's own scheduler, and the checklist below asks you
            to confirm you did.
        </div>
    </div>

    <h2>Publish checklist</h2>
    <div class="panel checks">
        @foreach (config('youtube.checklist') as $key => $item)
            <label>
                <input type="checkbox" wire:model.live="checklist.{{ $key }}" @disabled(! $this->editable())>
                <span>
                    {{ $item['label'] }}
                    @if ($item['required'] ?? false) <span class="badge warn">required</span> @endif
                </span>
            </label>
        @endforeach

        <div class="muted small" style="margin-top:8px">
            Every one of these happens on YouTube, not here. The two marked required have consequences
            outside this app: the disclosure is a platform obligation, and the kids setting silently
            turns off comments and personalised ads if it is wrong.
        </div>
    </div>

    @if ($this->editable())
        <div class="row">
            <button wire:click="save">Save sheet</button>

            <button class="gate" wire:click="approve" @disabled(! $this->canApprove())
                    wire:confirm="Mark this story published? The upload itself is still yours to do.">
                Approve Gate 4 &mdash; sheet is ready
            </button>

            @unless ($this->canApprove())
                <span class="muted small">
                    @if ($story->status->value === 'rendered')
                        Approve <a href="{{ route('stories.preview', $story) }}">Gate 3</a> first.
                        The sheet can be drafted now, but it cannot be approved before somebody has
                        watched the render.
                    @else
                        Clear the blocking problems above to approve.
                    @endif
                </span>
            @endunless
        </div>
    @else
        <div class="alert ok">
            Published on {{ $story->updated_at?->format('D d M Y H:i') }}. This sheet is now read-only.
        </div>
    @endif

    <h2>Copy-paste sheet</h2>
    <div class="panel">
        <pre class="sheet">{{ $titleSelected }}

{{ $description }}

TAGS ({{ $budget['used'] }}/{{ $budget['budget'] }} chars)
{{ implode(', ', $budget['tags']) }}

PINNED COMMENT
{{ $pinnedComment }}</pre>
    </div>
</div>
