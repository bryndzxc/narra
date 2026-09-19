<?php

namespace Tests\Support;

use App\Support\LocaleGuard;

/**
 * The prompt locale check's detectors, as pure functions so each can be
 * pointed at a known-bad input (the GuardsGoRedTest rule: a detector living
 * inside the test that uses it cannot be drilled).
 *
 * ---------------------------------------------------------------------------
 * WHY THE STATIC HALF IS THE ONE THAT MATTERS
 * ---------------------------------------------------------------------------
 *
 * The rendered half renders a branch matrix and can only see branches somebody
 * put in it. A new branch nobody adds is the fixture-too-small failure that
 * voided a contract three times (CLAUDE.md, self-defeating checks table). So
 * the static half reads every string literal in every file a prompt writer
 * can reach, and the set of files is DERIVED from the code, not listed: a
 * hand-maintained file list goes stale the same way a hand-maintained matrix
 * does.
 */
final class PromptLocaleScan
{
    /** The classes that send text to a writer. The closure starts here. */
    public const ROOTS = [
        'app/Services/Claude/ClaudeScriptWriter.php',
        'app/Services/Claude/ClaudeMetadataWriter.php',
    ];

    /**
     * Every app file reachable from the roots by class reference.
     *
     * Followed through tokens, not `use` lines: a same-namespace class needs
     * no import (TalksToClaude is used that way), and a fully qualified
     * `\App\...` name needs none either. A closure built from `use` lines
     * alone missed TalksToClaude on the first measurement.
     *
     * @param  array<int, string>  $roots  relative to the project root
     * @return array<int, string>
     */
    public static function reachableFiles(string $projectRoot, array $roots = self::ROOTS): array
    {
        $bs = chr(92);
        $queue = $roots;
        $seen = [];

        while (($file = array_shift($queue)) !== null) {
            $path = $projectRoot.'/'.$file;

            if (isset($seen[$file]) || ! is_file($path)) {
                continue;
            }

            $seen[$file] = true;
            $source = (string) file_get_contents($path);
            $namespace = preg_match('/^namespace\s+([^;]+);/m', $source, $n) ? trim($n[1]) : '';

            foreach (token_get_all($source) as $token) {
                if (! is_array($token)) {
                    continue;
                }

                $candidates = match ($token[0]) {
                    T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED => [ltrim($token[1], $bs)],
                    // An unqualified name resolves to the current namespace.
                    T_STRING => [$namespace.$bs.$token[1]],
                    default => [],
                };

                foreach ($candidates as $class) {
                    if (! str_starts_with($class, 'App'.$bs)) {
                        continue;
                    }

                    $candidate = 'app/'.str_replace($bs, '/', substr($class, 4)).'.php';

                    if (! isset($seen[$candidate]) && is_file($projectRoot.'/'.$candidate)) {
                        $queue[] = $candidate;
                    }
                }
            }
        }

        $files = array_keys($seen);
        sort($files);

        return $files;
    }

    /**
     * The text of every string literal in PHP source — single, double,
     * heredoc and nowdoc bodies — and nothing from comments, which the writer
     * never sees and which legitimately quote the words this checks for.
     */
    public static function literals(string $source): string
    {
        $text = '';

        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
                $text .= $token[1]."\n";
            }
        }

        return $text;
    }

    /**
     * Occurrences not covered by an allowed exception.
     *
     * An exception is a term plus a phrase that must appear in the hit's
     * context. Keyed on the phrase, so a DELIBERATE use (the Tito/Lola ban has
     * to name the words) stays allowed and a new use of the same word anywhere
     * else is a finding.
     *
     * @param  array<int, string>  $terms
     * @param  array<int, array{term: string, phrase: string}>  $allowed
     * @return array<int, array{term: string, context: string}>
     */
    public static function findings(LocaleGuard $guard, string $text, array $terms, array $allowed = []): array
    {
        return array_values(array_filter(
            $guard->occurrences($text, $terms),
            static function (array $hit) use ($allowed): bool {
                foreach ($allowed as $exception) {
                    if (mb_strtolower($hit['term']) === mb_strtolower($exception['term'])
                        && str_contains(mb_strtolower($hit['context']), mb_strtolower($exception['phrase']))) {
                        return false;
                    }
                }

                return true;
            },
        ));
    }
}
