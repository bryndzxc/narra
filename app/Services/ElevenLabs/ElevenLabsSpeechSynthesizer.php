<?php

namespace App\Services\ElevenLabs;

use App\Contracts\SpeechSynthesizer;
use App\Enums\CostCategory;
use App\Enums\CostUnit;
use App\Models\Scene;
use App\Support\Providers\ProviderUsage;
use App\Support\Providers\SpeechQuota;
use App\Support\Providers\SynthesizedSpeech;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * The narrator. ElevenLabs text-to-speech, one call per scene.
 *
 * Per scene and never per story, which is the spec's rule and a billing one
 * rather than a tidiness one: a single call for a 36-minute narration means one
 * mispronounced name forces a full re-bill of 30,000 characters, where a scene
 * is re-generatable in isolation for the price of a scene.
 *
 * **PCM in, WAV out, and that is the load-bearing detail here.** The API
 * returns `pcm_*` as headerless signed 16-bit little-endian samples — no RIFF
 * header, no rate, no channel count. Written straight to disk that is a file
 * ffprobe cannot read, and every consumer downstream reaches for ffprobe:
 * GenerateSceneNarration probes it for the duration the clip's frame count is
 * ceil()'d from, and PadSceneAudio decodes it to check the audio fits inside
 * those frames. So the header is built here.
 *
 * Choosing PCM at all is the same argument one level up. An MP3's declared
 * duration and its decoded sample count are different numbers — the decoder
 * emits encoder delay and padding the container never mentions — so an MP3
 * would put a few samples of slop between the probe and the decode on each of
 * 186 scenes. The failure that eventually surfaces is PadSceneAudio's "padding
 * would become a trim", which reads as a frame-count bug and is not one.
 *
 * **This provider can run out.** On a plan without overage, ElevenLabs does not
 * bill past the allowance, it refuses. A 401/402 mid-batch is therefore an
 * expected operating state and not a bug, and it is translated into a message
 * that says so — see the quota check in `synthesize()` failures. `quota()`
 * exists so a run can be refused before it strands itself halfway.
 */
class ElevenLabsSpeechSynthesizer implements SpeechSynthesizer
{
    /**
     * Bytes per sample in every `pcm_*` format the API offers. The API is
     * documented as signed 16-bit little-endian mono for all of them.
     */
    private const PCM_BYTES_PER_SAMPLE = 2;

    private const PCM_CHANNELS = 1;

    public function synthesize(Scene $scene, string $text, string $voiceId): SynthesizedSpeech
    {
        $text = trim($text);

        if ($text === '') {
            throw new RuntimeException(
                "Scene {$scene->sequence} has no narration text. An empty TTS call still bills a "
                .'request and returns nothing to render over.'
            );
        }

        $format = $this->outputFormat();
        $model = $this->modelName();

        $response = $this->client()->withOptions(['stream' => false])->post(
            $this->url($voiceId, $format),
            $this->payload($scene, $text, $model),
        );

        if ($response->failed()) {
            throw new RuntimeException($this->explainFailure($response->status(), $response->body(), $scene, $voiceId));
        }

        $bytes = $response->body();

        if ($bytes === '') {
            throw new RuntimeException(sprintf(
                'ElevenLabs returned 200 with an empty body for scene %d. Nothing was written and the '
                .'call may still have been billed.',
                $scene->sequence,
            ));
        }

        $sampleRate = $this->pcmSampleRate($format);
        $samples = intdiv(strlen($bytes), self::PCM_BYTES_PER_SAMPLE);

        if ($samples === 0) {
            throw new RuntimeException(sprintf(
                'ElevenLabs returned %d bytes for scene %d — less than one whole PCM sample.',
                strlen($bytes),
                $scene->sequence,
            ));
        }

        // Exact by construction, not probed and not declared: raw PCM has a
        // whole number of samples at a known rate, so this IS the duration.
        // Rounded to whole ms only because the schema stores integer ms; the
        // sample count is what everything exact is rebuilt from later.
        // CEIL, not round, and the whole frame-count chain depends on it.
        //
        // `duration_ms` is an integer-millisecond proxy for a sample count, and
        // everything downstream derives the video length from it as
        // `frames = ceil(duration_ms / 1000 * fps)`. That ceil is what
        // guarantees the spec's invariant — video >= audio for every scene, so
        // padding only ever ADDS silence — but the guarantee only holds if
        // `duration_ms` is itself an upper bound on the real audio.
        //
        // round() breaks that, by up to half a millisecond, and only when the
        // true duration lands just past a frame boundary that the rounded-down
        // value falls short of. On story 9 that was 3 scenes in 186:
        // 5433.458 ms stored as 5433, giving 163 frames = 5433.333 ms, leaving
        // the audio 6 samples too long for the video that was supposed to
        // contain it. Padding would have had to become a trim, and PadSceneAudio
        // correctly refuses to do that — 186 clips into a render.
        //
        // Ceiling costs at most one extra millisecond, which is at most one
        // extra frame of silence in a rare case. That is exactly the trade the
        // spec already makes with ceil().
        $durationMs = (int) ceil($samples / $sampleRate * 1000);

        // The vendor's own count, when it sends one. Billing is in credits and
        // a credit is not always a character, so a header that reports what was
        // actually charged beats our count of the string we sent — and it is
        // the only way to settle whether stitching context is billed.
        $charged = $this->reportedCharacterCost($response->header('character-cost'));

        return new SynthesizedSpeech(
            bytes: $this->wrapPcmInWav($bytes, $sampleRate),
            mimeType: 'audio/wav',
            durationMs: $durationMs,
            voiceId: $voiceId,
            usage: $this->usage($text, $model, $charged, $durationMs, $samples, $response->header('request-id')),
        );
    }

    /**
     * American English voices on this account.
     *
     * Filtered to the audience's accent rather than returned whole, because the
     * story stores one `voice_id` for the life of a channel and a British
     * narrator on a US-audience channel is a mistake that survives every video
     * made after it. The label is built from the vendor's own metadata so the
     * picker reads as a casting note rather than a UUID.
     *
     * @return array<int, array{id: string, name: string, locale: string}>
     */
    public function voices(): array
    {
        $response = $this->client()->get($this->baseUrl().'/voices');

        if ($response->failed()) {
            throw new RuntimeException(sprintf(
                'ElevenLabs refused the voice list (HTTP %d): %s%s',
                $response->status(),
                mb_substr($response->body(), 0, 300),
                $response->status() === 401
                    ? "\nThis API key is missing the `voices_read` permission. Add it in the "
                      .'ElevenLabs dashboard under Developers > API Keys.'
                    : '',
            ));
        }

        $voices = [];

        foreach ((array) $response->json('voices', []) as $voice) {
            if (! is_array($voice) || ! is_string($voice['voice_id'] ?? null)) {
                continue;
            }

            $labels = is_array($voice['labels'] ?? null) ? $voice['labels'] : [];
            $accent = strtolower((string) ($labels['accent'] ?? ''));

            if ($accent !== '' && $accent !== 'american') {
                continue;
            }

            $voices[] = [
                'id' => $voice['voice_id'],
                'name' => trim(sprintf(
                    '%s (%s%s)',
                    (string) ($voice['name'] ?? $voice['voice_id']),
                    (string) ($labels['gender'] ?? 'unspecified'),
                    isset($labels['use_case']) ? ', '.(string) $labels['use_case'] : '',
                )),
                'locale' => 'en-US',
            ];
        }

        return $voices;
    }

    /**
     * What the allowance has left.
     *
     * Deliberately tolerant of a scoped key. `user_read` is a separate
     * permission from the one that synthesizes, so a perfectly working
     * narration key can be unable to answer this — and the honest answer then
     * is "unreadable", never a zero and never an optimistic pass. See
     * SpeechQuota.
     */
    public function quota(): SpeechQuota
    {
        $response = $this->client()->get($this->baseUrl().'/user/subscription');

        if ($response->status() === 401) {
            return SpeechQuota::unreadable(
                'this API key lacks the `user_read` permission, so the remaining credit balance '
                .'cannot be checked. Add it in the ElevenLabs dashboard under Developers > API Keys, '
                .'or read the balance off the usage page by hand before a large run.'
            );
        }

        if ($response->failed()) {
            return SpeechQuota::unreadable(sprintf(
                'ElevenLabs returned HTTP %d: %s',
                $response->status(),
                mb_substr($response->body(), 0, 200),
            ));
        }

        return new SpeechQuota(
            readable: true,
            tier: is_string($response->json('tier')) ? $response->json('tier') : null,
            used: (int) $response->json('character_count', 0),
            limit: (int) $response->json('character_limit', 0),
            // Two spellings in the wild across API versions; either one being
            // true is the thing that matters.
            canExtend: (bool) ($response->json('can_extend_character_limit')
                ?? $response->json('allowed_to_extend_character_limit')
                ?? false),
            resetsAt: is_numeric($response->json('next_character_count_reset_unix'))
                ? (int) $response->json('next_character_count_reset_unix')
                : null,
        );
    }

    /**
     * Credits this text will consume on the configured model.
     *
     * Public because the estimate and the pre-flight both need it and neither
     * should re-derive a multiplier. A credit is not a character — the v2
     * multilingual models bill 1 per character and flash/turbo bill 0.5 — so
     * counting characters and calling them credits would over-quote a flash run
     * by double and, worse, under-quote nothing, which is the direction that
     * hides.
     */
    public function creditsFor(string $text, ?string $model = null): float
    {
        return mb_strlen($text) * $this->creditsPerCharacter($model ?? $this->modelName());
    }

    public function providerName(): string
    {
        return 'elevenlabs';
    }

    public function isSimulated(): bool
    {
        return false;
    }

    public function modelName(): ?string
    {
        return (string) config('providers.elevenlabs.tts.model');
    }

    public function creditsPerCharacter(?string $model = null): float
    {
        $model ??= (string) $this->modelName();
        $card = (array) config('providers.elevenlabs.tts.pricing.credits_per_character', []);

        return (float) ($card[$model]
            ?? config('providers.elevenlabs.tts.pricing.default_credits_per_character', 1.0));
    }

    public function usdPerThousandCharacters(?string $model = null): float
    {
        return 1000
            * $this->creditsPerCharacter($model)
            * (float) config('providers.elevenlabs.tts.pricing.usd_per_credit');
    }

    /**
     * The request body.
     *
     * @return array<string, mixed>
     */
    private function payload(Scene $scene, string $text, ?string $model): array
    {
        $payload = [
            'text' => $text,
            'model_id' => $model,
            'voice_settings' => $this->voiceSettings(),
            'apply_text_normalization' => (string) config('providers.elevenlabs.tts.text_normalization', 'auto'),
        ];

        if ((bool) config('providers.elevenlabs.tts.deterministic_seed', true)) {
            // Derived from the scene id, not the sequence: a reorder must not
            // change a scene's performance, for the same reason paid assets are
            // filed by id. Bounded to the documented 0..4294967295 range.
            $payload['seed'] = $scene->id % 4294967296;
        }

        if ((bool) config('providers.elevenlabs.tts.stitch_context', false)) {
            $payload += array_filter([
                'previous_text' => $this->neighbourText($scene, -1),
                'next_text' => $this->neighbourText($scene, 1),
            ], fn (?string $v): bool => $v !== null);
        }

        return $payload;
    }

    /**
     * The adjacent scene's narration, as unspoken prosody context.
     *
     * Read from the database rather than carried in, because the caller is
     * GenerateSceneNarration working on one scene and giving it the job of
     * assembling context would push a TTS concern into the action. Scoped to
     * the same story and ordered by sequence, so a reordered scene picks up its
     * new neighbours.
     */
    private function neighbourText(Scene $scene, int $direction): ?string
    {
        $neighbour = Scene::query()
            ->where('story_id', $scene->story_id)
            ->when(
                $direction < 0,
                fn ($q) => $q->where('sequence', '<', $scene->sequence)->orderByDesc('sequence'),
                fn ($q) => $q->where('sequence', '>', $scene->sequence)->orderBy('sequence'),
            )
            ->first();

        $text = trim((string) $neighbour?->narration_text);

        return $text === '' ? null : $text;
    }

    /** @return array<string, mixed> */
    private function voiceSettings(): array
    {
        $settings = (array) config('providers.elevenlabs.tts.voice_settings', []);

        return [
            'stability' => (float) ($settings['stability'] ?? 0.5),
            'similarity_boost' => (float) ($settings['similarity_boost'] ?? 0.75),
            'style' => (float) ($settings['style'] ?? 0.0),
            'use_speaker_boost' => (bool) ($settings['use_speaker_boost'] ?? true),
            'speed' => (float) ($settings['speed'] ?? 1.0),
        ];
    }

    /**
     * What this call cost, priced from the vendor's count where it gave one.
     *
     * `$charged` is the `character-cost` response header. Preferring it over
     * our own `mb_strlen` is the same rule the image side learned the hard way:
     * trust what came back, not what was sent. It is also the only way to find
     * out whether stitched context is billed, which is why the header lands in
     * `detail` either way rather than being silently consumed.
     */
    private function usage(
        string $text,
        ?string $model,
        ?int $charged,
        int $durationMs,
        int $samples,
        ?string $requestId,
    ): ProviderUsage {
        $sent = mb_strlen($text);
        $billable = $charged ?? $sent;
        $credits = $billable * $this->creditsPerCharacter($model);

        return new ProviderUsage(
            provider: 'elevenlabs',
            operation: 'synthesize_speech',
            category: CostCategory::Asset,
            // Characters, because that is the unit the vendor's own usage page
            // reports and the one the operator reconciles against. Credits are
            // a derived multiple and live in `detail`.
            quantity: (float) $billable,
            unit: CostUnit::Characters,
            usdCost: round($credits * (float) config('providers.elevenlabs.tts.pricing.usd_per_credit'), 6),
            detail: [
                'model' => $model,
                'characters_sent' => $sent,
                // Null means the vendor sent no header on this call and the
                // figure above is ours. Recorded rather than hidden, so a
                // reconciliation against the usage page knows which it is
                // looking at.
                'characters_charged_header' => $charged,
                'credits' => $credits,
                'credits_per_character' => $this->creditsPerCharacter($model),
                'stitched_context' => (bool) config('providers.elevenlabs.tts.stitch_context', false),
                'duration_ms' => $durationMs,
                'samples' => $samples,
                'output_format' => $this->outputFormat(),
                'request_id' => $requestId,
            ],
            simulated: false,
            model: $model,
        );
    }

    private function reportedCharacterCost(?string $header): ?int
    {
        return $header !== null && is_numeric($header) ? (int) $header : null;
    }

    /**
     * A RIFF/WAVE header in front of the raw samples.
     *
     * The API sends `pcm_*` headerless. Everything downstream — ffprobe for the
     * duration, ffmpeg for the pad and the concat — needs the rate and layout,
     * and raw PCM carries neither.
     */
    private function wrapPcmInWav(string $pcm, int $sampleRate): string
    {
        $dataBytes = strlen($pcm);
        $byteRate = $sampleRate * self::PCM_CHANNELS * self::PCM_BYTES_PER_SAMPLE;
        $blockAlign = self::PCM_CHANNELS * self::PCM_BYTES_PER_SAMPLE;

        return 'RIFF'
            .pack('V', 36 + $dataBytes)
            .'WAVEfmt '
            .pack('V', 16)                                    // fmt chunk size
            .pack('v', 1)                                     // PCM
            .pack('v', self::PCM_CHANNELS)
            .pack('V', $sampleRate)
            .pack('V', $byteRate)
            .pack('v', $blockAlign)
            .pack('v', 8 * self::PCM_BYTES_PER_SAMPLE)
            .'data'
            .pack('V', $dataBytes)
            .$pcm;
    }

    private function outputFormat(): string
    {
        return (string) config('providers.elevenlabs.tts.output_format', 'pcm_24000');
    }

    /**
     * The sample rate the chosen format declares.
     *
     * Refuses anything that is not `pcm_*` rather than guessing. A compressed
     * format would still download and still write a playable file — and would
     * silently reintroduce the decoded-vs-declared gap that choosing PCM exists
     * to close. Better to fail on the first scene than at the concat assertion.
     */
    private function pcmSampleRate(string $format): int
    {
        if (! preg_match('/^pcm_(\d+)$/', $format, $matches)) {
            throw new RuntimeException(sprintf(
                'ELEVENLABS_TTS_OUTPUT_FORMAT is "%s". This provider writes a WAV container around raw '
                .'PCM and needs a pcm_* format to know the sample rate. A compressed format would still '
                ."produce a playable file, which is why this refuses rather than guessing:\n"
                .'its declared duration and its decoded length differ, and PadSceneAudio compares '
                .'exactly those two numbers on every scene.',
                $format,
            ));
        }

        return (int) $matches[1];
    }

    /**
     * Turn a vendor status code into something the operator can act on.
     *
     * 401 is the interesting one and it is why this method exists. On a plan
     * without overage, an exhausted allowance is not a billing event — it is a
     * refusal, arriving mid-batch on whichever scene happened to cross the
     * line. Reported as a generic HTTP failure it reads as a broken key; it is
     * not, and the fix is a different one.
     */
    private function explainFailure(int $status, string $body, Scene $scene, string $voiceId): string
    {
        $base = sprintf(
            'ElevenLabs refused narration for scene %d (HTTP %d): %s',
            $scene->sequence,
            $status,
            mb_substr($body, 0, 400),
        );

        $quotaHit = str_contains($body, 'quota') || str_contains($body, 'credits');

        return match (true) {
            $status === 401 && $quotaHit => $base
                ."\n\nThis is an exhausted allowance, not a broken key. Free and Starter plans have NO "
                .'overage — generation stops at the limit rather than billing past it, so a batch that '
                .'crosses the line mid-run leaves the remaining scenes unnarrated and the earlier ones '
                ."paid for.\nTop up or upgrade the plan, then re-press Generate assets: every scene that "
                .'already has audio is skipped and not re-billed.',

            $status === 401 => $base
                ."\n\nThe key was rejected. Check ELEVENLABS_API_KEY, and check the key still carries "
                .'`text_to_speech` permission in the ElevenLabs dashboard.',

            $status === 422 || $status === 400 => $base
                ."\n\nThe request was malformed for this model. The usual causes are a voice_id that is "
                .sprintf('not on this account (this call used "%s" — run `php artisan voices:list` to see ', $voiceId)
                .'what is), or apply_text_normalization=on against a flash/turbo model, which those '
                .'models reject outright rather than ignoring.',

            $status === 429 => $base
                ."\n\nRate limited. Nothing was billed for this call. Run fewer `assets` workers, or "
                .'re-press Generate assets once the batch settles — finished scenes are not re-billed.',

            default => $base,
        };
    }

    private function client(): PendingRequest
    {
        $key = (string) config('providers.elevenlabs.api_key');

        if (trim($key) === '') {
            throw new RuntimeException(
                'ELEVENLABS_API_KEY is not set, so the narrator cannot be built. Set it in .env, or set '
                .'PROVIDER_SPEECH_SYNTHESIZER=fake to run the pipeline on silent placeholder audio.'
            );
        }

        return Http::withHeaders(['xi-api-key' => $key])
            // The only real timeout on this platform. `queue:work --timeout` is
            // enforced with a pcntl alarm and pcntl does not exist in Windows
            // PHP, so without this a stalled request holds a worker forever.
            ->timeout((int) config('providers.elevenlabs.tts.timeout_seconds', 180))
            ->connectTimeout(15)
            // Zero by default, and see the config comment: a timed-out TTS call
            // may already have been generated and billed on their side, so an
            // automatic retry is an automatic second charge against an
            // allowance that stops the video when it runs out.
            ->retry(max(1, (int) config('providers.elevenlabs.tts.max_retries', 0) + 1), 1000, throw: false);
    }

    private function url(string $voiceId, string $format): string
    {
        return sprintf(
            '%s/text-to-speech/%s?output_format=%s',
            $this->baseUrl(),
            rawurlencode($voiceId),
            rawurlencode($format),
        );
    }

    private function baseUrl(): string
    {
        return rtrim((string) config('providers.elevenlabs.base_url'), '/');
    }
}
