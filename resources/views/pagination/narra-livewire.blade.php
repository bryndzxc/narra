{{--
    The same paginator, driven by Livewire rather than by hrefs.

    Livewire replaces Laravel's default paginator view with `livewire::tailwind`
    for its own components, so setting Paginator::defaultView() alone fixes the
    plain Blade pages and leaves Gate 2 — the 199-scene page this was actually
    reported on — still rendering an unstyled Tailwind view.

    Identical markup and classes to pagination/narra.blade.php; only the
    navigation differs. wire:key is on every control because Livewire diffs this
    list on every page change and unkeyed siblings get reused for the wrong
    page.
--}}
@if ($paginator->hasPages())
    <nav class="pagination" role="navigation" aria-label="Pagination">
        @if ($paginator->onFirstPage())
            <span class="page disabled" aria-disabled="true">&laquo; prev</span>
        @else
            <button type="button" class="page" wire:key="page-prev"
                    wire:click="previousPage('{{ $paginator->getPageName() }}')">&laquo; prev</button>
        @endif

        @foreach ($elements as $element)
            @if (is_string($element))
                <span class="page gap">{{ $element }}</span>
            @endif

            @if (is_array($element))
                @foreach ($element as $page => $url)
                    @if ($page == $paginator->currentPage())
                        <span class="page current" aria-current="page" wire:key="page-{{ $page }}">{{ $page }}</span>
                    @else
                        <button type="button" class="page" wire:key="page-{{ $page }}"
                                wire:click="gotoPage({{ $page }}, '{{ $paginator->getPageName() }}')">{{ $page }}</button>
                    @endif
                @endforeach
            @endif
        @endforeach

        @if ($paginator->hasMorePages())
            <button type="button" class="page" wire:key="page-next"
                    wire:click="nextPage('{{ $paginator->getPageName() }}')">next &raquo;</button>
        @else
            <span class="page disabled" aria-disabled="true">next &raquo;</span>
        @endif

        <span class="muted small pagination-count">
            {{ $paginator->firstItem() }}&ndash;{{ $paginator->lastItem() }} of {{ $paginator->total() }}
        </span>
    </nav>
@endif
