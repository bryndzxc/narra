{{--
    The advisory cluster, in one place because it renders in two.

    In the busy layout it is the third column of the decision row. When there is
    nothing to approve and nothing to authorise it is a full-width block instead
    — which is LOUDER, not quieter, and that is the direction this file's rule
    only ever allows.

    Each advisory keeps its own `.alert.warn` treatment and they cluster. Folding
    them into plain rows inside one box would quieten fourteen warnings in order
    to tidy them, which is the thing the stylesheet's rule exists to prevent.

    The heading comes from the gate's `GateVoice`, because it names a DECISION
    and the decision is not always there. "Worth a look before you approve" on a
    published story sat one panel away from a strip saying nothing can be
    approved — two sentences on one screen contradicting each other. Same list,
    same loudness, a heading that is true in the state it renders in.

    It is the voice rather than a heading string because that was the fix
    applied once, by hand, on this partial — and Gate 1 then shipped two more
    instances of the same defect in prose the partial never sees. One mechanism,
    asked by every gate.

    @var array<int, string> $warnings
    @var \App\Support\GateVoice $voice
    @var string $subject
    @var bool $wide
--}}
<div @class(['advisories', 'wide' => $wide ?? false])>
    <div class="head">
        <h2>{{ $voice->advisoryHeading($subject) }}</h2>
        <span class="count">{{ count($warnings) }}</span>
    </div>

    @forelse ($warnings as $warning)
        <div class="alert warn small">{{ $warning }}</div>
    @empty
        <div class="alert ok small">
            Nothing flagged. The structural checks, the character-text guard and the reference
            style check all pass on these scenes.
        </div>
    @endforelse
</div>
