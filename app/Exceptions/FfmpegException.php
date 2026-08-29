<?php

namespace App\Exceptions;

use RuntimeException;
use Symfony\Component\Process\Process;

class FfmpegException extends RuntimeException
{
    public static function failed(Process $process): self
    {
        $stderr = trim($process->getErrorOutput());

        // FFmpeg puts the useful line last, and the useless lines first.
        $lines = array_slice(preg_split('/\r?\n/', $stderr) ?: [], -8);

        return new self(sprintf(
            "FFmpeg exited %s.\n\nCommand:\n%s\n\nStderr (last %d lines):\n%s",
            $process->getExitCode() ?? 'null',
            $process->getCommandLine(),
            count($lines),
            implode("\n", $lines) ?: '(empty)'
        ));
    }

    public static function timedOut(Process $process, float $timeout): self
    {
        return new self(sprintf(
            "FFmpeg exceeded its %.0fs timeout and was killed.\n\nCommand:\n%s",
            $timeout,
            $process->getCommandLine()
        ));
    }
}
