<?php

namespace App\Support\Providers;

/**
 * One act's place in the outline, before any of it has been written.
 *
 * `title` doubles as the YouTube chapter title, which is why it is here at
 * outline time rather than being derived later — the operator approves it at
 * Gate 1 knowing it will be read as a chapter, and a title written only as an
 * internal label makes a poor one.
 */
final class ActOutline
{
    public function __construct(
        public readonly int $sequence,
        public readonly string $title,
        public readonly string $summary,
    ) {}
}
