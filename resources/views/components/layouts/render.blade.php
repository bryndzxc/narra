<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? 'Renders' }} - Narra</title>

    {{--
        Refreshes only while something is actually running. A page that reloads
        every five seconds forever is a page an operator closes, and then the
        one thing that reports a hung worker is not open when it matters.
    --}}
    @if ($refresh ?? false)
        <meta http-equiv="refresh" content="5">
    @endif

    @include('partials.base-css')
</head>
<body>
<header class="top">
    <span class="brand">NARRA</span>
    <nav class="row" style="gap:14px">
        <a href="{{ route('stories.index') }}">stories</a>
        <a href="{{ route('renders.index') }}" class="on">renders</a>
    </nav>
    <span class="right sub">render queue &mdash; no Horizon on this platform, so this is the dashboard</span>
</header>

<main>
    {{ $slot }}
</main>

<footer>
    @if ($refresh ?? false)
        Refreshing every 5s while work is in flight.
    @else
        Nothing running &mdash; this page is not refreshing itself.
    @endif
</footer>
</body>
</html>
