<?php

namespace App\Support;

/**
 * What the operator's idea says about itself, read without the model.
 *
 * The premise generator reports whether the idea was revenge-shaped and what
 * it became, and Gate 1 shows that line on every roll where it is true. That
 * report is the generator's own. This is a second reading of the same idea
 * from the operator's words, so a generator that translated an idea and did
 * not say so still leaves a line on the page: the operator wants to learn
 * which of their ideas need translating, and a silent translation teaches
 * nothing.
 *
 * A phrase list, and therefore always one rewrite behind — which is fine for
 * what it does. It never refuses anything and never decides a candidate; it
 * only asks for the line when the model did not give one. A miss here costs
 * the line on one roll; the model's flag is still read.
 */
final class PremiseIdea
{
    private const REVENGE_MARKERS = [
        'revenge', 'avenge', 'avenged', 'payback', 'pay back', 'paid back',
        'made them pay', 'make them pay', 'made her pay', 'make her pay', 'made him pay', 'make him pay',
        'got even', 'get even', 'getting even', 'got back at', 'get back at', 'getting back at',
        'ruined them', 'ruined her', 'ruined him', 'ruin them', 'ruin her', 'ruin him',
        'destroyed them', 'destroyed her', 'destroyed him', 'destroy them', 'destroy her', 'destroy him',
        'taught them a lesson', 'teach them a lesson', 'taught her a lesson', 'teach her a lesson',
        'karma',
    ];

    /** @return array<int, string> the revenge phrases the idea contains */
    public static function revengeMarkers(string $idea): array
    {
        $lower = mb_strtolower($idea);

        return array_values(array_filter(
            self::REVENGE_MARKERS,
            static fn (string $marker): bool => (bool) preg_match('/\b'.preg_quote($marker, '/').'\b/u', $lower),
        ));
    }
}
