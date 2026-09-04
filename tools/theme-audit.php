<?php

/**
 * Two jobs, both of them about a change nobody can see going wrong.
 *
 * 1. **The dark theme did not move.** Splitting one palette into two is a pure
 *    refactor — every `#171a22` becomes `var(--panel)` and `--panel` resolves
 *    back to `#171a22` — and a pure refactor is exactly the kind of change that
 *    is waved through because the diff "looks obviously safe". It is not safe:
 *    one mistyped hex in two hundred token lines shifts a surface by an amount
 *    no reviewer will catch and no test will fail on.
 *
 *    So the check is mechanical. Every rule in the OLD stylesheet is resolved
 *    against the old palette, every rule in the NEW one against the dark
 *    remap, and the two are compared declaration by declaration. Anything that
 *    is not identical is either an intended addition or a regression, and the
 *    tool cannot tell which — so it prints both and lets a human say.
 *
 * 2. **The two dark remaps agree.** CSS cannot express one dark block that
 *    answers both `[data-theme="dark"]` and `prefers-color-scheme`, so there
 *    are two copies. This codebase's own history is a list of things that went
 *    wrong because one copy of a rule was corrected and the other was not — a
 *    retry prompt restating a guard's list, a hint restating a `--max-time`.
 *    Two copies with a tool refusing to let them diverge is a different thing
 *    from two copies with a comment asking nicely.
 *
 * 3. **Every ink token can actually be read.** Contrast is computed for each
 *    `-ink` against the surface it lands on, in both themes. This is the one
 *    check that is about the redesign rather than about the refactor: the whole
 *    premise of this stylesheet is that a refusal is loud, and a warning whose
 *    text fails contrast is quieter than the panel beside it while looking
 *    entirely deliberate.
 *
 * Usage:
 *   php tools/theme-audit.php                       contrast + remap check
 *   php tools/theme-audit.php --against=<file>      also diff dark vs a baseline
 */
// Overridable so the audit can be run against a sheet whose answer is known.
define('STYLESHEET', (static function (array $argv): string {
    foreach (array_slice($argv, 1) as $arg) {
        if (str_starts_with($arg, '--css=')) {
            return substr($arg, strlen('--css='));
        }
    }

    return __DIR__.'/../resources/views/partials/base-css.blade.php';
})($argv));

$args = array_slice($argv, 1);
$baseline = null;

foreach ($args as $arg) {
    if (str_starts_with($arg, '--against=')) {
        $baseline = substr($arg, strlen('--against='));
    }
}

// -- parsing -----------------------------------------------------------------

function styleBody(string $path): string
{
    $source = (string) file_get_contents($path);

    // The stylesheet is a blade partial; the blade comment above it is not CSS.
    if (preg_match('/<style>(.*)<\/style>/s', $source, $m) === 1) {
        $source = $m[1];
    }

    return (string) preg_replace('#/\*.*?\*/#s', '', $source);
}

/**
 * Custom properties declared by any block whose selector matches $selectors.
 *
 * Later blocks win, which is how the dark remap overrides the light default —
 * the same order the browser applies.
 *
 * @param  array<int, string>  $selectors
 * @return array<string, string>
 */
function tokensFrom(string $css, array $selectors): array
{
    $tokens = [];

    preg_match_all('/([^{}]+)\{([^{}]*)\}/s', $css, $blocks, PREG_SET_ORDER);

    foreach ($blocks as $block) {
        $selector = trim((string) preg_replace('/\s+/', ' ', $block[1]));
        $selector = trim((string) preg_replace('/^.*\}/s', '', $selector));

        $matched = false;

        foreach ($selectors as $wanted) {
            if ($selector === $wanted) {
                $matched = true;

                break;
            }
        }

        if (! $matched) {
            continue;
        }

        preg_match_all('/(--[A-Za-z0-9-]+)\s*:\s*([^;]+);/', $block[2], $declarations, PREG_SET_ORDER);

        foreach ($declarations as $declaration) {
            $tokens[$declaration[1]] = trim($declaration[2]);
        }
    }

    return $tokens;
}

/**
 * Every ordinary rule, keyed by at-rule context AND selector.
 *
 * ---------------------------------------------------------------------------
 * WHY THIS WALKS BRACES INSTEAD OF MATCHING THEM
 * ---------------------------------------------------------------------------
 *
 * The first version used one regex for `selector { body }` and got two things
 * wrong the moment `@media` blocks appeared, and BOTH of them were the audit
 * lying rather than the audit failing:
 *
 *   - `main` inside a media query was merged with top-level `main`, so the
 *     report showed a rule that "moved" to `padding: 26px; …; padding: 16px`.
 *     Nothing had moved. A responsive override had been folded into the rule
 *     it overrides.
 *   - the FIRST rule inside each media block was dropped entirely, because its
 *     captured selector still carried the `@media (…) {` prelude and was
 *     skipped as an at-rule. Rules were silently missing from the comparison.
 *
 * A checkpoint tool that reports phantom changes and hides real ones is worse
 * than no checkpoint, because it is read as one. So the context is tracked.
 *
 * Custom-property blocks are skipped: they are the palette, and the palette is
 * SUPPOSED to differ between the two files.
 *
 * @return array<string, string>
 */
function rulesFrom(string $css): array
{
    $rules = [];
    $context = [];
    $buffer = '';
    $length = strlen($css);

    for ($i = 0; $i < $length; $i++) {
        $char = $css[$i];

        if ($char === '{') {
            $head = trim((string) preg_replace('/\s+/', ' ', $buffer));
            $buffer = '';

            if (str_starts_with($head, '@')) {
                // An at-rule opens a context its children are reported under.
                $context[] = $head;

                continue;
            }

            // A selector: read its body up to the matching close brace. A
            // declaration block cannot nest here, so the next `}` ends it.
            $end = strpos($css, '}', $i);

            if ($end === false) {
                break;
            }

            $body = trim((string) preg_replace('/\s+/', ' ', substr($css, $i + 1, $end - $i - 1)));
            $i = $end;

            if ($head === '' || $body === '') {
                continue;
            }

            // A block that only sets custom properties is a palette, not a rule.
            if (preg_match('/^\s*(--[A-Za-z0-9-]+\s*:[^;]*;\s*|color-scheme\s*:[^;]*;\s*)+$/', $body) === 1) {
                continue;
            }

            // Palette lines mixed into a real rule are dropped for the same reason.
            $body = trim((string) preg_replace('/--[A-Za-z0-9-]+\s*:[^;]*;\s*/', '', $body));

            if ($body === '') {
                continue;
            }

            $key = $context === [] ? $head : implode(' ', $context).' { '.$head;
            $rules[$key] = isset($rules[$key]) ? $rules[$key].' '.$body : $body;

            continue;
        }

        if ($char === '}') {
            array_pop($context);
            $buffer = '';

            continue;
        }

        $buffer .= $char;
    }

    return $rules;
}

/**
 * Replace every `var(--x)` with what --x holds, repeatedly, until none remain.
 *
 * @param  array<string, string>  $tokens
 */
function resolve(string $value, array $tokens, int $depth = 0): string
{
    if ($depth > 12 || ! str_contains($value, 'var(')) {
        return $value;
    }

    $resolved = (string) preg_replace_callback(
        '/var\(\s*(--[A-Za-z0-9-]+)\s*(?:,([^()]*))?\)/',
        function (array $m) use ($tokens): string {
            if (isset($tokens[$m[1]])) {
                return $tokens[$m[1]];
            }

            return isset($m[2]) ? trim($m[2]) : $m[0];
        },
        $value,
    );

    return resolve($resolved, $tokens, $depth + 1);
}

// -- contrast ----------------------------------------------------------------

/** @return array{0: float, 1: float, 2: float}|null */
function rgb(string $hex): ?array
{
    $hex = trim($hex);

    if (preg_match('/^#([0-9a-f]{6})$/i', $hex, $m) !== 1) {
        return preg_match('/^#([0-9a-f]{3})$/i', $hex, $m) === 1
            ? [hexdec($m[1][0].$m[1][0]), hexdec($m[1][1].$m[1][1]), hexdec($m[1][2].$m[1][2])]
            : null;
    }

    return [
        (float) hexdec(substr($m[1], 0, 2)),
        (float) hexdec(substr($m[1], 2, 2)),
        (float) hexdec(substr($m[1], 4, 2)),
    ];
}

/** @param array{0: float, 1: float, 2: float} $c */
function luminance(array $c): float
{
    $channel = static function (float $v): float {
        $v /= 255;

        return $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
    };

    return 0.2126 * $channel($c[0]) + 0.7152 * $channel($c[1]) + 0.0722 * $channel($c[2]);
}

function contrast(string $ink, string $ground): ?float
{
    $a = rgb($ink);
    $b = rgb($ground);

    if ($a === null || $b === null) {
        return null;
    }

    $la = luminance($a);
    $lb = luminance($b);

    return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
}

/**
 * The tint an alert paints behind its own text, so the ink is measured against
 * what it actually sits on rather than against the page.
 *
 * @param  array{0: float, 1: float, 2: float}  $a
 * @param  array{0: float, 1: float, 2: float}  $b
 */
function mix(array $a, float $percent, array $b): string
{
    $r = $a[0] * $percent + $b[0] * (1 - $percent);
    $g = $a[1] * $percent + $b[1] * (1 - $percent);
    $bl = $a[2] * $percent + $b[2] * (1 - $percent);

    return sprintf('#%02x%02x%02x', (int) round($r), (int) round($g), (int) round($bl));
}

// -- run ---------------------------------------------------------------------

$css = styleBody(STYLESHEET);
$light = tokensFrom($css, [':root']);
$darkOverrides = tokensFrom($css, [':root[data-theme="dark"]']);
$mediaOverrides = tokensFrom($css, [':root:not([data-theme="light"])']);
$dark = array_merge($light, $darkOverrides);

$failures = 0;

// 1. The two dark remaps ------------------------------------------------------

echo "── The two dark remaps ──\n";

$a = $darkOverrides;
$b = $mediaOverrides;
ksort($a);
ksort($b);

if ($a === $b) {
    printf("  identical, %d token(s).\n\n", count($a));
} else {
    $failures++;
    echo "  DIVERGED. The explicit toggle and the system preference would render\n";
    echo "  different consoles, and only one of them would ever get looked at.\n";

    foreach (array_unique(array_merge(array_keys($a), array_keys($b))) as $key) {
        $left = $a[$key] ?? '(absent)';
        $right = $b[$key] ?? '(absent)';

        if ($left !== $right) {
            printf("    %s\n      [data-theme=dark] %s\n      prefers-dark      %s\n", $key, $left, $right);
        }
    }

    echo "\n";
}

// 2. Ink contrast -------------------------------------------------------------

echo "── Ink contrast ──\n";
echo "  Ink measured on the tint it is actually printed on, not on the page.\n";
echo "  4.5 is the floor for body text; a refusal wants a good deal more.\n\n";

// Each ink, the tint percentage its surface uses, and the surface token.
$inkSurfaces = [
    ['--fail-ink', '--fail', 0.13, '--panel', 'alert.err / .fail'],
    ['--warn-ink', '--warn', 0.13, '--panel', 'alert.warn'],
    ['--ok-ink', '--ok', 0.10, '--panel', 'alert.ok'],
    ['--money-ink', '--money', 0.13, '--panel', 'alert.money'],
    ['--fail-ink', '--fail', 0.14, '--panel-2', 'badge.fail'],
    ['--warn-ink', '--warn', 0.14, '--panel-2', 'badge.warn'],
    ['--ok-ink', '--ok', 0.12, '--panel-2', 'badge.ok'],
    ['--run-ink', '--run', 0.12, '--panel-2', 'badge.run'],
    ['--money-ink', '--money', 0.14, '--panel-2', 'badge.money'],
    ['--text', null, 0.0, '--panel', 'body text'],
    ['--muted', null, 0.0, '--panel', 'secondary prose'],
    ['--meta', null, 0.0, '--panel', 'labels, th'],
    ['--err-ink', null, 0.0, '--bg', 'pre.err'],
    ['--primary-ink', '--primary-from', 1.0, '--panel-2', 'button.primary'],
    ['--danger-ink', '--danger-from', 1.0, '--panel-2', 'button.danger'],
    ['--gate-ink', '--gate-from', 1.0, '--panel-2', 'button.gate'],
    ['--on-accent', '--run', 1.0, '--panel', 'pagination current'],
    ['--accent-ink', null, 0.0, '--panel', 'links'],
    ['--accent-ink', null, 0.0, '--bg-2', 'nav, current item'],
    // The band's own ink, on the band's own ground rather than on a panel.
    ['--alarm-ink', '--alarm-from', 1.0, '--panel', 'alarm band text'],
    ['--alarm-warn-ink', '--alarm-warn-from', 1.0, '--panel', 'absent band text'],
];

foreach (['light' => $light, 'dark' => $dark] as $themeName => $tokens) {
    printf("  %s\n", strtoupper($themeName));

    foreach ($inkSurfaces as [$inkToken, $tintToken, $percent, $groundToken, $where]) {
        $ink = resolve('var('.$inkToken.')', $tokens);
        $ground = resolve('var('.$groundToken.')', $tokens);

        if ($tintToken !== null) {
            $tint = resolve('var('.$tintToken.')', $tokens);
            $tintRgb = rgb($tint);
            $groundRgb = rgb($ground);

            if ($tintRgb !== null && $groundRgb !== null) {
                $ground = mix($tintRgb, $percent, $groundRgb);
            }
        }

        $ratio = contrast($ink, $ground);

        if ($ratio === null) {
            printf("    %-22s %-20s unmeasurable (%s on %s)\n", $where, '', $ink, $ground);

            continue;
        }

        $verdict = $ratio >= 4.5 ? 'ok' : ($ratio >= 3.0 ? 'LOW' : 'FAILS');

        if ($ratio < 4.5) {
            $failures++;
        }

        printf("    %-22s %5.2f:1  %-5s  %s on %s\n", $where, $ratio, $verdict, $ink, $ground);
    }

    echo "\n";
}

// 3. Prominence ---------------------------------------------------------------

echo "── Prominence ──\n";
echo "  THE rule for this stylesheet is that no refusal, warning or advisory may\n";
echo "  get quieter. A restyle is the easiest place to lose that, because nothing\n";
echo "  fails and no test goes red — the page just becomes calmer than the truth.\n";
echo "  So it is measured: how far each loud surface separates from the ORDINARY\n";
echo "  panel beside it. A surface that does not separate is decoration.\n\n";

/*
 * The token the RULE paints, not a restatement of the percentage in it.
 *
 * This table used to carry its own copy of every wash percentage — 13%, 10%,
 * 7% — which made the audit a second source of truth for exactly the numbers
 * it exists to police. It would have agreed with the stylesheet until the
 * first time somebody tuned a wash without tuning the audit, and then it would
 * have gone on reporting a prominence the page did not have. So the loud
 * surfaces are tokens in the stylesheet and this reads them.
 *
 * The money panel is a gradient and is measured at its WEAKER stop: the
 * quietest part of a surface is the part that decides whether it reads.
 *
 * label, background token, accent edge px
 */
$loudSurfaces = [
    ['alert.err / .fail', ['--alert-err-bg'], 4],
    ['alert.warn', ['--alert-warn-bg'], 4],
    ['alert.ok', ['--alert-ok-bg'], 4],
    ['alert.money', ['--alert-money-bg'], 4],
    // A gradient, measured across both stops. The money panel fades 7% to 3%
    // in dark, and judging it on either end alone answers a different question
    // from the one an operator's eye asks — which is how the whole rectangle
    // reads against the rectangle above it.
    ['panel.money', ['--money-panel-from', '--money-panel-to'], 3],
    ['warnfill (panel/row)', ['--warnfill-bg'], 4],
    ['gates .current', ['--gate-current-bg'], 2],
    /*
     * The alarm band. Not a wash on a panel — a saturated flood with near-white
     * text — so it is measured the same way and expected to be far louder than
     * anything above it. If it ever stops being the largest number in this
     * table, something has gone quiet that must not.
     */
    ['band (alarm)', ['--alarm-from', '--alarm-to'], 1],
    ['band (absent)', ['--alarm-warn-from', '--alarm-warn-to'], 1],
];

/**
 * Evaluate `color-mix(in srgb, #hex N%, #hex)` down to a flat colour.
 *
 * @param  array<string, string>  $tokens
 */
function flatten(string $token, array $tokens): ?string
{
    $expression = resolve('var('.$token.')', $tokens);

    if (preg_match('/color-mix\(\s*in srgb\s*,\s*(\S+)\s+([\d.]+)%\s*,\s*(\S+)\s*\)/', $expression, $m) === 1) {
        $tint = rgb($m[1]);
        $ground = rgb($m[3]);

        return $tint === null || $ground === null
            ? null
            : mix($tint, ((float) $m[2]) / 100, $ground);
    }

    return rgb($expression) === null ? null : $expression;
}

foreach (['light' => $light, 'dark' => $dark] as $themeName => $tokens) {
    printf("  %s\n", strtoupper($themeName));

    $panel = resolve('var(--panel)', $tokens);

    foreach ($loudSurfaces as [$label, $backgroundTokens, $edge]) {
        $stops = array_map(fn (string $t): ?string => flatten($t, $tokens), $backgroundTokens);

        if (in_array(null, $stops, true) || rgb($panel) === null) {
            printf("    %-22s unmeasurable\n", $label);

            continue;
        }

        // The mean of the stops: a gradient reads as a whole rectangle.
        $channels = array_map(fn (string $hex): array => (array) rgb($hex), $stops);
        $surface = sprintf(
            '#%02x%02x%02x',
            (int) round(array_sum(array_column($channels, 0)) / count($channels)),
            (int) round(array_sum(array_column($channels, 1)) / count($channels)),
            (int) round(array_sum(array_column($channels, 2)) / count($channels)),
        );

        $separation = contrast($surface, $panel);

        /*
         * The floor is 1.10, and it is stated as a ratio above 1.0 rather than
         * as a WCAG threshold because WCAG is about TEXT and these are washes
         * behind text. Below about 1.1 a tint on a panel is not a quiet
         * surface, it is the same surface — which is exactly how `.panel.money`
         * spent a phase looking like the panel above it.
         */
        $verdict = $separation !== null && $separation >= 1.10 ? 'ok' : 'FLAT';

        if ($verdict === 'FLAT') {
            $failures++;
        }

        printf(
            "    %-22s %.3fx from the panel, %dpx accent edge  %-4s  %s vs %s\n",
            $label,
            (float) $separation,
            $edge,
            $verdict,
            $surface,
            $panel,
        );
    }

    echo "\n";
}

// 4. Dark against a baseline --------------------------------------------------

if ($baseline !== null) {
    echo "── Dark theme against the baseline ──\n";

    if (! is_file($baseline)) {
        printf("  %s does not exist.\n", $baseline);

        exit(1);
    }

    $oldCss = styleBody($baseline);

    /*
     * THE BASELINE IS RESOLVED IN DARK, LIKE THE SHEET IT IS COMPARED AGAINST.
     *
     * This read `tokensFrom($oldCss, [':root'])` — the LIGHT palette — and
     * compared it against the current sheet resolved in dark. Every rule
     * mentioning a themed token therefore differed by construction, and a file
     * compared against ITSELF reported 159 of 343 rules as MOVED.
     *
     * That is worse than a broken tool. CLAUDE.md leans on this check to catch
     * "one mistyped hex in two hundred token lines" during the palette split —
     * and a real mistyped hex would have been one line in a hundred and fifty
     * nine false ones, which is indistinguishable from not being reported.
     * Caught by the only test that can catch it: diffing the file against a
     * copy of itself and requiring zero.
     */
    $oldTokens = array_merge(
        tokensFrom($oldCss, [':root']),
        tokensFrom($oldCss, [':root[data-theme="dark"]']),
    );

    $oldRules = rulesFrom($oldCss);
    $newRules = rulesFrom($css);

    $changed = [];
    $added = [];
    $removed = [];

    foreach ($oldRules as $selector => $body) {
        if (! isset($newRules[$selector])) {
            $removed[] = $selector;

            continue;
        }

        $before = resolve($body, $oldTokens);
        $after = resolve($newRules[$selector], $dark);

        if ($before !== $after) {
            $changed[$selector] = [$before, $after];
        }
    }

    foreach ($newRules as $selector => $body) {
        if (! isset($oldRules[$selector])) {
            $added[] = $selector;
        }
    }

    printf(
        "  %d rule(s) in the baseline, %d now. %d identical.\n\n",
        count($oldRules),
        count($newRules),
        count($oldRules) - count($changed) - count($removed),
    );

    if ($changed !== []) {
        $failures++;
        printf("  MOVED  (%d) — every one of these needs a reason:\n\n", count($changed));

        foreach ($changed as $selector => [$before, $after]) {
            printf("    %s\n      was: %s\n      now: %s\n\n", $selector, $before, $after);
        }
    }

    if ($removed !== []) {
        $failures++;
        printf("  GONE  (%d)\n    %s\n\n", count($removed), implode("\n    ", $removed));
    }

    if ($added !== []) {
        printf("  NEW  (%d) — additions, not regressions:\n    %s\n\n", count($added), implode("\n    ", $added));
    }

    if ($changed === [] && $removed === []) {
        echo "  Every rule the baseline had resolves to the same declarations in dark.\n\n";
    }
}

printf("%s\n", $failures === 0 ? 'No findings.' : $failures.' finding(s).');

exit($failures === 0 ? 0 : 1);
