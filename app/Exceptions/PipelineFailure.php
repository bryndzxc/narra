<?php

namespace App\Exceptions;

use App\Enums\FailureKind;
use RuntimeException;
use Throwable;

/**
 * A failure with a known kind, thrown where no more specific exception fits.
 *
 * Extends RuntimeException so every caller and test catching that still
 * catches this. The message says what happened; the repair is built at
 * display time by FailureRemedy from the kind.
 */
class PipelineFailure extends RuntimeException implements ClassifiedFailure
{
    /**
     * @param  array<string, scalar>  $facts
     */
    public function __construct(
        string $message,
        private readonly FailureKind $kind,
        private readonly array $facts = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function failureKind(): FailureKind
    {
        return $this->kind;
    }

    public function failureFacts(): array
    {
        return $this->facts;
    }
}
