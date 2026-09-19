@props(['model', 'recent' => [], 'streak' => null, 'anthology' => false])

{{--
    HOW THE VIDEO ENDS, chosen by the operator before the outline.

    One component for the new-story form and Gate 1, so the two pickers cannot
    say different things about the same choice. The last few videos' endings
    are printed with it (App\Support\RecentEndings), and a run of one ending is
    said out loud above them, because a choice made one story at a time cannot
    vary the channel unless the person making it can see the channel. Required
    with no default: a model left to pick converges, and so does a default.
--}}
<div>
    <label>Ending</label>

    @if ($anthology)
        <div class="muted small mt-1" style="max-width:60ch">
            An anthology has no ending to choose: each act is its own story and runs the whole arc itself.
        </div>
    @else
        <div class="mt-1">
            @foreach (\App\Enums\StoryEnding::cases() as $case)
                <label class="small" style="display:block; margin-bottom:6px">
                    <input type="radio" wire:model.live="{{ $model }}" value="{{ $case->value }}">
                    <strong>{{ $case->label() }}.</strong> {{ $case->description() }}
                </label>
            @endforeach
        </div>

        @error($model) <div class="alert err wide mt-3">{{ $message }}</div> @enderror

        @if ($streak !== null)
            <div class="alert warn wide mt-3">
                The last {{ $streak['count'] }} videos all ended the same way:
                <strong>{{ $streak['ending']->label() }}</strong>.
            </div>
        @endif

        @if ($recent === [])
            <div class="muted small mt-1">No earlier video has an ending on record.</div>
        @else
            <div class="muted small mt-2">How the last {{ count($recent) }} videos ended, newest first:</div>
            <ul class="small mt-1">
                @foreach ($recent as $row)
                    <li>
                        <strong>{{ $row['ending']->label() }}</strong> &mdash; {{ $row['title'] }}
                        @if (! $row['chosen'])
                            <span class="muted">(outlined before the choice existed; read from its chapters)</span>
                        @elseif (! $row['written'])
                            <span class="muted">(chosen; not written yet)</span>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    @endif
</div>
