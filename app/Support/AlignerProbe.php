<?php

namespace App\Support;

use Symfony\Component\Process\Process;
use Throwable;

/**
 * Can the configured Python actually import whisperx?
 *
 * **The failure this exists for.** 181 scenes failed a batch with
 * `ModuleNotFoundError: No module named 'whisperx'`, one job at a time, after
 * the narration they were aligning had already been paid for. Nothing before the
 * dispatch had ever asked the question, because the only thing that could answer
 * it was a real alignment — and a real alignment needs real audio, so the check
 * lived downstream of the spend it should have gated.
 *
 * Importing the module needs neither audio nor a model. It costs a few hundred
 * milliseconds and it answers the only question that actually failed.
 *
 * **`python` is not an answer, and this refuses it.** The interpreter is resolved
 * from config and it must be an absolute path. Anything PATH-relative is rejected
 * before a process is even spawned, because PATH is a property of whoever started
 * the process and not of the machine:
 *
 *   - on Windows, `python` is routinely the per-user App Execution Alias, which
 *     is a reparse point in the user's own `WindowsApps` folder. A service
 *     started under a different account does not have it and never will;
 *   - a Store-installed Python puts its packages under that user's sandboxed
 *     `LocalCache`, so even the same name resolving for two accounts does not
 *     mean the same site-packages;
 *   - and a bare `python` that resolves to the alias STUB exits silently with no
 *     output at all, which is indistinguishable from a crash.
 *
 * So a run that works from the operator's shell and fails in a worker is the
 * expected outcome of leaving this on PATH, not a surprise. `isAbsolute()` is the
 * check that makes it impossible.
 *
 * Existence is deliberately not asserted by stat — see `unusable()`.
 */
final class AlignerProbe
{
    /**
     * How long to wait for `import whisperx`.
     *
     * Generous, because the import itself pulls torch — several seconds cold on
     * a spinning disk — and a probe that times out on a slow but working install
     * would block a dispatch that should have gone ahead.
     */
    private const TIMEOUT = 120;

    /**
     * The result of asking, in a shape a caller can both test and print.
     *
     * @return array{
     *     ok: bool,
     *     interpreter: string,
     *     resolved: ?string,
     *     version: ?string,
     *     error: ?string
     * }
     */
    public static function check(): array
    {
        $interpreter = (string) config('providers.whisperx.python', 'python');

        if (($reason = self::unusable($interpreter)) !== null) {
            return self::fail($interpreter, $reason);
        }

        // Asks for three things at once because a probe that only answered
        // "did it import" would leave the two most useful follow-up questions —
        // WHICH interpreter, and which Python — to a second round trip that
        // nobody makes when they are debugging at speed.
        $script = 'import sys; import whisperx; '
            .'print(sys.executable); print("%d.%d.%d" % sys.version_info[:3])';

        try {
            $process = new Process(
                [$interpreter, '-c', $script],
                null,
                ['PYTHONUNBUFFERED' => '1', 'PYTHONIOENCODING' => 'utf-8'],
            );
            $process->setTimeout(self::TIMEOUT);
            $process->run();
        } catch (Throwable $e) {
            return self::fail($interpreter, sprintf(
                "the interpreter could not be started at all: %s\n"
                .'That is a path or a permissions problem, not a missing package.%s',
                $e->getMessage(),
                // Only mentioned once starting has actually failed. On its own a
                // false is_file() proves nothing here — see unusable().
                @is_file($interpreter) ? '' : "\nNothing is readable at that path.",
            ));
        }

        if (! $process->isSuccessful()) {
            return self::fail($interpreter, self::explain($process));
        }

        $lines = array_values(array_filter(array_map('trim', explode("\n", $process->getOutput()))));

        return [
            'ok' => true,
            'interpreter' => $interpreter,
            'resolved' => $lines[0] ?? null,
            'version' => $lines[1] ?? null,
            'error' => null,
        ];
    }

    /** Whether the aligner is the thing that would do the timings work. */
    public static function isActive(): bool
    {
        return (string) config('providers.transcriber') === 'whisperx';
    }

    /**
     * Why the configured interpreter cannot be used, before spawning anything.
     */
    private static function unusable(string $interpreter): ?string
    {
        if (trim($interpreter) === '') {
            return 'WHISPERX_PYTHON is empty.';
        }

        if (! self::isAbsolute($interpreter)) {
            return sprintf(
                "WHISPERX_PYTHON is \"%s\", which is resolved from PATH rather than named outright.\n"
                .'PATH belongs to whoever started the process, so this cannot mean the same thing in '
                .'your shell and in a queue worker — and on Windows a bare `python` is usually the '
                .'per-user App Execution Alias, which another account does not have and a Store '
                ."install's packages sit behind anyway.\n"
                .'Set it to the absolute path of the interpreter that has whisperx installed. '
                .'`%s -c "import sys; print(sys.executable)"` from a shell where alignment works will '
                .'print it.',
                $interpreter,
                $interpreter,
            );
        }

        // Deliberately NOT an is_file() check.
        //
        // A Windows App Execution Alias — which is what a Store-installed
        // Python is reached through, and what this project's aligner actually
        // runs on — is a zero-length reparse point that PHP's stat cannot
        // follow. `is_file()` returns false for a path that executes perfectly.
        // Refusing on that would have blocked the one interpreter on this
        // machine that has whisperx installed.
        //
        // So existence is not asserted here at all: it is proved by running the
        // thing, which is a stronger claim anyway. A path that does not exist
        // fails to start and explain() says so.
        return null;
    }

    /**
     * Absolute on either platform: a Windows drive path, a UNC share, or a POSIX
     * root. Deliberately not `realpath()` — that would accept a relative path
     * that happens to resolve from the current working directory, which is the
     * precise ambiguity being refused.
     */
    private static function isAbsolute(string $path): bool
    {
        return (bool) preg_match('#^([a-zA-Z]:[\\\\/]|\\\\\\\\|/)#', $path);
    }

    private static function explain(Process $process): string
    {
        $stderr = trim($process->getErrorOutput());

        $missing = str_contains($stderr, 'ModuleNotFoundError')
            || str_contains($stderr, 'No module named');

        if ($missing) {
            return sprintf(
                "this interpreter exists but does not have whisperx installed.\n"
                ."Install it into THIS interpreter — not into whichever one your shell finds:\n"
                ."    \"%s\" -m pip install whisperx\n\n"
                .'stderr: %s',
                $process->getCommandLine() !== '' ? (string) config('providers.whisperx.python') : '<python>',
                mb_substr($stderr, 0, 400),
            );
        }

        if ($stderr === '' && trim($process->getOutput()) === '') {
            return sprintf(
                "the interpreter produced no output at all and exited %s.\n"
                .'On Windows that is the signature of the Microsoft Store alias stub, which exits '
                .'silently instead of running anything. Point WHISPERX_PYTHON at a real interpreter.',
                $process->getExitCode() === null ? 'unknown' : (string) $process->getExitCode(),
            );
        }

        return sprintf(
            "importing whisperx failed (exit %s).\nstderr: %s",
            $process->getExitCode() === null ? 'unknown' : (string) $process->getExitCode(),
            mb_substr($stderr, 0, 600),
        );
    }

    /**
     * @return array{ok: bool, interpreter: string, resolved: ?string, version: ?string, error: string}
     */
    private static function fail(string $interpreter, string $reason): array
    {
        return [
            'ok' => false,
            'interpreter' => $interpreter,
            'resolved' => null,
            'version' => null,
            'error' => $reason,
        ];
    }
}
