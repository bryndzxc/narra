<?php

namespace App\Contracts;

use App\Models\Character;
use App\Support\Providers\GeneratedImage;

/**
 * One candidate face for one character.
 *
 * Separate from ImageGenerator rather than a fifth argument on it, because the
 * two calls do not take the same subject. A still belongs to a Scene and is
 * one of 150-250; a reference belongs to a Character, is one of three or four,
 * and is the input the stills are conditioned on. Folding them together would
 * mean a nullable Scene on the method that must never be given one.
 *
 * They are usually the same vendor behind the same endpoint, and one class may
 * implement both. That is an implementation detail and not a reason to make
 * the interfaces lie about what they are given.
 *
 * Everything here bills. A sheet is a handful of images rather than a
 * batch, but it is generated before Gate 2 has been crossed, so its cost rows
 * are written as CostCategory::Reference and pass the one guard that unlocks
 * at `scenes_drafted`.
 */
interface ReferenceImageGenerator extends ProviderIdentity
{
    /**
     * @param  string  $prompt  Built from the character's frozen description
     *                          and the reference frame in config/characters.php.
     *                          Reaches a paid API and, slugged, the filesystem
     *                          — treated as hostile input at every step.
     * @param  int|null  $seed  The character's locked seed. Providers that
     *                          cannot honour one say so via supportsSeed()
     *                          rather than accepting it and ignoring it.
     */
    public function generateReference(
        Character $character,
        string $prompt,
        ?int $seed = null,
    ): GeneratedImage;

    /**
     * Hand the provider an image to keep, and get back its handle for it.
     *
     * The optimisation that makes references affordable at scale: a character
     * in 60 scenes is 60 image calls that each need its face, and uploading the
     * same PNG 60 times is 60 uploads that buy nothing. Providers that store
     * uploads — ElevenLabs' assets API — return an id here that can be cited
     * instead.
     *
     * Null from providers with no such concept. Callers must treat that as
     * normal and fall back to sending bytes, never as a failure.
     */
    public function storeReference(Character $character, string $bytes, string $mimeType): ?string;

    /**
     * The model that draws a candidate face, which is NOT ProviderIdentity's
     * modelName().
     *
     * One class implements both image contracts against two different
     * endpoints — text-to-image for a reference, which has no input image yet,
     * and edit for a scene still, which is conditioned on approved faces. A
     * single `modelName()` cannot answer for both, and letting it try is the
     * same class of guess that recorded 36 Seedream faces as Gemini.
     */
    public function referenceModelName(): ?string;

    /** Whether this provider can honour a locked seed. */
    public function supportsSeed(): bool;
}
