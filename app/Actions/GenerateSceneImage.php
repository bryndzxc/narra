<?php

namespace App\Actions;

use App\Contracts\ImageGenerator;
use App\Models\Scene;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * One scene's still — the production caller for the character reference
 * mechanism.
 *
 * This is the step the whole character sheet feature was built for. Nine faces
 * were generated, reviewed and picked at Gate 2 so that every frame those
 * people appear in could be conditioned on the approved image rather than on a
 * description. ResolveSceneReferences is what turns a picked face into the
 * references this call carries, and it refuses to return a partial set: a scene
 * featuring a character with no reference on file throws here rather than
 * generating them from text.
 *
 * That refusal is the reason the call is shaped this way. A face drawn from a
 * description looks entirely correct in isolation — nothing downstream catches
 * it, no test fails, the image is fine — and the defect only surfaces as a face
 * that has changed between scene 40 and scene 90, by which point every still
 * has been paid for. There is no fallback path here, deliberately, and there
 * must never be one added.
 *
 * Idempotent, because re-running costs $0.035 a scene and a resumed batch must
 * not be a repeated one. A still that is already on disk and whose image prompt
 * has not changed since Gate 2 approval is kept and not re-billed. That is also
 * what makes the retry press cheap: dispatching all 186 after five failures
 * bills for five.
 *
 * The cost row is written BEFORE the file is filed, exactly as the character
 * sheet does it. A provider that billed and then failed to have its bytes
 * written has still billed, and a ledger that forgot it understates the video.
 */
class GenerateSceneImage
{
    public function __construct(
        private readonly ImageGenerator $images,
        private readonly ResolveSceneReferences $references,
        private readonly RecordProviderCost $costs,
    ) {}

    /**
     * @return array{path: string, billed: bool, references: int, log: string}
     */
    public function handle(Scene $scene): array
    {
        $story = $scene->story;

        // Asserted here as well as at the cost row. The row's guard cannot be
        // walked past, but it fires after the provider has already billed —
        // this one fires before the call is made.
        $story->assertPaidAssetsUnlocked('generate_scene_image');

        if ($this->isAlreadyGenerated($scene)) {
            return [
                'path' => (string) $scene->image_path,
                'billed' => false,
                'references' => 0,
                'log' => 'kept existing still, prompt unchanged since approval',
            ];
        }

        $prompt = trim((string) $scene->image_prompt);

        if ($prompt === '') {
            throw new RuntimeException("Scene {$scene->sequence} has no image prompt to generate from.");
        }

        // Throws MissingCharacterReferenceException if any character in this
        // frame has no approved face, or if the frame carries more characters
        // than the bound provider can hold references for. Gate 2 refuses to
        // open while the first is true; this is the backstop that catches a
        // reference deleted or a provider swapped since.
        $references = $this->references->handle($scene);

        // The invariant, asserted where the knowledge to assert it exists.
        //
        // An empty reference set is legitimate — a frame with nobody in it, and
        // roughly a quarter of a real story is exactly that. A frame WITH
        // people and no faces is the defect the whole feature exists to
        // prevent, and only this layer can tell the two apart: the provider
        // sees an empty array either way.
        //
        // ResolveSceneReferences already returns every face or throws, so this
        // cannot fire today. It is here because the cost of it ever becoming
        // reachable is 186 stills with a face that changes at scene 90, and
        // that is not visible until all of them are paid for.
        if ($references === [] && $scene->characters()->exists()) {
            throw new RuntimeException(sprintf(
                'Scene %d has characters in frame but resolved no references. It will not be '
                .'generated from their descriptions — a face drawn from text looks correct on its '
                .'own and drifts across the video.',
                $scene->sequence,
            ));
        }

        $image = $this->images->generate(
            scene: $scene,
            prompt: $prompt,
            // The character's locked seed is not passed: a frame has a cast,
            // not a seed, and the providers that matter here honour neither.
            // The reference carries the whole consistency load — see
            // FalSeedreamImageGenerator::supportsSeed().
            seed: null,
            references: $references,
        );

        // Money first.
        $this->costs->handle($story, $image->usage);

        $path = $this->store($scene, $image->bytes, $image->mimeType);

        $scene->forceFill(['image_path' => $path])->save();

        return [
            'path' => $path,
            'billed' => true,
            'references' => count($references),
            'log' => sprintf(
                '%dx%d %s, %d reference(s), $%s',
                $image->width ?? 0,
                $image->height ?? 0,
                $image->mimeType,
                count($references),
                number_format($image->usage->usdCost, 4),
            ),
        ];
    }

    /**
     * Whether the still on file can be kept.
     *
     * Three conditions, and all three are load-bearing. The row must point
     * somewhere, the bytes must actually be there — a path to a file a failed
     * run never wrote is not an asset — and the prompt must be the one the
     * operator approved, since an edited prompt is exactly what
     * ApproveScenesGate nulls this field for.
     */
    private function isAlreadyGenerated(Scene $scene): bool
    {
        return ! $scene->needsImage()
            && $scene->image_path !== null
            && $this->disk()->exists($scene->image_path);
    }

    /**
     * File the bytes under a short, stable, slugged name.
     *
     * Filed by scene ID, not by sequence, and that is the one decision here
     * worth arguing about. Clips are filed as `scene-%03d` from the sequence
     * because they are free to rebuild when a reorder renumbers everything.
     * A still is not free — it is ~70% of the video's cost — so filing it under
     * a number that a reorder reassigns would leave every paid still sitting
     * under some other scene's name. The ID never moves.
     *
     * The extension comes from what the provider actually returned rather than
     * what was asked for: fal answers a 1024x1024 PNG request with a 1920x1920
     * JPEG, and a `.png` full of JPEG bytes misdescribes itself to everything
     * downstream that reads the extension.
     */
    private function store(Scene $scene, string $bytes, string $mimeType): string
    {
        $path = sprintf(
            '%d/stills/scene-%d.%s',
            $scene->story_id,
            $scene->id,
            match ($mimeType) {
                'image/jpeg' => 'jpg',
                'image/webp' => 'webp',
                default => 'png',
            },
        );

        $this->disk()->put($path, $bytes);

        return $path;
    }

    private function disk(): Filesystem
    {
        return Storage::disk((string) config('render.assets.disk', 'assets'));
    }
}
