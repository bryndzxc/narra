{{--
    The one shell for the whole console.

    There were two near-identical layouts before this — `app` and `render` —
    differing in a conditional refresh tag and a footer sentence, and each
    hand-rolling its own copy of the header and nav. `render` is now a thin
    delegate to this file, so the chrome exists once.

    Props:
      $title     browser tab
      $section   which nav item is current; defaults to the route
      $subtitle  the line on the right of the top bar
      $refresh   emit the 5s meta refresh (the render pages, while work is live)
      $footer    replaces the default footer sentence

    They are DECLARED below rather than relied on. Without `@props` they arrive
    as `$attributes`, `$refresh ?? false` is silently false forever, and the
    render pages lose their meta refresh — a progress page that stops updating
    looks exactly like one where nothing is happening, which is row 7 of the
    false-success table reintroduced by a layout refactor.
--}}
@props([
    'title' => 'Narra',
    'section' => null,
    'subtitle' => null,
    'refresh' => false,
])

@php
    /*
     * The nav, as data. Adding a destination is a row here rather than another
     * copy of an anchor with its own hand-written active test — which is what
     * the two old layouts had, and they had already drifted: `render`'s copy
     * had no "new" link and no active state on `stories`.
     */
    $nav = [
        ['route' => 'dashboard', 'label' => 'Dashboard', 'match' => 'dashboard', 'icon' => '◈'],
        ['route' => 'stories.index', 'label' => 'Stories', 'match' => 'stories.*', 'icon' => '▤'],
        ['route' => 'stories.create', 'label' => 'New story', 'match' => 'stories.create', 'icon' => '＋'],
        ['route' => 'renders.index', 'label' => 'Renders', 'match' => 'renders.*', 'icon' => '◐'],
    ];

    $current = $section ?? null;
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }}</title>

    {{--
        Refreshes only while something is actually running. A page that reloads
        every five seconds forever is a page an operator closes, and then the
        one thing that reports a hung worker is not open when it matters.
    --}}
    @if ($refresh)
        <meta http-equiv="refresh" content="5">
    @endif

    {{--
        Before the stylesheet, and deliberately.

        This stamps the theme onto <html> ahead of the first paint. Run any
        later — at the end of the body, or from a listener — and every load
        flashes the default theme first, which on a page that reloads itself
        every five seconds is not a flash, it is a strobe.

        Wrapped in try/catch because localStorage throws rather than returning
        null in a few configurations, and a console that fails to render
        because it could not remember a colour preference would be an absurd
        way to lose the queue dashboard.
    --}}
    <script>
        (function () {
            var theme = null;

            try {
                theme = localStorage.getItem('narra-theme');
            } catch (e) {
                theme = null;
            }

            if (theme !== 'light' && theme !== 'dark') {
                theme = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches
                    ? 'dark'
                    : 'light';
            }

            document.documentElement.setAttribute('data-theme', theme);
        })();
    </script>

    @include('partials.base-css')
</head>
<body>
<div class="shell">
    <aside class="side">
        <a href="{{ route('dashboard') }}" class="brand">NARRA</a>

        <nav>
            @foreach ($nav as $item)
                @php
                    $on = $current !== null
                        ? $current === $item['route']
                        : request()->routeIs($item['match']);

                    // "Stories" must not light up on the create page, which has
                    // its own entry directly beneath it.
                    if ($item['route'] === 'stories.index' && request()->routeIs('stories.create')) {
                        $on = false;
                    }
                @endphp
                <a href="{{ route($item['route']) }}" @class(['on' => $on])>
                    <span class="ico" aria-hidden="true">{{ $item['icon'] }}</span>
                    {{ $item['label'] }}
                </a>
            @endforeach
        </nav>

        <div class="sidefoot">
            <button type="button" id="theme-toggle" class="btn tiny themetoggle" aria-live="polite">
                <span class="ico" aria-hidden="true">◑</span>
                <span data-theme-label>Theme</span>
            </button>

            <p class="small">
                The app produces a file and a metadata sheet. The upload, and the
                &ldquo;altered or synthetic content&rdquo; disclosure, are done by a human.
            </p>
        </div>
    </aside>

    <div class="content">
        <header class="top">
            <span class="where">{{ $title }}</span>
            <span class="right sub">{{ $subtitle ?? 'four gates, and nothing publishes itself' }}</span>
        </header>

        <main>
            {{ $slot }}
        </main>

        <footer>
            @if (isset($footer))
                {{ $footer }}
            @else
                Four gates, and none of them can be crossed by this app on its own.
            @endif
        </footer>
    </div>
</div>

<script>
    (function () {
        var button = document.getElementById('theme-toggle');

        if (! button) {
            return;
        }

        var label = button.querySelector('[data-theme-label]');

        var paint = function () {
            var now = document.documentElement.getAttribute('data-theme');
            var next = now === 'dark' ? 'light' : 'dark';

            button.setAttribute('title', 'Switch to the ' + next + ' theme');

            if (label) {
                label.textContent = now === 'dark' ? 'Dark' : 'Light';
            }
        };

        button.addEventListener('click', function () {
            var next = document.documentElement.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';

            document.documentElement.setAttribute('data-theme', next);

            // Per browser, which is what was asked for. It is also the only
            // place it COULD live: there is no user model in this app and the
            // spec says not to build one for a single-operator tool.
            try {
                localStorage.setItem('narra-theme', next);
            } catch (e) {
                // A preference that cannot be saved still applies to this page.
            }

            paint();
        });

        paint();
    })();
</script>
</body>
</html>
