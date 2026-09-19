<?php

namespace App\Support;

/**
 * Which voice reads a narrator of a given gender, and the reverse.
 *
 * One voice per narrator gender, fixed for the channel — the table under
 * Voice in CLAUDE.md, kept in `providers.narrator_voices`. The new-story form
 * asks for the narrator's gender and resolves the voice here; the premise
 * generator reads the gender back from `voice_id`, so the first person it
 * writes is the one the voice reads as. The voice is the record of the
 * choice, which is why there is no gender column.
 */
final class NarratorVoice
{
    public const GENDERS = ['male', 'female'];

    public static function voiceFor(string $gender): string
    {
        $voices = (array) config('providers.narrator_voices');

        if (! isset($voices[$gender]) || trim((string) $voices[$gender]) === '') {
            throw new \InvalidArgumentException(sprintf(
                'No narrator voice is configured for "%s". The channel keeps one voice per narrator gender: %s.',
                $gender,
                implode(', ', array_keys($voices)),
            ));
        }

        return (string) $voices[$gender];
    }

    /** The gender a voice is the channel's narrator for, or null when it is in no row. */
    public static function genderOf(?string $voiceId): ?string
    {
        if ($voiceId === null || $voiceId === '') {
            return null;
        }

        $gender = array_search($voiceId, (array) config('providers.narrator_voices'), true);

        return is_string($gender) ? $gender : null;
    }

    public static function label(string $gender): string
    {
        return $gender === 'female' ? 'A woman narrating' : 'A man narrating';
    }
}
