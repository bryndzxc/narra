<?php

/**
 * Control bytes in source, which are invisible everywhere a human would look.
 *
 * ---------------------------------------------------------------------------
 * THE DEFECT THIS EXISTS FOR — TWICE, IN THIS CODEBASE
 * ---------------------------------------------------------------------------
 *
 * A word-boundary regex was written as `'/\b'.preg_quote($cue).'\b/u'` and what
 * landed in the file was `'/<0x08>'.preg_quote($cue).'<0x08>/u'` — a literal
 * BACKSPACE where `\b` was intended, because the escape was consumed by a layer
 * between the author and the file. The regex is valid. PHP compiles it. It
 * matches nothing.
 *
 *   1. `tools/blade-php-scan.php`, widening a rule to blade directives inside
 *      foreign comments. The tool ran clean and silently matched nothing; the
 *      known-answer count went 2 -> 1 and named it.
 *   2. `app/Actions/ValidateSceneDrafts.php`, the close-frame setting advisory.
 *      Same byte, same cause, AFTER the first was written down in CLAUDE.md.
 *      Here it would have failed the other way — every close frame reported as
 *      naming no setting, an over-report in the loudest direction.
 *
 * Both were found by piping a line through `cat -A` and seeing `^H`. That is a
 * habit, and the second instance is proof that a habit written down is not a
 * mechanism: the note existed and the byte still shipped.
 *
 * ---------------------------------------------------------------------------
 * WHY NOTHING ELSE IN THE TOOLCHAIN CAN SEE IT
 * ---------------------------------------------------------------------------
 *
 * This is the argument for the tool, and it is unusually complete:
 *
 *   - **A test cannot.** The byte predates any case written for the rule it
 *     breaks, and a rule that matches nothing still satisfies a RED case that
 *     asserts something IS reported by some other clause.
 *   - **A diff cannot.** 0x08 renders as nothing in every editor, in `git
 *     diff`, and in a review. The two versions are visually identical.
 *   - **`php -l` cannot.** The regex is well-formed; so is the file.
 *   - **PHPStan / a linter cannot.** It is a valid string literal.
 *
 * So the byte is invisible to every instrument this project already runs, which
 * is exactly the "a check that cannot fire is indistinguishable from a check
 * that passed" shape — one level below the check.
 *
 * ---------------------------------------------------------------------------
 * WHAT IS FLAGGED, AND WHAT IS DELIBERATELY NOT
 * ---------------------------------------------------------------------------
 *
 * Flagged: C0 control characters that have no business in source —
 * 0x00-0x08, 0x0B, 0x0C, 0x0E-0x1F, and 0x7F.
 *
 * NOT flagged, and each omission is a decision rather than an oversight:
 *
 *   - **0x09 TAB**, 0x0A LF, 0x0D CR. Whitespace. A tab in a source file is a
 *     style question and this is not a style tool; reporting them would bury
 *     the one finding that matters under thousands that do not, which is the
 *     over-report failure this project has already had in three of four tools.
 *   - **Anything above 0x7F.** The prose in this codebase is full of em dashes
 *     and curly quotes on purpose. A tool that flagged UTF-8 would be
 *     unrunnable here on its first day.
 *
 * The scan is byte-oriented rather than character-oriented, which is correct
 * for this question: a C0 byte cannot occur inside a well-formed UTF-8
 * multi-byte sequence, so there is no risk of a false hit from a split
 * character.
 *
 * Exit code is non-zero when anything is found. This is the rare check with no
 * benign category — there is no legitimate reason for a backspace to be in a
 * PHP file — so unlike `scoped-override-audit` it is a defect list, not a
 * judgement list.
 *
 * Usage:  php tools/nonprintable-scan.php [--path=app --path=tests ...]
 */

$paths = [];

foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--path=')) {
        $paths[] = substr($arg, strlen('--path='));
    }
}

$sweepingWholeTree = $paths === [];

if ($sweepingWholeTree) {
    // `docs` and `CLAUDE.md` are here because prose is where this defect is
    // MOST likely to arrive, not least: both are written in long generated
    // blocks, which is the authoring route that produced all four known
    // instances. The extension filter already accepted `md`; nothing pointed it
    // at the two `md` targets that matter.
    $paths = ['app', 'config', 'database', 'routes', 'tests', 'tools', 'docs', 'CLAUDE.md'];
}

/** The C0 set minus tab, newline and carriage return, plus DEL. */
const OFFENDING = '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/';

/** How a byte is written when it is named in a report. */
function describe(string $byte): string
{
    $names = [
        "\x00" => 'NUL', "\x07" => 'BEL', "\x08" => 'BACKSPACE — almost always a \\b that lost its backslash',
        "\x0B" => 'VT', "\x0C" => 'FF', "\x1B" => 'ESC', "\x7F" => 'DEL',
    ];

    return sprintf('0x%02X %s', ord($byte), $names[$byte] ?? 'control character');
}

$findings = [];
$scanned = 0;

foreach ($paths as $path) {
    $root = __DIR__.'/../'.$path;

    if (is_file($root)) {
        // A single FILE is a legitimate target, and supporting one is what
        // closed this tool's own blind spot. It reported "0 finding(s)" on a
        // run made immediately after 148 lines of GENERATED prose were appended
        // to CLAUDE.md — the exact authoring route this tool exists to police —
        // because CLAUDE.md is a file at the repository root and every default
        // path was a directory. The report was honest about its coverage one
        // line above the zero and was still read as broader than it was.
        //
        // An absence from an instrument that cannot see the subject is not
        // evidence about the subject. That is the probe rule, turned on a tool
        // rather than on a measurement.
        $files = [new SplFileInfo($root)];
    } elseif (is_dir($root)) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    } else {
        // Named rather than skipped. A typo'd path that silently scanned
        // nothing would report a clean tree, which is the failure mode this
        // whole file is about.
        fwrite(STDERR, "Path not found: {$path}\n");

        exit(2);
    }

    foreach ($files as $file) {
        if (! $file->isFile() || ! in_array($file->getExtension(), ['php', 'blade', 'json', 'md'], true)) {
            continue;
        }

        // The known-answer fixture contains the defect ON PURPOSE, so the tree
        // sweep must not report it — the same reason blade-php-scan defaults to
        // `resources/views` and takes its fixture through an explicit --views.
        // Skipped by path rather than by name so a second byte fixture needs no
        // change here, and only under `fixtures/` so a real test file carrying
        // a stray byte is still caught. One did: NarrationProviderTest held
        // 4,800 literal NUL bytes as a fake audio payload, which worked and was
        // unreadable, and is now written as "\x00\x00".
        // Only on the default sweep. An explicit --path is somebody pointing
        // this at a known input on purpose, and the known input IS the fixture.
        if ($sweepingWholeTree && str_contains(str_replace('\\', '/', $file->getPathname()), '/tests/fixtures/')) {
            continue;
        }

        $scanned++;
        $contents = (string) file_get_contents($file->getPathname());

        if (! preg_match(OFFENDING, $contents)) {
            continue;
        }

        foreach (explode("\n", $contents) as $number => $line) {
            if (! preg_match_all(OFFENDING, $line, $matches)) {
                continue;
            }

            foreach (array_unique($matches[0]) as $byte) {
                $findings[] = [
                    'file' => str_replace('\\', '/', substr($file->getPathname(), strlen(__DIR__.'/../'))),
                    'line' => $number + 1,
                    'byte' => describe($byte),
                    // The line with the offender made visible, so the report
                    // shows what an editor cannot.
                    'context' => trim(preg_replace(OFFENDING, '<<HERE>>', $line) ?? ''),
                ];
            }
        }
    }
}

echo "\n";
echo "Non-printable byte scan\n";
echo str_repeat('-', 72)."\n";
printf("%d file(s) scanned across: %s\n\n", $scanned, implode(', ', $paths));

foreach ($findings as $finding) {
    printf("  %s:%d\n", $finding['file'], $finding['line']);
    printf("      %s\n", $finding['byte']);
    printf("      %s\n\n", mb_strimwidth($finding['context'], 0, 120, '...'));
}

printf("%d finding(s).\n", count($findings));

if ($findings !== []) {
    echo "\nA control byte in source is invisible to every other instrument here: it\n";
    echo "renders as nothing in an editor and in a diff, it is a valid string literal\n";
    echo "to php -l, and a regex built from it compiles and matches nothing.\n";
}

exit($findings === [] ? 0 : 1);
