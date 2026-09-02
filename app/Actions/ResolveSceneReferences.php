<?php

namespace App\Actions;

use App\Contracts\ImageGenerator;
use App\Contracts\ReferenceImageGenerator;
use App\Exceptions\MissingCharacterReferenceException;
use App\Models\Character;
use App\Models\Scene;
use App\Support\Providers\CharacterReferenceImage;

/**
 * Every face a scene needs, or an exception. Never a partial set.
 *
 * This is the enforcement point for the rule that a scene featuring a character
 * with no reference on file must fail loudly rather than fall back to text.
 * That rule is easy to state and easy to lose, because losing it does not look
 * like a failure: the image still generates, it still looks fine on its own,
 * and the defect is only visible as a face that changes between scene 40 and
 * scene 90 — by which point 199 stills have been paid for.
 *
 * So the failure is structural rather than remembered. There is no argument for
 * "text only", no nullable return and no partial array. A caller either gets
 * every character's approved face or gets a thrown exception naming exactly who
 * is missing.
 *
 * The reference set is also checked against what the provider can carry.
 * Leonardo's Character Reference occupies one slot; the ElevenLabs models take
 * 5 to 14 depending on which one is configured. A frame with more named
 * characters than the model has slots is refused here, because the alternative
 * is the provider silently ignoring the surplus and generating those people
 * from text — the same defect, arriving through a different door.
 */
class ResolveSceneReferences
{
    public function __construct(
        private readonly ImageGenerator $images,
        private readonly ReferenceImageGenerator $references,
    ) {}

    /**
     * @return array<int, CharacterReferenceImage>
     *
     * @throws MissingCharacterReferenceException
     */
    public function handle(Scene $scene): array
    {
        $cast = $scene->characters()->orderBy('name')->get();

        if ($cast->isEmpty()) {
            // Legitimate and common: establishing shots, empty rooms, objects.
            // A frame with nobody in it needs nobody's face.
            return [];
        }

        $missing = $cast->reject(fn (Character $c): bool => $c->hasUsableReference());

        if ($missing->isNotEmpty()) {
            throw MissingCharacterReferenceException::forScene($scene, $missing->all());
        }

        $ceiling = $this->images->maxReferences();

        if ($cast->count() > $ceiling) {
            throw MissingCharacterReferenceException::tooManyForProvider($scene, $cast->all(), $ceiling);
        }

        return $cast->map(fn (Character $c): CharacterReferenceImage => $this->bind($c))->all();
    }

    /**
     * Attach the bytes, and the provider's handle for them if it has one.
     *
     * The handle is what stops a character who appears in 60 scenes being
     * uploaded 60 times. It is cached on the character the first time the
     * provider issues one, so the upload happens once per reference rather than
     * once per still.
     */
    private function bind(Character $character): CharacterReferenceImage
    {
        $bytes = $character->referenceBytes();
        $selected = $character->references()->whereNotNull('selected_at')->first();

        $handle = $selected?->provider_reference;

        if ($handle === null && $selected !== null) {
            $handle = $this->references->storeReference($character, $bytes, $selected->mimeType());

            if ($handle !== null) {
                $selected->forceFill(['provider_reference' => $handle])->save();
            }
        }

        return CharacterReferenceImage::forCharacter(
            $character,
            $bytes,
            $selected?->mimeType() ?? 'image/png',
            $handle,
        );
    }
}
