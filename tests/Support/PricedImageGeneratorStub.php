<?php

namespace Tests\Support;

use App\Contracts\ImageGenerator;
use App\Enums\CostCategory;
use App\Enums\CostUnit;
use App\Models\Scene;
use App\Services\Fake\FakeImageGenerator;
use App\Support\Providers\CharacterReferenceImage;
use App\Support\Providers\GeneratedImage;
use App\Support\Providers\ProviderUsage;

/**
 * A provider that behaves like fal for pricing without touching the network.
 *
 * It exists to keep the cost-pinning test meaningful now that every fake bills
 * $0.00. "The quote equals the charge" is trivially true when both are zero,
 * and a test that only proves 0 == 0 would have passed happily through the
 * exact incident this stub was written after.
 *
 * So it reports itself as a real provider — `isSimulated()` is false, the rate
 * comes from `providers.fal.usd_per_image` — while delegating the bytes to
 * FakeImageGenerator so the pipeline still gets a decodable PNG. That makes the
 * arithmetic testable end to end: estimate, ledger, and the per-row decimal(10,4)
 * rounding all exercised at a non-zero rate.
 *
 * It is a test double and lives in tests/. Binding it in production would be
 * the original bug wearing a different hat: an object claiming a vendor it
 * never calls.
 */
class PricedImageGeneratorStub implements ImageGenerator
{
    public function __construct(private readonly FakeImageGenerator $inner) {}

    /**
     * @param  array<int, CharacterReferenceImage>  $references
     */
    public function generate(
        Scene $scene,
        string $prompt,
        ?int $seed = null,
        array $references = [],
    ): GeneratedImage {
        $image = $this->inner->generate($scene, $prompt, $seed, $references);

        return new GeneratedImage(
            bytes: $image->bytes,
            mimeType: $image->mimeType,
            width: $image->width,
            height: $image->height,
            seed: $image->seed,
            usage: new ProviderUsage(
                provider: 'fal',
                operation: 'generate_image',
                category: CostCategory::Asset,
                quantity: 1.0,
                unit: CostUnit::Images,
                usdCost: (float) config('providers.fal.usd_per_image'),
                detail: ['references' => count($references)],
                simulated: false,
                model: $this->modelName(),
            ),
        );
    }

    /** Whatever the inner fake recorded, so reference assertions still work. */
    public function calls(): array
    {
        return $this->inner->calls;
    }

    public function supportsSeed(): bool
    {
        return false;
    }

    public function maxReferences(): int
    {
        return (int) config('providers.fal.max_references', 10);
    }

    public function providerName(): string
    {
        return 'fal';
    }

    public function isSimulated(): bool
    {
        return false;
    }

    public function modelName(): ?string
    {
        return (string) config('providers.fal.edit_model');
    }
}
