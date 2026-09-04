{{--
    The render pages' layout, which is now the console's layout with two words
    changed.

    It used to be a second full copy of the chrome — its own doctype, its own
    header, its own hand-written nav — and the two copies had already drifted:
    this one had no "new story" link and no active state on `stories`. Two
    hand-maintained copies of one thing is the shape this project keeps finding
    at the bottom of its bugs, and a layout is no more exempt than a guard is.
--}}
@props(['title' => 'Renders', 'refresh' => false])

{{--
    The subtitle is a real em dash, not `&mdash;`.

    It is a PROP, and the shell echoes it with `{{ }}` — so an entity written
    here arrives escaped and the page reads "render queue &mdash; no Horizon".
    It survived as raw markup for as long as the header was hand-written in
    each layout; folding the two layouts into one turned it into data.

    The fix is the character, never `{!! !!}`. Unescaping a prop to render a
    dash opens the whole component to whatever is passed to it later, which is
    an absurd price for a punctuation mark.

    Entities are still fine in slot CONTENT and in ordinary attributes — the
    footer below and the `title=""` tooltips elsewhere are raw markup, and the
    browser decodes those itself.
--}}
<x-layouts.app
    :title="$title"
    section="renders.index"
    :refresh="$refresh"
    subtitle="render queue — no Horizon on this platform, so this is the dashboard"
>
    {{ $slot }}

    <x-slot:footer>
        @if ($refresh)
            Refreshing every 5s while work is in flight.
        @else
            Nothing running &mdash; this page is not refreshing itself.
        @endif
    </x-slot:footer>
</x-layouts.app>
