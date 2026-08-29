<?php

namespace App\Actions;

use App\Support\AssStyle;
use App\Support\SubtitleChunker;
use InvalidArgumentException;
use RuntimeException;

/**
 * Step 3a: the karaoke .ass file.
 *
 * Each scene is broken into short chunks and each chunk becomes one Dialogue
 * line. Word timings arrive scene-relative and are shifted into whole-video
 * time using offset_samples — the exact integer — never offset_ms, which is
 * rounded for display.
 *
 * Written with a streaming writer. At 35 minutes this carries roughly 6,000
 * timed words, and concatenating strings would hold it all in memory for no
 * reason.
 *
 * Silence is emitted as a BARE {\k} spacer, not folded into a neighbouring
 * word:
 *
 *  - A scene's lead-in gets a spacer at the start of its first line, so the
 *    highlight does not sweep before there is any audio. The line's Start
 *    timestamp is still the scene offset, which is what anchors the timeline.
 *  - A scene's trailing silence and frame padding get a spacer at the end of
 *    its last line, so the highlight stops when the speech does.
 *
 * Every line's {\k} durations tile it completely, chunks meet exactly within a
 * scene, and scenes meet exactly across the whole video.
 */
class GenerateAssSubtitles
{
    public function __construct(private readonly SubtitleChunker $chunker) {}

    /**
     * @param  array<int, array<string, mixed>>  $scenes
     * @param  array<int, array<string, mixed>>  $sceneAudio  Keyed by scene sequence.
     * @return array{
     *     output_path: string,
     *     lines: int,
     *     words: int,
     *     spacers: int,
     *     longest_chunk: int,
     *     total_centiseconds: int,
     *     bytes: int
     * }
     */
    public function handle(array $scenes, array $sceneAudio, string $outputPath, string $title): array
    {
        $style = AssStyle::fromConfig();
        $rate = (int) config('render.audio.sample_rate');

        if (! is_dir($directory = dirname($outputPath))) {
            mkdir($directory, 0775, true);
        }

        $handle = fopen($outputPath, 'wb');

        if ($handle === false) {
            throw new RuntimeException("Could not open {$outputPath} for writing.");
        }

        $lines = 0;
        $words = 0;
        $spacers = 0;
        $longest = 0;
        $lastEnd = 0;

        try {
            fwrite($handle, $style->scriptInfo($title));
            fwrite($handle, "\n");
            fwrite($handle, $style->stylesBlock());
            fwrite($handle, "\n");
            fwrite($handle, $style->eventsHeader());

            foreach ($scenes as $scene) {
                $sequence = (int) $scene['sequence'];
                $audio = $sceneAudio[$sequence] ?? null;

                if ($audio === null) {
                    throw new InvalidArgumentException("No scene_audio entry for scene {$sequence}.");
                }

                $sceneWords = $scene['words'];
                $offsetSamples = (int) $audio['offset_samples'];

                $sceneStart = self::samplesToCentiseconds($offsetSamples, $rate);
                $sceneEnd = self::samplesToCentiseconds($offsetSamples + (int) $audio['padded_samples'], $rate);

                [$wordStart, $wordEnd] = $this->absoluteBoundaries($sceneWords, $offsetSamples, $sceneStart, $sceneEnd, $rate);

                $chunks = $this->chunker->chunk(array_column($sceneWords, 'word'));

                foreach ($chunks as $index => $chunk) {
                    $isFirst = $index === 0;
                    $isLast = $index === count($chunks) - 1;

                    $i = $chunk['start'];
                    $j = $i + $chunk['length'];

                    // A chunk begins where its first word begins, except the
                    // scene's opening chunk, which begins at the scene offset
                    // so the timeline has no gap at the seam.
                    $lineStart = $isFirst ? $sceneStart : $wordStart[$i];
                    $lineEnd = $isLast ? $sceneEnd : $wordEnd[$j - 1];

                    $segments = [];

                    if ($isFirst && $wordStart[0] > $sceneStart) {
                        $segments[] = ['k' => $wordStart[0] - $sceneStart, 'text' => ''];
                        $spacers++;
                    }

                    for ($m = $i; $m < $j; $m++) {
                        $segments[] = [
                            'k' => $wordEnd[$m] - $wordStart[$m],
                            'text' => $this->assertPlain((string) $sceneWords[$m]['word']),
                        ];
                    }

                    if ($isLast && $sceneEnd > $wordEnd[count($sceneWords) - 1]) {
                        $segments[] = ['k' => $sceneEnd - $wordEnd[count($sceneWords) - 1], 'text' => ''];
                        $spacers++;
                    }

                    fwrite($handle, $this->dialogue($segments, $lineStart, $lineEnd));

                    $lines++;
                    $longest = max($longest, $chunk['length']);
                }

                $words += count($sceneWords);
                $lastEnd = $sceneEnd;
            }
        } finally {
            fclose($handle);
        }

        return [
            'output_path' => $outputPath,
            'lines' => $lines,
            'words' => $words,
            'spacers' => $spacers,
            'longest_chunk' => $longest,
            'total_centiseconds' => $lastEnd,
            'bytes' => filesize($outputPath) ?: 0,
        ];
    }

    /**
     * Sample position to centiseconds, rounded once.
     *
     * A line's start and the previous line's end derive from the same
     * cumulative sample count, so consecutive lines meet exactly.
     */
    public static function samplesToCentiseconds(int $samples, int $sampleRate): int
    {
        return (int) round($samples * 100 / $sampleRate);
    }

    /**
     * Absolute start and end centisecond for every word in a scene.
     *
     * Each is a rounded absolute position rather than an accumulated length, so
     * rounding error cannot build up along the scene. Word n's end and word
     * n+1's start round the same millisecond value and therefore land on the
     * same centisecond, which is what makes chunks meet with no gap.
     *
     * @param  array<int, array<string, mixed>>  $words
     * @return array{0: array<int, int>, 1: array<int, int>}
     */
    public function absoluteBoundaries(array $words, int $offsetSamples, int $sceneStart, int $sceneEnd, int $sampleRate): array
    {
        if ($words === []) {
            throw new InvalidArgumentException('A scene with no words cannot be timed.');
        }

        $offsetMs = $offsetSamples * 1000 / $sampleRate;

        $starts = [];
        $ends = [];
        $floor = $sceneStart;

        foreach ($words as $word) {
            // Clamp into the scene and keep monotonic: real transcripts carry
            // sub-centisecond words that would otherwise time backwards.
            $start = max($floor, min($sceneEnd, (int) round(($offsetMs + (int) $word['start_ms']) / 10)));
            $end = max($start, min($sceneEnd, (int) round(($offsetMs + (int) $word['end_ms']) / 10)));

            $starts[] = $start;
            $ends[] = $end;
            $floor = $end;
        }

        return [$starts, $ends];
    }

    /**
     * @param  array<int, array{k: int, text: string}>  $segments
     */
    private function dialogue(array $segments, int $startCs, int $endCs): string
    {
        $text = '';
        $wordsWritten = 0;

        foreach ($segments as $segment) {
            // Words are separated by a single space; a spacer contributes no
            // text at all, so stripping the tags reproduces the narration
            // exactly. Keyed on "have we written a word yet" rather than on the
            // previous segment, so a spacer between words cannot swallow the
            // separator and run two words together.
            $separator = $wordsWritten > 0 && $segment['text'] !== '' ? ' ' : '';

            $text .= $separator.'{\k'.$segment['k'].'}'.$segment['text'];

            if ($segment['text'] !== '') {
                $wordsWritten++;
            }
        }

        return sprintf(
            "Dialogue: 0,%s,%s,%s,,0,0,0,,%s\n",
            AssStyle::timestamp($startCs),
            AssStyle::timestamp($endCs),
            AssStyle::STYLE_NAME,
            $text
        );
    }

    /**
     * ASS treats braces and backslashes as markup. Refuse rather than mangle a
     * word, which would break the reassembly guarantee — in Phase 2 this means
     * a transcript needs sanitising upstream.
     */
    private function assertPlain(string $word): string
    {
        if (preg_match('/[{}\\\\\r\n]/', $word) === 1) {
            throw new InvalidArgumentException(
                "Word contains ASS markup characters and cannot be written verbatim: '{$word}'"
            );
        }

        return $word;
    }
}
