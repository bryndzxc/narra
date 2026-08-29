<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Splits a scene's words into short subtitle chunks.
 *
 * The reference format shows one short line at a time, so this is part of the
 * channel's visual signature rather than a formatting detail.
 *
 * Break points are chosen by dynamic programming over the whole scene, not
 * greedily left to right. A greedy pass fills each line to its limit and leaves
 * whatever remains as a stub, and it cannot trade a slightly worse line now for
 * a much better break later — which is exactly what "break on clause boundaries
 * first, word count second" requires.
 *
 * Cost per chunk = (length - ideal)^2 + penalty for where it breaks. Sentence
 * ends are free, clause punctuation is cheap, mid-clause is expensive, so a
 * chunk only splits mid-clause when the alternative is worse.
 */
final class SubtitleChunker
{
    /** Breaking after these costs nothing — the clause is over. */
    private const SENTENCE_END = ['.', '!', '?', '…'];

    /** Breaking after these is cheap — a natural pause. */
    private const CLAUSE_END = [',', ';', ':', '—', '–'];

    private const PENALTY_SENTENCE = 0.0;

    private const PENALTY_CLAUSE = 2.0;

    private const PENALTY_MID_CLAUSE = 8.0;

    public function __construct(
        private readonly float $idealWords = 6.5,
        private readonly int $maxWords = 10,
    ) {
        if ($maxWords < 1) {
            throw new InvalidArgumentException('maxWords must be at least 1.');
        }
    }

    public static function fromConfig(): self
    {
        return new self(
            (float) config('render.subtitles.chunk.ideal_words', 6.5),
            (int) config('render.subtitles.chunk.max_words', 10),
        );
    }

    /**
     * @param  array<int, string>  $words
     * @return array<int, array{start: int, length: int}> Contiguous, covering every word exactly once.
     */
    public function chunk(array $words): array
    {
        $n = count($words);

        if ($n === 0) {
            return [];
        }

        // best[j] = cheapest way to cover the first j words.
        $best = array_fill(0, $n + 1, INF);
        $from = array_fill(0, $n + 1, -1);
        $best[0] = 0.0;

        for ($j = 1; $j <= $n; $j++) {
            for ($i = max(0, $j - $this->maxWords); $i < $j; $i++) {
                if ($best[$i] === INF) {
                    continue;
                }

                $cost = $best[$i] + $this->cost($words, $i, $j, $n);

                if ($cost < $best[$j]) {
                    $best[$j] = $cost;
                    $from[$j] = $i;
                }
            }
        }

        $chunks = [];

        for ($j = $n; $j > 0; $j = $from[$j]) {
            $i = $from[$j];
            array_unshift($chunks, ['start' => $i, 'length' => $j - $i]);
        }

        return $chunks;
    }

    /**
     * @param  array<int, string>  $words
     */
    private function cost(array $words, int $i, int $j, int $n): float
    {
        $length = $j - $i;

        // Length is what pulls chunks toward the target size; squaring it means
        // two middling chunks beat one perfect chunk and one stub.
        $size = ($length - $this->idealWords) ** 2;

        // The final chunk of a scene ends at the scene boundary, which is
        // always a legitimate place to stop.
        $boundary = $j === $n ? 0.0 : $this->breakPenalty($words[$j - 1]);

        return $size + $boundary;
    }

    private function breakPenalty(string $word): float
    {
        $last = mb_substr(rtrim($word), -1);

        // Trailing quotes and brackets sit outside the punctuation that matters.
        if (in_array($last, ['"', "'", ')', ']', '”', '’'], true)) {
            $last = mb_substr(rtrim($word), -2, 1);
        }

        if (in_array($last, self::SENTENCE_END, true)) {
            return self::PENALTY_SENTENCE;
        }

        if (in_array($last, self::CLAUSE_END, true)) {
            return self::PENALTY_CLAUSE;
        }

        return self::PENALTY_MID_CLAUSE;
    }
}
