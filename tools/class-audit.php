<?php

/**
 * Every class the markup asks for, against every class the stylesheet answers.
 *
 * This is the diff that found `.panel.money` — three blades writing
 * `class="panel money"` on the three screens where an operator authorises
 * spending, and nothing in the stylesheet defining that combination, so all
 * three money screens rendered as an ordinary panel. Identical in shape to
 * `.alert.err`, which spent a whole phase drawing every refusal in the default
 * border colour.
 *
 * **A class the markup asks for and the stylesheet does not answer fails
 * silently and looks deliberate.** That is worse than a missing method, because
 * a missing method throws. Nothing goes red here either — which is exactly why
 * this has to be a tool that is RUN rather than a rule somebody remembers.
 *
 * ---------------------------------------------------------------------------
 * WHY IT CHECKS COMBINATIONS AND NOT TOKENS
 * ---------------------------------------------------------------------------
 *
 * A naive audit greps the stylesheet for the word `warnfill`, finds
 * `.bar .warnfill`, and reports it defined. It is defined — as a fill inside a
 * progress bar. Written on a `<div class="panel warnfill">` it matches nothing,
 * and that was a live defect on two load-bearing signals for a whole phase.
 *
 * So every rule is reduced to its SUBJECT compound — the last thing in the
 * selector, the element actually painted — and each usage is asked whether any
 * subject compound can apply to it. Four ways it can fail:
 *
 *   UNDEFINED  no rule anywhere names this class.
 *   TAG        every rule naming it requires a different element
 *              (`button.primary` written on an `<a>`).
 *   COMBO      every rule naming it requires a co-class this element lacks.
 *   CONTEXT    every rule naming it requires an ancestor or a sibling
 *              (`.bar .warnfill` written on a panel).
 *
 * CONTEXT is reported rather than asserted: a static reader cannot know the
 * ancestors of a blade fragment, so it is a question for a human. Every
 * instance found so far has been a real defect.
 *
 * A class that appears ONLY in the ancestor position of some rule — `.checks`,
 * which exists to scope `.checks label` and paints nothing itself — is a SCOPE
 * and is answered. Reporting it undefined would be the audit's own version of
 * the token check it exists to replace: right that no rule paints it, wrong
 * about what that means.
 *
 * Usage:  php tools/class-audit.php [--all] [--json]
 */
/*
 * Overridable so the tool can be pointed at a KNOWN input.
 *
 * A tool that can only be run against the live tree cannot be run against a
 * case whose answer is known in advance, and three defects in three turns were
 * found by accident because none of these had ever been. See
 * tests/Feature/ToolsAnswerKnownCasesTest.php.
 */
define('VIEWS', pathArg($argv, '--views=') ?? __DIR__.'/../resources/views');

function pathArg(array $argv, string $flag): ?string
{
    foreach (array_slice($argv, 1) as $arg) {
        if (str_starts_with($arg, $flag)) {
            return substr($arg, strlen($flag));
        }
    }

    return null;
}

$args = array_slice($argv, 1);
$showAll = in_array('--all', $args, true);
$asJson = in_array('--json', $args, true);

// -- 1. What the stylesheet answers -----------------------------------------

/**
 * Every rule's subject compound, keyed by each class that compound names.
 *
 * Also returns every class that appears in an ANCESTOR position, which is what
 * separates a container that scopes its descendants from a class nothing
 * defines at all.
 *
 * @return array{0: array<string, array<int, array{tag: ?string, with: array<int, string>, contextual: bool, selector: string}>>, 1: int, 2: array<string, array<int, string>>}
 */
function stylesheetRules(string $css): array
{
    // Comments first, or a selector quoted inside one gets counted as a rule.
    $css = (string) preg_replace('#/\*.*?\*/#s', '', $css);

    $rules = [];
    $scopes = [];
    $count = 0;

    // Every `selector {` in the file, at any nesting depth. At-rules are
    // skipped as selectors but their contents are still walked, so a rule
    // inside @media is read like any other.
    preg_match_all('/([^{}]+)\{/', $css, $matches);

    foreach ($matches[1] as $selectorList) {
        // Anything trailing the previous declaration block.
        $selectorList = trim((string) preg_replace('/^.*\}/s', '', $selectorList));

        if ($selectorList === '' || str_starts_with($selectorList, '@')) {
            continue;
        }

        foreach (explode(',', $selectorList) as $selector) {
            $selector = trim((string) preg_replace('/\s+/', ' ', $selector));

            if ($selector === '') {
                continue;
            }

            $count++;

            // The subject is the LAST compound: `.bar .warnfill` paints the
            // warnfill and requires a `.bar` somewhere above it.
            $parts = preg_split('/\s*[>+~]\s*|\s+/', $selector) ?: [];
            $subject = (string) end($parts);
            $contextual = count($parts) > 1;

            // Everything ahead of the subject is a scope. `.checks label` means
            // `.checks` paints nothing and still answers for itself.
            foreach (array_slice($parts, 0, -1) as $ancestor) {
                preg_match_all('/\.([A-Za-z0-9_-]+)/', $ancestor, $scopeMatches);

                foreach ($scopeMatches[1] as $scopeClass) {
                    $scopes[$scopeClass][] = $selector;
                }
            }

            // `:hover`, `:has(input:checked)`, `::before` — strip the pseudo
            // tail without eating a class that precedes it.
            $bare = (string) preg_replace('/::?[a-zA-Z-]+(\([^)]*\))?/', '', $subject);

            preg_match_all('/\.([A-Za-z0-9_-]+)/', $bare, $classMatches);
            $classes = $classMatches[1];

            if ($classes === []) {
                continue;
            }

            preg_match('/^([A-Za-z][A-Za-z0-9]*)/', $bare, $tagMatch);
            $tag = $tagMatch[1] ?? null;

            foreach ($classes as $class) {
                $rules[$class][] = [
                    'tag' => $tag,
                    'with' => array_values(array_diff($classes, [$class])),
                    'contextual' => $contextual,
                    'selector' => $selector,
                ];
            }
        }
    }

    return [$rules, $count, array_map(fn (array $s): array => array_values(array_unique($s)), $scopes)];
}

// -- 2. What the markup asks for --------------------------------------------

/**
 * Every element in every blade that carries a class, with its tag and the full
 * set of classes it can carry.
 *
 * Conditional classes — `@class([...])`, a ternary inside an interpolation —
 * are folded in as possible, because reachability is the question: a rule that
 * only applies when a condition holds still has to exist.
 *
 * @return array<int, array{file: string, line: int, tag: string, classes: array<int, string>, dynamic: bool}>
 */
function markupUsages(string $dir): array
{
    $usages = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));

    foreach ($files as $file) {
        if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.blade.php')) {
            continue;
        }

        // The stylesheet is not markup. Its own comments quote the selectors it
        // defines, and reading those back as elements would let the file answer
        // its own audit — which is the one thing this tool must never do.
        if ($file->getFilename() === 'base-css.blade.php') {
            continue;
        }

        $path = str_replace('\\', '/', $file->getPathname());
        $offset = strpos($path, 'resources/views');
        $path = $offset === false ? $path : substr($path, $offset);
        $source = (string) file_get_contents($file->getPathname());

        /*
         * PHP digraphs containing `>` are masked before the tag scan.
         *
         * Without this the scan ends a tag at the `>` of an `=>`, so
         * `<div class="panel" @class(['warnfill' => $worst !== ...])>` is read
         * as an element whose attributes stop mid-array — and the conditional
         * class is never seen. That is not a cosmetic parsing bug: `warnfill`
         * is one of the two live defects this audit exists to find, and it
         * landed in "defined, never asked for" instead of in the findings,
         * which is a tool reporting the opposite of the truth.
         */
        // The masks are the same LENGTH as what they replace, deliberately:
        // every line number in this report is computed from an offset into this
        // string, and a mask that shortened the source would drift every citation
        // by a line or two. A report that cites the wrong line stops being read.
        $masked = strtr($source, ['=>' => "\x01\x01", '->' => "\x02\x02", '>=' => "\x03\x03"]);

        // An opening tag, up to the closing bracket that is not inside a quoted
        // attribute. Blade echoes inside attributes are common here.
        preg_match_all(
            '/<([a-zA-Z][a-zA-Z0-9-]*)((?:"[^"]*"|\'[^\']*\'|[^>"\'])*)>/',
            $masked,
            $tags,
            PREG_OFFSET_CAPTURE,
        );

        foreach ($tags[0] as $i => $whole) {
            $tag = strtolower($tags[1][$i][0]);
            $attrs = strtr((string) $tags[2][$i][0], ["\x01\x01" => '=>', "\x02\x02" => '->', "\x03\x03" => '>=']);

            if (! str_contains($attrs, 'class')) {
                continue;
            }

            $classes = [];
            $dynamic = false;

            if (preg_match('/\bclass\s*=\s*"([^"]*)"/', $attrs, $m) === 1) {
                $value = $m[1];

                /*
                 * Only a literal in the BRANCH POSITION of a ternary is a class
                 * name. Every other quoted string inside an interpolation is
                 * something else entirely, and taking them all produced three
                 * false findings out of five on the first run:
                 *
                 *   `$overall['active'] ? 'run' : 'ok'`      — a subscript
                 *   `request()->routeIs('stories.*')`        — a route pattern
                 *   `in_array($act['phase'], ['search', …])` — a comparison set
                 *   `$panel['shot'] === 'wide'`              — a comparison
                 *
                 * A tool whose entire value is that its findings are real cannot
                 * carry a 60% false rate; the reader stops checking, and then it
                 * is a check that cannot fire. `? x : y` is what reaches the DOM,
                 * so `? x : y` is what gets read.
                 */
                if (preg_match_all("/[?:]\s*'([^']*)'/", $value, $inner) > 0) {
                    foreach ($inner[1] as $literal) {
                        foreach (preg_split('/\s+/', trim($literal)) ?: [] as $c) {
                            if ($c !== '') {
                                $classes[] = $c;
                            }
                        }
                    }
                }

                if (str_contains($value, '$')) {
                    $dynamic = true;
                }

                // Everything outside an interpolation is a plain class name.
                $literalOnly = (string) preg_replace('/\{\{.*?\}\}/s', ' ', $value);

                foreach (preg_split('/\s+/', trim($literalOnly)) ?: [] as $c) {
                    if ($c !== '' && preg_match('/^[A-Za-z0-9_-]+$/', $c) === 1) {
                        $classes[] = $c;
                    }
                }
            }

            // `@class(['warnfill' => $cond, 'panel'])`
            if (preg_match('/@class\(\[(.*?)\]\)/s', $attrs, $m) === 1) {
                if (preg_match_all("/'([A-Za-z0-9_ -]+)'/", $m[1], $inner) > 0) {
                    foreach ($inner[1] as $literal) {
                        foreach (preg_split('/\s+/', trim($literal)) ?: [] as $c) {
                            if ($c !== '') {
                                $classes[] = $c;
                            }
                        }
                    }
                }
            }

            $classes = array_values(array_unique($classes));

            if ($classes === []) {
                continue;
            }

            $usages[] = [
                'file' => $path,
                'line' => substr_count(substr($source, 0, (int) $whole[1]), "\n") + 1,
                'tag' => $tag,
                'classes' => $classes,
                'dynamic' => $dynamic,
            ];
        }
    }

    return $usages;
}

// -- 3. The diff -------------------------------------------------------------

/**
 * Can any rule naming `$class` apply to this element?
 *
 * @param  array<int, array{tag: ?string, with: array<int, string>, contextual: bool, selector: string}>  $rules
 * @param  array<int, string>  $has
 * @param  array<int, string>  $scopes
 * @return array{verdict: string, detail: string}
 */
function reach(array $rules, string $tag, array $has, array $scopes): array
{
    if ($rules === []) {
        return $scopes === []
            ? ['verdict' => 'UNDEFINED', 'detail' => 'no rule anywhere names this class']
            : ['verdict' => 'OK', 'detail' => 'scope for '.implode(', ', $scopes)];
    }

    $tagBlocked = [];
    $comboBlocked = [];
    $contextual = [];

    foreach ($rules as $rule) {
        if ($rule['tag'] !== null && $rule['tag'] !== $tag) {
            $tagBlocked[] = $rule['selector'].' (needs <'.$rule['tag'].'>)';

            continue;
        }

        $missing = array_diff($rule['with'], $has);

        if ($missing !== []) {
            $comboBlocked[] = $rule['selector'].' (needs .'.implode('.', $missing).')';

            continue;
        }

        if ($rule['contextual']) {
            $contextual[] = $rule['selector'];

            continue;
        }

        return ['verdict' => 'OK', 'detail' => $rule['selector']];
    }

    if ($contextual !== []) {
        // A class that also scopes something is doing two jobs, and the scope
        // job is satisfied here whatever the paint job needs.
        if ($scopes !== []) {
            return ['verdict' => 'OK', 'detail' => 'scope for '.implode(', ', $scopes)];
        }

        return [
            'verdict' => 'CONTEXT',
            'detail' => 'only reachable under an ancestor: '.implode(', ', array_unique($contextual)),
        ];
    }

    if ($tagBlocked !== []) {
        return ['verdict' => 'TAG', 'detail' => implode(', ', array_unique($tagBlocked))];
    }

    return ['verdict' => 'COMBO', 'detail' => implode(', ', array_unique($comboBlocked))];
}

// -- run ---------------------------------------------------------------------

$css = styleBodyOf((string) file_get_contents(VIEWS.'/partials/base-css.blade.php'));

/**
 * Only what is between <style> and </style>.
 *
 * The sheet is a blade partial: a comment, then a <style> tag. Read whole, the
 * first rule after that tag parses as `<style> .thing` — an ANCESTOR-scoped
 * rule — so the tool answers CONTEXT, its benign verdict, for a rule that is
 * not scoped at all. Latent on this sheet today only because the first rule
 * happens to be `:root`, which carries no class.
 *
 * Found by running the tool against a fixture whose answer was known, which is
 * the only way any of this gets found deliberately.
 */
function styleBodyOf(string $source): string
{
    if (preg_match('#<style[^>]*>(.*)</style>#s', $source, $m) === 1) {
        return $m[1];
    }

    return $source;
}
[$rules, $ruleCount, $scopes] = stylesheetRules($css);
$usages = markupUsages(VIEWS);

// Classes something else paints. Livewire writes its own; the stock welcome
// page ships Tailwind that never resolves. Neither is this stylesheet's job.
$ignorePrefixes = [
    'wire-', 'sm:', 'md:', 'lg:', 'dark:', 'hover:', 'focus:', 'starting:',
    'before:', 'after:', 'not-has-',
];

$findings = [];
$answered = 0;

foreach ($usages as $usage) {
    foreach ($usage['classes'] as $class) {
        foreach ($ignorePrefixes as $prefix) {
            if (str_starts_with($class, $prefix)) {
                continue 2;
            }
        }

        $result = reach($rules[$class] ?? [], $usage['tag'], $usage['classes'], $scopes[$class] ?? []);

        if ($result['verdict'] === 'OK') {
            $answered++;

            if (! $showAll) {
                continue;
            }
        }

        $findings[] = [
            'class' => $class,
            'verdict' => $result['verdict'],
            'detail' => $result['detail'],
            'file' => $usage['file'],
            'line' => $usage['line'],
            'tag' => $usage['tag'],
            'on' => implode(' ', $usage['classes']),
        ];
    }
}

// Defined and never asked for. Not a defect on its own — but a rule with no
// markup behind it is the other half of the same seam.
//
// Reported WITH the caveat, never as a verdict: a class applied through a bare
// `class="cell {{ $status }}"` cannot be resolved by a static reader, so the
// honest claim is "no literal asks for this", not "nothing does". Printing the
// stronger claim would invite deleting a rule that is load-bearing, and an
// audit that exists because a silent gap looks deliberate has no business
// opening one.
$dynamicFiles = array_values(array_unique(array_map(
    fn (array $u): string => $u['file'],
    array_filter($usages, fn (array $u): bool => $u['dynamic']),
)));
sort($dynamicFiles);

$askedFor = [];

foreach ($usages as $usage) {
    foreach ($usage['classes'] as $class) {
        $askedFor[$class] = true;
    }
}

$unused = array_values(array_diff(array_keys($rules + $scopes), array_keys($askedFor)));
sort($unused);

$problems = array_values(array_filter($findings, fn (array $f): bool => $f['verdict'] !== 'OK'));

if ($asJson) {
    echo json_encode([
        'selectors' => $ruleCount,
        'elements' => count($usages),
        'answered' => $answered,
        'findings' => $findings,
        'defined_never_used' => $unused,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";

    exit($problems === [] ? 0 : 1);
}

printf(
    "%d selectors in the stylesheet · %d classed elements in the markup · %d class usages answered\n\n",
    $ruleCount,
    count($usages),
    $answered,
);

$order = ['UNDEFINED' => 0, 'TAG' => 1, 'COMBO' => 2, 'CONTEXT' => 3, 'OK' => 4];
usort($findings, fn (array $a, array $b): int => [$order[$a['verdict']], $a['class'], $a['file']]
    <=> [$order[$b['verdict']], $b['class'], $b['file']]);

$byVerdict = [];

foreach ($findings as $finding) {
    $byVerdict[$finding['verdict']][] = $finding;
}

foreach (['UNDEFINED', 'TAG', 'COMBO', 'CONTEXT', 'OK'] as $verdict) {
    if (! isset($byVerdict[$verdict])) {
        continue;
    }

    printf("%s  (%d)\n", $verdict, count($byVerdict[$verdict]));

    foreach ($byVerdict[$verdict] as $f) {
        printf("  .%s  on <%s class=\"%s\">\n", $f['class'], $f['tag'], $f['on']);
        printf("      %s:%d\n", $f['file'], $f['line']);
        printf("      %s\n\n", $f['detail']);
    }
}

if ($unused !== []) {
    printf("NO LITERAL ASKS FOR THESE  (%d)\n  %s\n", count($unused), implode(', ', $unused));
    printf(
        "  Not proof they are unused: %d file(s) build a class from a variable this\n"
        ."  reader cannot resolve. Check by hand before deleting a rule.\n  %s\n\n",
        count($dynamicFiles),
        implode("\n  ", $dynamicFiles),
    );
}

printf("%d finding(s).\n", count($problems));

exit($problems === [] ? 0 : 1);
