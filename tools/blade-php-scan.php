<?php

/**
 * The ways a blade file can compile into something other than what it reads as
 * — all silent, all landing far from the cause.
 *
 * ---------------------------------------------------------------------------
 * THE PASS ORDER, MEASURED RATHER THAN REMEMBERED
 * ---------------------------------------------------------------------------
 *
 * `BladeCompiler::compileString()` runs, in this order:
 *
 *   1. prepareStringsForCompilationUsing   Livewire's inline-island compiler
 *   2. storeUncompiledBlocks               @verbatim…@endverbatim, @php…@endphp
 *   3. compileComments                     {{-- --}} stripped HERE
 *   4. compileComponentTags                <x-…>, <x-slot>
 *   5. precompilers                        Livewire: morph-aware @if,
 *                                          <livewire:…>, ExtendBlade
 *   6. token_get_all + parseToken          @directives and {{ echoes }}
 *
 * Only 1 and 2 run before comments are stripped. **Everything a pass in 1 or 2
 * recognises is dangerous inside a `{{-- --}}` comment; nothing a pass in 4-6
 * recognises is.** That line was got WRONG once, in this file's own docblock:
 * it claimed the component-tag compiler was a precompiler running before
 * comments. It is not — it runs one step after them, and a component tag inside
 * a blade comment is inert. Verified by compiling both and looking, which is
 * the only way this should ever be asserted.
 *
 * ---------------------------------------------------------------------------
 * WHAT ACTUALLY BROKE, AND WHY THE FIRST VERSION OF THE RULE WAS BACKWARDS
 * ---------------------------------------------------------------------------
 *
 * A `<x-gate-group>` written to NAME the component in prose took every page in
 * the console down with `syntax error, unexpected end of file, expecting
 * "elseif"`, a thousand lines from the text that caused it. It was inside a CSS
 * comment in `base-css.blade.php` — and Blade has no idea what a CSS comment
 * is. It was never in a comment at all as far as the compiler is concerned; it
 * was ordinary template text, inside `<style>`, where a compiled component
 * render is a syntax error.
 *
 * The rule written for it looked for component tags inside BLADE comments,
 * which is the safe case — so it flagged what cannot break and missed what did.
 * The general form is not "comments are compiled" but: **a comment Blade does
 * not know is a comment is not a comment.** Blade knows `{{-- --}}`. It does
 * not know `/* *\/`, `//`, or `<!-- -->`.
 *
 * ---------------------------------------------------------------------------
 * THE FOUR SHAPES REFUSED
 * ---------------------------------------------------------------------------
 *
 *   SWALLOWED            the parenthesised inline form `@php($x = ...)` exists,
 *                        and `storePhpBlocks` does not know it does — it lazily
 *                        pairs `/(?<!@)@php(.*?)@endphp/s`, so an inline one
 *                        ABOVE a block is paired with that block's closer and
 *                        every line between is swallowed into raw PHP.
 *
 *   UNPAIRED             a closing php directive with no opener before it. A
 *                        literal `@endphp` in a comment reads exactly like a
 *                        real one to pass 2 — measured: it closes a real block
 *                        early and the rest of that block leaks out as markup.
 *
 *   PRE-COMMENT-TOKEN    any other pass-1 or pass-2 token written inside a
 *                        blade comment: `@verbatim`, `@endverbatim`,
 *                        `@island`, `@endisland`. Same blindness as UNPAIRED,
 *                        different token. Named separately because `@php` gets
 *                        the richer pairing analysis above and these do not.
 *
 *   COMPILED-IN-COMMENT  a component or livewire tag inside a comment Blade
 *                        cannot see — CSS or HTML. It is compiled, because to
 *                        Blade it is not in a comment. This is the one that
 *                        took the console down.
 *
 * Nothing in the test suite can catch these generally — a broken view fails only
 * the tests that happen to render it, and reads as an application bug rather
 * than as a compilation one. So it is a scan, and it has known-answer fixtures
 * in ToolsAnswerKnownCasesTest, including the NEGATIVE one: a component tag in
 * a blade comment must NOT be reported, or the rule is backwards again.
 *
 * Usage:  php tools/blade-php-scan.php
 */
// Overridable so the scanner can be run against a blade whose answer is known.
define('VIEWS', (static function (array $argv): string {
    foreach (array_slice($argv, 1) as $arg) {
        if (str_starts_with($arg, '--views=')) {
            return substr($arg, strlen('--views='));
        }
    }

    return __DIR__.'/../resources/views';
})($argv));

/** @return array<int, array{file: string, line: int, kind: string, detail: string}> */
function scan(string $dir): array
{
    $findings = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));

    // Built at runtime so this file can describe the tokens without containing
    // them in a form its own scan would flag if it were ever a blade file.
    $open = '@'.'php';
    $close = '@'.'endphp';

    foreach ($files as $file) {
        if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.blade.php')) {
            continue;
        }

        $path = str_replace('\\', '/', $file->getPathname());
        $offset = strpos($path, 'resources/views');
        $path = $offset === false ? $path : substr($path, $offset);
        $source = (string) file_get_contents($file->getPathname());

        // -- Tokens that pass 1 and pass 2 see INSIDE a blade comment --------
        //
        // Comments are stripped at pass 3, so anything an earlier pass matches
        // is matched inside them. `@php`/`@endphp` get the richer pairing walk
        // below; these are the rest of the set, reported on sight.
        //
        // `@island` is here because the island compiler is pass 1 — earlier
        // than the php blocks. No view in this project uses islands today and
        // the pass short-circuits unless the file contains `@endisland`, so
        // this has never fired. It is listed because "no view uses it yet" is
        // the reason a hazard goes unnoticed, not a reason it is absent.
        $preCommentTokens = ['@'.'verbatim', '@'.'endverbatim', '@'.'island', '@'.'endisland'];

        // -- Tags that are compiled because Blade cannot see the comment -----
        //
        // Blade knows exactly one comment syntax. A CSS or HTML comment is
        // ordinary template text to it, so a component named in prose inside
        // one is a real component render — inside `<style>` that is a syntax
        // error that takes the whole page down, a thousand lines from the text
        // that caused it.
        $foreignComments = [
            '/\/\*.*?\*\//s',       // CSS or JS block comment
            '/<!--.*?-->/s',        // HTML comment
        ];

        // A component tag, OR any blade directive. Both are compiled inside a
        // comment Blade cannot see, and BOTH have now done it: a `<x-gate-group>`
        // named in prose inside a CSS comment took every page in the console
        // down, and so, ten minutes into the fix for it, did an `@if` written in
        // prose in the very comment explaining the first one.
        //
        // The directive list is every one that takes an expression, since those
        // are the ones that fail loudly; a bare `@endif` in prose is caught by
        // the pairing walk below or by nothing, and a false positive on the word
        // "@media" inside a stylesheet would make this unrunnable — so `@media`
        // and `@keyframes`, which are CSS at-rules rather than blade, are not in
        // it.
        $componentTag = '/<\/?(?:x-[A-Za-z0-9._:-]+|livewire:[A-Za-z0-9._:-]+)'
            .'|(?<!@)@(?:if|elseif|unless|foreach|forelse|for|while|switch|case|include|'
            .'props|class|checked|disabled|error|php|json|isset|empty|auth|guest)\b/';

        $lineAt = static fn (int $at): int => substr_count(substr($source, 0, $at), "\n") + 1;

        if (preg_match_all('/\{\{--.*?--\}\}/s', $source, $comments, PREG_OFFSET_CAPTURE)) {
            foreach ($comments[0] as $comment) {
                foreach ($preCommentTokens as $token) {
                    if (! preg_match('/(?<!@)'.preg_quote($token, '/').'\b/', (string) $comment[0])) {
                        continue;
                    }

                    $findings[] = [
                        'file' => $path,
                        'line' => $lineAt((int) $comment[1]),
                        'kind' => 'PRE-COMMENT-TOKEN',
                        'detail' => sprintf(
                            'the comment starting here contains "%s", and the pass that matches it '
                            .'runs BEFORE comments are stripped — it will be paired with a real one '
                            .'elsewhere in the file. Name the directive without its leading @.',
                            $token,
                        ),
                    ];
                }
            }
        }

        foreach ($foreignComments as $pattern) {
            if (! preg_match_all($pattern, $source, $foreign, PREG_OFFSET_CAPTURE)) {
                continue;
            }

            foreach ($foreign[0] as $comment) {
                if (preg_match($componentTag, (string) $comment[0], $tag) !== 1) {
                    continue;
                }

                $findings[] = [
                    'file' => $path,
                    'line' => $lineAt((int) $comment[1]),
                    'kind' => 'COMPILED-IN-COMMENT',
                    'detail' => sprintf(
                        'the comment starting here contains "%s", and it is not a blade comment — '
                        .'Blade cannot see a CSS or HTML comment, so this is compiled. Name a '
                        .'component without its angle brackets and a directive without its @.',
                        $tag[0],
                    ),
                ];
            }
        }

        $events = [];

        // An opener, and whether it is the inline parenthesised form.
        preg_match_all('/(?<!@)'.preg_quote($open, '/').'(\(|\s|$)/m', $source, $m, PREG_OFFSET_CAPTURE);

        foreach ($m[0] as $i => $hit) {
            $events[] = [$m[1][$i][0] === '(' ? 'inline' : 'block', (int) $hit[1]];
        }

        foreach (['/'.preg_quote($close, '/').'/'] as $pattern) {
            preg_match_all($pattern, $source, $m, PREG_OFFSET_CAPTURE);

            foreach ($m[0] as $hit) {
                $events[] = ['close', (int) $hit[1]];
            }
        }

        usort($events, fn (array $a, array $b): int => $a[1] <=> $b[1]);

        $line = static fn (int $at): int => substr_count(substr($source, 0, $at), "\n") + 1;

        // Walk the file the way the compiler's pass does: a block consumes to
        // the next closer; an inline that still finds a closer after it is the
        // hazard.
        $i = 0;

        while ($i < count($events)) {
            [$kind, $at] = $events[$i];

            if ($kind === 'block') {
                $j = $i + 1;

                while ($j < count($events) && $events[$j][0] !== 'close') {
                    $j++;
                }

                $i = $j + 1;

                continue;
            }

            if ($kind === 'inline') {
                $j = $i + 1;

                while ($j < count($events) && $events[$j][0] !== 'close') {
                    $j++;
                }

                if ($j < count($events)) {
                    $findings[] = [
                        'file' => $path,
                        'line' => $line($at),
                        'kind' => 'SWALLOWED',
                        'detail' => sprintf(
                            'an inline php directive here is paired with the closer on line %d; '
                            .'everything between is compiled as raw PHP',
                            $line($events[$j][1]),
                        ),
                    ];
                }

                $i++;

                continue;
            }

            // A closer with no opener before it: either a stray, or the file
            // has a literal token in a comment. Both are worth a look.
            $findings[] = [
                'file' => $path,
                'line' => $line($at),
                'kind' => 'UNPAIRED',
                'detail' => 'a closing php directive with no opener — a literal token in a comment '
                    .'reads exactly like a real one to the compiler',
            ];

            $i++;
        }
    }

    return $findings;
}

$findings = scan(VIEWS);

printf("%d blade file(s) scanned for php-block hazards.\n\n", iterator_count(
    new CallbackFilterIterator(
        new RecursiveIteratorIterator(new RecursiveDirectoryIterator(VIEWS)),
        static fn ($f): bool => $f->isFile() && str_ends_with($f->getFilename(), '.blade.php'),
    ),
));

foreach ($findings as $finding) {
    printf("%s  %s:%d\n    %s\n\n", $finding['kind'], $finding['file'], $finding['line'], $finding['detail']);
}

printf("%d finding(s).\n", count($findings));

exit($findings === [] ? 0 : 1);
