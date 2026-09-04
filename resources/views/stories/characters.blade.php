@php
    /** @var \App\Models\Story $story */
    /** @var \App\Enums\Gate $gate */
@endphp

<x-layouts.app :title="$story->title.' — character sheets'">
    <div class="row" style="margin-bottom:4px">
        <h1 style="margin:0">{{ $story->title }}</h1>
        <span class="badge">{{ $story->status->value }}</span>
        <span class="badge money">Gate 2 &mdash; character sheets</span>
        <span class="right small">
            <a href="{{ route('stories.scenes', $story) }}">back to the scene list</a>
        </span>
    </div>

    <p class="muted mono small">
        {{ $story->slug }} &middot; {{ $story->characters()->count() }} characters &middot;
        {{ $story->scenes()->count() }} scenes &middot;
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
        Deliberately not a fifth entry in the stepper. The four gates are the
        product's central promise and their count is load-bearing; this is a
        sub-step of Gate 2, reached from it and returning to it.
    --}}
    <p class="small muted" style="max-width:70ch">
        A locked seed keeps a face stable only while everything around it holds still, and in this
        format nothing does &mdash; pose, lighting, background and who else is in frame change every
        scene by design. The reference image is the part that does not move. Pick one per character
        here, and every still they appear in is generated against it.
    </p>

    <livewire:gates.character-sheets :story="$story" />
</x-layouts.app>
