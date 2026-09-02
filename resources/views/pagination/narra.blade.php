{{--
    Pagination, written against this project's own CSS.

    Laravel's default paginator view is `pagination::tailwind` and Livewire
    overrides it with `livewire::tailwind` for its own components. Neither works
    here: this app ships hand-written CSS in partials/base-css.blade.php and has
    no Tailwind at all, so every utility class in those views is inert.

    The visible symptom was a chevron the height of the viewport on the Gate 2
    scenes page. `hidden sm:flex` did not hide the desktop block, and `w-5 h-5`
    did not size the inline SVG, so the icon expanded to fill its container.

    No SVG here, deliberately. A text arrow cannot be mis-sized by a missing
    stylesheet, and this app has exactly one stylesheet to lose.
--}}
@if ($paginator->hasPages())
    <nav class="pagination" role="navigation" aria-label="Pagination">
        @if ($paginator->onFirstPage())
            <span class="page disabled" aria-disabled="true">&laquo; prev</span>
        @else
            <a class="page" href="{{ $paginator->previousPageUrl() }}" rel="prev">&laquo; prev</a>
        @endif

        @foreach ($elements as $element)
            @if (is_string($element))
                <span class="page gap">{{ $element }}</span>
            @endif

            @if (is_array($element))
                @foreach ($element as $page => $url)
                    @if ($page == $paginator->currentPage())
                        <span class="page current" aria-current="page">{{ $page }}</span>
                    @else
                        <a class="page" href="{{ $url }}">{{ $page }}</a>
                    @endif
                @endforeach
            @endif
        @endforeach

        @if ($paginator->hasMorePages())
            <a class="page" href="{{ $paginator->nextPageUrl() }}" rel="next">next &raquo;</a>
        @else
            <span class="page disabled" aria-disabled="true">next &raquo;</span>
        @endif

        <span class="muted small pagination-count">
            {{ $paginator->firstItem() }}&ndash;{{ $paginator->lastItem() }} of {{ $paginator->total() }}
        </span>
    </nav>
@endif
