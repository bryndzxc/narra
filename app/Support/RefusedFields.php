<?php

namespace App\Support;

use Closure;
use Illuminate\Support\MessageBag;

/**
 * A component's validation error bag, whole, as labelled and anchored lines
 * for the block that says "this was refused" where the press happened.
 *
 * ---------------------------------------------------------------------------
 * WHY THIS IS ONE CLASS AND NOT THREE COPIES
 * ---------------------------------------------------------------------------
 *
 * Livewire catches a ValidationException, fills the error bag and answers
 * 200. No modal, no log, no exception reaches a handler. The only surface a
 * validation refusal has on its own is an `@error` directive beside its field
 * — and a rule whose field has none refuses in complete silence. Gate 1's act
 * summary had none (story 28 could not be approved and nothing on the page
 * said so); Gate 2's image prompt had none (1,495 of 1,693 stored prompts were
 * over its cap, so most scene edits were refused with no message); Gate 4's
 * title and description had none.
 *
 * The block that renders THIS list is the fix for all three, and it renders
 * the bag WHOLE — every key, not the ones a template author remembered — so a
 * rule added to a component's rules is on the page by construction. A page
 * that wants the block asks for every error and describes each key; a key the
 * page does not describe still renders, under its raw name, rather than being
 * dropped. See resources/views/components/refused-save.blade.php.
 */
final class RefusedFields
{
    /**
     * @param  Closure(string): array{0: string, 1: string}  $describe  key => [label, anchor id or '']
     * @return array<int, array{key: string, label: string, anchor: string, message: string}>
     */
    public static function from(MessageBag $errors, Closure $describe): array
    {
        $out = [];

        foreach ($errors->getMessages() as $key => $messages) {
            [$label, $anchor] = $describe($key);

            $out[] = [
                'key' => $key,
                'label' => $label,
                'anchor' => $anchor,
                'message' => implode(' ', $messages),
            ];
        }

        return $out;
    }

    /**
     * The fallback description for a key nobody labelled: readable, unanchored.
     *
     * @return array{0: string, 1: string}
     */
    public static function plain(string $key): array
    {
        return [ucfirst(str_replace(['.', '_'], ' ', $key)), ''];
    }
}
