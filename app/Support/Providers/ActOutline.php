<?php

namespace App\Support\Providers;

use App\Enums\ActPhase;

/**
 * One act's place in the outline, before any of it has been written.
 *
 * `title` doubles as the YouTube chapter title, which is why it is here at
 * outline time rather than being derived later — the operator approves it at
 * Gate 1 knowing it will be read as a chapter, and a title written only as an
 * internal label makes a poor one.
 *
 * `escalationBeat` is what this act makes worse, AND FOR WHOM. In the
 * escalation phase the acts compound against the narrator: nothing is resolved
 * before the departure, because an act that resolves something has spent the
 * tension the rest of the video runs on. After the departure the same beat runs
 * the other way and names what the antagonist's next attempt costs HER. Stated
 * separately from `summary` so it cannot be quietly omitted — a summary can
 * describe events without any of them costing anyone anything, and that is
 * exactly the failure mode.
 *
 * `phase` is which of those two directions applies, and it is carried here
 * rather than inferred from `sequence` because the act generator needs it. The
 * previous version could only ask "is this the last act" and answered every
 * other act with "end worse off than it started" — right for act 2 and the
 * precise opposite of what act 6 of a seven-act story needs.
 */
final class ActOutline
{
    public function __construct(
        public readonly int $sequence,
        public readonly string $title,
        public readonly string $summary,
        public readonly string $escalationBeat = '',
        /** Null on an anthology, where every act runs the whole arc itself. */
        public readonly ?ActPhase $phase = null,
    ) {}
}
