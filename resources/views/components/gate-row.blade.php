{{--
    A gate's decision/advisory row.

    The row is a function of its groups the same way each group is a function of
    its findings: with nothing in any of them there is no row, so the page does
    not carry a 16px margin and a grid around a void.

    It matters that the emptiness is decided HERE and not by a condition at the
    call site. Gate 1 opened its row with
    `@if ($spine['problems'] || $this->localeWarnings() || $spine['warnings'] || ...)`
    — a hand-written restatement of the four conditions inside it, which is the
    two-copies-of-one-rule shape this codebase has already paid for twice. Add a
    fifth group and the copies disagree silently.

    The class is a LITERAL and there is no attribute bag, which is not a style
    preference. `tools/class-audit.php` reads the markup for literal class
    attributes, so a class written as `$attributes->class([...])` is invisible to
    it — the first version of this file did exactly that and the audit
    immediately reported `.gatecols` under NO LITERAL ASKS FOR THESE, which is
    its "this rule is dead" list, about the rule the whole row depends on. A
    component that hides its classes from the audit makes every class it carries
    unauditable.

    @var \Illuminate\View\ComponentSlot $slot
--}}
@if (\App\Support\SlotContent::hasContent($slot))
    <div class="gatecols">{{ $slot }}</div>
@endif
