<?php

namespace App\Exceptions;

use App\Enums\FailureKind;

/**
 * An exception that knows what kind of failure it is.
 *
 * Implemented by the exceptions whose repair is known. The facts are what the
 * remedy needs to name the right act, scene or queue, and nothing else: they
 * are stored on the row, so they must stay true for as long as the row exists.
 * A sentence about how the pipeline works is never a fact.
 */
interface ClassifiedFailure
{
    public function failureKind(): FailureKind;

    /** @return array<string, scalar> */
    public function failureFacts(): array;
}
