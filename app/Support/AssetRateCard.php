<?php

namespace App\Support;

use App\Contracts\ImageGenerator;
use App\Contracts\ProviderIdentity;
use App\Contracts\SpeechSynthesizer;
use App\Contracts\Transcriber;
use App\Services\ElevenLabs\ElevenLabsSpeechSynthesizer;
use App\Services\WhisperX\WhisperXTranscriber;
use RuntimeException;

/**
 * What a scene's assets cost, before any of them have been generated.
 *
 * **Every answer here comes from the resolved provider instance, never from
 * config, and that is the whole design of this class rather than a detail.**
 *
 * It used to read `config('providers.image_generator')`. That is a different
 * question from the one the operator is asking. Config says what SHOULD be
 * bound; only the container knows what IS. When `PROVIDER_IMAGE_GENERATOR` was
 * missing from `.env` the two disagreed silently, and the disagreement produced
 * exactly the failure this class is supposed to prevent: a confirmation screen
 * naming a vendor, a projection priced at that vendor's rate, and 186 flat-fill
 * PNGs from a fake — with $8.12 written to the ledger for calls nobody made.
 *
 * So the rate is chosen by asking the object that will run. A simulated
 * provider prices at zero, because it will bill zero, and `isSimulated()` is
 * surfaced so the screen can say so in words rather than leaving the operator
 * to notice a lowercase "fake" in a table column.
 *
 * The pinning test remains what keeps the arithmetic honest: a projection that
 * drifts from what is actually charged is worse than no projection, because it
 * will be believed.
 */
class AssetRateCard
{
    /** USD per generated scene still, for whatever is actually bound. */
    public function usdPerImage(): float
    {
        return $this->rate($this->images(), 'image_generator', fn (string $p): float => match ($p) {
            // $0.035 flat at every resolution — the one published, checkable
            // per-image price among the candidates.
            'fal' => (float) config('providers.fal.usd_per_image'),

            'elevenlabs' => (float) config('providers.elevenlabs.pricing.credits_per_image')
                * (float) config('providers.elevenlabs.pricing.usd_per_credit'),

            default => throw new RuntimeException($this->noRateCard('image_generator', $p)),
        });
    }

    /**
     * USD per 1,000 characters of narration.
     *
     * Asked of the bound instance rather than read from a config key, because
     * TTS is billed in CREDITS and the credits-per-character multiplier is a
     * property of the MODEL: 1.0 on the v2 multilingual models, 0.5 on flash
     * and turbo. A flat per-1k figure in this file would price a flash run at
     * double, and would keep doing so silently after a model switch.
     *
     * The figure is a plan rate — a subscription divided by its allowance — so
     * it answers "did this fit inside the plan" honestly and "what would this
     * cost at scale" only loosely. See the rate-card comment in config.
     */
    public function usdPerThousandSpeechCharacters(): float
    {
        return $this->rate($this->speech(), 'speech_synthesizer', function (string $p): float {
            $speech = $this->speech();

            if ($p === 'elevenlabs' && $speech instanceof ElevenLabsSpeechSynthesizer) {
                return $speech->usdPerThousandCharacters();
            }

            throw new RuntimeException($this->noRateCard('speech_synthesizer', $p));
        });
    }

    /**
     * What the speech provider will actually bill for a body of text.
     *
     * Asked of the bound instance for the same reason the rate above is: TTS is
     * billed in CREDITS and the multiplier is a property of the model, so a
     * caller counting characters and quoting them as the bill is quoting the
     * wrong number. `EstimateSceneAssets` did exactly that — it summed
     * `mb_strlen` and called it billable, which over-quoted story 21's
     * narration by exactly 2.000x across 270 scenes, reported the ElevenLabs
     * allowance as having 6,948 credits left when it had 27,953, and shaped a
     * session's spending decisions on a scarcity that was not there.
     *
     * The synthesizer has exposed `creditsFor()` since it was written. Nothing
     * called it. Two computations of one quantity, which is the defect this
     * codebase keeps finding at the bottom of its own list.
     *
     * A provider with no credit concept bills per character, so the fallback is
     * the character count itself rather than a refusal — the number is then
     * the same on both sides, which is the property that matters.
     */
    public function speechBillableUnitsFor(string $text): float
    {
        $speech = $this->speech();

        return $speech instanceof ElevenLabsSpeechSynthesizer
            ? $speech->creditsFor($text)
            : (float) mb_strlen($text);
    }

    /**
     * USD per minute of audio transcribed.
     *
     * WhisperX is zero, and it reaches that zero by a different route from a
     * fake's. The stand-in is zero because nothing happened; WhisperX is zero
     * because a local model on local hardware genuinely has no bill. Both print
     * $0.00 and only one of them is simulated, which is exactly why this class
     * asks the instance for its own rate instead of inferring anything from the
     * number being zero.
     */
    public function usdPerTranscribedMinute(): float
    {
        return $this->rate($this->transcriber(), 'transcriber', function (string $p): float {
            $transcriber = $this->transcriber();

            if ($p === 'whisperx' && $transcriber instanceof WhisperXTranscriber) {
                return $transcriber->usdPerMinute();
            }

            throw new RuntimeException($this->noRateCard('transcriber', $p));
        });
    }

    public function imageProvider(): string
    {
        return $this->images()->providerName();
    }

    public function speechProvider(): string
    {
        return $this->speech()->providerName();
    }

    public function transcriberProvider(): string
    {
        return $this->transcriber()->providerName();
    }

    /** The model actually doing the stills, from the instance that will do them. */
    public function imageModel(): ?string
    {
        return $this->images()->modelName();
    }

    /**
     * Whether any of the three stages is a stand-in.
     *
     * The one thing the operator most needs on screen before pressing, and the
     * thing that was missing: a run can be entirely simulated while every
     * number on the page looks like a bill.
     */
    public function hasSimulatedStage(): bool
    {
        return $this->simulatedStages() !== [];
    }

    /**
     * Which stages are simulated, named for the warning.
     *
     * @return array<int, string>
     */
    public function simulatedStages(): array
    {
        $simulated = [];

        foreach (['stills' => $this->images(), 'narration' => $this->speech(), 'word timings' => $this->transcriber()] as $label => $provider) {
            if ($provider->isSimulated()) {
                $simulated[] = $label;
            }
        }

        return $simulated;
    }

    /**
     * Whether the prices above are declared in config rather than billed back.
     *
     * No image API returns a cost field on a generation response, so a real
     * vendor's figure here is declared and must be reconciled. A simulated
     * provider is not "declared" — it is zero, and certain.
     */
    public function isDeclaredRatherThanBilled(): bool
    {
        return ! $this->images()->isSimulated();
    }

    /**
     * A simulated provider bills zero, so it quotes zero. No exceptions and no
     * config lookup: the two must agree by construction, or the quote is once
     * again describing a run that will not happen.
     *
     * @param  callable(string): float  $realRate
     */
    private function rate(ProviderIdentity $provider, string $configKey, callable $realRate): float
    {
        if ($provider->isSimulated()) {
            return 0.0;
        }

        return $realRate($provider->providerName());
    }

    private function images(): ImageGenerator
    {
        return app(ImageGenerator::class);
    }

    private function speech(): SpeechSynthesizer
    {
        return app(SpeechSynthesizer::class);
    }

    private function transcriber(): Transcriber
    {
        return app(Transcriber::class);
    }

    private function noRateCard(string $configKey, string $provider): string
    {
        return sprintf(
            'No asset rate card for %s "%s". Scene assets cannot be priced before they are generated, '
            .'and this app does not authorise paid asset generation without showing the cost first. '
            .'Add the rate to config/providers.php and the case to AssetRateCard.',
            $configKey,
            $provider,
        );
    }
}
