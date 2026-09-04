{{--
    Failed scenes, listed on the page that retries them.

    The batch policy is that three failures out of 186 flag for retry rather
    than failing the video — which is only true if the three are findable.

    In one place because it renders in two, and WHERE it renders is the point.
    When there is something to authorise it sits under the decision row, because
    the button that re-dispatches these is up there. When there is not — a
    published story with one scene that never got its assets — it is the only
    actionable thing on the page and it goes to the top, full width. A failure
    is not a quiet state, which is the same rule the dashboard's quiet layout
    keeps: anything that has actually failed is an alert at the top of the page.

    @var \Illuminate\Support\Collection<int, array{sequence: int, stage: string, error: string}> $failures
--}}
@if ($failures->isNotEmpty())
    <div class="alert warn wide">
        <strong>{{ $failures->count() }} scene(s) failed asset generation.</strong>
        <div class="small mt-1">
            The rest of the story is unaffected and is not re-billed.
            {{ $retryable
                ? 'The button above re-runs only these.'
                : 'Asset generation is not available at this status, so there is no retry from this page — the scene is named here so it is findable rather than only countable.' }}
        </div>
        <table class="small mt-3" style="width:100%">
            <thead>
                <tr><th style="width:60px">Scene</th><th style="width:140px">Stage</th><th>Error</th></tr>
            </thead>
            <tbody>
                @foreach ($failures as $failure)
                    <tr>
                        <td class="mono">{{ $failure['sequence'] }}</td>
                        <td class="mono">{{ $failure['stage'] }}</td>
                        <td class="mono" style="word-break:break-word">{{ $failure['error'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif
