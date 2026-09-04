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
    /*
     * The two standing counts. One sweep, memoised for the request, shared by
     * the rail and by the dashboard below it — a badge that disagreed with the
     * page it links to would be a parallel computation of a figure that already
     * exists, which is a defect this project has paid for twice.
     */
    $counts = \App\Support\ConsoleCounts::all();

    $nav = [
        ['route' => 'dashboard', 'label' => 'Dashboard', 'match' => 'dashboard', 'icon' => '◈'],
        [
            'route' => 'stories.index',
            'label' => 'Stories',
            'match' => 'stories.*',
            'icon' => '▤',
            // Gates standing open. Gold, because it is a decision waiting.
            'count' => $counts['waiting'] ?: null,
        ],
        ['route' => 'stories.create', 'label' => 'New story', 'match' => 'stories.create', 'icon' => '＋'],
        [
            'route' => 'renders.index',
            'label' => 'Renders',
            'match' => 'renders.*',
            'icon' => '◐',
            // Jobs waiting on a queue. Red only when nothing is listening to
            // them: depth alone is a worker working, depth with nobody on it is
            // the pipeline stopped.
            'count' => $counts['queued'] ?: null,
            'loud' => $counts['stranded'],
        ],
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
                    @if (($item['count'] ?? null) !== null)
                        <span @class(['count', 'loud' => $item['loud'] ?? false])
                              title="{{ $item['loud'] ?? false
                                  ? 'Jobs are waiting on a queue with nothing listening.'
                                  : 'Waiting on you.' }}">{{ $item['count'] }}</span>
                    @endif
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

            {{-- What the console is holding, in the frame rather than in a
                 panel, because it is true on every page. Both figures come
                 from the same sweep the rail's badges read. --}}
            <span class="stat">
                {{ $counts['waiting'] }} {{ \Illuminate\Support\Str::plural('gate', $counts['waiting']) }} waiting
                @if ($counts['queued'] > 0)
                    &middot; {{ number_format($counts['queued']) }} queued{{ $counts['stranded'] ? ', stranded' : '' }}
                @endif
            </span>

            <span class="right sub">{{ $subtitle ?? 'four gates, and nothing publishes itself' }}</span>

            {{-- Month-to-date, from SpendSummary — the same predicate the
                 ledger maintains the per-story totals with. --}}
            <span class="stat spend" title="Video spend this month. Evaluation spend is real and is kept out of it; the dashboard prints it separately.">
                ${{ number_format(\App\Support\SpendSummary::forCurrentMonth()->monthVideoSpend, 2) }}
                <span class="when">{{ now()->format('M') }}</span>
            </span>
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

    /*
     * Age every reading stamp on the page, once a second.
     *
     * A rendered page cannot know how long it has been sitting there, and this
     * console has pages that deliberately stop refreshing: the renders views
     * drop their meta refresh whenever nothing is running, which is precisely
     * when queue workers get restarted. The result is a worker-health panel
     * reporting processes that no longer exist — every number on it true as of
     * a moment that has passed, which is the same defect as a render page
     * showing a previous run's stages as current.
     *
     * The server cannot fix that; only the clock in the browser can. So the
     * panel carries the epoch second it was read at and this ages it.
     *
     * Rescanned every tick rather than bound once, because livewire replaces
     * the DOM on every poll and a listener attached to the old nodes would
     * quietly stop updating — a staleness indicator that itself goes stale
     * being the one outcome worth designing against.
     */
    (function () {
        var STALE_AFTER = 60; // 4x the 15s poll: comfortably not a slow request.

        var tick = function () {
            var now = Date.now() / 1000;

            document.querySelectorAll('[data-read-at]').forEach(function (el) {
                var age = Math.max(0, Math.round(now - parseInt(el.dataset.readAt, 10)));
                var label = el.querySelector('[data-reading-age]');
                var warn = el.querySelector('[data-reading-warn]');
                var text;

                if (age < 10) {
                    text = 'read just now';
                } else if (age < 90) {
                    text = 'read ' + age + 's ago';
                } else if (age < 5400) {
                    text = 'read ' + Math.round(age / 60) + 'm ago';
                } else {
                    text = 'read ' + (Math.round(age / 360) / 10) + 'h ago';
                }

                if (label) {
                    label.textContent = text;
                }

                var stale = age >= STALE_AFTER;

                el.classList.toggle('stale', stale);

                if (warn) {
                    warn.hidden = ! stale;
                }
            });
        };

        tick();
        setInterval(tick, 1000);
    })();
</script>
</body>
</html>
