<?php

namespace App\Services\Fake;

use App\Contracts\ImageGenerator;
use App\Enums\CostCategory;
use App\Enums\CostUnit;
use App\Models\Scene;
use App\Support\Providers\GeneratedImage;
use App\Support\Providers\ProviderUsage;

/**
 * Stills without a bill.
 *
 * Returns a real, decodable PNG at the configured render resolution rather than
 * a placeholder string. That matters more here than it looks: FFmpeg's `-loop 1`
 * does not fail on an undecodable image, it loops forever printing "Invalid PNG
 * signature" and emits no frames, and the pipeline has a guard specifically for
 * that. A fake that returned junk bytes would make every render test exercise
 * the guard instead of the render.
 *
 * Costs are recorded at the configured fake rate rather than zero, so a
 * fixture-driven run produces a realistic per-video breakdown to check the
 * ~$1 estimate against.
 */
class FakeImageGenerator implements ImageGenerator
{
    /** @var array<int, array<string, mixed>> */
    public array $calls = [];

    public function generate(
        Scene $scene,
        string $prompt,
        ?int $seed = null,
        ?string $referenceImage = null,
    ): GeneratedImage {
        $this->calls[] = [
            'scene_id' => $scene->id,
            'sequence' => $scene->sequence,
            'prompt' => $prompt,
            'seed' => $seed,
            'has_reference' => $referenceImage !== null,
        ];

        $width = (int) config('render.video.width', 1920);
        $height = (int) config('render.video.height', 1080);

        return new GeneratedImage(
            bytes: $this->png($width, $height, $scene->sequence),
            mimeType: 'image/png',
            width: $width,
            height: $height,
            // Echoed back, or derived deterministically from the scene. A fake
            // that returned null would make the seed-consistency path
            // untestable, and character drift across 250 stills is the single
            // biggest quality risk in this format.
            seed: $seed ?? crc32((string) $scene->id),
            usage: new ProviderUsage(
                provider: 'fake',
                operation: 'generate_image',
                category: CostCategory::Asset,
                quantity: 1.0,
                unit: CostUnit::Images,
                usdCost: (float) config('providers.fake.image_usd'),
                detail: ['seeded' => $seed !== null],
            ),
        );
    }

    public function supportsSeed(): bool
    {
        return true;
    }

    /** A real PNG, flat-filled, varied per scene so a misfiled clip is visible. */
    private function png(int $width, int $height, int $sequence): string
    {
        $image = imagecreatetruecolor($width, $height);

        $shade = 40 + ($sequence * 37) % 160;
        imagefilledrectangle($image, 0, 0, $width, $height, imagecolorallocate($image, $shade, $shade, $shade + 20));

        ob_start();
        imagepng($image);
        $bytes = (string) ob_get_clean();

        imagedestroy($image);

        return $bytes;
    }
}
