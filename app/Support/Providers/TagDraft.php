<?php

namespace App\Support\Providers;

use App\Models\YoutubeMetadata;

/**
 * Proposed tags, before the budget is enforced on them.
 *
 * The 500-character budget is asked for in the prompt and enforced here,
 * because those are two different claims. A model told a budget is not a model
 * that respects one, and the spec's rule is that the budget is enforced rather
 * than silently truncated at upload.
 *
 * "Enforced" means whole tags are dropped from the tail, never a tag cut
 * mid-word: `withinBudget()` returns a shorter LIST, not a shorter string. A
 * truncated tag is a different tag, and shipping one is exactly the silent
 * substitution this rule exists to prevent.
 */
final class TagDraft
{
    /**
     * @param  array<int, string>  $tags  As proposed, in order.
     */
    public function __construct(
        public readonly array $tags,
        public readonly ProviderUsage $usage,
    ) {}

    /**
     * The longest leading run of these tags that fits the budget.
     *
     * Leading, so the order the model proposed — most specific first, which is
     * what the prompt asks for — decides what survives.
     *
     * @return array<int, string>
     */
    public function withinBudget(int $charBudget): array
    {
        $kept = [];

        foreach ($this->tags as $tag) {
            $candidate = [...$kept, $tag];

            if (YoutubeMetadata::charCountFor($candidate) > $charBudget) {
                continue;
            }

            $kept = $candidate;
        }

        return $kept;
    }

    /**
     * How many were dropped to make it fit.
     *
     * Reported rather than swallowed. A tag list quietly arriving 40% shorter
     * than the model wrote is the kind of thing that should be visible in the
     * stage log, not discovered by counting.
     */
    public function droppedCount(int $charBudget): int
    {
        return count($this->tags) - count($this->withinBudget($charBudget));
    }

    public function proseForInspection(): string
    {
        return implode(', ', $this->tags);
    }
}
