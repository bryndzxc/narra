<?php

namespace App\Contracts;

use App\Models\Scene;
use App\Support\Providers\CharacterReferenceImage;
use App\Support\Providers\GeneratedImage;

/**
 * One scene's still.
 *
 * The dominant line item: 150-250 of these per video at roughly 70% of its
 * cost. Nothing here may run before Gate 2 — enforced at the data layer, since
 * every call writes an `asset` cost row and those assert the story has passed
 * it.
 *
 * `$seed` and `$references` exist for character consistency, which gets harder
 * as the video gets longer. A locked seed plus a stored reference image per
 * character is the mechanism; a provider that ignores both will produce a face
 * that changes at scene 90.
 *
 * **`$references` is a list, and that is load-bearing.** It was a single
 * nullable blob first, which quietly encoded an assumption the stories do not
 * hold to: that a frame has at most one person in it. Scenes in this format
 * routinely have two or three named characters, and a signature that can only
 * carry one face means every scene after the first character is generated from
 * text for everybody else — the exact drift the reference exists to prevent,
 * reintroduced by the shape of the parameter.
 *
 * It also decided the provider. Leonardo's Character Reference occupies one
 * ControlNet preprocessor slot per generation; ElevenLabs' `images[]` takes up
 * to 14. Refs-per-call is not a detail of the integration, it is the capability
 * the format needs.
 */
interface ImageGenerator extends ProviderIdentity
{
    /**
     * @param  string  $prompt  Operator-reviewed at Gate 2. Reaches a paid API
     *                          and, slugged, the filesystem — treated as
     *                          hostile input at every step.
     * @param  array<int, CharacterReferenceImage>  $references
     *                                                           Every character in this frame, each with the
     *                                                           approved face it must be drawn with. Resolved by
     *                                                           ResolveSceneReferences, which refuses to return
     *                                                           a partial set: a scene featuring a character
     *                                                           with no reference on file fails loudly rather
     *                                                           than falling back to text.
     */
    public function generate(
        Scene $scene,
        string $prompt,
        ?int $seed = null,
        array $references = [],
    ): GeneratedImage;

    /** Whether this provider can honour a locked seed. */
    public function supportsSeed(): bool;

    /**
     * How many character references this provider accepts in one call.
     *
     * Asked before the request, not learned from a 4xx. The failure that
     * matters is not the error — it is a provider that accepts fifteen
     * references, uses fourteen, and silently draws the fifteenth character
     * from text. A caller that knows the ceiling can refuse the scene instead.
     */
    public function maxReferences(): int;
}
