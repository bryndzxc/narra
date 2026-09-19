<?php

namespace App\Support\Providers;

/**
 * One roll of the premise generator: the candidates, and what it said about
 * the idea it was given.
 *
 * `ideaWasRevengeShaped` and `translation` are the generator's own account of
 * turning a revenge idea into this genre's arc, which does not do revenge. The
 * operator sees that line on every roll where it is true, so an idea that
 * needed translating is never translated silently.
 */
final class PremiseDraftSet
{
    /**
     * @param  array<int, PremiseCandidate>  $candidates
     */
    public function __construct(
        public readonly array $candidates,
        public readonly bool $ideaWasRevengeShaped,
        public readonly string $translation,
        public readonly int $requested,
        public readonly ProviderUsage $usage,
    ) {}
}
