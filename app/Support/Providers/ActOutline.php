<?php

namespace App\Support\Providers;

/**
 * One act's place in the outline, before any of it has been written.
 *
 * `title` doubles as the YouTube chapter title, which is why it is here at
 * outline time rather than being derived later — the operator approves it at
 * Gate 1 knowing it will be read as a chapter, and a title written only as an
 * internal label makes a poor one.
 *
 * `escalationBeat` is what this act makes worse. In an aggrieved-narrator
 * melodrama the acts compound rather than progress: nothing is resolved before
 * the exposure, because an act that resolves something has spent the tension
 * the rest of the video runs on. Stated separately from `summary` so it cannot
 * be quietly omitted — a summary can describe events without any of them
 * costing the narrator anything, and that is exactly the failure mode.
 */
final class ActOutline
{
    public function __construct(
        public readonly int $sequence,
        public readonly string $title,
        public readonly string $summary,
        public readonly string $escalationBeat = '',
    ) {}
}
