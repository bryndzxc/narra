<?php

namespace App\Support;

/**
 * A short, stable digest of the look a reference face was drawn in.
 *
 * One function, because the only property that matters is that the value
 * written at generation time and the value compared against later are computed
 * the same way. Two expressions of that would drift, and a fingerprint that
 * drifts reports every sheet as stale until somebody turns the check off.
 *
 * **What goes into it, and what deliberately does not.**
 *
 * In: `scenes.art_style`, because that is the look. In: `scenes.constraints`,
 * because they are appended to the same prompt and a change to them ("no text"
 * becoming "no text and no borders") changes what came back. In:
 * `characters.reference_frame` and `characters.inherit_scene_style`, because a
 * reference generated without the style block inherited, or against a different
 * framing instruction, is a different kind of artifact from one generated with
 * them — and telling those apart is the entire job here.
 *
 * Out: the model name and the provider. Those are already columns on the row,
 * they answer a different question, and folding them in would make a provider
 * swap read as a style change — which would be a false alarm on exactly the
 * kind of migration an operator does deliberately.
 *
 * Out: whitespace and case. A reflowed config value is the same look, and a
 * fingerprint that fires on a re-indent is a fingerprint nobody trusts.
 */
final class StyleFingerprint
{
    /**
     * The digest of whatever look is configured right now.
     */
    public static function current(): string
    {
        return self::of(
            (string) config('scenes.art_style'),
            (string) config('scenes.constraints'),
            (string) config('characters.reference_frame'),
            (bool) config('characters.inherit_scene_style'),
        );
    }

    /**
     * The digest of a specific look, so a test can pin one and a preview can
     * ask about a candidate without applying it.
     */
    public static function of(
        string $artStyle,
        string $constraints = '',
        string $referenceFrame = '',
        bool $inheritStyle = true,
    ): string {
        $parts = array_map(
            self::normalise(...),
            [$artStyle, $constraints, $referenceFrame, $inheritStyle ? 'inherit' : 'standalone'],
        );

        return substr(hash('sha256', implode('|', $parts)), 0, 16);
    }

    /**
     * Case-folded, whitespace-collapsed.
     *
     * A style block is edited as prose and wraps differently every time it is
     * touched. Firing on a reflow would train the operator to ignore the alarm,
     * which costs more than the alarm is worth.
     */
    private static function normalise(string $value): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $value)));
    }
}
