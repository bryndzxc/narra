<?php

namespace Tests\Feature;

use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Every tool, run against a case whose answer is known in advance.
 *
 * ---------------------------------------------------------------------------
 * THE PATTERN THIS CLOSES
 * ---------------------------------------------------------------------------
 *
 * Three defects in three turns, all the same shape, none of them found on
 * purpose:
 *
 *   - `strpos` returns false, false coerces to 0, so every ordering assertion
 *     in a layout test passed for an element that had been DELETED. Found by
 *     drilling a different assertion.
 *   - `.gatecols .alert { max-width: none }` was the only rule lifting a global
 *     cap, scoped to a container a new layout removes. Found by looking at a
 *     screenshot.
 *   - `theme-audit --against` resolved the baseline in LIGHT and the live sheet
 *     in DARK, so it reported 159 of 343 rules as MOVED when comparing a file
 *     to ITSELF. Found while establishing a baseline for something else.
 *
 * The common factor is not carelessness. It is that **no tool here had ever
 * been run against an input whose correct output was known**. Every one was
 * pointed only at the live tree, where any output looks plausible, and a tool
 * that is confidently wrong is indistinguishable from one that is right.
 *
 * So each tool gets a fixture it must answer exactly, and the fixtures live in
 * `tests/fixtures/tools/`. Writing these found two more defects immediately:
 * class-audit and the scoped-override sweep both read the sheet whole, so the
 * first rule after `<style>` parsed as `<style> .thing` and was reported as
 * ancestor-scoped — CONTEXT, the benign verdict — when it was not scoped at
 * all.
 *
 * The rule going forward: a tool that cannot be pointed at a known input is a
 * tool that cannot be tested. All four take a path now.
 */
class ToolsAnswerKnownCasesTest extends TestCase
{
    /**
     * class-audit: one undefined class, one genuinely scoped class, and an
     * unscoped rule that must NOT be called scoped.
     */
    public function test_class_audit_answers_its_known_case(): void
    {
        $out = $this->tool('class-audit.php', ['--views=tests/fixtures/tools/classaudit']);

        $this->assertStringContainsString('UNDEFINED  (1)', $out, 'The orphan class must be reported undefined.');
        $this->assertStringContainsString('.orphan', $out);

        $this->assertStringContainsString('CONTEXT  (1)', $out, 'Exactly one class is ancestor-scoped.');
        $this->assertStringContainsString('.scoped .innerthing', $out);

        // The regression the fixture was written to catch: `.known` is declared
        // by an unscoped rule and must never be reported as reachable only
        // under `<style>`.
        $this->assertStringNotContainsString(
            '<style>',
            $out,
            'The sheet is a blade partial. Reading it whole makes the first rule after the '
            .'<style> tag look ancestor-scoped, which answers CONTEXT for a rule that is not.',
        );

        // AN OPERAND IS NOT A CLASS. The parser used to grep every quoted
        // string inside `@class([...])`, so the right-hand side of a condition
        // — `'leaving' => $act['phase'] === 'departure'` — landed in the class
        // list and was reported UNDEFINED. Gate 1's rebuild produced SEVEN such
        // phantoms in one pass, in the tool's most severe category: the one
        // that found `.panel.money`.
        //
        // A loud section that fills with findings nobody can act on is a loud
        // section that stops being read, which is the same argument this project
        // makes about an alarm firing for something the reader cannot act on.
        foreach (['alpha', 'beta', 'gamma', 'kind'] as $operand) {
            $this->assertStringNotContainsString(
                '.'.$operand,
                $out,
                sprintf(
                    '"%s" appears only inside a condition in the fixture\'s @class array. It is '
                    .'never emitted as a class and must never be reported as one.',
                    $operand,
                ),
            );
        }
    }

    /**
     * blade-php-scan: the four shapes it documents, and the one it must NOT
     * report.
     *
     * A closing directive with no opener — a literal `@endphp` in a comment —
     * an inline `@php(...)` that finds a closer after it and swallows
     * everything between, a pass-1/pass-2 token like `@verbatim` written inside
     * a blade comment, and a component tag inside a CSS comment, which IS
     * compiled because Blade cannot see a CSS comment.
     *
     * THE NEGATIVE CASE IS THE IMPORTANT ONE. The first version of that last
     * rule looked for component tags inside BLADE comments and reported them —
     * which is the safe case: `compileComments` runs one step BEFORE
     * `compileComponentTags`, so a tag in a blade comment is inert. The rule
     * flagged what cannot break and missed what did. The fixture carries both
     * comments so a rule that is backwards again fails here rather than in a
     * console outage.
     */
    public function test_blade_php_scan_answers_its_known_case(): void
    {
        $out = $this->tool('blade-php-scan.php', ['--views=tests/fixtures/tools/blade']);

        $this->assertStringContainsString('UNPAIRED', $out, 'A literal @endphp in a comment must be caught.');
        $this->assertStringContainsString('SWALLOWED', $out, 'An inline @php paired with a later closer must be caught.');
        $this->assertStringContainsString(
            'PRE-COMMENT-TOKEN',
            $out,
            'A @verbatim inside a blade comment must be caught: pass 2 runs before comments are '
            .'stripped, so it pairs with a real one elsewhere in the file.',
        );
        $this->assertStringContainsString(
            'COMPILED-IN-COMMENT',
            $out,
            'A component tag inside a CSS comment must be caught: Blade cannot see a CSS comment, '
            .'so the tag is compiled — and one of them took every page in the console down.',
        );

        // Two foreign comments, two shapes: a component tag and a directive.
        // BOTH have taken the console down, ten minutes apart, the second one
        // inside the comment written to explain the first.
        //
        // THE FIXTURE NOW CARRIES TWO NEGATIVE CASES AGAINST THIS COUNT, and
        // they fail in opposite directions:
        //
        //   - a component tag in a BLADE comment, which is inert because
        //     compileComments runs first. Reporting it flags what cannot break,
        //     which is how the first version of this rule came to miss the CSS
        //     comment that actually broke the console.
        //   - the same CSS comment inside a php block, which is inert because
        //     storeUncompiledBlocks extracts it at pass 2, before comments are
        //     stripped and before anything is compiled. This one WAS reported
        //     until the scan learned to mask those blocks — a false positive in
        //     a severe category, the same failure mode as `class-audit`'s
        //     `@class` operands and `theme-audit`'s comma-merge GONE.
        //
        // Both were verified against `BladeCompiler::compileString()` rather
        // than reasoned about, which is the standing rule for a claim about
        // pass order and the rule this check was once written in violation of.
        $this->assertSame(
            2,
            substr_count($out, 'COMPILED-IN-COMMENT'),
            'Exactly the two FOREIGN, COMPILED comments must be reported — not the blade comment '
            .'beside them, and not the CSS comment inside the php block, both of which the '
            .'compiler leaves untouched.',
        );

        // And the clean file in the same directory produces nothing of its own.
        $this->assertStringNotContainsString('clean.blade.php', $out);
    }

    /**
     * scoped-override-audit: one conditional override, and one scoped rule that
     * repeats the same value and is therefore not one.
     */
    public function test_scoped_override_audit_answers_its_known_case(): void
    {
        $out = $this->tool('scoped-override-audit.php', ['--css=tests/fixtures/tools/css/override.blade.php']);

        $this->assertStringContainsString('1 finding(s).', $out, 'Exactly one conditional override in the fixture.');
        $this->assertStringContainsString('.thing { max-width }', $out);

        // `.same` is given the same value inside and outside, so losing the
        // container loses nothing and it is not a finding.
        $this->assertStringNotContainsString('.same', $out);
    }

    /**
     * nonprintable-scan: one file with a 0x08, one without, and nothing else.
     *
     * The GREEN half is doing the heavy lifting here. The bytes this tool must
     * IGNORE are the ones a codebase full of em dashes and curly quotes is made
     * of, so a scan that flagged UTF-8 or whitespace would satisfy the RED case
     * perfectly and be uninstallable — the over-report failure three of the
     * four other tools shipped with.
     *
     * `clean.php` is one byte away from `offender.php` and carries, on purpose:
     * a correct `\b`, a tab, CRLF endings and an em dash. None is a finding.
     *
     * Both fixtures are generated from `chr(92)` and `chr(8)` rather than
     * written as escapes, because the whole defect is that an escape can be
     * eaten by a layer between the author and the file — the first draft of
     * THIS fixture had a 0x08 in its clean half for exactly that reason, which
     * is the third instance of the defect and the reason the tool exists.
     */
    public function test_nonprintable_scan_answers_its_known_case(): void
    {
        $out = $this->tool('nonprintable-scan.php', ['--path=tests/fixtures/tools/bytes']);

        $this->assertStringContainsString('1 finding(s).', $out);
        $this->assertStringContainsString('offender.php:7', $out);
        $this->assertStringContainsString('BACKSPACE', $out);

        // The negative half. A tab, CRLF and an em dash are not control-byte
        // defects, and a tool that said they were could not be run here.
        $this->assertStringNotContainsString('clean.php', $out);
    }

    /**
     * And the tree itself is clean, which is the assertion that fails the day
     * somebody reintroduces the byte.
     *
     * This is the rare check with no benign category — there is no legitimate
     * reason for a backspace in a PHP file — so it is asserted rather than
     * merely reported. It has fired twice in this codebase for real, both times
     * in a regex whose `\b` lost its backslash, and both times it was found by
     * a person piping a line through `cat -A`.
     */
    public function test_no_source_file_carries_a_control_byte(): void
    {
        $out = $this->tool('nonprintable-scan.php', []);

        $this->assertStringContainsString(
            '0 finding(s).',
            $out,
            "A control byte is invisible in an editor, in a diff and to php -l. If this is red, "
            ."read the file:line it names — the byte is almost certainly a \\b that lost its "
            .'backslash, in a regex that now compiles and matches nothing.',
        );
    }

    /**
     * theme-audit --against: a sheet compared with ITSELF must report nothing.
     *
     * The strongest known answer available for a differ, and the one that would
     * have caught the light-against-dark defect the day it was written. It had
     * never been run.
     */
    public function test_theme_audit_against_reports_nothing_for_an_identical_sheet(): void
    {
        $sheet = resource_path('views/partials/base-css.blade.php');
        $copy = sys_get_temp_dir().'/narra-theme-identity-'.getmypid().'.blade.php';

        copy($sheet, $copy);

        try {
            $out = $this->tool('theme-audit.php', ['--against='.$copy]);

            $this->assertMatchesRegularExpression(
                '/(\d+) rule\(s\) in the baseline, \1 now\. \1 identical\./',
                $out,
                'A stylesheet compared against a copy of itself must report every rule identical.',
            );
            $this->assertStringNotContainsString('MOVED', $out);
        } finally {
            @unlink($copy);
        }
    }

    /**
     * And it still DETECTS: one changed token names the rules that use it.
     *
     * An identity check alone would be satisfied by a differ that reports
     * nothing ever, which is the failure one step past the one being fixed.
     */
    public function test_theme_audit_against_still_detects_a_changed_token(): void
    {
        $sheet = resource_path('views/partials/base-css.blade.php');
        $copy = sys_get_temp_dir().'/narra-theme-typo-'.getmypid().'.blade.php';

        $source = (string) file_get_contents($sheet);
        $this->assertStringContainsString('--d-panel: #1c1e2b;', $source, 'Fixture token moved; update this test.');

        file_put_contents($copy, str_replace('--d-panel: #1c1e2b;', '--d-panel: #1c1e2c;', $source));

        try {
            $out = $this->tool('theme-audit.php', ['--against='.$copy]);

            $this->assertStringContainsString(
                'MOVED',
                $out,
                'One mistyped hex in the dark palette must be reported.',
            );
        } finally {
            @unlink($copy);
        }
    }

    /**
     * A COMMA-MERGE IS NOT A DELETION, and the differ used to say it was.
     *
     * Rules were keyed by the whole selector LIST — everything before the `{` —
     * so `.alpha, .beta { … }` was one rule named ".alpha, .beta". Merging two
     * identical rules into one comma-separated rule reported both originals GONE
     * and the result NEW, while nothing the page paints had changed. Measured on
     * a two-rule sheet: **2 GONE, 0 identical**, the tool saying every rule in
     * the baseline had vanished, for a pure reformat.
     *
     * A FALSE POSITIVE IN A SEVERE CATEGORY, which is the direction this project
     * had not seen until `class-audit`'s `@class` parser. GONE increments the
     * failure count and carries no "not a regression" qualifier, so it reads as
     * a loss — and it MASKS: comma-merge two rules while genuinely deleting a
     * third and you get three GONE entries that look alike, on the one check
     * CLAUDE.md leans on to catch a single mistyped hex among two hundred token
     * lines.
     *
     * It was found by reading the tool's output on a real change — the Gate 1
     * rebuild merged `.advisories > .head h2` with `.alerthead h2` and the audit
     * called the original gone — and then reproduced on a fixture whose answer
     * is known.
     */
    public function test_theme_audit_does_not_call_a_comma_merge_a_deletion(): void
    {
        $out = $this->tool('theme-audit.php', [
            '--css=tests/fixtures/tools/css/theme-merged.blade.php',
            '--against=tests/fixtures/tools/css/theme-split.blade.php',
        ]);

        $this->assertStringContainsString(
            '3 rule(s) in the baseline, 3 now. 3 identical.',
            $out,
            'Splitting `.alpha, .beta` into two rules changes no declaration. Every selector in '
            .'the baseline must still be found, and every one must resolve identically.',
        );

        $this->assertStringNotContainsString(
            'GONE',
            $out,
            'Neither selector is gone. Both paint exactly what they painted before.',
        );
    }

    /**
     * And it still reports a selector that really did disappear.
     *
     * The GREEN half of the pair above. A differ made silent about GONE would
     * satisfy that assertion perfectly and be worth nothing — the failure one
     * step past the one being fixed, which is the same reason the identity case
     * is paired with the changed-token case.
     */
    public function test_theme_audit_still_reports_a_selector_that_is_really_gone(): void
    {
        $out = $this->tool('theme-audit.php', [
            '--css=tests/fixtures/tools/css/theme-deleted.blade.php',
            '--against=tests/fixtures/tools/css/theme-split.blade.php',
        ]);

        $this->assertStringContainsString('GONE  (1)', $out);
        $this->assertStringContainsString('.gamma', $out, 'The deleted selector must be named.');
    }

    /**
     * @param  array<int, string>  $args
     */
    private function tool(string $script, array $args): string
    {
        $process = new Process(
            [PHP_BINARY, 'tools/'.$script, ...$args],
            base_path(),
            null,
            null,
            120,
        );

        $process->run();

        return $process->getOutput().$process->getErrorOutput();
    }
}
