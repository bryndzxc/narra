<?php

namespace App\Services\Fake;

use App\Contracts\SpeechSynthesizer;
use App\Enums\CostCategory;
use App\Enums\CostUnit;
use App\Models\Scene;
use App\Support\Providers\ProviderUsage;
use App\Support\Providers\SynthesizedSpeech;

/**
 * Narration without a bill.
 *
 * The duration is derived from the text at a realistic narration rate rather
 * than being a constant. Everything downstream is arithmetic on that number —
 * frame counts, padding, cumulative offsets, the subtitle timeline, the act
 * timings the chapters come from — so a fake that returned a fixed 10,000 ms
 * for every scene would make the whole timing suite agree with itself and with
 * nothing else.
 */
class FakeSpeechSynthesizer implements SpeechSynthesizer
{
    /** @var array<int, array<string, mixed>> */
    public array $calls = [];

    public function synthesize(Scene $scene, string $text, string $voiceId): SynthesizedSpeech
    {
        $characters = mb_strlen($text);
        $words = max(1, str_word_count($text));
        // The same constant the script writer's word target is derived from.
        // If these two disagreed, a story written to hit 35 minutes would
        // render to something else and nobody would find out until the mux.
        $wpm = (int) config('render.narration.words_per_minute');
        $durationMs = (int) round($words / $wpm * 60_000);

        $this->calls[] = [
            'scene_id' => $scene->id,
            'sequence' => $scene->sequence,
            'voice_id' => $voiceId,
            'characters' => $characters,
            'duration_ms' => $durationMs,
        ];

        return new SynthesizedSpeech(
            // WAV, not MP3, and deliberately: padding and concatenation happen
            // in PCM because stream-copying padded MP3s accumulates per-file
            // encoder delay — measured at +458 ms over 12 scenes.
            bytes: $this->silentWav($durationMs),
            mimeType: 'audio/wav',
            durationMs: $durationMs,
            voiceId: $voiceId,
            usage: ProviderUsage::simulated(
                operation: 'synthesize_speech',
                category: CostCategory::Asset,
                quantity: (float) $characters,
                unit: CostUnit::Characters,
                detail: ['words' => $words, 'duration_ms' => $durationMs],
            ),
        );
    }

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

    public function voices(): array
    {
        // American English only. The audience is US and the story stores one
        // voice_id so a channel keeps a consistent narrator.
        return [
            ['id' => 'narrator-us-01', 'name' => 'Warm, mid-range, male', 'locale' => 'en-US'],
            ['id' => 'narrator-us-02', 'name' => 'Neutral, female', 'locale' => 'en-US'],
        ];
    }

    /** A valid mono 44.1 kHz PCM WAV of the requested length. */
    private function silentWav(int $durationMs): string
    {
        $sampleRate = (int) config('render.audio.sample_rate', 44100);
        // FLOOR — the same invariant as the real synthesizer, approached from
        // the other side. This one starts from a declared duration and
        // generates samples for it, so rounding UP would emit audio fractionally
        // longer than the duration it reports, which is the identical failure:
        // the frame count derived from the declared value would not contain it.
        //
        // Worth being strict about in the FAKE especially. Its habit of agreeing
        // with config by construction is what hid the 160 wpm error for a whole
        // phase and hid the sample-rate domain bug for another; it should not
        // also be the one component that can violate the padding invariant.
        $samples = (int) floor($durationMs / 1000 * $sampleRate);
        $dataBytes = $samples * 2;

        return 'RIFF'
            .pack('V', 36 + $dataBytes)
            .'WAVEfmt '
            .pack('V', 16)
            .pack('v', 1)
            .pack('v', 1)
            .pack('V', $sampleRate)
            .pack('V', $sampleRate * 2)
            .pack('v', 2)
            .pack('v', 16)
            .'data'
            .pack('V', $dataBytes)
            .str_repeat("\0", $dataBytes);
    }
}
