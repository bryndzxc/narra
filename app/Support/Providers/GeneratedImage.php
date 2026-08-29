<?php

namespace App\Support\Providers;

/**
 * One still, as returned by an image provider.
 *
 * Carries bytes rather than a path: where a still is stored is the caller's
 * decision (Storage::disk(), slugged filename, Windows path length), and a
 * provider that wrote to disk itself would put that decision in the wrong
 * place and make the fake harder to write than the real one.
 *
 * `seed` comes back because character consistency across 150-250 stills is the
 * single biggest quality risk in this format, and a locked seed per character
 * is the mechanism. A provider that cannot report the seed it used cannot
 * support that, and saying so here is better than discovering it at scene 90.
 */
final class GeneratedImage
{
    public function __construct(
        public readonly string $bytes,
        public readonly string $mimeType,
        public readonly int $width,
        public readonly int $height,
        public readonly ?int $seed,
        public readonly ProviderUsage $usage,
    ) {}
}
