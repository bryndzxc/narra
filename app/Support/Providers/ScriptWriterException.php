<?php

namespace App\Support\Providers;

use RuntimeException;

/**
 * A script generation call did not produce usable text.
 *
 * Its own type because the pipeline treats it differently from a transport
 * error: a refusal, a truncation at max_tokens, or a schema-shaped response
 * that came back malformed are all "this act does not exist", and the act is
 * re-runnable in isolation. The message says which, because "re-run act 4" and
 * "rework the premise" are different jobs for the operator.
 */
class ScriptWriterException extends RuntimeException {}
