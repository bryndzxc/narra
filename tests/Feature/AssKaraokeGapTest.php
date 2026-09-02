<?php

namespace Tests\Feature;

use App\Actions\GenerateAssSubtitles;
use Tests\TestCase;

/**
 * Karaoke timing survives real alignment, which has silence between words.
 *
 * **The seam.** Every fixture this generator had ever seen was hand-written, and
 * a hand-written fixture times words end-to-start: word n ends on the exact
 * centisecond word n+1 begins. So the gap between words was always zero, and the
 * generator's assumption that a line's `{\k}` durations are just its words'
 * durations was true by construction and never tested.
 *
 * WhisperX reports the real pauses. On story 9's first real subtitle pass the
 * verifier found 0 of 840 lines tiling, 839 gapped seams, and a timeline ending
 * 24 cs before the video. Nothing had regressed — the case had simply never
 * existed until a real transcript arrived.
 *
 * These tests use GAPPED timings, which is the axis a fixture cannot vary.
 */
class AssKaraokeGapTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        parent::setUp();

        $this->path = sys_get_temp_dir().'/narra-ass-'.bin2hex(random_bytes(4)).'.ass';
    }

    protected function tearDown(): void
    {
        @unlink($this->path);

        parent::tearDown();
    }

    /** Every line's {\k} durations must add up to exactly what the line spans. */
    public function test_k_durations_tile_each_line_when_words_have_gaps(): void
    {
        $this->generate($this->gappedScenes());

        foreach ($this->dialogueLines() as $n => $line) {
            $this->assertSame(
                $line['span'],
                $line['k_sum'],
                sprintf(
                    'Line %d spans %d cs but its {\k} durations sum to %d. The difference is silence '
                    .'that belongs to no segment.',
                    $n + 1,
                    $line['span'],
                    $line['k_sum'],
                ),
            );
        }
    }

    /** And consecutive lines must meet, with nothing falling between them. */
    public function test_lines_meet_with_no_gaps_when_words_have_gaps(): void
    {
        $this->generate($this->gappedScenes());

        $lines = $this->dialogueLines();

        for ($i = 1; $i < count($lines); $i++) {
            $this->assertSame(
                $lines[$i - 1]['end'],
                $lines[$i]['start'],
                sprintf('Line %d starts at %d cs but line %d ended at %d cs.',
                    $i + 1, $lines[$i]['start'], $i, $lines[$i - 1]['end']),
            );
        }
    }

    /**
     * The KARAOKE timeline must reach the end of the padded audio.
     *
     * Asserted on the swept `{\k}` total, not on the last line's End field, and
     * the difference is the whole value of this test. Every scene's final line is
     * structurally `isLast`, so its End is `$sceneEnd` whatever the gap handling
     * does — an assertion on End cannot fail from this bug and would be a test
     * that looks like coverage and is not.
     *
     * The production verifier caught it on the swept total: "timeline ends at
     * 177846 cs, video ends at 177870". That is `start + sum(k)` falling short,
     * which is exactly what is asserted here.
     *
     * Two scenes of five seconds, so the sweep must reach 1000 cs.
     */
    public function test_the_karaoke_sweep_reaches_the_end_of_the_video(): void
    {
        $this->generate($this->gappedScenes());

        $lines = $this->dialogueLines();
        $last = end($lines);

        $this->assertSame(0, $lines[0]['start']);
        $this->assertSame(1000, $last['end'], 'The last line should END on the video length.');
        $this->assertSame(
            1000,
            $last['start'] + $last['k_sum'],
            'The highlight sweep stops before the video does — silence that belongs to no segment.',
        );
    }

    /** The gapless case still works — this widened the rule, it did not move it. */
    public function test_contiguous_timings_still_tile(): void
    {
        $contiguous = $this->gappedScenes()[0];
        $cursor = 0;

        // Same words, re-timed end-to-start: the hand-written fixture shape.
        foreach ($contiguous['words'] as $index => $word) {
            $length = $word['end_ms'] - $word['start_ms'];
            $contiguous['words'][$index]['start_ms'] = $cursor;
            $contiguous['words'][$index]['end_ms'] = $cursor += $length;
        }

        $this->generate([$contiguous], sceneCount: 1);

        foreach ($this->dialogueLines() as $line) {
            $this->assertSame($line['span'], $line['k_sum']);
        }
    }

    /**
     * Two scenes of sixteen words each, separated by real silence.
     *
     * Sixteen matters. The chunker targets 6.5 words a line and caps at 10, so
     * a four-word scene is ONE line — and with one line per scene every line is
     * both first and last, `$lineEnd` is always `$sceneEnd`, and the seam and
     * total assertions pass no matter how the gaps are handled. The first
     * version of this fixture had four words and proved nothing on three of its
     * four tests. Sixteen forces two or three lines per scene, which is what
     * puts a real chunk boundary in the middle of a pause.
     *
     * @return array<int, array<string, mixed>>
     */
    private function gappedScenes(): array
    {
        // (start_ms, end_ms) with gaps of 20-200 ms — the shape WhisperX
        // actually returns, including one long mid-clause pause.
        $timings = [
            [40, 180], [200, 420], [600, 780], [800, 950],
            [1000, 1180], [1250, 1400], [1420, 1600], [1700, 1850],
            [1900, 2100], [2150, 2300], [2400, 2600], [2650, 2800],
            [2900, 3100], [3150, 3300], [3400, 3600], [3650, 3800],
        ];

        $vocabulary = [
            'The', 'house', 'was', 'empty', 'and', 'nobody', 'had', 'lived',
            'there', 'since', 'the', 'winter', 'my', 'mother', 'finally', 'left',
        ];

        $scenes = [];

        foreach ([1, 2] as $sequence) {
            $words = [];

            foreach ($timings as $index => [$startMs, $endMs]) {
                $words[] = [
                    'word' => $vocabulary[$index],
                    'start_ms' => $startMs,
                    'end_ms' => $endMs,
                ];
            }

            $scenes[] = ['sequence' => $sequence, 'words' => $words];
        }

        return $scenes;
    }

    /**
     * @param  array<int, array<string, mixed>>  $scenes
     */
    private function generate(array $scenes, int $sceneCount = 2): void
    {
        $rate = (int) config('render.audio.sample_rate');

        $audio = [];

        // Five seconds a scene, so the sixteen words fit with real trailing
        // silence after the last one.
        $sceneSamples = 5 * $rate;

        foreach (range(1, $sceneCount) as $sequence) {
            $audio[$sequence] = [
                'offset_samples' => ($sequence - 1) * $sceneSamples,
                'padded_samples' => $sceneSamples,
            ];
        }

        app(GenerateAssSubtitles::class)->handle($scenes, $audio, $this->path, 'Gap test');
    }

    /**
     * Parse the generated file back into start / end / k-sum per line.
     *
     * Read from the FILE rather than from the action's return value, because the
     * file is what libass consumes and the return value is a summary the bug
     * would not have shown up in.
     *
     * @return array<int, array{start: int, end: int, span: int, k_sum: int}>
     */
    private function dialogueLines(): array
    {
        $lines = [];

        foreach (file($this->path, FILE_IGNORE_NEW_LINES) ?: [] as $row) {
            if (! str_starts_with($row, 'Dialogue:')) {
                continue;
            }

            $fields = explode(',', $row, 10);
            $start = self::toCentiseconds($fields[1]);
            $end = self::toCentiseconds($fields[2]);

            preg_match_all('/\{\\\\k(\d+)\}/', $fields[9], $matches);

            $lines[] = [
                'start' => $start,
                'end' => $end,
                'span' => $end - $start,
                'k_sum' => array_sum(array_map('intval', $matches[1])),
            ];
        }

        $this->assertNotEmpty($lines, 'No Dialogue lines were written.');

        return $lines;
    }

    /** `0:00:01.23` to centiseconds. */
    private static function toCentiseconds(string $stamp): int
    {
        [$h, $m, $s] = explode(':', trim($stamp));
        [$seconds, $cs] = explode('.', $s);

        return ((int) $h * 3600 + (int) $m * 60 + (int) $seconds) * 100 + (int) $cs;
    }
}
