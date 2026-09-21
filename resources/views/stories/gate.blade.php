@php
    /** @var \App\Models\Story $story */
    /** @var \App\Enums\Gate $gate */
    $waiting = $story->awaitingGate();

    $routes = [
        1 => 'stories.outline',
        2 => 'stories.scenes',
        3 => 'stories.preview',
        4 => 'stories.metadata',
    ];

    $blurbs = [
        1 => 'Premise and act outline',
        2 => 'Every scene, before anything bills',
        3 => 'Watch the render',
        4 => 'The publish sheet',
    ];
@endphp

<x-layouts.app :title="$story->title">
    {{-- The new-story form flashes what it did and redirects HERE. Nothing
         here rendered it, so "created and queued" never reached the operator
         on the page it was written for. --}}
    @if (session('notice'))
        <div class="alert ok wide">{{ session('notice') }}</div>
    @endif

    <div class="row mb-1">
        <h1 class="m-none">{{ $story->title }}</h1>
        <span class="badge">{{ $story->status->value }}</span>
        @if ($waiting)
            <span class="badge money">{{ $waiting->label() }} awaiting you</span>
        @endif
        <span class="right small">
            <a href="{{ route('renders.show', $story->slug) }}">render progress</a>
        </span>
    </div>
    <p class="muted mono small">
        {{ $story->slug }} &middot; {{ $story->scenes()->count() }} scenes &middot;
        {{ $story->acts()->count() }} acts &middot;
        ${{ number_format((float) $story->total_cost_usd, 4) }} spent
        @if ($story->evaluationSpend() > 0)
            {{-- Kept out of the total on purpose: a style preview or a bake-off
                 borrowed this cast to test the channel and is not part of this
                 video. Shown anyway, because spend that is logged and nowhere
                 on screen is the shape this project keeps mistaking for fine. --}}
            &middot; <span class="muted">+ ${{ number_format($story->evaluationSpend(), 4) }} evaluation</span>
        @endif
    </p>

    {{--
        A fixture says so on its own page, and says why.

        Without this the flag is invisible from here and the only symptom is an
        absence: the story never appears in "Waiting on you" or "Not moving" and
        nothing explains it. Future-you finds a story parked at `rendered` for a
        year, goes looking for the bug, and the bug is a deliberate decision
        nobody wrote down — which is the shape this project keeps finding at the
        bottom of its own defects.

        Advisory rather than alarm: nothing is wrong here. It is the one panel on
        a story page that exists to STOP somebody acting.
    --}}
    @if ($story->isFixture())
        {{-- `wide`, because this renders beside a full-width gate body at every
             status. It was the last alert in the console at the 96ch cap, and
             it was invisible to every check: `alertsWithoutTheirOwnWidth` runs
             over each gate COMPONENT's markup, and this wrapper is not part of
             any of them. --}}
        <div class="alert wide">
            <strong>This is a fixture. It is not going to be published, and it is not waiting on you.</strong>
            <div class="mt-1">{{ $story->fixture_note }}</div>
            <div class="muted small mt-2">
                It is kept out of &ldquo;Waiting on you&rdquo;, out of &ldquo;Not moving&rdquo; and out of
                the count in the rail &mdash; a section that always holds something it should not is a
                section you learn to skim. Everything else works normally: its costs count, its pages open,
                and every gate below still does what it says.
            </div>
        </div>
    @endif

    {{--
        A cleared story says so, for the same reason a fixture does, and it is
        the more misleading of the two without a line here.

        Every path column is null and no file is behind them, so Gate 2 shows
        scenes with no stills, the faces page shows characters with no
        reference, and both are TRUE. Read cold they say the assets failed to
        generate on a story that is on YouTube. That is the false-success shape
        inverted — an accurate reading presented as a problem — and the only
        thing that can tell the two apart is a record that it was deliberate.

        Advisory, never an alarm: nothing is wrong, and the one thing it has to
        stop is somebody pressing a spend button to "fix" it.
    --}}
    @if ($story->assetsCleared())
        <div class="alert wide">
            <strong>The working assets of this story were cleared on purpose.</strong>
            <div class="mt-1">
                Deleted {{ $story->assets_cleared_at->toFormattedDayDateString() }}, freeing
                {{ \App\Actions\ClearStoryAssets::human((int) $story->assets_cleared_bytes) }}
                &mdash; the stills, the per-scene narration, the reference sheets and the composed
                thumbnails. Nothing generated below this point is missing by accident.
            </div>
            <div class="muted small mt-2">
                Every scene, character, cost and render-job row was kept, and so were the word timings,
                the subtitles and the scene manifest. The path columns are empty rather than naming files
                that are gone, which is why the pages below show no pictures.
                <strong>Re-rendering this story means buying its stills and its narration again.</strong>
            </div>
        </div>
    @endif

    {{-- The stepper is the spine of the whole tool: four gates, always visible,
         always in order, so it is never unclear which decision is outstanding. --}}
    <div class="gates">
        @foreach (\App\Enums\Gate::cases() as $g)
            @php
                $passed = $story->hasPassedGate($g);
                $current = $waiting === $g;
                $class = $current ? 'current' : ($passed ? 'passed' : 'locked');
            @endphp
            {{-- `viewing` is a real rule now. It was written here from the
                 start and defined nowhere, so this tag carried an inline
                 border-colour to do the job its own class was already asking
                 for. --}}
            <a href="{{ route($routes[$g->value], $story) }}"
               class="{{ $class }} {{ $g === $gate ? 'viewing' : '' }}">
                <div class="num">Gate {{ $g->value }}</div>
                <div class="name">{{ $g->name }}</div>
                <div class="muted small">{{ $blurbs[$g->value] }}</div>
                <div class="state">
                    @if ($current) waiting on you
                    @elseif ($passed) approved
                    @else not reached
                    @endif
                </div>
            </a>
        @endforeach
    </div>

    @switch($gate->value)
        @case(1) <livewire:gates.outline-gate :story="$story" /> @break
        @case(2) <livewire:gates.scenes-gate :story="$story" /> @break
        @case(3) <livewire:gates.preview-gate :story="$story" /> @break
        @case(4) <livewire:gates.metadata-gate :story="$story" /> @break
    @endswitch
</x-layouts.app>
