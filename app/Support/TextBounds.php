<?php

namespace App\Support;

/**
 * Which of a set of text fields are over their bound, as sentences.
 *
 * One implementation for every model that carries per-field character bounds
 * (Act, Scene), so the form rule, the prompt and the post-call guard measure
 * the same way. Multibyte-aware, the way Laravel's `max:` on a string is —
 * characters, not bytes — so a summary full of curly quotes and em dashes
 * cannot pass one and fail the other.
 */
final class TextBounds
{
    /**
     * @param  array<string, int>  $bounds  column => max characters
     * @param  array<string, string>  $fields  column => text
     * @return array<string, string>  column => problem, empty when all fit
     */
    public static function overflows(array $bounds, array $fields): array
    {
        $problems = [];

        foreach ($bounds as $column => $max) {
            if (! array_key_exists($column, $fields)) {
                continue;
            }

            $length = mb_strlen((string) $fields[$column]);

            if ($length > $max) {
                $problems[$column] = sprintf(
                    '%s is %s characters against a bound of %s',
                    str_replace('_', ' ', $column),
                    number_format($length),
                    number_format($max),
                );
            }
        }

        return $problems;
    }
}
