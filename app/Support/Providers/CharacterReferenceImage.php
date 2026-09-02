<?php

namespace App\Support\Providers;

use App\Models\Character;

/**
 * One approved face, on its way into a scene's image call.
 *
 * The unit the scene generator is handed instead of a bare blob of bytes,
 * because a provider needs to know two things a blob cannot say.
 *
 * **Whose face it is.** The models that accept several references accept them
 * as an unlabelled array, and an unlabelled array of three faces is three
 * faces the generator may assign to the wrong three people. The name travels
 * with the image so the prompt can tie them together — the same name the
 * character's frozen description is already keyed on in the prompt body.
 *
 * **Whether the provider has already seen it.** `providerReference` is the
 * vendor's own handle for an image it is storing — an ElevenLabs `asset_id`.
 * A reference is cited by every one of the 150-250 stills its character
 * appears in, and re-uploading a megabyte of the same PNG two hundred times is
 * two hundred uploads that buy nothing. Upload once, cite by id after that.
 * Null means the provider has no such concept, or has not seen this one yet,
 * and the bytes go inline.
 */
final class CharacterReferenceImage
{
    public function __construct(
        /** As it appears in the prompt's cast block. */
        public readonly string $characterName,
        public readonly string $bytes,
        public readonly string $mimeType,
        /** The character's locked seed, if the provider can honour one. */
        public readonly ?int $seed = null,
        /** The provider's reusable handle for these exact bytes, if it issues one. */
        public readonly ?string $providerReference = null,
    ) {}

    public static function forCharacter(Character $character, string $bytes, string $mimeType, ?string $providerReference = null): self
    {
        return new self(
            characterName: $character->name,
            bytes: $bytes,
            mimeType: $mimeType,
            seed: $character->seed,
            providerReference: $providerReference,
        );
    }

    /** A copy carrying the handle the provider just issued for these bytes. */
    public function withProviderReference(string $reference): self
    {
        return new self(
            characterName: $this->characterName,
            bytes: $this->bytes,
            mimeType: $this->mimeType,
            seed: $this->seed,
            providerReference: $reference,
        );
    }

    public function base64(): string
    {
        return base64_encode($this->bytes);
    }
}
