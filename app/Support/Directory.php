<?php

namespace App\Support;

use RuntimeException;

/**
 * Creating a directory, safely, when more than one process may be doing it.
 *
 * One method, and it exists because of a real failure rather than tidiness.
 *
 * `if (! is_dir($d)) { mkdir($d); }` is a check-then-act race. The render queue
 * runs the 1-2 workers the spec calls for, and at the start of a fan-out batch
 * they pick up their first scene at the same moment and both find the clips
 * directory missing. Both call mkdir; one wins; the loser gets
 * "mkdir(): File exists" as a warning-turned-exception and fails a scene whose
 * still, audio and timings were all fine. On a 186-scene render that is one
 * clip lost for no reason, and it is invisible until the batch stops short of
 * the chain.
 *
 * The fix is the second `is_dir()`. mkdir returning false is not a failure on
 * its own — another process having already created the directory is the
 * outcome we wanted. Only "still not there afterwards" is worth throwing over.
 */
class Directory
{
    public static function ensure(string $path): string
    {
        if (! is_dir($path) && ! @mkdir($path, 0775, true) && ! is_dir($path)) {
            throw new RuntimeException(
                "Could not create directory: {$path}. Check the parent exists and is writable — on "
                .'Windows also check the 260-character path limit, which a deep render root can hit.'
            );
        }

        return $path;
    }
}
