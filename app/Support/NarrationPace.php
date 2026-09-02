<?php

namespace App\Support;

/**
 * How fast the narrator actually reads, and whether the run still matches it.
 *
 * **The seam this closes.** The script writer is handed a word target derived
 * from a words-per-minute figure. The runtime estimate is derived from the same
 * figure. The fake TTS derives its durations from it too — and that last one is
 * why nothing ever caught it being wrong: the fake agreed with the constant by
 * construction, so every timing test passed against a number that had never met
 * a vendor. The first real voice read 22% faster than the figure its script was
 * sized against, and the only thing that noticed was a person dividing words by
 * minutes, sixty-nine paid scenes in.
 *
 * A constant on its own does not fix that, and 160 sitting in config for the
 * whole run is the proof. What fixes it is comparing the constant to reality on
 * every scene, which is what `violation()` is for.
 *
 * **Per voice, not global.** Reading rate belongs to a voice at a speed. Brian
 * at 0.9 is not Brian at 1.0 and is not some other narrator at either, so a
 * single global constant guaranteed that casting a second voice would reopen
 * this exactly. An unmeasured voice falls back to the global figure and says so
 * — `isMeasured()` exists so a caller can tell "measured at 172" from "assumed
 * 160", which are very different claims.
 */
final class NarrationPace
{
    /**
     * Words per minute expected of this narrator.
     *
     * The voice's own measured figure where there is one, the global fallback
     * otherwise. Never zero: everything downstream divides by it.
     */
    public static function expectedWpm(?string $voiceId): int
    {
        $measured = self::profile($voiceId)['words_per_minute'] ?? null;

        return max(1, (int) ($measured ?? config('render.narration.words_per_minute', 160)));
    }

    /** Whether that figure came from a real measurement or is the fallback. */
    public static function isMeasured(?string $voiceId): bool
    {
        return isset(self::profile($voiceId)['words_per_minute']);
    }

    /** Human name for the voice, where the profile carries one. */
    public static function voiceName(?string $voiceId): ?string
    {
        $name = self::profile($voiceId)['name'] ?? null;

        return is_string($name) ? $name : null;
    }

    /**
     * The speed the profile was measured at.
     *
     * Carried because the figure is only true at that speed. If the configured
     * speed has moved and the profile has not, the expected wpm is stale — and
     * a stale expectation is worse than no expectation, because the check built
     * on it would pass while being wrong.
     */
    public static function measuredAtSpeed(?string $voiceId): ?float
    {
        $speed = self::profile($voiceId)['measured_at_speed'] ?? null;

        return $speed === null ? null : (float) $speed;
    }

    /** The narration speed currently configured on the synthesizer. */
    public static function configuredSpeed(): float
    {
        return (float) config('providers.elevenlabs.tts.voice_settings.speed', 1.0);
    }

    /**
     * Whether the profile still describes the speed we are generating at.
     *
     * True when the voice is unmeasured — there is nothing to be stale.
     */
    public static function profileMatchesConfiguredSpeed(?string $voiceId): bool
    {
        $measuredAt = self::measuredAtSpeed($voiceId);

        return $measuredAt === null || abs($measuredAt - self::configuredSpeed()) < 0.001;
    }

    /** Observed words per minute for a piece of narration that exists. */
    public static function measure(int $words, int $durationMs): float
    {
        if ($durationMs <= 0) {
            return 0.0;
        }

        return $words / ($durationMs / 60000);
    }

    /**
     * Whether enough narration exists yet to judge pace on.
     *
     * **Cumulative across the story, not one scene, and that distinction is the
     * whole reliability of this check.** Measured on story 9's real audio, four
     * consecutive scenes read 189, 201, 233 and 206 wpm — a 23% spread between
     * neighbours, from nothing but where the sentence breaks fall and how many
     * short lines of dialogue a scene happens to carry. A per-scene test tight
     * enough to catch a 22% systematic drift would fire on scene 4 of a
     * perfectly healthy run and cancel the batch.
     *
     * The failure being caught is systematic — the voice reads at one rate and
     * the script was sized for another — so the right instrument is the running
     * average. It is stable within a few scenes and it still fires early: on
     * story 9 the very first scene carries 56 words, which is over this
     * threshold on its own.
     */
    public static function isReliableSample(int $words): bool
    {
        return $words >= (int) config('render.narration.pace_min_words', 50);
    }

    /**
     * The complaint about the narration SO FAR, or null if it is fine.
     *
     * `$words` and `$durationMs` are cumulative totals across every scene of
     * this story already narrated by the current voice at the current speed —
     * not one scene. See isReliableSample().
     *
     * Returns a sentence rather than a bool because the caller throws it, and
     * the operator reading it needs to know which two numbers disagreed and
     * what to do — not that "a check failed".
     */
    public static function violation(?string $voiceId, int $words, int $durationMs): ?string
    {
        if (! self::isReliableSample($words) || $durationMs <= 0) {
            return null;
        }

        $expected = self::expectedWpm($voiceId);
        $actual = self::measure($words, $durationMs);
        $drift = ($actual - $expected) / $expected;
        $tolerance = (float) config('render.narration.pace_tolerance', 0.12);

        // A stale profile is a violation in its own right, whatever the drift
        // says. The expected figure is only true at the speed it was measured
        // at, so once the synthesizer moves, the comparison below is being made
        // against a number describing different audio — and it can PASS while
        // being meaningless, which is the version of this failure that is
        // hardest to ever notice. Re-measuring is one bake-off and one line.
        if (! self::profileMatchesConfiguredSpeed($voiceId)) {
            return sprintf(
                "The measured pace for %s was taken at speed %.2f, but the synthesizer is set to %.2f.\n"
                ."  expected : %d wpm  (stale — it describes this voice at a different speed)\n"
                ."  actual   : %.0f wpm  (%d words in %.1f s)\n\n"
                .'Every runtime number downstream is derived from the expected figure, so this is not '
                .'a warning about accuracy — it is a comparison that cannot mean anything. Re-measure '
                .'with `php artisan narration:bakeoff <story>` and update render.narration.voices, or '
                .'put the speed back.',
                self::voiceName($voiceId) ?? (string) $voiceId,
                (float) self::measuredAtSpeed($voiceId),
                self::configuredSpeed(),
                $expected,
                $actual,
                $words,
                $durationMs / 1000,
            );
        }

        if (abs($drift) <= $tolerance) {
            return null;
        }

        return sprintf(
            "Narration pace is %+.0f%% off what this script was sized against.\n"
            ."  expected : %d wpm  (%s)\n"
            ."  actual   : %.0f wpm  (%d words in %.1f s)\n"
            .'  tolerance: %.0f%%%s'
            ."\n\nThis matters because the word target the script was written to is DERIVED from "
            .'the expected figure, so the finished video will not run the length it was written '
            ."for — a %+.0f%% drift turns a 36-minute script into roughly %s.\n"
            .'Fix the narration speed, or re-measure the voice with `php artisan narration:bakeoff '
            ."<story>` and update render.narration.voices.\n"
            .'Nothing after this scene is worth generating until the two agree.',
            $drift * 100,
            $expected,
            self::isMeasured($voiceId)
                ? sprintf('measured for %s', self::voiceName($voiceId) ?? $voiceId)
                : 'the fallback constant — this voice has no measured profile',
            $actual,
            $words,
            $durationMs / 1000,
            $tolerance * 100,
            // The stale-profile case is refused earlier and never reaches here.
            '',
            $drift * 100,
            gmdate('i:s', (int) round(36 * 60 / (1 + $drift))),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function profile(?string $voiceId): array
    {
        if ($voiceId === null || trim($voiceId) === '') {
            return [];
        }

        $profile = config('render.narration.voices.'.$voiceId);

        return is_array($profile) ? $profile : [];
    }
}
