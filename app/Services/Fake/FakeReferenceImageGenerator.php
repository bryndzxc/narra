<?php

namespace App\Services\Fake;

use App\Contracts\ReferenceImageGenerator;
use App\Enums\CostCategory;
use App\Enums\CostUnit;
use App\Models\Character;
use App\Support\Providers\GeneratedImage;
use App\Support\Providers\ProviderUsage;

/**
 * Candidate faces without a bill.
 *
 * Returns a real, decodable PNG for the same reason the still fake does — a
 * reference that cannot be decoded is one that will be uploaded to a provider
 * and rejected there, at the far end of the expensive stage.
 *
 * The candidates within one batch are deliberately DIFFERENT from each other.
 * A fake that returned four identical images would make the one thing the
 * operator does at this step — choose — impossible to exercise, and would hide
 * the bug where every candidate is generated with the same seed and the sheet
 * silently offers four copies of one face.
 *
 * Costs are recorded at the configured fake rate rather than zero, so the
 * projection the sheet shows before generating can be checked against what the
 * ledger says afterwards.
 */
class FakeReferenceImageGenerator implements ReferenceImageGenerator
{
    /** @var array<int, array<string, mixed>> */
    public array $calls = [];

    /** @var array<int, array<string, mixed>> */
    public array $stored = [];

    /** Set by a test to make the next generate() fail, as a provider would. */
    public ?string $failWith = null;

    public function generateReference(
        Character $character,
        string $prompt,
        ?int $seed = null,
    ): GeneratedImage {
        $this->calls[] = [
            'character_id' => $character->id,
            'name' => $character->name,
            'prompt' => $prompt,
            'seed' => $seed,
        ];

        if ($this->failWith !== null) {
            $message = $this->failWith;
            $this->failWith = null;

            throw new \RuntimeException($message);
        }

        $width = (int) config('characters.width', 1024);
        $height = (int) config('characters.height', 1024);

        return new GeneratedImage(
            bytes: $this->png($width, $height, $seed ?? count($this->calls)),
            mimeType: 'image/png',
            width: $width,
            height: $height,
            seed: $seed,
            usage: ProviderUsage::simulated(
                operation: 'generate_character_reference',
                // Not Asset. A sheet is generated at Gate 2, before it has been
                // crossed, and the guard that lets it through is keyed on this.
                // See CostCategory::Reference.
                category: CostCategory::Reference,
                quantity: 1.0,
                unit: CostUnit::Images,
                detail: ['seeded' => $seed !== null],
            ),
        );
    }

    /**
     * A handle, so the reuse path is exercised rather than only the inline one.
     *
     * Deterministic on the bytes: uploading the same reference twice returns
     * the same id, which is what a caller checking "have we already stored
     * this" depends on.
     */
    public function storeReference(Character $character, string $bytes, string $mimeType): ?string
    {
        $id = 'fake-asset-'.substr(hash('sha256', $bytes), 0, 16);

        $this->stored[] = [
            'character_id' => $character->id,
            'asset_id' => $id,
            'bytes' => strlen($bytes),
            'mime' => $mimeType,
        ];

        return $id;
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

    public function referenceModelName(): ?string
    {
        return null;
    }

    public function supportsSeed(): bool
    {
        return true;
    }

    /** A real PNG, varied per call so four candidates are four pictures. */
    private function png(int $width, int $height, int $variant): string
    {
        $image = imagecreatetruecolor($width, $height);

        $shade = 30 + ($variant * 53) % 180;
        imagefilledrectangle($image, 0, 0, $width, $height, imagecolorallocate($image, $shade, 40, 200 - $shade));

        // A blob where a head would be, so a reference cropped or scaled wrong
        // is visible rather than a flat colour that looks fine at any size.
        imagefilledellipse(
            $image,
            intdiv($width, 2),
            intdiv($height, 2),
            intdiv($width, 3),
            intdiv($height, 2),
            imagecolorallocate($image, 230, 200, 180)
        );

        ob_start();
        imagepng($image);
        $bytes = (string) ob_get_clean();

        imagedestroy($image);

        return $bytes;
    }
}
