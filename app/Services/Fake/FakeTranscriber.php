<?php

namespace App\Services\Fake;

use App\Contracts\Transcriber;
use App\Enums\CostCategory;
use App\Enums\CostUnit;
use App\Support\Providers\ProviderUsage;
use App\Support\Providers\Transcription;
use RuntimeException;

/**
 * Word timings without a bill.
 *
 * Distributes the audio's duration across the expected words proportionally to
 * their length, which produces timings that are wrong in the way real ones are
 * wrong — non-uniform, contiguous, and summing exactly to the duration. A fake
 * that spaced words evenly would hide bugs in the karaoke timing, since the ASS
 * `{\k}` durations are what the whole word-by-word highlight is built from and
 * an off-by-one there is invisible until a video is watched.
 *
 * Contiguity is the property that matters most: each word starts where the last
 * one ended, and the final word ends exactly at `durationMs`. Gaps or overlaps
 * in `{\k}` timings desynchronise everything after them in the scene.
 */
class FakeTranscriber implements Transcriber
{
    /** @var array<int, array<string, mixed>> */
    public array $calls = [];

    public function transcribe(string $audioPath, ?string $expectedText = null): Transcription
    {
        if (! is_readable($audioPath)) {
            throw new RuntimeException("Nothing to transcribe: {$audioPath} is not readable.");
        }

        $durationMs = $this->wavDurationMs($audioPath);

        $words = preg_split('/\s+/u', trim((string) $expectedText), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $this->calls[] = [
            'audio_path' => $audioPath,
            'duration_ms' => $durationMs,
            'word_count' => count($words),
            'had_expected_text' => $expectedText !== null,
        ];

        if ($words === []) {
            return new Transcription([], $durationMs, $this->usage($durationMs));
        }

        $weights = array_map(fn (string $word): int => max(1, mb_strlen($word)), $words);
        $total = array_sum($weights);

        $timed = [];
        $cursor = 0;

        foreach ($words as $index => $word) {
            // The last word absorbs the rounding remainder so the transcript
            // ends exactly on the audio rather than a few milliseconds short —
            // which is what a trailing gap in the karaoke line looks like.
            $end = $index === count($words) - 1
                ? $durationMs
                : $cursor + (int) round($weights[$index] / $total * $durationMs);

            $timed[] = ['word' => $word, 'start_ms' => $cursor, 'end_ms' => max($cursor, $end)];
            $cursor = max($cursor, $end);
        }

        return new Transcription($timed, $durationMs, $this->usage($durationMs));
    }

    private function usage(int $durationMs): ProviderUsage
    {
        $seconds = $durationMs / 1000;

        return new ProviderUsage(
            provider: 'fake',
            operation: 'transcribe',
            category: CostCategory::Asset,
            quantity: round($seconds, 4),
            unit: CostUnit::AudioSeconds,
            usdCost: $seconds / 60 * (float) config('providers.fake.transcribe_usd_per_minute'),
        );
    }

    /** Read the length from the WAV header rather than decoding it. */
    private function wavDurationMs(string $path): int
    {
        $header = (string) file_get_contents($path, false, null, 0, 44);

        if (strlen($header) < 44 || substr($header, 0, 4) !== 'RIFF') {
            throw new RuntimeException("Not a WAV file: {$path}");
        }

        $byteRate = unpack('V', substr($header, 28, 4))[1] ?? 0;
        $dataBytes = unpack('V', substr($header, 40, 4))[1] ?? 0;

        if ($byteRate < 1) {
            throw new RuntimeException("WAV header declares a zero byte rate: {$path}");
        }

        return (int) round($dataBytes / $byteRate * 1000);
    }
}
