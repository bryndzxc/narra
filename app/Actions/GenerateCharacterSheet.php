<?php

namespace App\Actions;

use App\Contracts\ReferenceImageGenerator;
use App\Enums\AssetStatus;
use App\Models\Character;
use App\Models\CharacterReference;
use App\Support\ImagePromptBuilder;
use App\Support\StyleFingerprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Three or four candidate faces for one character, for the operator to choose
 * between.
 *
 * The first thing in this project that spends money on an asset, and the shape
 * of it is set by that. Four rules, each of which is a line of the spec made
 * mechanical:
 *
 *  1. **Every attempt writes a cost row, including the ones nobody picks.**
 *     Four candidates are four charges. A sheet that only recorded the winner
 *     would make the ledger unreconcilable against the real bill by a factor of
 *     four, and "what did this video cost" is the question the table exists to
 *     answer.
 *
 *  2. **A failure part-way through keeps what it bought.** If candidate three
 *     errors, candidates one and two are still on disk, still recorded, and
 *     still choosable. Rolling the batch back would discard images that were
 *     already billed for.
 *
 *  3. **The cost row is written before the image is filed.** A charge that
 *     happened is a charge that happened; if writing the PNG then fails, the
 *     money must still be in the ledger. The row is the money and the file is
 *     the artefact, in that order.
 *
 *  4. **A new round is a new batch, never an overwrite.** Regenerating is an
 *     explicit operator action with a bill, so what the previous round cost
 *     stays visible next to what the new one cost.
 *
 * Candidates are deliberately generated WITHOUT the character's locked seed
 * even where the provider supports one. A seed is what makes a face repeatable;
 * four repetitions of one face are not a choice. The seed's job starts after
 * the pick, holding the chosen face steady across 150-250 stills — the sheet's
 * job is to find the face worth holding.
 */
class GenerateCharacterSheet
{
    public function __construct(
        private readonly ReferenceImageGenerator $generator,
        private readonly ImagePromptBuilder $prompts,
        private readonly RecordProviderCost $costs,
    ) {}

    /**
     * @return Collection<int, CharacterReference> The candidates from this batch, failures included.
     */
    public function handle(Character $character, ?int $candidates = null): Collection
    {
        $story = $character->story;

        // Asserted here as well as at the cost row. The row's guard is the one
        // that cannot be walked past, but it fires after a paid call has
        // already been made — the provider has billed and the exception arrives
        // too late to prevent it. This one fires before.
        $story->assertReferenceSpendUnlocked('generate_character_reference');

        $count = $this->clamp($candidates);
        $batch = ((int) $character->references()->max('batch')) + 1;
        $prompt = $this->prompts->buildReference($character);

        $produced = collect();

        for ($sequence = 1; $sequence <= $count; $sequence++) {
            $produced->push($this->one($character, $batch, $sequence, $prompt));
        }

        return $produced;
    }

    /**
     * One candidate. Isolated so a provider failure costs one image, not four.
     */
    private function one(Character $character, int $batch, int $sequence, string $prompt): CharacterReference
    {
        $reference = CharacterReference::create([
            'character_id' => $character->id,
            'batch' => $batch,
            'sequence' => $sequence,
            'prompt' => $prompt,
            // The look this face is being drawn in, recorded at generation
            // rather than inferred later. `ImagePromptBuilder` promised since
            // Phase 2 that a retuned style would mark the sheets stale, and
            // nothing wrote the value that claim needed — so every still a
            // character appeared in could inherit a face from the old look with
            // nothing anywhere saying so.
            'style_fingerprint' => StyleFingerprint::current(),
            // From the generator that is about to run, never from config.
            // These two lines read `providers.reference_image_generator` and
            // `providers.elevenlabs.image_model` regardless of what was bound,
            // which is how 36 faces drawn by Seedream came to be recorded as
            // `gemini-3.1-flash-image` — a config default for a provider that
            // was not even in use.
            'provider' => $this->generator->providerName(),
            'model' => $this->generator->referenceModelName(),
            'status' => AssetStatus::Generating,
        ]);

        try {
            $image = $this->generator->generateReference($character, $prompt);
        } catch (Throwable $e) {
            // Recorded, not thrown. One candidate failing is a candidate the
            // operator does not get to choose between, not a reason to lose the
            // three that worked. The row stays so the failure is visible on the
            // sheet rather than showing three images and no explanation.
            $reference->update([
                'status' => AssetStatus::Failed,
                'error' => mb_substr($e->getMessage(), 0, 2000),
            ]);

            report($e);

            return $reference;
        }

        // The money first. A provider that billed and then failed to have its
        // image filed has still billed, and a ledger that forgot it is a ledger
        // that understates the video.
        $this->costs->handle($character->story, $image->usage);

        $path = $this->store($character, $batch, $sequence, $image->bytes, $image->mimeType);

        $reference->update([
            // Overwritten from what actually served the call. The pre-call value
            // is what we intended to use; this is what did.
            'model' => $image->usage->model ?? $this->generator->referenceModelName(),
            'seed' => $image->seed,
            'image_path' => $path,
            'width' => $image->width,
            'height' => $image->height,
            'usd_cost' => $image->usage->usdCost,
            'status' => AssetStatus::Ready,
        ]);

        return $reference->refresh();
    }

    /**
     * File the bytes under a short, slugged, collision-proof name.
     *
     * The character's name never reaches the path. It is operator-editable text
     * that ends up on a filesystem, which this app treats as hostile input
     * everywhere — and on Windows a 260-character path limit makes a long name
     * a real failure rather than a theoretical one.
     */
    private function store(Character $character, int $batch, int $sequence, string $bytes, string $mimeType): string
    {
        // The extension comes from what the provider actually returned, not
        // from what was asked for. Seedream answers a PNG-shaped request with a
        // JPEG, and a `.png` file full of JPEG is not cosmetic: CharacterReference
        // derives its mime from this filename, and those bytes go back out to
        // the image API inside a data URI that would then misdescribe them.
        $path = sprintf(
            '%s/char-%d/b%02d-%02d.%s',
            $character->story_id,
            $character->id,
            $batch,
            $sequence,
            match ($mimeType) {
                'image/jpeg' => 'jpg',
                'image/webp' => 'webp',
                default => 'png',
            },
        );

        Storage::disk((string) config('characters.disk', 'characters'))->put($path, $bytes);

        return $path;
    }

    private function clamp(?int $candidates): int
    {
        return max(1, min(
            $candidates ?? (int) config('characters.candidates', 4),
            (int) config('characters.max_candidates', 6),
        ));
    }
}
