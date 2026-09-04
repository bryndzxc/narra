<div>
    @if ($problem)
        <div class="alert err pre-line">{{ $problem }}</div>
    @endif

    <div class="panel">
        <label for="premise">Premise</label>
        <div class="muted small" style="margin:0 0 8px">
            The one thing this app will not write for you. A situation, and what goes wrong in it &mdash;
            the outline is generated from these sentences and every act is written against that outline.
            Write it for a US audience: US settings, US names, imperial units, American spelling. A
            denylist runs on every generated act and fails the job loudly rather than passing Filipino
            idiom through to Gate 1.
        </div>
        <textarea id="premise" rows="6" wire:model.blur="premise"
                  placeholder="My younger brother and his wife moved into our late mother's house without asking&hellip;"></textarea>
        @error('premise') <div class="alert err mt-3">{{ $message }}</div> @enderror

        <label class="mt-7" for="cast-age">Cast age range <span class="muted small">optional</span></label>
        <div class="muted small" style="margin:0 0 8px">
            Read by the character extraction, which is where each character's age is decided and
            frozen &mdash; that description is then pasted into every one of the 150&ndash;250 stills
            they appear in. It steers the ages the script does not state outright; where the script
            does state one, the script wins, because a picture that contradicts the narration is
            worse than one outside the intended range.
            <br>
            Worth stating because the art style cannot state it for you: one style line is shared by
            every story, so it can only describe how age is drawn, never who is in this one. An anime
            style renders a cast of thirty-somethings well and a cast built around a funeral, memory
            care and an elderly uncle badly.
        </div>
        <textarea id="cast-age" rows="2" wire:model.blur="castAgeProfile"
                  placeholder="Spouses in their late twenties and thirties. Workplace and marriage settings. No elderly characters carrying plot."></textarea>
        @error('castAgeProfile') <div class="alert err mt-3">{{ $message }}</div> @enderror
    </div>

    <div class="panel">
        <div class="row">
            <div class="grow">
                <label for="title">Working title <span class="muted small">optional</span></label>
                <input id="title" type="text" wire:model.blur="title"
                       placeholder="Left blank, the first 60 characters of the premise are used.">
                <div class="muted small mt-1">
                    Not the YouTube title. That one is written after the render, five variants of it, and
                    picking one is Gate 4.
                </div>
                @error('title') <div class="alert err mt-3">{{ $message }}</div> @enderror
            </div>
        </div>

        <div class="row mt-6">
            <div>
                <label for="locale">Setting</label>
                <select id="locale" wire:model.live="localeProfile">
                    @foreach ($this->locales() as $key => $label)
                        <option value="{{ $key }}">{{ $label }}</option>
                    @endforeach
                </select>
                <div class="muted small mt-1" style="max-width:52ch">
                    Where the story is set. The narration is American English either way &mdash; this
                    changes the world, not the language.
                    <strong>China</strong> is the translated-web-novel register a large part of this
                    niche runs on: elders and in-laws with real authority, dowry and bride price, face
                    and filial duty, accusations made outright. Yuan and metric.
                    <br>
                    Chosen here and only here. Everything after this button is generated against it
                    &mdash; the outline, then the acts, then the cast &mdash; so there is no later
                    point where changing it leaves a story consistent.
                </div>
                @error('localeProfile') <div class="alert err mt-3">{{ $message }}</div> @enderror
            </div>
        </div>

        <div class="row mt-6">
            <div>
                <label for="format">Format</label>
                <select id="format" wire:model.live="format">
                    @foreach ($formats as $case)
                        <option value="{{ $case->value }}">{{ $case->label() }}</option>
                    @endforeach
                </select>
                <div class="muted small mt-1" style="max-width:52ch">
                    <strong>Single</strong> is one continuous narrative across every act &mdash; this genre
                    needs it to escalate. <strong>Anthology</strong> is 3&ndash;5 self-contained stories, one
                    per act: easier to write, lower coherence risk, and the chapter titles become natural
                    hooks.
                </div>
            </div>

            <div>
                <label for="acts">Acts <span class="muted small">optional</span></label>
                <input id="acts" type="number" min="3" max="8" wire:model.blur="acts"
                       placeholder="{{ $this->estimate()['acts'] }}">
                <div class="muted small mt-1" style="max-width:46ch">
                    One chapter per act. The default is sized for 30&ndash;40 minutes at the narrator's
                    measured pace &mdash; if that pace was measured wrong, the fix is more acts here, not
                    re-narrating later. Story 9 came back 21 seconds short and re-reading it would have
                    cost the entire remaining monthly allowance.
                </div>
                @error('acts') <div class="alert err mt-3">{{ $message }}</div> @enderror
            </div>
        </div>
    </div>

    {{-- The queue this button dispatches to, beside the button and not on some
         other page. `text` spent two phases in config and in the setup docs
         receiving nothing at all, and an absent worker is a warning rather than
         a refusal — so without this, "queued" and "queued into nothing" look
         exactly the same. --}}
    <x-worker-health :queues="[$this->workers()]" :compact="true" />

    <div class="panel money">
        <label>What this spends</label>
        <table class="mt-3">
            <tbody>
            <tr>
                <td>Billed calls</td>
                <td class="mono">{{ $this->estimate()['calls'] }}</td>
                <td class="muted small">
                    One outline, then {{ $this->estimate()['acts'] }} act scripts. Sequential &mdash; each
                    act is written knowing the ones before it. A single call cannot hold 7,000 words of
                    coherent narrative.
                </td>
            </tr>
            <tr>
                <td>Provider</td>
                <td class="mono">{{ $this->estimate()['provider'] }}</td>
                <td class="muted small">From config. What is actually bound is printed by the command.</td>
            </tr>
            @foreach ($this->estimate()['roster'] as $line)
                <tr>
                    <td colspan="3" class="mono small muted">{{ $line }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>

        <div class="muted small mt-4">
            Text only. Nothing here generates an image or a second of audio &mdash; that is behind Gate 2,
            where 150&ndash;250 stills are roughly 70% of a video's cost.
        </div>
    </div>

    <div class="actions">
        @if (! $confirming)
            <button type="button" class="primary" wire:click="askToCreate">
                Write this story
            </button>
            <span class="muted small">
                Creates the row and shows the bill. Nothing is queued by this press.
            </span>
        @else
            <div class="alert warn" style="width:100%">
                <strong>{{ $this->estimate()['calls'] }} billed call(s)</strong>, one cost row each,
                queued on the <span class="mono">{{ $this->workers()['queue'] }}</span> queue.
                @if ($this->workers()['state'] === \App\Support\WorkerHealth::ABSENT)
                    Nothing is listening on that queue right now &mdash; the jobs will wait, and nothing is
                    lost, but nothing happens until a worker starts.
                @endif
                <div class="mt-4">
                    <button type="button" class="primary" wire:click="create">
                        Queue it &mdash; {{ $this->estimate()['calls'] }} call(s)
                    </button>
                    <button type="button" wire:click="cancel">Back</button>
                </div>
            </div>
        @endif
    </div>

    <div class="panel">
        <div class="muted small">
            This stops at the act scripts, on purpose. Gate 1 is where you read the outline and approve
            it; the scenes are cut afterwards, from the Gate 2 page, as a separate press. There is no
            button in this app that carries a story through a gate.
        </div>
        @if ($existing > 0)
            <div class="small mt-3">
                <a href="{{ route('stories.index') }}">{{ $existing }} story/stories already here</a>
            </div>
        @endif
    </div>
</div>
