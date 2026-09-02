<?php

namespace App\Services\Fake;

use App\Contracts\ImageGenerator;
use App\Enums\CostCategory;
use App\Enums\CostUnit;
use App\Models\Scene;
use App\Support\Providers\CharacterReferenceImage;
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

    /**
     * @param  array<int, CharacterReferenceImage>  $references
     */
    public function generate(
        Scene $scene,
        string $prompt,
        ?int $seed = null,
        array $references = [],
    ): GeneratedImage {
        $this->calls[] = [
            'scene_id' => $scene->id,
            'sequence' => $scene->sequence,
            'prompt' => $prompt,
            'seed' => $seed,
            // Names rather than a boolean. "Was there a reference" cannot tell
            // a test that a two-hander got one face and generated the other
            // from text, which is the failure this fake is most useful for
            // catching.
            'references' => array_map(
                fn (CharacterReferenceImage $r): string => $r->characterName,
                $references
            ),
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
            usage: ProviderUsage::simulated(
                operation: 'generate_image',
                category: CostCategory::Asset,
                quantity: 1.0,
                unit: CostUnit::Images,
                detail: ['seeded' => $seed !== null, 'references' => count($references)],
            ),
        );
    }

    public function providerName(): string
    {
        return 'fake';
    }

    /**
     * Nothing left this machine and nothing was billed. The cost row this
     * produces is $0.00, and RecordProviderCost enforces that rather than
     * trusting it.
     */
    public function isSimulated(): bool
    {
        return true;
    }

    public function modelName(): ?string
    {
        return null;
    }

    public function supportsSeed(): bool
    {
        return true;
    }

    /**
     * The real number, not PHP_INT_MAX.
     *
     * A fake with no ceiling makes the one guard that matters untestable: a
     * scene whose cast exceeds what the provider can carry must be refused, not
     * silently truncated. 14 is what the configured ElevenLabs model accepts.
     */
    public function maxReferences(): int
    {
        return (int) config('providers.elevenlabs.max_references', 14);
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
