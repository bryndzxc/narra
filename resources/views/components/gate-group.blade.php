{{--
    One column of a decision/advisory row, which exists only if it has something
    in it.

    THE DEFECT. Gate 1's row has three groups — spine problems, locale terms,
    structural warnings — and on the published story only two had findings. The
    third was still a `<div>`, so the grid still cut it a track, and the row
    opened on an empty column that pushed the two real ones right.

    That is `.dash.quiet`'s defect at the width of one column: a layout that is
    a constant where it should be a function of state. It had been fixed on the
    dashboard, then again on Gate 2 as a whole-page quiet state — and the tests
    written for those measure a whole page being empty, so neither could see one
    group of three being absent while the row still reserved its track.

    The fix is not another `@if` around another wrapper. `.gatecols` lays out
    against the children it actually has (`grid-auto-flow: column`), and this
    makes a group with nothing in it render no child at all. An empty track
    stops being something an author has to remember to prevent.

    `col` names one of the dashboard's own columns. It exists because `.dash`
    was the last place in the console still holding this shape — a fixed
    three-track template whose children are populated conditionally — and it had
    stayed correct only because every column happened to carry an unconditional
    wrapper. That is an accident, not a guarantee, and one `@if` around one card
    would end it. The classes are written out as literals rather than passed
    through an attribute bag: a class arriving through `$attributes` is a class
    `tools/class-audit.php` cannot see, and x-gate-row already proved that by
    having the live `.gatecols` rule reported as one no markup asks for.

    @var \Illuminate\View\ComponentSlot $slot
    @var ?string $col
--}}
@props(['col' => null])

@if (\App\Support\SlotContent::hasContent($slot))
    @if ($col)
        <div @class(['flow' => $col === 'flow', 'rail' => $col === 'rail'])>{{ $slot }}</div>
    @else
        <div>{{ $slot }}</div>
    @endif
@endif
