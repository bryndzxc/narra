<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * The narrator is not reading at the rate the script was sized for.
 *
 * Its own type rather than a bare RuntimeException because one caller has to
 * treat it differently: SceneAssetJob cancels the whole batch on this and only
 * this. A failed TTS call is one scene's problem and the other 185 are still
 * worth generating; a pace mismatch is every scene's problem, and continuing
 * means paying for a hundred and eighty-five more files that will be discarded
 * for the same reason as the first.
 */
class NarrationPaceException extends RuntimeException {}
