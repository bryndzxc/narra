<?php

namespace App\Contracts;

use App\Models\Scene;
use App\Support\Providers\GeneratedImage;

/**
 * One scene's still.
 *
 * The dominant line item: 150-250 of these per video at roughly 70% of its
 * cost. Nothing here may run before Gate 2 — enforced at the data layer, since
 * every call writes an `asset` cost row and those assert the story has passed
 * it.
 *
 * `$seed` and `$referenceImage` exist for character consistency, which gets
 * harder as the video gets longer. A locked seed plus a stored reference image
 * per character is the mechanism; a provider that ignores both will produce a
 * face that changes at scene 90.
 */
interface ImageGenerator
{
    /**
     * @param  string  $prompt  Operator-reviewed at Gate 2. Reaches a paid API
     *                          and, slugged, the filesystem — treated as
     *                          hostile input at every step.
     * @param  string|null  $referenceImage  Raw bytes of a character reference.
     */
    public function generate(
        Scene $scene,
        string $prompt,
        ?int $seed = null,
        ?string $referenceImage = null,
    ): GeneratedImage;

    /** Whether this provider can honour a locked seed. */
    public function supportsSeed(): bool;
}
