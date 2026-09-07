<style>
    /* naming <x-gate-group> in prose here IS compiled: Blade cannot see a CSS comment */
    /* and so is a directive: an @if written in prose here
       took the console down ten minutes after the component tag did */
    .thing { color: red; }
</style>
{{-- naming <x-gate-group> in prose HERE is inert: compileComments runs first --}}
{{-- but a @verbatim written here is not, because pass 2 runs before comments --}}
<span>text</span>

@php
    /* AND THE NEGATIVE CASE FOR THE SAME RULE: this CSS comment names
       <x-gate-group> and an @if, inside a block storeUncompiledBlocks
       extracts at pass 2 — before comments are stripped and before
       anything is compiled. Verified against compileString(): the tag
       comes back untouched. Reporting it would be a false positive in a
       severe category. */
    $inert = true;
@endphp
