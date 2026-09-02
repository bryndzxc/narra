<?php

namespace App\Support\Providers;

/**
 * Thumbnail overlay phrases and the pinned comment.
 *
 * The short copy around the video. Unfiltered, like every provider result here:
 * the word-count rule on overlay text is enforced by GenerateMetadata after the
 * cost row exists, not by the provider before it.
 */
final class MetadataCopyDraft
{
    /**
     * @param  array<int, string>  $thumbnailText
     *                                             Three to five phrases of three to five words. Short because they
     *                                             have to be readable at the size a thumbnail is actually seen.
     * @param  string  $pinnedComment
     *                                 Pinned to the top of the comments for the life of the video, so it
     *                                 is the one comment guaranteed to be read.
     */
    public function __construct(
        public readonly array $thumbnailText,
        public readonly string $pinnedComment,
        public readonly ProviderUsage $usage,
    ) {}

    public function proseForInspection(): string
    {
        return implode("\n", [...$this->thumbnailText, $this->pinnedComment]);
    }
}
