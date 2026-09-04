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
        $this->assertSame(
            2,
            substr_count($out, 'COMPILED-IN-COMMENT'),
            'Exactly the two FOREIGN comments must be reported. A component tag inside a BLADE '
            .'comment is inert — compileComments runs before compileComponentTags — and reporting '
            .'it flags what cannot break, which is how the first version of this rule came to miss '
            .'the CSS comment that actually broke the console.',
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
