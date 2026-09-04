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
