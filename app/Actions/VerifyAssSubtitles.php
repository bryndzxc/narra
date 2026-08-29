<?php

namespace App\Actions;

use RuntimeException;

/**
 * Re-reads a written .ass file and checks it against the source manifests.
 *
 * Parses the file from disk and groups its lines back into scenes by their
 * timestamps. It never re-runs the chunker, so it cannot agree with the writer
 * merely by repeating the writer's own decisions.
 */
class VerifyAssSubtitles
{
    /**
     * @param  array<int, array<string, mixed>>  $scenes
     * @param  array<int, array<string, mixed>>  $sceneAudio  Keyed by sequence.
     * @return array<string, array{pass: bool, detail: string, failures: array<int, string>}>
     */
    public function handle(string $assPath, array $scenes, array $sceneAudio, int $totalFrames, int $fps): array
    {
        $lines = $this->parse($assPath);
        $rate = (int) config('render.audio.sample_rate');

        if ($lines === []) {
            throw new RuntimeException('No Dialogue lines found in '.basename($assPath));
        }

        $grouped = $this->groupIntoScenes($lines, $scenes, $sceneAudio, $rate);

        return [
            'timeline_ends_on_total' => $this->checkEnd($lines, $totalFrames, $fps),
            'first_line_at_scene_offset' => $this->checkOffsets($grouped, $sceneAudio, $rate),
            'k_durations_tile_each_line' => $this->checkTiling($lines),
            'chunks_meet_with_no_gaps' => $this->checkContiguity($lines),
            'text_matches_narration' => $this->checkText($grouped),
            'chunk_sizes_within_cap' => $this->checkChunkSizes($lines),
        ];
    }

    /**
     * Group parsed lines under the scene whose time range contains them.
     * Derived from scene_audio offsets, not from the chunker.
     *
     * @param  array<int, array<string, mixed>>  $lines
     * @param  array<int, array<string, mixed>>  $scenes
     * @param  array<int, array<string, mixed>>  $sceneAudio
     * @return array<int, array{scene: array<string, mixed>, start: int, end: int, lines: array<int, array<string, mixed>>}>
     */
    private function groupIntoScenes(array $lines, array $scenes, array $sceneAudio, int $rate): array
    {
        $grouped = [];
        $cursor = 0;

        foreach ($scenes as $scene) {
            $audio = $sceneAudio[(int) $scene['sequence']];

            $start = GenerateAssSubtitles::samplesToCentiseconds((int) $audio['offset_samples'], $rate);
            $end = GenerateAssSubtitles::samplesToCentiseconds(
                (int) $audio['offset_samples'] + (int) $audio['padded_samples'],
                $rate
            );

            $own = [];

            while ($cursor < count($lines) && $lines[$cursor]['start_cs'] < $end) {
                $own[] = $lines[$cursor];
                $cursor++;
            }

            $grouped[] = ['scene' => $scene, 'start' => $start, 'end' => $end, 'lines' => $own];
        }

        if ($cursor !== count($lines)) {
            throw new RuntimeException(sprintf(
                '%d dialogue lines fall outside every scene range.',
                count($lines) - $cursor
            ));
        }

        return $grouped;
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     * @return array{pass: bool, detail: string, failures: array<int, string>}
     */
    private function checkEnd(array $lines, int $totalFrames, int $fps): array
    {
        $last = end($lines);

        // Walked from the line's start through its {\k} values rather than read
        // off the End timestamp, so the tiling is what is actually tested.
        $endCs = $last['start_cs'] + array_sum(array_column($last['segments'], 'k'));

        // Compared in CENTISECONDS, because that is the only resolution ASS
        // has. The video's true end is frames/fps seconds, which is usually not
        // a whole centisecond — 105,365 frames at 30fps is 351216.667cs — so
        // the timeline must land on the nearest representable value, not on the
        // millisecond-rounded duration. Comparing a centisecond-quantised
        // timeline against a millisecond figure is a category error that can
        // differ by up to 5ms; it only ever passed because 4863 frames at 30fps
        // happens to land exactly on a centisecond.
        $expectedCs = (int) round($totalFrames / $fps * 100);

        // Sub-frame residual against the true (unrounded) video end.
        $residualMs = $endCs * 10 - $totalFrames / $fps * 1000;

        $trailing = 0;
        foreach (array_reverse($last['segments']) as $segment) {
            if ($segment['text'] !== '') {
                break;
            }
            $trailing += $segment['k'];
        }

        return [
            'pass' => $endCs === $expectedCs,
            'detail' => sprintf(
                'timeline ends at %d cs (%s), video ends at %.4f cs -> nearest representable %d cs; '
                .'residual %+.2f ms of a %.2f ms frame; last spoken word ends at %d ms, trailing spacer %d ms',
                $endCs,
                $last['end_raw'],
                $totalFrames / $fps * 100,
                $expectedCs,
                $residualMs,
                1000 / $fps,
                $endCs * 10 - $trailing * 10,
                $trailing * 10
            ),
            'failures' => $endCs === $expectedCs ? [] : [sprintf('off by %+d cs', $endCs - $expectedCs)],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $grouped
     * @param  array<int, array<string, mixed>>  $sceneAudio
     * @return array{pass: bool, detail: string, failures: array<int, string>}
     */
    private function checkOffsets(array $grouped, array $sceneAudio, int $rate): array
    {
        $failures = [];

        foreach ($grouped as $group) {
            $sequence = (int) $group['scene']['sequence'];

            if ($group['lines'] === []) {
                $failures[] = sprintf('scene-%03d produced no lines', $sequence);

                continue;
            }

            $expected = GenerateAssSubtitles::samplesToCentiseconds(
                (int) $sceneAudio[$sequence]['offset_samples'],
                $rate
            );

            if ($group['lines'][0]['start_cs'] !== $expected) {
                $failures[] = sprintf(
                    'scene-%03d first line starts at %d cs, expected %d cs',
                    $sequence, $group['lines'][0]['start_cs'], $expected
                );
            }

            $lastOfScene = end($group['lines']);

            if ($lastOfScene['end_cs'] !== $group['end']) {
                $failures[] = sprintf(
                    'scene-%03d last line ends at %d cs, expected %d cs',
                    $sequence, $lastOfScene['end_cs'], $group['end']
                );
            }
        }

        return [
            'pass' => $failures === [],
            'detail' => sprintf('%d of %d scenes open on their offset_samples and close on their padded end', count($grouped) - count($failures), count($grouped)),
            'failures' => $failures,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     * @return array{pass: bool, detail: string, failures: array<int, string>}
     */
    private function checkTiling(array $lines): array
    {
        $failures = [];

        foreach ($lines as $line) {
            $sum = array_sum(array_column($line['segments'], 'k'));
            $span = $line['end_cs'] - $line['start_cs'];

            if ($sum !== $span) {
                $failures[] = sprintf('line %d: k sum %d cs vs span %d cs', $line['index'] + 1, $sum, $span);
            }

            foreach ($line['segments'] as $segment) {
                if ($segment['k'] < 0) {
                    $failures[] = sprintf('line %d: negative {\k%d}', $line['index'] + 1, $segment['k']);
                }
            }
        }

        return [
            'pass' => $failures === [],
            'detail' => sprintf('%d of %d lines tiled exactly', count($lines) - count($failures), count($lines)),
            'failures' => $failures,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     * @return array{pass: bool, detail: string, failures: array<int, string>}
     */
    private function checkContiguity(array $lines): array
    {
        $failures = [];

        for ($i = 1; $i < count($lines); $i++) {
            if ($lines[$i]['start_cs'] !== $lines[$i - 1]['end_cs']) {
                $failures[] = sprintf(
                    'line %d starts at %d cs but line %d ended at %d cs',
                    $i + 1, $lines[$i]['start_cs'], $i, $lines[$i - 1]['end_cs']
                );
            }
        }

        return [
            'pass' => $failures === [],
            'detail' => sprintf('%d line seams, all exact (within and across scenes)', max(0, count($lines) - 1)),
            'failures' => $failures,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $grouped
     * @return array{pass: bool, detail: string, failures: array<int, string>}
     */
    private function checkText(array $grouped): array
    {
        $failures = [];

        foreach ($grouped as $group) {
            $sequence = (int) $group['scene']['sequence'];

            // Chunks are separate lines, so the scene's text is their texts
            // joined by the space that used to sit between the words.
            $actual = implode(' ', array_map(fn (array $l): string => $l['text'], $group['lines']));

            if ($actual !== (string) $group['scene']['narration_text']) {
                $failures[] = sprintf('scene-%03d text differs', $sequence);
            }

            $actualWords = [];
            foreach ($group['lines'] as $line) {
                $actualWords = array_merge($actualWords, $line['words']);
            }

            if ($actualWords !== array_column($group['scene']['words'], 'word')) {
                $failures[] = sprintf(
                    'scene-%03d word list differs (%d vs %d words)',
                    $sequence, count($actualWords), count($group['scene']['words'])
                );
            }
        }

        return [
            'pass' => $failures === [],
            'detail' => sprintf('%d of %d scenes reassemble to their narration_text', count($grouped) - count($failures), count($grouped)),
            'failures' => $failures,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     * @return array{pass: bool, detail: string, failures: array<int, string>}
     */
    private function checkChunkSizes(array $lines): array
    {
        $cap = (int) config('render.subtitles.chunk.max_words', 10);
        $failures = [];
        $counts = [];

        foreach ($lines as $line) {
            $counts[] = count($line['words']);

            if (count($line['words']) > $cap) {
                $failures[] = sprintf('line %d holds %d words, cap is %d', $line['index'] + 1, count($line['words']), $cap);
            }
        }

        return [
            'pass' => $failures === [],
            'detail' => sprintf(
                '%d..%d words per line, mean %.1f, cap %d',
                min($counts), max($counts), array_sum($counts) / count($counts), $cap
            ),
            'failures' => $failures,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function parse(string $assPath): array
    {
        if (! is_readable($assPath)) {
            throw new RuntimeException("Cannot read {$assPath}");
        }

        $lines = [];
        $handle = fopen($assPath, 'rb');

        if ($handle === false) {
            throw new RuntimeException("Cannot open {$assPath}");
        }

        try {
            while (($row = fgets($handle)) !== false) {
                if (! str_starts_with($row, 'Dialogue:')) {
                    continue;
                }

                $fields = explode(',', substr($row, strlen('Dialogue:')), 10);

                if (count($fields) < 10) {
                    throw new RuntimeException('Malformed Dialogue line: '.trim($row));
                }

                $text = rtrim($fields[9], "\r\n");
                $segments = $this->segments($text);
                $stripped = implode('', array_column($segments, 'text'));

                $lines[] = [
                    'index' => count($lines),
                    'start_raw' => trim($fields[1]),
                    'end_raw' => trim($fields[2]),
                    'start_cs' => $this->toCentiseconds(trim($fields[1])),
                    'end_cs' => $this->toCentiseconds(trim($fields[2])),
                    'segments' => $segments,
                    'text' => $stripped,
                    'words' => preg_split('/\s+/u', trim($stripped), -1, PREG_SPLIT_NO_EMPTY) ?: [],
                ];
            }
        } finally {
            fclose($handle);
        }

        return $lines;
    }

    /**
     * Split a Dialogue text into {\k} segments, keeping the text that follows
     * each one. A segment whose text is empty is a silence spacer.
     *
     * @return array<int, array{k: int, text: string}>
     */
    private function segments(string $text): array
    {
        $parts = preg_split('/\{\\\\k(\d+)\}/', $text, -1, PREG_SPLIT_DELIM_CAPTURE);

        if ($parts === false || count($parts) < 3) {
            throw new RuntimeException('Dialogue line carries no karaoke tags: '.$text);
        }

        // parts[0] is whatever preceded the first tag, which must be nothing.
        if ($parts[0] !== '') {
            throw new RuntimeException('Text before the first {\k} tag: '.$text);
        }

        $segments = [];

        for ($i = 1; $i < count($parts); $i += 2) {
            $segments[] = ['k' => (int) $parts[$i], 'text' => $parts[$i + 1] ?? ''];
        }

        return $segments;
    }

    private function toCentiseconds(string $timestamp): int
    {
        if (preg_match('/^(\d+):(\d{2}):(\d{2})\.(\d{2})$/', $timestamp, $m) !== 1) {
            throw new RuntimeException("Unparseable ASS timestamp: {$timestamp}");
        }

        return ((int) $m[1]) * 360000 + ((int) $m[2]) * 6000 + ((int) $m[3]) * 100 + (int) $m[4];
    }
}
