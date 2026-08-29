<x-layouts.app :title="'Stories'">
    <h1>Stories</h1>
    <p class="muted">Every video in flight, and which gate it is waiting at.</p>

    @if ($stories->isEmpty())
        <div class="panel">
            <p class="muted" style="margin:0 0 8px">Nothing here yet.</p>
            <p class="mono" style="margin:0">php artisan render:import sample-story</p>
        </div>
    @else
        <div class="panel" style="padding:0">
            <table>
                <thead>
                <tr>
                    <th>Story</th>
                    <th>Status</th>
                    <th>Waiting on</th>
                    <th>Scenes</th>
                    <th>Cost</th>
                    <th>Publish</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($stories as $story)
                    @php($gate = $story->awaitingGate())
                    <tr>
                        <td>
                            <a href="{{ route('stories.show', $story) }}">{{ $story->title }}</a>
                            <div class="muted mono">{{ $story->slug }} &middot; {{ $story->format->label() }}</div>
                        </td>
                        <td><span class="badge">{{ $story->status->value }}</span></td>
                        <td>
                            @if ($gate)
                                <span class="badge money">{{ $gate->label() }}</span>
                            @else
                                <span class="muted small">the pipeline</span>
                            @endif
                        </td>
                        <td class="mono">{{ $story->scenes_count }}</td>
                        <td class="mono">${{ number_format((float) $story->total_cost_usd, 4) }}</td>
                        <td class="muted mono small">
                            @if ($story->target_publish_at)
                                {{ $story->targetPublishAtEastern()?->format('D d M H:i') }} ET<br>
                                {{ $story->targetPublishAtManila()?->format('D d M H:i') }} PHT
                            @else
                                &mdash;
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>

        {{ $stories->links() }}
    @endif
</x-layouts.app>
