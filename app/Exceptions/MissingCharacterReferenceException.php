<?php

namespace App\Exceptions;

use App\Enums\FailureKind;
use App\Models\Character;
use App\Models\Scene;
use RuntimeException;

/**
 * A scene was about to be drawn without a face it needs.
 *
 * Its own exception class rather than a generic RuntimeException because this
 * is the loud failure the whole reference feature is built around, and it has
 * to survive contact with a 200-job batch. A batch that logs "RuntimeException"
 * against eleven scenes tells the operator nothing; one that names the
 * characters tells them the sheet is incomplete and which rows to fix.
 *
 * Deliberately NOT recoverable and deliberately not downgraded to a warning.
 * Falling back to generating the character from text produces an image that
 * looks fine in isolation, so nothing downstream would catch it — the defect
 * only becomes visible as a face that changes across the video, after every
 * still has been paid for.
 */
class MissingCharacterReferenceException extends RuntimeException implements ClassifiedFailure
{
    /**
     * MissingCharacterReference when a picked reference is what is missing.
     * `tooManyForProvider()` builds this class too, and has no known repair:
     * splitting a frame or cutting its cast is a judgement about the scene,
     * and nothing measures which is right.
     */
    private FailureKind $kind = FailureKind::MissingCharacterReference;

    public function failureKind(): FailureKind
    {
        return $this->kind;
    }

    public function failureFacts(): array
    {
        return [];
    }

    /**
     * @param  array<int, Character>  $missing
     */
    public static function forScene(Scene $scene, array $missing): self
    {
        $names = implode(', ', array_map(
            fn (Character $c): string => sprintf(
                '%s (%s)',
                $c->name,
                $c->reference_image_path === null
                    ? 'no reference picked'
                    : 'reference file missing from disk'
            ),
            $missing
        ));

        return new self(sprintf(
            'Scene %d features %s with no usable reference image, so it will not be generated. '
            .'This is refused rather than generated from the description alone: a face drawn from '
            .'text looks correct on its own and drifts across the video, which is not visible '
            .'until every still has been paid for.',
            $scene->sequence,
            $names,
        ));
    }

    /**
     * @param  array<int, Character>  $cast
     */
    public static function tooManyForProvider(Scene $scene, array $cast, int $ceiling): self
    {
        $e = new self(sprintf(
            'Scene %d has %d named characters (%s) and the configured image model accepts %d '
            .'reference image%s per call. Generating it would silently draw the surplus characters '
            .'from text.',
            $scene->sequence,
            count($cast),
            implode(', ', array_map(fn (Character $c): string => $c->name, $cast)),
            $ceiling,
            $ceiling === 1 ? '' : 's',
        ));
        $e->kind = FailureKind::Unclassified;

        return $e;
    }
}
