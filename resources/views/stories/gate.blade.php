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
