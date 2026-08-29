@php
    $anyActive = $rows->contains(fn (array $row): bool => $row['running'] > 0);
@endphp

<x-layouts.render :title="'Renders'" :refresh="$anyActive">
    <h1>Renders</h1>
    <p class="muted">Every story with queue activity, most recently touched first.</p>

    @if ($rows->isEmpty())
        <div class="panel">
            <p class="muted" style="margin:0 0 8px">No stories yet.</p>
            <p class="mono" style="margin:0">php artisan render:import sample-story</p>
            <p class="mono" style="margin:0">php artisan render:dispatch sample-story</p>
        </div>
    @else
        <div class="panel" style="padding:0">
            <table>
                <thead>
                <tr>
                    <th>Story</th>
                    <th>Status</th>
                    <th style="width:220px">Jobs</th>
                    <th>Failed</th>
                    <th>Last activity</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($rows as $row)
                    @php($story = $row['story'])
                    <tr>
                        <td>
                            <a href="{{ route('renders.show', $story->slug) }}">{{ $story->title }}</a>
                            <div class="muted mono">{{ $story->slug }} &middot; {{ $story->scenes_count }} scenes</div>
                        </td>
                        <td>
                            <span class="badge {{ $row['failed'] > 0 ? 'fail' : ($row['running'] > 0 ? 'run' : 'ok') }}">
                                {{ $story->status->value }}
                            </span>
                            @if ($row['stale'] > 0)
                                <div><span class="badge warn" title="No heartbeat — likely hung">{{ $row['stale'] }} stale</span></div>
                            @endif
                        </td>
                        <td>
                            <div class="bar">
                                <span class="done" style="width: {{ $row['percent'] }}%"></span>
                                @if ($row['failed'] > 0)
                                    <span class="bad" style="width: {{ (int) floor($row['failed'] / max(1, $row['jobs']) * 100) }}%"></span>
                                @endif
                            </div>
                            <div class="muted mono">{{ $row['succeeded'] }}/{{ $row['jobs'] }} done</div>
                        </td>
                        <td class="{{ $row['failed'] > 0 ? 'mono' : 'muted mono' }}">{{ $row['failed'] }}</td>
                        <td class="muted mono">{{ $row['last_activity']?->diffForHumans() ?? '—' }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif
</x-layouts.render>
