<?php

namespace App\Services\Fake;

use App\Contracts\MetadataWriter;
use App\Enums\CostCategory;
use App\Enums\CostUnit;
use App\Models\Story;
use App\Support\Providers\MetadataCopyDraft;
use App\Support\Providers\ProviderUsage;
use App\Support\Providers\TagDraft;
use App\Support\Providers\TitleDraft;

/**
 * A publish-sheet writer that never touches the network.
 *
 * It returns the right SHAPE and the right SIZE, for the same reason
 * FakeScriptWriter does: almost every rule GenerateMetadata enforces is about
 * length, and a fake that returned "lorem ipsum" would let a broken length
 * check pass. Titles land near the 70-character target, overlay phrases are
 * three to five words, and the tag list is written to sit just inside the
 * 500-character budget.
 *
 * The injection points exist to produce the outputs a real model occasionally
 * produces and that must never reach Gate 4 — a title past the hard limit, a
 * tag list over budget, an overlay phrase too long to read. Every one of those
 * is invisible on the page and silent on YouTube, so a test needs a way to hand
 * the Action one.
 *
 * Cost is recorded as zero but still recorded. "This cost nothing" and "nobody
 * wrote a cost row" must not look the same.
 */
class FakeMetadataWriter implements MetadataWriter
{
    /** @var array<int, array<string, mixed>> */
    public array $calls = [];

    /** Titles the next call returns instead of the generated ones. */
    public ?array $titleOverride = null;

    /** Tags the next call returns instead of the generated ones. */
    public ?array $tagOverride = null;

    /** Overlay phrases the next call returns instead of the generated ones. */
    public ?array $thumbnailOverride = null;

    /** Text spliced into the next titles, for testing the locale denylist. */
    public ?string $injectIntoTitles = null;

    public function providerName(): string
    {
        return 'fake';
    }

    public function isSimulated(): bool
    {
        return true;
    }

    public function modelName(): ?string
    {
        return null;
    }

    public function titles(Story $story, int $variants): TitleDraft
    {
        $this->calls[] = ['method' => 'titles', 'story_id' => $story->id, 'variants' => $variants];

        $titles = $this->titleOverride ?? $this->plausibleTitles($story, $variants);
        $this->titleOverride = null;

        $opening = sprintf(
            'She told me it was temporary. Four years later I was still paying for the house she '
            .'was living in, and %s.',
            trim((string) $story->exposure_moment) !== ''
                ? mb_strtolower(rtrim(trim((string) $story->exposure_moment), '.'))
                : 'everybody at the table heard exactly why'
        );

        if ($this->injectIntoTitles !== null) {
            $titles[] = $this->injectIntoTitles;
            $this->injectIntoTitles = null;
        }

        return new TitleDraft($titles, $opening, $this->usage('generate_titles', count($titles)));
    }

    public function copy(Story $story, array $titleOptions): MetadataCopyDraft
    {
        $this->calls[] = [
            'method' => 'copy',
            'story_id' => $story->id,
            'title_options' => $titleOptions,
        ];

        $phrases = $this->thumbnailOverride ?? [
            'FOUR YEARS OF PAYMENTS',
            'SHE CHANGED THE LOCKS',
            'THEN I SAID THE NUMBER',
            'NOBODY LEFT THE TABLE',
        ];

        $this->thumbnailOverride = null;

        return new MetadataCopyDraft(
            $phrases,
            'I left out what happened at the lawyer\'s office two weeks later. Tell me if you want '
            .'that one.',
            $this->usage('generate_copy', count($phrases)),
        );
    }

    public function tags(Story $story, array $titleOptions, int $charBudget): TagDraft
    {
        $this->calls[] = [
            'method' => 'tags',
            'story_id' => $story->id,
            'title_options' => $titleOptions,
            'char_budget' => $charBudget,
        ];

        $tags = $this->tagOverride ?? [
            'family betrayal story',
            'inheritance dispute',
            'entitled family member',
            'long form story',
            'reddit style story',
            'family drama story',
            'true story narration',
            'she thought she was owed it',
            'confronted at the funeral',
            'mothers house',
            'sibling betrayal',
            'storytime',
        ];

        $this->tagOverride = null;

        return new TagDraft($tags, $this->usage('generate_tags', count($tags)));
    }

    /**
     * @return array<int, string>
     */
    private function plausibleTitles(Story $story, int $variants): array
    {
        // Written to sit either side of the 70-character target rather than all
        // at one length: the target is a warning threshold, and a fake that
        // never crosses it can never exercise the warning.
        $shapes = [
            'My Brother Moved Into Our Late Mother\'s House and Told Me to Stop Paying',
            'I Paid Her Mortgage for Four Years. She Called It My Contribution.',
            'She Changed the Locks on the House I Was Paying For',
            'At Her Birthday Dinner I Finally Said the Number Out Loud',
            'Four Years of Autopay, One Sentence at the Funeral Lunch',
            'They Called It Helping Out Until I Showed Them the Statements',
        ];

        $titles = array_slice($shapes, 0, max(1, $variants));

        // Keep the story recognisable in the output so a test asserting the
        // sheet belongs to THIS story has something to assert on.
        if (trim((string) $story->title) !== '') {
            $titles[0] = mb_substr($titles[0], 0, 60);
        }

        return $titles;
    }

    private function usage(string $operation, int $quantity): ProviderUsage
    {
        return ProviderUsage::simulated(
            operation: $operation,
            category: CostCategory::Text,
            quantity: (float) $quantity,
            unit: CostUnit::Requests,
            detail: ['note' => 'fake metadata writer — no vendor contacted'],
        );
    }
}
