<?php

namespace Database\Factories;

use App\Enums\AssetStatus;
use App\Models\Character;
use App\Models\CharacterReference;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Storage;

/**
 * @extends Factory<CharacterReference>
 */
class CharacterReferenceFactory extends Factory
{
    protected $model = CharacterReference::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'character_id' => Character::factory(),
            'batch' => 1,
            'sequence' => 1,
            'prompt' => $this->faker->sentence(20),
            'seed' => null,
            'provider' => 'fake',
            'model' => null,
            'provider_reference' => null,
            'image_path' => null,
            'width' => 1024,
            'height' => 1024,
            'status' => AssetStatus::Pending,
            'usd_cost' => 0,
            'selected_at' => null,
        ];
    }

    /**
     * A candidate with real bytes actually on the disk.
     *
     * Bytes rather than only a path, because the code under test asks whether
     * the file exists and refuses when it does not — that refusal is the point
     * of `isUsable()`, and a factory that set a path to nothing would make
     * every test of the happy path exercise the failure instead.
     */
    public function ready(): static
    {
        return $this->afterCreating(function (CharacterReference $reference): void {
            $path = sprintf(
                '%d/char-%d/b%02d-%02d.png',
                $reference->character->story_id,
                $reference->character_id,
                $reference->batch,
                $reference->sequence,
            );

            Storage::disk((string) config('characters.disk', 'characters'))
                ->put($path, $this->png());

            $reference->forceFill([
                'image_path' => $path,
                'status' => AssetStatus::Ready,
                'usd_cost' => (float) config('providers.fake.reference_usd', 0.04),
            ])->save();
        });
    }

    /** Ready, chosen, and mirrored onto the character — the state stills need. */
    public function selected(): static
    {
        return $this->ready()->afterCreating(function (CharacterReference $reference): void {
            $reference->forceFill(['selected_at' => now()])->save();

            $reference->character->forceFill([
                'reference_image_path' => $reference->image_path,
            ])->save();
        });
    }

    public function failed(): static
    {
        return $this->state(fn (): array => [
            'status' => AssetStatus::Failed,
            'error' => 'provider refused the request',
        ]);
    }

    /** A real, decodable PNG — the same reason the fake generator returns one. */
    private function png(): string
    {
        $image = imagecreatetruecolor(64, 64);
        imagefilledrectangle($image, 0, 0, 64, 64, imagecolorallocate($image, 120, 90, 160));

        ob_start();
        imagepng($image);
        $bytes = (string) ob_get_clean();

        imagedestroy($image);

        return $bytes;
    }
}
