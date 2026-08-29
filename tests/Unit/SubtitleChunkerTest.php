<?php

namespace Tests\Unit;

use App\Support\SubtitleChunker;
use PHPUnit\Framework\TestCase;

class SubtitleChunkerTest extends TestCase
{
    private function words(string $text): array
    {
        return preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY);
    }

    private function rendered(array $words, array $chunks): array
    {
        return array_map(
            fn (array $c): string => implode(' ', array_slice($words, $c['start'], $c['length'])),
            $chunks
        );
    }

    public function test_chunks_cover_every_word_exactly_once_and_in_order(): void
    {
        $words = $this->words(
            'Nobody told Dana that the diner was closing. She found out the way everyone in Cedar '
            .'Falls found out anything, from a hand lettered sign taped to the inside of the front window.'
        );

        $chunks = (new SubtitleChunker)->chunk($words);

        $cursor = 0;
        $rebuilt = [];

        foreach ($chunks as $chunk) {
            $this->assertSame($cursor, $chunk['start'], 'Chunks must be contiguous.');
            $rebuilt = array_merge($rebuilt, array_slice($words, $chunk['start'], $chunk['length']));
            $cursor += $chunk['length'];
        }

        $this->assertSame(count($words), $cursor);
        $this->assertSame($words, $rebuilt);
    }

    public function test_no_chunk_exceeds_the_hard_cap(): void
    {
        $words = $this->words(str_repeat('word ', 97));

        foreach ((new SubtitleChunker(6.5, 10))->chunk($words) as $chunk) {
            $this->assertLessThanOrEqual(10, $chunk['length']);
            $this->assertGreaterThan(0, $chunk['length']);
        }
    }

    public function test_it_breaks_on_a_sentence_end_rather_than_filling_the_line(): void
    {
        // A greedy filler would run past "closing." to reach its word limit.
        $words = $this->words('Nobody told Dana that the diner was closing. She found out the way everyone');

        $rendered = $this->rendered($words, (new SubtitleChunker)->chunk($words));

        $this->assertSame('Nobody told Dana that the diner was closing.', $rendered[0]);
    }

    public function test_it_prefers_a_clause_boundary_to_a_mid_clause_break(): void
    {
        $words = $this->words('in Cedar Falls found out anything, from a hand lettered sign taped');

        $rendered = $this->rendered($words, (new SubtitleChunker)->chunk($words));

        $this->assertStringEndsWith('anything,', $rendered[0]);
    }

    public function test_it_avoids_leaving_a_one_word_stub(): void
    {
        // 11 words: a greedy 10 + 1 split is legal but reads badly. The squared
        // size penalty should produce something balanced instead.
        $words = $this->words('one two three four five six seven eight nine ten eleven');

        $chunks = (new SubtitleChunker)->chunk($words);

        foreach ($chunks as $chunk) {
            $this->assertGreaterThanOrEqual(2, $chunk['length'], 'A one-word line is a stub.');
        }
    }

    public function test_short_input_stays_a_single_chunk(): void
    {
        $words = $this->words('a very short line indeed');

        $this->assertCount(1, (new SubtitleChunker)->chunk($words));
    }

    public function test_empty_input_produces_no_chunks(): void
    {
        $this->assertSame([], (new SubtitleChunker)->chunk([]));
    }

    public function test_trailing_quotes_do_not_hide_the_sentence_end(): void
    {
        $words = $this->words('he said "we are closing." She found out the way everyone in Cedar');

        $rendered = $this->rendered($words, (new SubtitleChunker)->chunk($words));

        $this->assertStringEndsWith('closing."', $rendered[0]);
    }
}
