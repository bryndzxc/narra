<?php

namespace App\Services\Fake;

use App\Contracts\ScriptWriter;
use App\Enums\CostCategory;
use App\Enums\CostUnit;
use App\Models\Story;
use App\Support\Providers\ActOutline;
use App\Support\Providers\ActScriptDraft;
use App\Support\Providers\OutlineDraft;
use App\Support\Providers\ProviderUsage;

/**
 * A script writer that never touches the network.
 *
 * Tests never hit the network, so this is what every test in the suite runs
 * against. Two things make it useful rather than merely present:
 *
 *  1. **It produces the right SHAPE and the right SIZE.** Acts come back at
 *     roughly the requested word count, in US English, with a summary and a
 *     rehook line. A fake that returned "lorem ipsum" would let a bug through
 *     in anything downstream that reasons about length — and in this format
 *     almost everything does, because runtime is the product.
 *
 *  2. **It records what it was asked.** `$calls` captures every request in
 *     order, which is how the sequential-generation rule is tested: act 4 must
 *     have been given the summaries of acts 1-3, and a fan-out implementation
 *     would fail that assertion rather than silently producing drift nobody
 *     notices until a video is watched.
 *
 * Cost is recorded as zero but still recorded, so a fixture run exercises the
 * same cost path as a real one. "This cost nothing" and "nobody wrote a cost
 * row" must not look the same.
 */
class FakeScriptWriter implements ScriptWriter
{
    /** @var array<int, array<string, mixed>> */
    public array $calls = [];

    /** Text the next act will contain, for testing the locale denylist. */
    public ?string $injectIntoScript = null;

    /** Text the next outline will contain, same reason. */
    public ?string $injectIntoOutline = null;

    public function outline(Story $story, int $actCount): OutlineDraft
    {
        $this->calls[] = ['method' => 'outline', 'story_id' => $story->id, 'act_count' => $actCount];

        $acts = [];

        for ($i = 1; $i <= $actCount; $i++) {
            $acts[] = new ActOutline(
                sequence: $i,
                title: self::TITLES[($i - 1) % count(self::TITLES)],
                summary: sprintf(
                    'A high school senior in %s finds something in the %s that changes what she '
                    .'believes about her family. She tells nobody. By the end of the act she has '
                    .'decided to drive to the address on it. %s',
                    self::TOWNS[($i - 1) % count(self::TOWNS)],
                    $i % 2 === 0 ? 'garage' : 'attic',
                    $this->injectIntoOutline ?? ''
                ),
            );
        }

        $this->injectIntoOutline = null;

        return new OutlineDraft(
            title: 'The Thing She Found in the Attic',
            acts: $acts,
            usage: ProviderUsage::free('fake', 'generate_outline', CostCategory::Text),
        );
    }

    public function actScript(
        Story $story,
        ActOutline $act,
        array $fullOutline,
        array $priorSummaries,
        int $targetWords,
    ): ActScriptDraft {
        // Recorded in order. The test that this stage stayed sequential reads
        // exactly this: act N must have arrived carrying N-1 prior summaries.
        $this->calls[] = [
            'method' => 'actScript',
            'story_id' => $story->id,
            'sequence' => $act->sequence,
            'prior_summaries' => count($priorSummaries),
            'target_words' => $targetWords,
            'outline_size' => count($fullOutline),
        ];

        $script = $this->prose($targetWords, $act->sequence);

        if ($this->injectIntoScript !== null) {
            $script .= ' '.$this->injectIntoScript;
            $this->injectIntoScript = null;
        }

        return new ActScriptDraft(
            sequence: $act->sequence,
            script: $script,
            summary: sprintf(
                'Act %d: she drove to the address and found the house empty. The neighbor '
                .'recognized the name on the envelope. She is going back tomorrow.',
                $act->sequence
            ),
            rehookLine: 'The porch light was still on, and it was almost four in the morning.',
            usage: new ProviderUsage(
                provider: 'fake',
                operation: 'generate_act_script',
                category: CostCategory::Text,
                quantity: (float) $targetWords,
                unit: CostUnit::OutputTokens,
                usdCost: 0.0,
                detail: ['target_words' => $targetWords],
            ),
        );
    }

    /**
     * Filler that is the right length and reads as American.
     *
     * Not lorem ipsum: word count is load-bearing everywhere downstream, and
     * the locale denylist runs over whatever this returns, so a fake that
     * produced Latin would let a broken guard pass.
     */
    private function prose(int $targetWords, int $sequence): string
    {
        $sentences = [
            'The porch light was still on, and it was almost four in the morning.',
            'She had driven the whole length of Route 9 without turning the radio on once.',
            'Her mother kept the good scissors in the kitchen drawer with the tape and the batteries.',
            'It was forty-one degrees and the parking lot behind the diner was empty.',
            'Nobody in that town locked a back door, and everybody said so like it was a virtue.',
            'The envelope had a return address in Ohio and a postmark from nineteen years ago.',
            'She was seventeen and she had eleven hundred dollars in a shoebox under her bed.',
            'The sheriff had known her father since high school, which was the problem.',
        ];

        $words = [];
        $index = $sequence;

        while (count($words) < $targetWords) {
            $words = array_merge($words, explode(' ', $sentences[$index % count($sentences)]));
            $index++;
        }

        return implode(' ', array_slice($words, 0, $targetWords));
    }

    private const TITLES = [
        'The House on Route 9',
        'What the Neighbor Knew',
        'Eleven Hundred Dollars',
        'The Postmark',
        'Nobody Locks a Back Door',
        'Forty-One Degrees',
        'The Drive to Ohio',
        'What She Told Her Mother',
    ];

    private const TOWNS = ['Bellefonte', 'Marion', 'Cold Spring', 'Delaware', 'Warrensburg'];
}
