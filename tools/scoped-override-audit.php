<?php

/**
 * Declarations an element only gets while it sits inside a particular container.
 *
 * ---------------------------------------------------------------------------
 * THE DEFECT THIS EXISTS FOR
 * ---------------------------------------------------------------------------
 *
 * `.alert` caps its measure at 96ch, deliberately. The only rule lifting that
 * cap was `.gatecols .alert { max-width: none }` — scoped to the decision row.
 * When Gate 2 grew a quiet layout that DELETES that row, every alert on the
 * page silently snapped back to 96ch and rendered at about a third of a 1770px
 * viewport beside a full-width strip.
 *
 * Nothing failed. The rule did exactly what it said; it simply had no subject
 * any more. No test went red, theme-audit measured the same separations it
 * always had, and class-audit was satisfied — correctly, because its question
 * is "can this rule reach this element", and the answer was yes.
 *
 * This asks a different question: **does the element still get this declaration
 * when the container is gone?**
 *
 * ---------------------------------------------------------------------------
 * WHAT IT LOOKS FOR, PRECISELY
 * ---------------------------------------------------------------------------
 *
 * A CONDITIONAL OVERRIDE: a property set on an element by an unscoped rule AND
 * set again, to something else, by a rule scoped under an ancestor. The element
 * therefore has two appearances and which one it gets depends on where it is
 * rendered. Move it, or delete the wrapper, and it changes silently.
 *
 * The additive case — a scoped rule declaring a property the element has no
 * default for — is counted but not listed. If the container is gone the element
 * usually is too, and where it is not, the element simply looks plain rather
 * than wrong. It is the OVERRIDE that produces a thing which looks deliberate
 * and is not.
 *
 * ---------------------------------------------------------------------------
 * WHY THIS IS NOT PART OF class-audit
 * ---------------------------------------------------------------------------
 *
 * class-audit reduces every rule to its subject compound and throws the
 * declaration body away — it never needs it, because it is answering a
 * reachability question. This needs the bodies and nothing else. Folding the
 * two would also blur the CONTEXT verdict, which this codebase leans on meaning
 * exactly one thing.
 *
 * Usage:  php tools/scoped-override-audit.php [--all]
 */

// Overridable so the sweep can be run against a sheet whose answer is known.
define('SHEET', (static function (array $argv): string {
    foreach (array_slice($argv, 1) as $arg) {
        if (str_starts_with($arg, '--css=')) {
            return substr($arg, strlen('--css='));
        }
    }

    return __DIR__.'/../resources/views/partials/base-css.blade.php';
})($argv));

$showAll = in_array('--all', array_slice($argv, 1), true);

$css = (string) file_get_contents(SHEET);

// Only the <style> body. Read whole, the first rule after the tag parses as
// `<style> .thing` and is treated as scoped when it is not — the same latent
// defect class-audit carried, found the same way: against a known answer.
if (preg_match('#<style[^>]*>(.*)</style>#s', $css, $styleMatch) === 1) {
    $css = $styleMatch[1];
}
$css = (string) preg_replace('#/\*.*?\*/#s', '', $css);

/**
 * Every leaf rule in the sheet as [selector list, declaration body].
 *
 * A brace-depth walk rather than a regex, so a rule inside `@media` is read
 * like any other instead of swallowing the at-rule's own braces.
 *
 * @return array<int, array{0: string, 1: string}>
 */
function leafRules(string $css): array
{
    $rules = [];
    $length = strlen($css);
    $depth = 0;
    $selectorStart = 0;
    $stack = [];

    for ($i = 0; $i < $length; $i++) {
        if ($css[$i] === '{') {
            $selector = trim(substr($css, $selectorStart, $i - $selectorStart));
            $stack[++$depth] = ['selector' => $selector, 'body' => $i + 1];
            $selectorStart = $i + 1;

            continue;
        }

        if ($css[$i] === '}') {
            if ($depth > 0) {
                $frame = $stack[$depth];
                $body = substr($css, $frame['body'], $i - $frame['body']);

                // A block containing another block is an at-rule wrapper; its
                // children were captured on their own frames.
                if (! str_contains($body, '{') && ! str_starts_with($frame['selector'], '@')) {
                    $rules[] = [$frame['selector'], $body];
                }

                unset($stack[$depth]);
                $depth--;
            }

            $selectorStart = $i + 1;
        }
    }

    return $rules;
}

/** @return array<int, string> the property names a declaration body sets */
function properties(string $body): array
{
    $names = [];

    foreach (explode(';', $body) as $declaration) {
        $colon = strpos($declaration, ':');

        if ($colon === false) {
            continue;
        }

        $name = strtolower(trim(substr($declaration, 0, $colon)));

        if ($name !== '' && preg_match('/^-?[a-z][a-z0-9-]*$/', $name)) {
            $names[] = $name;
        }
    }

    return array_values(array_unique($names));
}

/** The value a rule gives a property, for the report. */
function valueOf(string $body, string $property): string
{
    foreach (explode(';', $body) as $declaration) {
        $colon = strpos($declaration, ':');

        if ($colon === false) {
            continue;
        }

        if (strtolower(trim(substr($declaration, 0, $colon))) === $property) {
            return trim(substr($declaration, $colon + 1));
        }
    }

    return '?';
}

// -- Split every selector into subject classes and ancestor classes ----------

$base = [];      // list of ['set' => [classes], 'props' => [prop => value], 'selector' => s]
$scoped = [];
$selectorCount = 0;

foreach (leafRules($css) as [$selectorList, $body]) {
    $props = properties($body);

    if ($props === []) {
        continue;
    }

    foreach (explode(',', $selectorList) as $selector) {
        $selector = trim((string) preg_replace('/\s+/', ' ', $selector));

        if ($selector === '' || str_starts_with($selector, '@')) {
            continue;
        }

        $selectorCount++;

        $parts = preg_split('/\s*[>+~]\s*|\s+/', $selector) ?: [];
        $subject = (string) end($parts);

        $bare = (string) preg_replace('/::?[a-zA-Z-]+(\([^)]*\))?/', '', $subject);
        preg_match_all('/\.([A-Za-z0-9_-]+)/', $bare, $subjectClasses);

        if ($subjectClasses[1] === []) {
            continue;
        }

        // A pseudo-class on the subject makes the rule conditional on STATE, not
        // on position — `:hover` is not an arrangement.
        if ($bare !== $subject) {
            continue;
        }

        $set = array_values(array_unique($subjectClasses[1]));
        sort($set);

        // The tag matters. `tr.warnfill { background: none }` describes a table
        // row and says nothing about the `<div class="warnfill">` that
        // `.bar .warnfill` paints — comparing them reported an override between
        // two elements that can never be the same node.
        preg_match('/^([a-zA-Z][a-zA-Z0-9-]*)/', $subject, $tagMatch);
        $tag = strtolower($tagMatch[1] ?? '');

        $values = [];

        foreach ($props as $property) {
            $values[$property] = valueOf($body, $property);
        }

        $ancestors = [];

        foreach (array_slice($parts, 0, -1) as $ancestor) {
            preg_match_all('/\.([A-Za-z0-9_-]+)/', $ancestor, $found);
            $ancestors = array_merge($ancestors, $found[1]);
        }

        if ($ancestors === []) {
            $base[] = ['set' => $set, 'tag' => $tag, 'props' => $values, 'selector' => $selector];

            continue;
        }

        $scoped[] = [
            'set' => $set,
            'tag' => $tag,
            'ancestors' => array_values(array_unique($ancestors)),
            'props' => $values,
            'selector' => $selector,
        ];
    }
}

// -- The conditional overrides ----------------------------------------------

/*
 * A base rule only speaks for the same element if its subject compound is a
 * SUBSET of the scoped rule's. `.alert` speaks for `.gatecols .alert`, because
 * anything the second matches the first matches too. `.panel.money` does NOT
 * speak for `.card .row-item.money`: they are different elements that happen to
 * share a class, and comparing them reported an override that cannot occur.
 * A tool that cries wolf is one nobody runs.
 */
$findings = [];
$additive = 0;

foreach ($scoped as $rule) {
    foreach ($rule['props'] as $property => $scopedValue) {
        $default = null;

        foreach ($base as $candidate) {
            if (! array_key_exists($property, $candidate['props'])) {
                continue;
            }

            if (array_diff($candidate['set'], $rule['set']) !== []) {
                continue;
            }

            // A tagged base rule only speaks for the same tag.
            if ($candidate['tag'] !== '' && $candidate['tag'] !== $rule['tag']) {
                continue;
            }

            // The most specific base rule that can match the same element wins,
            // the same way the cascade would resolve it.
            if ($default === null || count($candidate['set']) >= count($default['set'])) {
                $default = $candidate;
            }
        }

        if ($default === null) {
            $additive++;

            continue;
        }

        if ($default['props'][$property] === $scopedValue) {
            continue;
        }

        $findings[] = [
            'class' => '.'.implode('.', $rule['set']),
            'property' => $property,
            'container' => implode(' ', array_map(fn (string $a): string => '.'.$a, $rule['ancestors'])),
            'inside' => $scopedValue,
            'outside' => $default['props'][$property],
            'selector' => $rule['selector'],
            'base' => $default['selector'],
        ];
    }
}

usort($findings, fn (array $a, array $b): int => [$a['class'], $a['property']] <=> [$b['class'], $b['property']]);

// -- Report -----------------------------------------------------------------

printf(
    "%d selector(s) with declarations · %d scoped rule(s) · %d additive declaration(s) not listed\n\n",
    $selectorCount,
    count($scoped),
    $additive,
);

echo "CONDITIONAL OVERRIDES\n";
echo "  A property the element sets for itself AND is given a different value for\n";
echo "  inside a container. Delete the container and the element silently reverts.\n";
echo "  Each one is a judgement, not automatically a defect: the question to ask is\n";
echo "  whether any layout can render this element WITHOUT that container.\n\n";

if ($findings === []) {
    echo "  none\n";
}

foreach ($findings as $finding) {
    printf(
        "  %s { %s }\n      inside  %-22s %s: %s\n      outside %-22s %s: %s\n\n",
        $finding['class'],
        $finding['property'],
        $finding['container'],
        $finding['property'],
        $finding['inside'],
        '(no container)',
        $finding['property'],
        $finding['outside'],
    );
}

printf("%d finding(s).\n", count($findings));

exit($findings === [] ? 0 : 0);
