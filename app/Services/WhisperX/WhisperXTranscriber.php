<?php

namespace App\Services\WhisperX;

use App\Contracts\Transcriber;
use App\Enums\CostCategory;
use App\Enums\CostUnit;
use App\Support\Providers\ProviderUsage;
use App\Support\Providers\Transcription;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Word-level timings by FORCED ALIGNMENT against the known narration.
 *
 * Not transcription, and the class would be a different and worse class if it
 * were. We already have every word: it was written at Gate 1, edited and
 * approved at Gate 2, and a paid TTS call spoke it verbatim seconds ago. The
 * open question is not what was said — it is when each already-known word was
 * said.
 *
 * Asking a recogniser to re-derive the text would sometimes get it wrong, and
 * the errors would not stay small. The `.ass` karaoke line is generated word by
 * word from this output, so a single substituted word desynchronises the
 * highlight for the rest of the scene AND puts a word on screen that the
 * operator never approved at the gate. Alignment makes that impossible by
 * construction: the word list is an input, and the model chooses only where the
 * boundaries fall. `$expectedText` is therefore required here, not optional as
 * the contract's signature allows — a null would turn this back into the thing
 * it exists not to be.
 *
 * **Local and free, but NOT simulated.** `isSimulated()` returns false and the
 * cost is a real, measured $0.00. The distinction matters to the one query the
 * ledger exists to answer: a simulated row means "no artefact was produced and
 * nobody was contacted", and these timings are real timings from real audio.
 * Recording them as simulated would make `WHERE simulated = 0` quietly wrong in
 * the opposite direction from the bug that motivated the flag.
 *
 * **One process per scene, and that is a real cost.** Every call pays a cold
 * torch import and a wav2vec2 load before it aligns a second of audio — tens of
 * seconds of startup for a few seconds of work, times 186. It is accepted
 * rather than optimised away because the alternative is a resident daemon, and
 * a daemon on this platform means another thing NSSM has to supervise and
 * another thing that can be silently dead while the queue looks healthy. The
 * fan-out across `assets` workers is what makes it tolerable; a GPU device
 * makes it fast.
 *
 * Arguments go to Symfony Process as an ARRAY and the request goes over stdin.
 * Never a shell string: Windows PHP's escapeshellarg() replaces `%` and `"`
 * with spaces instead of escaping them, and narration text is exactly the
 * string that carries quotes.
 */
class WhisperXTranscriber implements Transcriber
{
    public function transcribe(string $audioPath, ?string $expectedText = null): Transcription
    {
        if (! is_readable($audioPath)) {
            throw new RuntimeException("Nothing to align: {$audioPath} is not readable.");
        }

        $text = trim((string) $expectedText);

        if ($text === '') {
            throw new RuntimeException(
                'WhisperXTranscriber was called with no expected text. This provider does forced '
                .'alignment, not transcription: the words are an input. Running without them would '
                .'mean the subtitles carried whatever a recogniser heard rather than the narration '
                .'approved at Gate 2, which is the exact failure alignment is chosen to prevent.'
            );
        }

        $expectedWords = $this->tokenize($text);

        $result = $this->run([
            'audio_path' => $audioPath,
            'text' => $text,
            'language' => (string) config('providers.whisperx.language', 'en'),
            'device' => (string) config('providers.whisperx.device', 'cpu'),
            'align_model' => config('providers.whisperx.align_model'),
            'cache_dir' => config('providers.whisperx.cache_dir'),
        ], $audioPath);

        $durationMs = (int) $result['duration_ms'];
        $words = $this->normalize((array) $result['words'], $durationMs);

        $this->assertAligned($words, $expectedWords, (int) ($result['unaligned'] ?? 0), $audioPath);

        return new Transcription(
            words: $words,
            durationMs: $durationMs,
            usage: new ProviderUsage(
                provider: 'whisperx',
                operation: 'align_timings',
                category: CostCategory::Asset,
                quantity: round($durationMs / 1000, 4),
                unit: CostUnit::AudioSeconds,
                // Real work, genuinely free: a local model on local hardware.
                // Zero WITHOUT `simulated: true` — see the class docblock.
                usdCost: 0.0,
                detail: [
                    'device' => $result['device'] ?? null,
                    'align_model' => $result['align_model'] ?? null,
                    'words' => count($words),
                    'expected_words' => count($expectedWords),
                    'unaligned' => (int) ($result['unaligned'] ?? 0),
                    'forced_alignment' => true,
                ],
                simulated: false,
                model: is_string($result['align_model'] ?? null) ? $result['align_model'] : null,
            ),
        );
    }

    public function providerName(): string
    {
        return 'whisperx';
    }

    /**
     * False. It costs nothing and it is still real.
     *
     * `isSimulated()` is what the money guard and the "what did this actually
     * cost" query key on, and it means "produced no real artefact, contacted
     * nobody" — not "was free". These timings come from a real model reading
     * real audio and are the timings the video ships with.
     */
    public function isSimulated(): bool
    {
        return false;
    }

    public function modelName(): ?string
    {
        return config('providers.whisperx.align_model')
            ?: sprintf('wav2vec2-default-%s', (string) config('providers.whisperx.language', 'en'));
    }

    /** USD per minute of audio. Local model, local hardware, no bill. */
    public function usdPerMinute(): float
    {
        return 0.0;
    }

    /**
     * Run the alignment script and decode its one JSON object.
     *
     * @param  array<string, mixed>  $request
     * @return array<string, mixed>
     */
    private function run(array $request, string $audioPath): array
    {
        $script = (string) config('providers.whisperx.script');

        if (! is_file($script)) {
            throw new RuntimeException(sprintf(
                'The WhisperX alignment script is not at %s. Set WHISPERX_SCRIPT, or restore '
                .'resources/whisperx/align.py.',
                $script,
            ));
        }

        $process = new Process(
            [(string) config('providers.whisperx.python', 'python'), $script],
            null,
            // Unbuffered, so a crash's traceback is on stderr in full rather
            // than lost in a pipe when the process dies.
            ['PYTHONUNBUFFERED' => '1', 'PYTHONIOENCODING' => 'utf-8'],
            json_encode($request, JSON_THROW_ON_ERROR),
        );

        // Symfony enforces this in userland and needs no pcntl, which is the
        // only reason there is a real bound here at all. It has to cover a cold
        // start: every call re-imports torch and reloads the model.
        $process->setTimeout((float) config('providers.whisperx.timeout_seconds', 600));

        try {
            $process->run();
        } catch (ProcessTimedOutException $e) {
            throw new RuntimeException(sprintf(
                'WhisperX alignment timed out after %ds on %s. Every call pays a cold torch import and '
                .'a model load before it aligns anything, so the first run on a machine also downloads '
                ."the wav2vec2 checkpoint.\nRaise WHISPERX_TIMEOUT, or warm the cache once with a "
                .'single scene before dispatching a batch.',
                (int) config('providers.whisperx.timeout_seconds', 600),
                basename($audioPath),
            ), 0, $e);
        }

        $stdout = trim($process->getOutput());
        $stderr = trim($process->getErrorOutput());

        if ($stdout === '') {
            throw new RuntimeException($this->explainSilence($process->getExitCode(), $stderr));
        }

        try {
            $decoded = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new RuntimeException(sprintf(
                'WhisperX returned output that is not JSON. Something wrote to stdout alongside the '
                ."response — a print() in the script, or a library that chatters at import.\n"
                ."First 400 bytes: %s\nstderr: %s",
                mb_substr($stdout, 0, 400),
                mb_substr($stderr, 0, 400),
            ), 0, $e);
        }

        if (! is_array($decoded)) {
            throw new RuntimeException('WhisperX returned a JSON value that is not an object.');
        }

        if (($decoded['ok'] ?? false) !== true) {
            throw new RuntimeException(sprintf(
                "WhisperX alignment failed (%s): %s\n%s",
                (string) ($decoded['kind'] ?? 'unknown'),
                (string) ($decoded['error'] ?? 'no error given'),
                mb_substr($stderr, 0, 600),
            ));
        }

        return $decoded;
    }

    /**
     * A process that produced nothing at all.
     *
     * Worth its own message because the overwhelmingly likely cause on a fresh
     * machine is that the package is not installed, and the raw stderr for that
     * is a ModuleNotFoundError buried under an import trace.
     */
    private function explainSilence(?int $exitCode, string $stderr): string
    {
        $missing = str_contains($stderr, 'ModuleNotFoundError')
            || str_contains($stderr, 'No module named');

        return sprintf(
            "WhisperX produced no output (exit %s).%s\nstderr: %s",
            $exitCode === null ? 'unknown' : (string) $exitCode,
            $missing
                ? "\n\nThe Python interpreter at WHISPERX_PYTHON does not have whisperx installed. "
                  ."Install it into that exact interpreter:\n"
                  ."    <python> -m pip install whisperx\n"
                  .'and confirm with `php artisan providers:show`, which runs a real alignment.'
                : "\n\nCheck WHISPERX_PYTHON points at an interpreter that exists — on Windows, `python` "
                  .'on PATH is often the Microsoft Store alias stub, which exits silently.',
            mb_substr($stderr, 0, 800),
        );
    }

    /**
     * Clamp the returned timings into the audio and keep them contiguous.
     *
     * The script already does both; this is the belt on the braces, because
     * everything after it is arithmetic that assumes them. A gap or an overlap
     * in `{\k}` durations desynchronises the highlight for the rest of the
     * scene, and an end past the file's length shifts every subsequent scene
     * once the offsets accumulate.
     *
     * @param  array<int, mixed>  $words
     * @return array<int, array{word: string, start_ms: int, end_ms: int}>
     */
    private function normalize(array $words, int $durationMs): array
    {
        $tolerance = (int) config('providers.whisperx.boundary_tolerance_ms', 50);
        $normalized = [];
        $cursor = 0;

        foreach ($words as $word) {
            if (! is_array($word) || ! is_string($word['word'] ?? null)) {
                continue;
            }

            $token = trim($word['word']);

            if ($token === '') {
                continue;
            }

            $start = max($cursor, (int) ($word['start_ms'] ?? 0));
            $end = max($start, (int) ($word['end_ms'] ?? $start));

            if ($end > $durationMs + $tolerance) {
                throw new RuntimeException(sprintf(
                    'Alignment placed "%s" ending at %d ms in audio that is %d ms long — %d ms past the '
                    ."end, beyond the %d ms tolerance.\nA margin of a few ms is rounding; this is not. "
                    .'It means the model aligned against different audio than it was given, and the '
                    .'subtitle timeline would be built on it.',
                    $token,
                    $end,
                    $durationMs,
                    $end - $durationMs,
                    $tolerance,
                ));
            }

            $start = min($start, $durationMs);
            $end = min($end, $durationMs);

            $normalized[] = ['word' => $token, 'start_ms' => $start, 'end_ms' => $end];
            $cursor = $end;
        }

        return $normalized;
    }

    /**
     * Confirm the alignment actually aligned the text it was given.
     *
     * Named for the failure it catches, per the spec's rule about guards: a
     * check that only tests the axis a component is already strong on always
     * passes. Alignment's strong axis is word ORDER — it cannot reorder or
     * invent words, because they are an input. Its weak axis is COVERAGE: it
     * can decline to place words it could not find in the audio, and whisperx
     * returns those with null timings rather than failing.
     *
     * So the check is on count and on unplaced words, not on the text matching
     * — the text matching cannot fail here and testing it would be theatre.
     *
     * @param  array<int, array{word: string, start_ms: int, end_ms: int}>  $words
     * @param  array<int, string>  $expected
     */
    private function assertAligned(array $words, array $expected, int $unaligned, string $audioPath): void
    {
        if ($words === []) {
            throw new RuntimeException(sprintf(
                'Alignment returned no words for %s, from %d words of narration. The ASS karaoke line '
                .'for this scene would be empty.',
                basename($audioPath),
                count($expected),
            ));
        }

        // Tokenisation differs between PHP and the aligner — hyphens, curly
        // apostrophes, numerals the model splits. A small drift is expected; a
        // large one means the two are not looking at the same text.
        $drift = abs(count($words) - count($expected));

        if ($drift > max(2, (int) ceil(count($expected) * 0.10))) {
            throw new RuntimeException(sprintf(
                'Alignment returned %d words for narration of %d words (%s). That is not a tokenisation '
                ."difference.\nThe likeliest cause is that this scene's audio and its narration_text "
                .'have drifted apart — regenerate the narration for this scene before aligning it.',
                count($words),
                count($expected),
                basename($audioPath),
            ));
        }

        if ($unaligned > 0 && $unaligned > (int) ceil(count($expected) * 0.05)) {
            throw new RuntimeException(sprintf(
                'Alignment could not place %d of %d words in %s. Unplaced words get a zero-length slot, '
                .'so the karaoke highlight would jump over them.',
                $unaligned,
                count($expected),
                basename($audioPath),
            ));
        }
    }

    /** @return array<int, string> */
    private function tokenize(string $text): array
    {
        return preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }
}
