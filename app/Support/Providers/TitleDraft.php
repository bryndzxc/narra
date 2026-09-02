<?php

namespace App\Support\Providers;

/**
 * Proposed titles and the description's opening hook.
 *
 * Both come out of one call because they are one promise. See MetadataWriter.
 *
 * Nothing here is filtered or trimmed — that is GenerateMetadata's job, after
 * the cost row is written. A provider that dropped an over-long title itself
 * would be deciding, silently, what the operator gets to choose from.
 */
final class TitleDraft
{
    /**
     * @param  array<int, string>  $titles  In the order proposed. The order is
     *                                      not a ranking; the operator picks.
     * @param  string  $descriptionOpening
     *                                      Two or three sentences, written as a hook rather than a summary.
     *                                      This is what shows in search and above the fold, and it is the
     *                                      only part of a 5,000-character description most people read.
     */
    public function __construct(
        public readonly array $titles,
        public readonly string $descriptionOpening,
        public readonly ProviderUsage $usage,
    ) {}

    /** Everything a locale denylist should be run over. */
    public function proseForInspection(): string
    {
        return implode("\n", [...$this->titles, $this->descriptionOpening]);
    }
}
