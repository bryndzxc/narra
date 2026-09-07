<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A second attempt to generate one character's sheet while the first is still
 * running.
 *
 * Its own type rather than a generic runtime error because the call site has to
 * tell it apart from a provider failure: a provider failure means the sheet did
 * not happen and should be retried, and this means the sheet IS happening and
 * must not be. Reported to the operator as reassurance rather than as a fault —
 * pressing twice is what the old screen invited, not something they did wrong.
 */
class SheetInFlightException extends RuntimeException {}
