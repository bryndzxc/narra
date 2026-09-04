<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? 'Narra' }}</title>
    @include('partials.base-css')
</head>
<body>
<header class="top">
    <span class="brand">NARRA</span>
    <nav class="row" style="gap:14px">
        <a href="{{ route('stories.index') }}" class="{{ request()->routeIs('stories.*') && ! request()->routeIs('stories.create') ? 'on' : '' }}">stories</a>
        <a href="{{ route('stories.create') }}" class="{{ request()->routeIs('stories.create') ? 'on' : '' }}">new</a>
        <a href="{{ route('renders.index') }}" class="{{ request()->routeIs('renders.*') ? 'on' : '' }}">renders</a>
    </nav>
    <span class="right sub">{{ $subtitle ?? 'four gates, and nothing publishes itself' }}</span>
</header>

<main>
    {{ $slot }}
</main>

<footer>
    The app produces a file and a metadata sheet. The upload, and the
    &ldquo;altered or synthetic content&rdquo; disclosure, are done by a human.
</footer>
</body>
</html>
