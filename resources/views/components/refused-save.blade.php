{{--
    A REFUSED SAVE, SAID WHERE THE PRESS HAPPENED.

    Rendered by a gate page directly beside the control that was pressed,
    from the component's whole error bag (App\Support\RefusedFields). It is
    an ordinary `.alert.err` — the same red every refusal in the console
    wears — plus `refused`, which lets it take a whole row inside a flex bar.
    `wide` because it sits beside full-width controls.

    Literal classes and no attribute bag, so class-audit can see every class
    it carries. Props only:

      fields   the RefusedFields list
      heading  the sentence in bold: what did not happen
      status   the story's status value, or null to say nothing about it
--}}
@props(['fields', 'heading', 'status' => null])
<div class="alert err wide refused" role="alert">
    <strong>{{ $heading }}</strong>
    {{-- One expression rather than a directive, because Blade does not see a
         directive glued to the word before it: `written@if` compiled to
         nothing and took every gate page down. The status is escaped by hand
         inside the raw echo. --}}
    {{ count($fields) }} {{ count($fields) === 1 ? 'field was' : 'fields were' }} refused, so nothing was written{!! $status !== null ? ' and the story stays at <span class="mono">'.e($status).'</span>' : '' !!}:
    <ul>
        @foreach ($fields as $refused)
            <li>
                @if ($refused['anchor'] !== '')
                    <a href="#{{ $refused['anchor'] }}"><strong>{{ $refused['label'] }}</strong></a>
                @else
                    <strong>{{ $refused['label'] }}</strong>
                @endif
                &mdash; {{ $refused['message'] }}
            </li>
        @endforeach
    </ul>
</div>
