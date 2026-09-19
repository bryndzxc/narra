<?php

namespace Tests\Support;

/**
 * Finds the knobs a piece of advice names, and says which of them do not exist.
 *
 * ---------------------------------------------------------------------------
 * WHY THIS EXISTS
 * ---------------------------------------------------------------------------
 *
 * Two pieces of advice in this app sent the operator somewhere there was
 * nothing to find. The truncation message said "raise ANTHROPIC_MAX_TOKENS",
 * a variable no config file reads, and the WhisperX message said "confirm with
 * `php artisan providers:show`", a command that has never existed. Both read
 * as specific, and both cost a run. A remedy that names a knob which is not
 * there is worse than no remedy.
 *
 * A pure function, not a private method on the test that uses it, so it can be
 * pointed at known-bad text. A detector that can only be pointed at the live
 * tree can only ever say the live tree is fine.
 *
 * ---------------------------------------------------------------------------
 * WHAT COUNTS AS NAMING A KNOB
 * ---------------------------------------------------------------------------
 *
 * - A COMMAND: `name:sub`, when it follows `php artisan`, sits in backticks, or
 *   uses a namespace some real command uses (`story:`, `assets:`...). The last
 *   rule is what catches "(story:write --acts-onyl=5)" written bare. A name in
 *   a namespace nothing uses, written bare, is not taken as a command: batch
 *   names like "scene-clips:" and prose like "note:" would otherwise be noise.
 * - A FLAG: every `--flag` after a command, up to the end of that command.
 * - AN ENV VAR: an UPPER_SNAKE token whose first segment is a prefix some real
 *   env var uses. That is what separates ANTHROPIC_MAX_TOKENS (a real prefix,
 *   not a real variable) from JSON_THROW_ON_ERROR (a PHP constant).
 */
final class NamedKnobs
{
    /**
     * @return array<int, array{command: string, flags: array<int, string>}>
     */
    public static function commands(string $text, array $knownNamespaces): array
    {
        $found = [];

        // The command name, then its tail: everything up to something that
        // cannot be part of a command line.
        preg_match_all(
            // Not after `<` or `</`: `<livewire:dashboard />` is a component
            // tag, and the first run over the views reported eight of them.
            '/(php\s+artisan\s+|`)?(?<![<\/\w-])([a-z][a-z0-9-]*):([a-z][a-z0-9:-]*)((?:[ \t]+[^\s`)\];,.]+)*)/',
            $text,
            $matches,
            PREG_SET_ORDER,
        );

        foreach ($matches as $match) {
            $prefixed = $match[1] !== '';
            $namespace = $match[2];

            if (! $prefixed && ! in_array($namespace, $knownNamespaces, true)) {
                continue;
            }

            preg_match_all('/(?<![\w-])--([a-z][a-z0-9-]*)/', $match[4], $flags);

            $found[] = [
                'command' => $namespace.':'.rtrim($match[3], ':-'),
                'flags' => array_values(array_unique($flags[1])),
            ];
        }

        return $found;
    }

    /** @return array<int, string> */
    public static function envVars(string $text, array $knownPrefixes): array
    {
        preg_match_all('/\b[A-Z][A-Z0-9]*(?:_[A-Z0-9]+)+\b/', $text, $matches);

        return array_values(array_unique(array_filter(
            $matches[0],
            static fn (string $token): bool => in_array(explode('_', $token)[0], $knownPrefixes, true),
        )));
    }

    /**
     * Every knob `$text` names that does not exist.
     *
     * @param  array<string, array<int, string>>  $commandOptions  command name => its option names
     * @param  array<int, string>  $envNames  every env var the app reads
     * @return array<int, string>
     */
    public static function missing(string $text, array $commandOptions, array $envNames): array
    {
        $namespaces = array_values(array_unique(array_map(
            static fn (string $name): string => explode(':', $name)[0],
            array_keys($commandOptions),
        )));

        $prefixes = array_values(array_unique(array_map(
            static fn (string $name): string => explode('_', $name)[0],
            $envNames,
        )));

        $problems = [];

        foreach (self::commands($text, $namespaces) as $named) {
            if (! array_key_exists($named['command'], $commandOptions)) {
                $problems[] = "command {$named['command']} does not exist";

                continue;
            }

            foreach ($named['flags'] as $flag) {
                if (! in_array($flag, $commandOptions[$named['command']], true)) {
                    $problems[] = "{$named['command']} has no --{$flag} option";
                }
            }
        }

        foreach (self::envVars($text, $prefixes) as $var) {
            if (! in_array($var, $envNames, true)) {
                $problems[] = "env var {$var} is read by nothing";
            }
        }

        return $problems;
    }

    /**
     * Whether `$text` tells the operator to raise an output ceiling.
     *
     * CLAUDE.md records this as backwards twice: a truncated call is billed at
     * whatever the ceiling is, so a higher ceiling makes the failure dearer
     * rather than rarer, and on both stages where it was measured the ceiling
     * was being filled by reasoning, not by the artefact. The variable exists,
     * so the existence check cannot see this; it needs its own.
     *
     * "Do not raise" is the GOOD case and is allowed: the outline's remedy says
     * exactly that, and a rule that banned the phrase would ban the correction.
     */
    public static function advisesRaisingACeiling(string $text): bool
    {
        $pattern = '/\b(raise|raising|increase|increasing|bump)\s+(the\s+)?(ANTHROPIC_MAX_TOKENS\w*|max_tokens|output ceiling|ceiling)\b/i';

        if (! preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE)) {
            return false;
        }

        foreach ($matches[0] as [$phrase, $offset]) {
            $before = strtolower(substr($text, max(0, $offset - 24), min(24, $offset)));

            if (! preg_match('/\b(not|never|no|don\'t|without)\b[^.]*$/', $before)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every string literal in a PHP file, comments excluded.
     *
     * Comments are excluded on purpose: this project's docblocks quote the
     * defects they replaced — "Raise ANTHROPIC_MAX_TOKENS" is written in three
     * of them — and a scan that could not tell the record of a mistake from the
     * mistake would fail on the fix.
     *
     * Literals joined by `.` are returned as ONE string. This codebase builds
     * every long message by concatenating short literals across lines, so
     * "then raise " and "ANTHROPIC_MAX_TOKENS_ACT_SCRIPT" sit in different
     * tokens — and a scan that read them separately would pass exactly the
     * sentence it exists to catch. A `sprintf` placeholder or a variable
     * between two literals ends the join, which is correct: the text there is
     * not known.
     *
     * @return array<int, array{line: int, text: string}>
     */
    public static function phpStrings(string $source): array
    {
        $strings = [];
        $current = null;
        $pendingDot = false;

        foreach (token_get_all($source) as $token) {
            $id = is_array($token) ? $token[0] : null;

            if (in_array($id, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            if (in_array($id, [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
                if ($current !== null && $pendingDot) {
                    $current['text'] .= self::unquote($token[1]);
                } else {
                    if ($current !== null) {
                        $strings[] = $current;
                    }

                    $current = ['line' => $token[2], 'text' => self::unquote($token[1])];
                }

                $pendingDot = false;

                continue;
            }

            if ($token === '.' && $current !== null) {
                $pendingDot = true;

                continue;
            }

            if ($current !== null) {
                $strings[] = $current;
                $current = null;
            }

            $pendingDot = false;
        }

        if ($current !== null) {
            $strings[] = $current;
        }

        return $strings;
    }

    private static function unquote(string $literal): string
    {
        $first = $literal[0] ?? '';

        return ($first === '\'' || $first === '"') && strlen($literal) >= 2
            ? substr($literal, 1, -1)
            : $literal;
    }
}
