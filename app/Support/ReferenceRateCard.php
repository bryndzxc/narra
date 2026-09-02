<?php

namespace App\Support;

use App\Contracts\ReferenceImageGenerator;
use RuntimeException;

/**
 * What one reference image costs, before one has been generated.
 *
 * A projection needs a price and a provider only reports a price after it has
 * billed, so this reads the same config the provider prices itself from. That
 * duplication is real and is the reason this class exists rather than the
 * number being inlined into the Livewire component: one place to correct when a
 * rate card moves, and one place for a test to pin.
 *
 * The pinning test is the part that matters. `ReferenceSheetCostTest` generates
 * a sheet through the fake and asserts the cost_entries sum equals what this
 * class projected. If the two ever drift, the operator is shown a number before
 * spending that is not the number they are charged — which is worse than
 * showing nothing, because it will be believed.
 */
class ReferenceRateCard
{
    /**
     * USD per generated reference image, for whichever provider is bound.
     */
    public function usdPerImage(): float
    {
        // A stand-in bills nothing, so it quotes nothing. Never a config
        // lookup: a "realistic" fake rate is what let $8.12 of unspent money
        // into the ledger and onto a confirmation screen.
        if ($this->generator()->isSimulated()) {
            return 0.0;
        }

        return match ($this->provider()) {
            'elevenlabs' => (float) config('providers.elevenlabs.pricing.credits_per_image')
                * (float) config('providers.elevenlabs.pricing.usd_per_credit'),

            // Flat per image at every resolution, and the only published,
            // checkable per-image price among the candidates.
            'fal' => (float) config('providers.fal.usd_per_image'),

            default => throw new RuntimeException(sprintf(
                'No reference rate card for provider "%s". A sheet cannot be priced before it is '
                .'generated, and this app does not generate paid images without showing the cost '
                .'first. Add the rate to config/providers.php.',
                $this->provider(),
            )),
        };
    }

    /**
     * Which implementation is bound right now — asked of the container, not of
     * config.
     *
     * Config says what should be bound; only the container knows what is. When
     * those disagreed for the scene-still generator, the app quoted one vendor
     * and ran another. See AssetRateCard, and ProviderIdentity.
     */
    public function provider(): string
    {
        return $this->generator()->providerName();
    }

    /** Whether a stand-in is bound: no vendor contacted, no real face drawn. */
    public function isSimulated(): bool
    {
        return $this->generator()->isSimulated();
    }

    /** The model actually doing the work, for the line the operator reads. */
    public function model(): ?string
    {
        return $this->generator()->referenceModelName();
    }

    private function generator(): ReferenceImageGenerator
    {
        return app(ReferenceImageGenerator::class);
    }

    /**
     * Whether the price above is a published figure or a declared one.
     *
     * Neither candidate provider publishes a verifiable per-image rate, and
     * ElevenLabs does not return a cost field on the generation response, so
     * every figure this app shows for images is declared rather than observed.
     * Saying so on the screen is the difference between an estimate the
     * operator reconciles against the real bill and one they trust.
     */
    public function isDeclaredRatherThanBilled(): bool
    {
        return ! $this->isSimulated();
    }
}
