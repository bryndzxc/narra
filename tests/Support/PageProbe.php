<?php

namespace Tests\Support;

use App\Support\GateVoice;
use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * The detectors behind the console's structural assertions, as pure functions.
 *
 * ---------------------------------------------------------------------------
 * WHY THEY ARE NOT PRIVATE METHODS ON THE TESTS ANY MORE
 * ---------------------------------------------------------------------------
 *
 * Two reasons, and the second is the one that matters.
 *
 * The first is ordinary: `alertsWithoutTheirOwnWidth()` and `matchCount()` were
 * copied verbatim into two test files. Two hand-maintained copies of one rule is
 * how they come to disagree, which is a sentence this codebase has written about
 * a retry prompt, a `--max-time` hint and a cost multiplier.
 *
 * The second is that **a detector living inside the test it serves cannot be
 * pointed at a known-bad input.** That is precisely what made the four
 * self-defeating checks in this project possible: `strpos` coercion, the
 * busy-only ordering test, `--against` comparing light to dark, and the row
 * assertion whose own fixture removed the condition it measures. Every one of
 * them was green, and every one of them was green about nothing.
 *
 * `tools/*.php` stopped being able to be confidently wrong the day each grew a
 * fixture whose answer was known in advance. These are the same thing for
 * assertions: pure, callable, and drilled in GuardsGoRedTest against an input
 * that must be reported and an input that must not.
 */
final class PageProbe
{
    /**
     * Row groups that occupy a grid track and show nothing.
     *
     * An element child of the named container — `.gatecols` by default, and
     * `.dash` for the dashboard's own three columns — with no element of its own
     * and no text.
     * Comments are neither: Livewire leaves a morph marker behind every false
     * `@if`, so a group that rendered nothing still carries one, and a
     * predicate that counted it would report content where the browser shows a
     * void.
     *
     * @return array<int, string>
     */
    public static function emptyRowGroups(string $html, string $container = 'gatecols'): array
    {
        $empty = [];

        foreach (self::query($html, "//*[%s]/*", self::hasClass($container)) as $group) {
            if ($group->getElementsByTagName('*')->length > 0) {
                continue;
            }

            if (trim((string) $group->textContent) !== '') {
                continue;
            }

            $empty[] = $group->nodeName.' class="'.$group->getAttribute('class').'"';
        }

        return $empty;
    }

    /**
     * Alerts relying on something other than themselves for their width.
     *
     * `.alert` caps its measure at 96ch on purpose. A rule lifting that cap must
     * live on the element, never under a container an arrangement can remove —
     * `.gatecols .alert` did, and every alert on Gate 2's quiet layout silently
     * snapped back to a third of the viewport when that row went away.
     *
     * Anything inside `.advisories` is excluded: that container widens its own
     * children as a grid, which is a different and deliberate arrangement.
     *
     * @return array<int, string> the class attribute of each offender
     */
    public static function alertsWithoutTheirOwnWidth(string $html): array
    {
        $offenders = self::query(
            $html,
            '//*[%s][not(%s)][not(ancestor::*[%s])]',
            self::hasClass('alert'),
            self::hasClass('wide'),
            self::hasClass('advisories'),
        );

        $out = [];

        foreach ($offenders as $node) {
            $out[] = $node->getAttribute('class');
        }

        return $out;
    }

    /**
     * Claims a page makes that the state it is in does not entitle it to.
     *
     * The fragments come from GateVoice, so a reworded clause moves this with
     * it, and because they are fragments this also catches a claim written by
     * hand without going through the voice at all.
     *
     * TWO KINDS NOW, AND THE SECOND IS WHY THIS WAS EXTENDED. It began covering
     * only clauses that OFFER AN ACTION — "N thing(s) block approval" on a page
     * that cannot be approved. Gate 4's strip made a claim of the other kind:
     * "Gate 4 is behind this story", at eight statuses where the gate is ahead
     * of it, plus "which is terminal" about `draft`. That is not an action, so
     * every fragment here missed it and a contract running over four gates at
     * eleven statuses each stayed green for a phase.
     *
     * Nothing in this loop had to change for it — the capabilities are data and
     * `GateVoice::claims()` now yields the position ones alongside the rest,
     * which is what the shared list was for. Two things did:
     *
     * 1. WHITESPACE IS NORMALISED FIRST, and without it this extension would
     *    have been decorative. Gate 4's blade wraps its disclosure between
     *    "which is" and "terminal", so `str_contains` on the raw HTML could not
     *    see the fragment at all — measured, not assumed: a grep of the
     *    rendered page for that exact string returns nothing while the sentence
     *    is plainly on screen. Every fragment is one line of prose here, but
     *    nothing stops a template from wrapping one, and a detector defeated by
     *    a line break is the "check that cannot fire" shape wearing a newline.
     *    Only runs of whitespace collapse; tags are left alone, so a fragment
     *    split across an element still does not match and cannot false-positive.
     *
     * 2. The finding names the KIND, because they want different reactions. An
     *    action claim is a sentence offering a button that is not there; a
     *    position claim is the page being wrong about where the story is.
     *
     * @return array<int, string>
     */
    public static function claimsNotEntitledTo(string $html, GateVoice $voice): array
    {
        $flat = (string) preg_replace('/\s+/', ' ', $html);

        $position = [GateVoice::PASSED, GateVoice::AHEAD, GateVoice::TERMINAL];

        $found = [];

        foreach (GateVoice::claims() as $capability => $fragments) {
            if ($voice->can($capability)) {
                continue;
            }

            foreach ($fragments as $fragment) {
                if (str_contains($flat, $fragment)) {
                    $found[] = sprintf(
                        '%s claim "%s" (not entitled to "%s")',
                        in_array($capability, $position, true) ? 'position' : 'action',
                        $fragment,
                        $capability,
                    );
                }
            }
        }

        return $found;
    }

    /**
     * How many elements a simple descendant/child selector matches.
     *
     * Handles exactly the shapes class-audit emits — `.a .b`, `.a > .b` and
     * `tag.b` — because a full CSS engine is not needed to answer "is the
     * ancestor actually there".
     */
    public static function matchCount(string $html, string $selector): int
    {
        $xpath = '';

        foreach (preg_split('/\s+/', trim($selector)) as $token) {
            if ($token === '>') {
                $xpath .= '/';

                continue;
            }

            if (! str_ends_with($xpath, '/')) {
                $xpath .= '//';
            }

            $parts = explode('.', $token);
            $tag = $parts[0] === '' ? '*' : $parts[0];

            $xpath .= $tag;

            foreach (array_slice($parts, 1) as $class) {
                $xpath .= sprintf('[%s]', self::hasClass($class));
            }
        }

        return (new DOMXPath(self::document($html)))->query($xpath)->length;
    }

    /**
     * Where a string is, or null when it is not on the page at all.
     *
     * NULL RATHER THAN FALSE, and that is the whole point. `strpos` returns
     * `false`, PHP coerces `false` to `0` in a numeric comparison, and
     * `assertLessThan($later, $earlier)` therefore PASSES for an element that
     * has been deleted from the page. Every ordering assertion in
     * ScenesGateLayoutTest was vacuously true that way, and it was found by
     * drilling — the test written to prevent exactly it stayed green when the
     * element it ordered was removed.
     *
     * Callers assert on the null before comparing; a null cannot be silently
     * compared to an int.
     */
    public static function offsetOf(string $html, string $needle): ?int
    {
        $at = strpos($html, $needle);

        return $at === false ? null : $at;
    }

    /** @return array<int, DOMElement> */
    private static function query(string $html, string $template, string ...$predicates): array
    {
        $nodes = (new DOMXPath(self::document($html)))->query(vsprintf($template, $predicates));

        $out = [];

        foreach ($nodes as $node) {
            if ($node instanceof DOMElement) {
                $out[] = $node;
            }
        }

        return $out;
    }

    private static function hasClass(string $name): string
    {
        return sprintf(
            "contains(concat(' ', normalize-space(@class), ' '), ' %s ')",
            $name,
        );
    }

    private static function document(string $html): DOMDocument
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="utf-8" ?><body>'.$html.'</body>');
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $document;
    }
}
