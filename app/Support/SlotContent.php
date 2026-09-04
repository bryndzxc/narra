<?php

namespace App\Support;

use Stringable;

/**
 * Whether a rendered Blade slot actually put anything on the page.
 *
 * Not `trim($slot) !== ''`. A slot whose every child was behind a false `@if`
 * still carries Livewire's morph markers — `<!--[if BLOCK]><![endif]-->` — so
 * the naive test reports content where the browser will show a void. That void
 * is the whole defect `<x-gate-group>` exists to make unreachable, and a
 * predicate that cannot see it would have shipped the defect inside its own
 * fix.
 */
final class SlotContent
{
    public static function hasContent(string|Stringable|null $slot): bool
    {
        $visible = preg_replace('/<!--.*?-->/s', '', (string) $slot);

        return trim((string) $visible) !== '';
    }
}
