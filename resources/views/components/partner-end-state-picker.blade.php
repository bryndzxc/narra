@props(['model', 'recent' => [], 'required' => false, 'partner' => null, 'settled' => false])

{{--
    WHAT THE NARRATOR AND THE FUTURE PARTNER ARE TO EACH OTHER BY THE END.

    One component for the new-story form and Gate 1, for the ending picker's
    reason: two pickers for one choice say different things about it within a
    month. Its own component rather than a second use of x-ending-picker,
    because the two are not the same question and only this one is conditional
    — it has a subject only when the cast names someone.

    Story 39 is why it exists: the operator's idea said "married her older
    sister" and the outline came back "introduces me to a room as her partner",
    which is a faithful rendering of the only words any prompt offered. See
    App\Enums\PartnerEndState.
--}}
<div>
    <label>By the end, the two of them are</label>

    <div class="muted small mt-1" style="max-width:70ch">
        @if ($partner !== null)
            Your cast names <strong>{{ $partner }}</strong> as the person the narrator ends up with.
        @else
            Read only if this story's cast ends up naming someone the narrator ends up with. Leave it
            unset if there is nobody &mdash; the ending is then the narrator alone and fine, and nothing
            here is used.
        @endif
        @if ($required)
            The outline is refused until you choose: it writes the relationship line and every act
            summary from this, so it cannot be picked afterwards without writing the outline again.
        @endif
    </div>

    <div class="mt-1">
        @foreach (\App\Enums\PartnerEndState::cases() as $case)
            <label class="small" style="display:block; margin-bottom:6px">
                <input type="radio" wire:model.live="{{ $model }}" value="{{ $case->value }}" @disabled($settled)>
                <strong>{{ $case->label() }}.</strong> {{ $case->description() }}
            </label>
        @endforeach
    </div>

    @error($model) <div class="alert err wide mt-3">{{ $message }}</div> @enderror

    {{-- The same visibility argument as the ending history: a choice made one
         story at a time cannot vary the channel unless the person making it can
         see the channel. One line rather than a list, because this is a
         narrower question than the ending and only stories that chose appear. --}}
    @if ($recent !== [])
        <div class="muted small mt-2">
            The last {{ count($recent) }} video(s) that chose one:
            {{ implode(', ', array_map(fn (array $row) => mb_strtolower($row['state']->label()), $recent)) }}.
        </div>
    @endif
</div>
