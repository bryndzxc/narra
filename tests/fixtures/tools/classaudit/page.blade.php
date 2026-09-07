<div class="known">answered by its own rule</div>
<div class="scoped"><span class="innerthing">only reachable under .scoped</span></div>
<div class="orphan">no rule anywhere paints this</div>

{{--
    A conditional class whose CONDITION contains string literals.

    The regression: the parser grepped every quoted string inside `@class([...])`
    and reported the operands as classes. `chosen` and `scoped` are classes; `alpha`,
    `beta`, `gamma` and `kind` are not, and none of them may ever be reported.
--}}
<div @class(['known', 'chosen' => $it['kind'] === 'alpha', 'scoped' => in_array($it['kind'], ['beta', 'gamma'], true)])>
    the operands here are not classes
</div>
