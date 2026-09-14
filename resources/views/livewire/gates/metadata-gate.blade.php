<div>
    @if ($notice)
        <div class="alert ok wide">{{ $notice }}</div>
    @endif

    @if ($problem)
        <div class="alert fail wide">{{ $problem }}</div>
    @endif

    @php
        $validation = $this->validation();
        $budget = $this->tagBudget();

        // Past the gate: read-only, and no decision left on this page.
        //
        // In this block rather than in an inline parenthesised php directive
        // above it. Blade's raw-php pass does not know the inline form exists,
        // so one written above a block is paired with that block's closer and
        // every line between is swallowed. It did exactly that here — 57 lines
        // of the drafting panel became raw PHP and every gate page 500'd — and
        // blade-php-scan named the pair in one run. (The directive tokens are
        // not written out even in this comment: the same pass matches them
        // inside a comment exactly as it matches a real one.)
        $settled = ! $this->editable();
        $meter = $this->titleMeter();
        $limits = config('youtube.limits');
    @endphp

    @if ($this->stale())
        <div class="alert fail wide">
            <strong>This sheet describes a render that no longer exists.</strong>
            <div class="small mt-2">
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
                <div class="mt-3">
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

    {{--
        The two advisory groups, side by side and laid out against the ones that
        have findings.

        A sheet routinely has one and not the other: a fresh draft has both, a
        finished one has neither. Written as two stacked `@if`s that is fine;
        written as a fixed row it is `.dash.quiet`'s defect at group width, which
        this project has now shipped on the dashboard, on Gate 2 and on Gate 1.
        `x-gate-group` renders no element when its slot is empty and `.gatecols`
        cuts one track per element it has, so the empty case is unreachable
        rather than something an author remembers.
    --}}
    {{-- Only for a sheet that exists. On one that has never been written,
         "no title selected" and "description is empty" restate the alert above
         and push it down the page — findings about the absence of a thing the
         line above already says is absent. --}}
    @if ($this->sheetGenerated())
    <x-gate-row>
        <x-gate-group>
            @if ($validation['blocking'])
                <div class="alert fail wide">
                    {{-- The count is the same either way; whether it names approval is
                         not. This rendered "block approval" on a story at `draft`. --}}
                    <strong>{{ $this->voice()->countBlocking(count($validation['blocking'])) }}</strong>
                    <ul class="small indent">
                        @foreach ($validation['blocking'] as $problem)
                            <li>{{ $problem }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </x-gate-group>

        <x-gate-group>
            @if ($validation['warnings'])
                <div class="alert warn wide">
                    <strong>{{ count($validation['warnings']) }} warning(s).</strong>
                    <ul class="small indent">
                        @foreach ($validation['warnings'] as $warning)
                            <li>{{ $warning }}</li>
                        @endforeach
                    </ul>
                    <div class="muted small mt-2">{{ $this->voice()->blocksApproval() }}</div>
                </div>
            @endif
        </x-gate-group>
    </x-gate-row>
    @endif

    {{--
        The producer for everything below it.

        This page shipped before it: the form was here, the limits were checked,
        and the only way to fill any of it in was to type it. That gap — a UI
        built in one phase whose producer was due in the next — is the same seam
        this project has now found six times, so the button is not a convenience.
    --}}
    {{--
        THE TWO STATES THE MOCK DOES NOT DRAW.

        Gate 4's mock declares no state enum at all — its only prop is the
        theme — and what it draws is a fully written sheet that is BLOCKED. So
        both ends of this page's life were unspecified, and both of them are
        states this project has already been bitten in.

        `sheetGenerated()` is not "a row exists": mount() firstOrCreate()s one,
        so opening this page manufactures the thing it would be testing for. Two
        live stories carry a row for no other reason. The CONTENT is the answer.
    --}}
    @unless ($this->sheetGenerated())
        <div class="alert wide">
            <div class="measure">
                <strong>No publish sheet has been generated for this story yet.</strong>
                Nothing below it has been written — there are no title variants to pick from, no
                description and no tags. The panel underneath writes all of it in one run; until it
                does, an empty form here would look like a sheet somebody cleared rather than one
                nobody has made.
            </div>
        </div>
    @endunless

    {{--
        THE STRIP IS A FUNCTION OF WHERE THE STORY IS, NOT OF WHAT CAN BE DONE.

        THE DEFECT. Every sentence in here was written for a published story and
        rendered whenever the page had no decision — and `! editable()` is false
        on BOTH sides of this gate, so all of it rendered at `draft`,
        `outlined`, `scripted`, `scenes_drafted`, `scenes_approved`,
        `assets_generating`, `assets_ready` and `rendering` too. Eight statuses
        telling the operator that Gate 4 was behind a story that has not been
        outlined, and that `draft` is terminal because the file is on YouTube.
        One click from the stepper, which links all four gates from every story.

        It survived a rebuild of this page and a contract running over four
        gates at eleven statuses each, for a reason worth keeping: `GateVoice`
        governs sentences that NAME AN ACTION, and these name none. They claim a
        POSITION. That is now the same mechanism — `standing()` and
        `statusQualifier()` — so the page cannot say where the story is except
        by asking, and `claimsNotEntitledTo` greps for both kinds.

        The RENDERING condition stays `! editable()` and that is deliberate: it
        answers "is there a decision on this page", which is the layout question
        and is correct. What was wrong was reading the answer to that question as
        an answer to "which side of the gate is this story on".
    --}}
    @if ($settled)
        <div class="strip">
            @if ($this->pastThisGate())
                <span><strong>{{ $this->voice()->standing() }}</strong> The sheet is read-only and it
                      is the one that was published.</span>
            @else
                <span><strong>{{ $this->voice()->standing() }}</strong> There is no sheet to write
                      yet, and nothing on this page can be filled in until there is.</span>
            @endif

            <span class="badge">{{ $this->metadata->status->value }}</span>

            <details class="why">
                <summary>What this status means</summary>
                <div class="small muted mt-2">
                    The story is at
                    <span class="mono">{{ $story->status->value }}</span>{{ $this->voice()->statusQualifier() }}.

                    @if ($this->pastThisGate())
                        The sheet is kept so the upload can be checked against what was approved.
                    @else
                        Gate 4 opens after the render: every chapter timestamp below is derived from
                        act timings, and act timings are written by the mux. Until then the sheet has
                        nothing to be built out of.
                    @endif
                </div>
            </details>
        </div>
    @endif


    {{--
        THE PANEL GOES ON A PUBLISHED STORY; THE REFUSAL DOES NOT GET QUIETER.

        On `published` this rendered a red "Not yet - the story is published"
        directly under a strip already saying the gate is behind this story and
        that the sheet is read-only. Both were true. The second one was chrome:
        a refusal repeated in a state that has already been explained is exactly
        the wear the alarm-band rule exists to prevent, and it is worse here
        than an ordinary duplication because it sits on a surface whose whole
        offer is a button that cannot be drawn.

        The rule this page keeps is that no refusal gets QUIETER - not that a
        refusal is kept wherever it was once written. Nothing below is toned
        down: `draftBlockers()` is unchanged and still loud everywhere it can
        still be acted on, `draft()` refuses through the same predicate, and
        `metadata:generate` refuses at the same statuses. What goes is a section
        offering an action that cannot exist - an empty group, handled by the
        mechanism already built rather than by a fourth hand-written condition.

        `pastThisGate()`, not `$settled`. `editable()` is false on BOTH sides of
        this gate, and before it the refusal is the only thing on the page that
        names what has to happen first.
    --}}
    <x-gate-group>
    @unless ($this->pastThisGate())
    <h2>Draft the sheet</h2>
    <div class="panel">
        @if ($this->drafting())
            <div class="alert warn wide">
                <strong>Writing the sheet now.</strong>
                <div class="small mt-1">
                    Three calls on the <span class="mono">{{ config('render.queues.text') }}</span> queue.
                    <a href="{{ route('renders.show', $story->slug) }}">Watch it</a>, then reload this page.
                </div>
            </div>
        @elseif ($this->draftBlockers())
            <div class="alert fail wide">
                <strong>Not yet.</strong>
                <ul class="small indent">
                    @foreach ($this->draftBlockers() as $blocker)
                        <li>{{ $blocker }}</li>
                    @endforeach
                </ul>
                <div class="small muted mt-2">
                    Checked before the button rather than after the bill. Chapters come from act timings
                    and act timings come from the mux, which is why this stage runs last.
                </div>
            </div>
        @else
            @php $job = $this->lastDraftJob(); @endphp

            @if ($job && $job->status->value === 'failed')
                <div class="alert fail wide">
                    <strong>The last run failed.</strong>
                    <div class="small mono mt-1">{{ $job->error }}</div>
                </div>
            @elseif ($job && $job->log)
                <div class="alert warn wide">
                    <strong>Last run trimmed something.</strong>
                    <div class="small mt-1">{{ $job->log }}</div>
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

            <div class="muted small mt-3">
                Three calls, not one. The titles and the description's opening lines are the judgement
                — they are the promise that gets the click, and in this genre the title states the
                ending. The overlay text and the pinned comment are short copy written against that
                promise. The tags are a keyword list against a hard 500-character budget, which is
                arithmetic. Cents, in total.
            </div>

            @if ($this->editable())
                <div class="mt-4">
                    @if ($confirmingDraft)
                        <div class="small mb-3">
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
    @endunless
    </x-gate-group>

    {{-- Everything below is the sheet itself. With nothing generated there is
         no sheet to show, and a full form over an empty row is the original
         Gate 4 defect — the form was there, the limits were checked, and there
         was nothing behind any of it. The draft panel above is what fills it. --}}
    @if ($this->sheetGenerated())
    <h2>Title</h2>
    <div class="panel">
        <div class="field">
            <label for="title">Selected title
                <span class="mono">{{ mb_strlen($titleSelected) }}/{{ $limits['title_hard'] }}</span>
            </label>
            <input id="title" type="text" wire:model.blur="titleSelected" @disabled(! $this->editable())>
            @error('titleSelected') <div class="error">{{ $message }}</div> @enderror

            {{-- Clamped, and the overage said in words. The mock draws this for
                 a title inside both marks; a title over 100 is the case the
                 hard limit exists FOR, and a fill computed as length/100 walks
                 off the element at 101. Same shape as Gate 3's window bar. The
                 limit itself is untouched — the validator still refuses. --}}
            <div class="meter">
                <span class="track"></span>
                <span class="used {{ $meter['tone'] }}" style="width:{{ $meter['used_percent'] }}%"></span>
                <span class="cap" style="left:{{ $meter['target_percent'] }}%"></span>
            </div>
            <div class="row small">
                <span class="mono muted">target {{ $meter['target'] }}</span>
                <span class="mono muted">hard {{ $meter['hard'] }}</span>
                @if ($meter['over_hard'] > 0)
                    <span class="badge fail">{{ $meter['over_hard'] }} over the hard limit</span>
                @elseif ($meter['over_target'] > 0)
                    <span class="badge warn">{{ $meter['over_target'] }} over the target</span>
                @endif
            </div>

            <div class="muted small mt-2 measure">
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
            <div class="row mt-4">
                <input type="text" class="grow" placeholder="Add a variant worth keeping"
                       wire:model="newTitleOption" wire:keydown.enter="addTitleOption">
                <button wire:click="addTitleOption">Add variant</button>
            </div>
            <div class="muted small mt-2">
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
            @error('description') <div class="error">{{ $message }}</div> @enderror
            <div class="muted small mt-2">
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
    <div class="panel flush">
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

        {{-- Which tags are past the line, not just that the total is.
             "Enforce it, do not silently truncate" is the spec's own wording,
             and a total alone cannot be acted on: dropping these here is a
             decision, dropping them at upload is an accident. The running count
             is the same arithmetic as YoutubeMetadata::charCountFor() rather
             than a second one that agrees only today. --}}
        @if ($this->tagRows())
            <div class="tags mt-3">
                @foreach ($this->tagRows() as $tag)
                    <span class="tagrow">
                        <span>{{ $tag['text'] }}</span>
                        <span class="mono">{{ $tag['running'] }}</span>
                        @if ($tag['over'])
                            <span class="over">dropped</span>
                        @endif
                    </span>
                @endforeach
            </div>
        @endif

        <div class="muted small mt-3 measure">
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
            <div class="muted small mt-2">
                Kept for the record and for a title that has to agree with the picture. The composed
                thumbnails below carry no text &mdash; in this format the title is the hook, and words
                burned into the image compete with it at the size anyone actually sees.
            </div>
        </div>

        {{-- The compositions. Two stills the story already owns, cropped to
             panels and butted together: no image is generated here and nothing
             is billed, which is why this button has no confirm step in front of
             it while the drafting button below does. --}}
        <label>Composed thumbnails &mdash; 1280&times;720, from stills you already own</label>

        @if ($this->thumbnailBlocker())
            <div class="alert warn mt-2 wide">
                <strong>Thumbnails cannot be composed.</strong> {{ $this->thumbnailBlocker() }}
            </div>
        @else
            <div class="actions" style="margin:6px 0 10px">
                <button type="button" wire:click="composeThumbnails">
                    {{ $this->thumbnailOptions() ? 'Re-compose thumbnails' : 'Compose thumbnails' }}
                </button>
                <span class="muted small">
                    Free. Crops frames this story has already paid for &mdash; no image is generated.
                </span>
            </div>
        @endif

        @if ($this->thumbnailOptions())
            <div class="row" style="align-items:flex-start">
                @foreach ($this->thumbnailOptions() as $option)
                    <label class="pickcard">
                        <span class="head">
                            <input type="radio" wire:model.live="thumbnailChoice" value="{{ $option['key'] }}"
                                   @disabled(! $this->editable())>
                            <span class="mono small muted">
                                scenes {{ implode(' + ', array_column($option['panels'], 'sequence')) }}
                                &middot; {{ number_format(($option['bytes'] ?? 0) / 1024) }} KB
                            </span>
                        </span>

                        <img class="still pick" loading="lazy"
                             src="{{ route('stories.thumbnail', ['story' => $story, 'key' => $option['key']]) }}"
                             alt="thumbnail composition {{ $option['key'] }}">

                        {{-- The ranking's reasoning, printed. This is a proxy for
                             face size built from the cast and the frame text, not
                             face detection, so a bad order has to be visibly a bad
                             order rather than an unexplained one. Left and right
                             are named: two bare "close" badges said nothing about
                             which panel each described. --}}
                        <span class="why">
                            @foreach ($option['panels'] as $i => $panel)
                                <span class="badge {{ $panel['shot'] === 'wide' ? 'warn' : '' }}">
                                    {{ $i === 0 ? 'left' : 'right' }}: {{ $panel['shot'] }}
                                </span>
                            @endforeach
                            @foreach ($option['reasons'] ?? [] as $reason)
                                <span class="mt-1" style="display:block">{{ $reason }}</span>
                            @endforeach
                        </span>
                    </label>
                @endforeach
            </div>
            <div class="muted small mt-2">
                The pick is copied to the delivery folder as <span class="mono">&lt;slug&gt;.jpg</span>
                when you save, beside the video. Ranked by how well each still is likely to read at
                thumbnail size &mdash; who is recorded in the frame, and how the frame was written &mdash;
                which is a proxy for face size and not face detection. You are looking at the actual
                images; the ranking is not.
            </div>
        @endif

        <label class="mt-7">Recommended still</label>
        <div class="row">
            @forelse ($this->thumbnailChoices() as $choice)
                <label class="tc" style="text-transform:none; letter-spacing:0">
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

        <div class="muted small mt-3">
            Typed in Eastern because that is where the viewers are &mdash; peak is roughly 6&ndash;10 PM ET
            &mdash; and stored in UTC. Both zones are shown back because that window lands in the small
            hours in Manila, which is exactly how a publish time gets fumbled. Nothing here uploads:
            this is the number you type into YouTube's own scheduler, and the checklist below asks you
            to confirm you did.
        </div>
    </div>

    <h2>Publish checklist</h2>
    <div class="panel checks">
        {{-- Each item states the value it is asking about, so the tick means
             "I entered THIS" rather than "I did a thing". A box that only asks
             is unfalsifiable, and it is how a video very nearly went out under
             Gaming. Channel constants come from config/youtube.php; the
             per-story ones from this sheet. --}}
        @foreach ($this->checklistItems() as $item)
            <label>
                <input type="checkbox" wire:model.live="checklist.{{ $item['key'] }}" @disabled(! $this->editable())>
                <span>
                    {{ $item['label'] }}
                    @if ($item['required']) <span class="badge warn">required</span> @endif

                    @if ($item['answerable'] && $item['value'] !== null)
                        <div class="mono mt-hair">
                            <span class="muted small">set to</span> <strong>{{ $item['value'] }}</strong>
                        </div>
                        @if ($item['detail'])
                            <div class="muted small">{{ $item['detail'] }}</div>
                        @endif
                    @elseif (! $item['answerable'])
                        {{-- Never blank. A blank beside a tick box reads as
                             "nothing needed here", which is the absence-as-
                             agreement mistake in miniature. --}}
                        <div class="small mt-hair">
                            <span class="badge warn">nothing to enter</span>
                            <span class="muted">{{ $item['detail'] }}</span>
                        </div>
                    @endif
                </span>
            </label>
        @endforeach

        <div class="muted small mt-3">
            Every one of these happens on YouTube, not here. The values are this channel's, from
            <code>config/youtube.php</code> &mdash; change them there, not per upload. The two marked
            required have consequences outside this app: the disclosure is a platform obligation, and
            the kids setting silently turns off comments and personalised ads if it is wrong.
        </div>
    </div>

    @endif

    @if ($this->editable())
        {{-- A refused save says so HERE, above the buttons that were pressed,
             from the whole error bag. `approve()` saves first, so a refused
             save is a refused gate crossing and the heading says both. --}}
        @if ($errors->any())
            <x-refused-save :fields="$this->refusedFields()"
                            heading="Sheet not saved, and Gate 4 not crossed."
                            :status="$story->status->value" />
        @endif

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
    @elseif ($this->pastThisGate())
        {{-- TWO DEFECTS IN ONE SENTENCE, and only one of them was position.

             It read "Published on <date>. This sheet is now read-only" and was
             the `@ else` of editable(), so it rendered on eight statuses where
             the story is not published — a GREEN alert over a story that had not
             been outlined, `ok` being the one colour on this page that means
             everything is finished. That half is fixed by the condition.

             The other half is that `updated_at` was never a publication date.
             It moves on any write, and `CostEntry::created` increments
             `stories.total_cost_usd`, which is a write. Story 9's banner
             resolved to 2026-09-02 02:16:15 — to the second, the moment a
             `fal` STYLE PREVIEW was billed, a day after the sheet was approved,
             and evaluation spend is the one category deliberately kept out of a
             video's cost. The next preview run would move it again.

             No column is substituted, because there is nothing to substitute:
             this app never uploads, so it has no publication event to record.
             The sentence now claims only what the app did itself — it crossed
             its own gate — through the same clause Gate 1's locked banner uses,
             so it is a checked position claim rather than a hand-written one.
             A real crossing timestamp is a reasonable thing to want later; it
             is not this. --}}
        <div class="alert ok wide">
            {{ $this->voice()->approved() }}. This sheet is read-only.
        </div>
    @endif

    @if ($this->sheetGenerated())
    <h2>Copy-paste sheet</h2>
    <div class="panel">
        <pre class="sheet">{{ $titleSelected }}

{{ $description }}

TAGS ({{ $budget['used'] }}/{{ $budget['budget'] }} chars)
{{ implode(', ', $budget['tags']) }}

UPLOAD SETTINGS
{{ \App\Support\PublishChecklist::uploadSettingsBlock($story, $this->metadata) }}

PINNED COMMENT
{{ $pinnedComment }}</pre>
    </div>
    @endif
</div>
