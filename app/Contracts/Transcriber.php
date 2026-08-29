<?php

namespace App\Contracts;

use App\Support\Providers\Transcription;

/**
 * Word-level timings for one scene's narration audio.
 *
 * Chunked per scene, deliberately. Run against a finished 40-minute file this
 * is slow and its word timestamps drift toward the end; per scene it is fast,
 * accurate, and re-runnable for the one scene that needs it.
 *
 * `$expectedText` is passed because the narration is already known — it was
 * written at Gate 1 and reviewed at Gate 2. Giving the transcriber the text it
 * is aligning against turns an open transcription problem into an alignment
 * one, which is both cheaper and considerably more accurate on proper nouns.
 */
interface Transcriber
{
    /**
     * @param  string  $audioPath  A readable local path to one scene's audio.
     */
    public function transcribe(string $audioPath, ?string $expectedText = null): Transcription;
}
