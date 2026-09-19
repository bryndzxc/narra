<?php

namespace App\Support\Providers;

use App\Enums\FailureKind;
use App\Exceptions\ClassifiedFailure;
use RuntimeException;
use Throwable;

/**
 * A script generation call did not produce usable text.
 *
 * Its own type because the pipeline treats it differently from a transport
 * error: a refusal, a truncation at max_tokens, or a schema-shaped response
 * that came back malformed are all "this act does not exist", and the act is
 * re-runnable in isolation.
 *
 * The message says what happened. What to do about it is NOT in the message:
 * it used to be ("re-run this act (story:write --acts-only=N)", "Rework the
 * premise at Gate 1", the whole truncation remedy), which froze advice into a
 * row that outlives the code it describes. The kind and facts say which
 * failure this is, and FailureRemedy builds the repair when the page is read.
 * Most of these carry no kind at all, and that is correct: a malformed
 * response has no known repair, and the page says so.
 */
class ScriptWriterException extends RuntimeException implements ClassifiedFailure
{
    /**
     * @param  array<string, scalar>  $facts
     */
    public function __construct(
        string $message = '',
        int $code = 0,
        ?Throwable $previous = null,
        private readonly FailureKind $kind = FailureKind::Unclassified,
        private readonly array $facts = [],
    ) {
        parent::__construct($message, $code, $previous);
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
