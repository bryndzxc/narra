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
    public static function expectedWpm(?string $voiceId, ?string $localeProfile): int
    {
        $measured = self::profile($voiceId, $localeProfile)['words_per_minute'] ?? null;

        return max(1, (int) ($measured ?? config('render.narration.words_per_minute', 160)));
    }

    /**
     * The best measurement available for this pair.
     *
     * A DIFFERENT QUESTION FROM `expectedWpm()`, and the difference is not
     * academic: a script is sized before there is a narrator. `voice_id` is
     * null until the channel's narrator is locked — `providers.default_voice_id`
     * is deliberately null, `GenerateSceneNarration` refuses to synthesize
     * without one, and `voices:list --set` assigns it — so at act-script time
     * the story routinely knows its locale and not its voice.
     *
     * **That is why pointing the word target at `expectedWpm()` alone would
     * have been a fix that did not fix.** `expectedWpm(null, 'en-US')` returns
     * the fallback 160, so every story created through the console would have
     * gone on being sized 18% short while the code read as corrected. Absence
     * reading as agreement, in the change written to stop exactly that.
     *
     * So this asks the locale when it cannot ask the voice, and it uses only
     * real measurements to do it. Three steps:
     *
     *   1. this voice, measured on this locale — the precise answer;
     *   2. otherwise the HIGHEST rate any voice has been measured at on this
     *      locale;
     *   3. otherwise the fallback constant, unchanged and still the honest
     *      answer when nothing at all has been measured.
     *
     * **Highest, not lowest, and that is the direction that protects the
     * floor.** Runtime is `minutes * wpm_sized / wpm_actual`, so sizing BELOW
     * the true reading rate is what produces a short video — 160 against 197 is
     * the 28.4-minute script this whole change exists to fix. Sizing slightly
     * above costs a little length, and the ceiling is not the constraint: 8
     * minutes is the only hard line, and the reference channels in this niche
     * run 44 and 54 minutes.
     *
     * ANYTHING ESTIMATING A RUNTIME USES THIS, not just the word target — and
     * that was found by a test rather than by design. The first version had
     * sizing ask this and the runtime estimate ask `expectedWpm()`, which for a
     * story carrying an unmeasured voice id meant one number for how long to
     * write and a different one for how long it would run: 197 and 160, in the
     * same app, about the same narration. That is the drift this whole change
     * exists to remove, reintroduced inside the change. Every story between 3
     * and 12 carries `narrator-us-01`, the placeholder the fake invented, so it
     * was not a hypothetical.
     *
     * `expectedWpm()` is untouched and stays the GUARD's figure. Its fallback
     * semantics are load-bearing for `isEnforceable()` — measured or not is the
     * question that decides whether a disagreement may cancel a batch — and
     * widening it to the locale would quietly make an unmeasured pair look
     * measured.
     *
     * It takes a voice and a locale rather than a Story ON PURPOSE. The thing
     * it must never read is `stories.sized_against_wpm`, and it structurally
     * cannot — see ScriptSizing, which is where the frozen figure is consulted.
     */
    public static function bestKnownWpm(?string $voiceId, ?string $localeProfile): int
    {
        if (self::isMeasured($voiceId, $localeProfile)) {
            return self::expectedWpm($voiceId, $localeProfile);
        }

        $measured = [];

        foreach (array_keys((array) config('render.narration.voices', [])) as $candidate) {
            if (self::isMeasured((string) $candidate, $localeProfile)) {
                $measured[] = self::expectedWpm((string) $candidate, $localeProfile);
            }
        }

        return $measured === []
            ? max(1, (int) config('render.narration.words_per_minute', 160))
            : max($measured);
    }

    /**
     * Whether this narrator has been measured ON THIS KIND OF SCRIPT.
     *
     * The locale half is not a refinement, it is the whole question. Brian is
     * measured — at 197 wpm, across 186 real scenes — and that figure says
     * nothing about how he reads en-CN, where two scenes came back at 219 and
     * 230. A method that answered "yes, measured" to both is what let the guard
     * cancel a 270-scene batch on the strength of a number about other prose.
     */
    public static function isMeasured(?string $voiceId, ?string $localeProfile): bool
    {
        return isset(self::profile($voiceId, $localeProfile)['words_per_minute']);
    }

    /** Human name for the voice. A property of the narrator, not of a script. */
    public static function voiceName(?string $voiceId): ?string
    {
        $name = self::voice($voiceId)['name'] ?? null;

        return is_string($name) ? $name : null;
    }

    /** Where the figure for this pair came from, for a page or a command to cite. */
    public static function measuredOn(?string $voiceId, ?string $localeProfile): ?string
    {
        $on = self::profile($voiceId, $localeProfile)['measured_on'] ?? null;

        return is_string($on) ? $on : null;
    }

    /**
     * The speed the profile was measured at.
     *
     * Carried because the figure is only true at that speed. If the configured
     * speed has moved and the profile has not, the expected wpm is stale — and
     * a stale expectation is worse than no expectation, because the check built
     * on it would pass while being wrong.
     */
    public static function measuredAtSpeed(?string $voiceId, ?string $localeProfile): ?float
    {
        $speed = self::profile($voiceId, $localeProfile)['measured_at_speed'] ?? null;

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
    public static function profileMatchesConfiguredSpeed(?string $voiceId, ?string $localeProfile): bool
    {
        $measuredAt = self::measuredAtSpeed($voiceId, $localeProfile);

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
     * Whether a drift on this pair is grounds for STOPPING the run.
     *
     * Detection and enforcement are separate questions and conflating them is
     * what made story 21 expensive. `violation()` always measures, because the
     * expected figure is the assumption the SCRIPT WAS SIZED AGAINST and
     * comparing reality to it is meaningful whether or not the narrator has
     * been profiled — that is precisely how story 9's 18% drift is caught, on a
     * story whose voice had no profile at all.
     *
     * What differs is what the disagreement PROVES.
     *
     *   Measured pair. The narrator is known on this kind of script, so reality
     *   disagreeing means something is genuinely wrong — the wrong speed, the
     *   wrong voice, a profile that has gone stale. Stop, at a cost of one
     *   scene.
     *
     *   Unmeasured pair. The assumption was a guess: the fallback constant, or
     *   a figure measured on different prose. Reality disagreeing means THE
     *   GUESS was wrong, which is not a reason to destroy a 270-scene run — the
     *   audio is unaffected and only the runtime estimate moves. Report it,
     *   loudly and on the record, and let the run establish the real number.
     *
     * The mixed-tempo damage this is sometimes assumed to prevent is not
     * prevented here and never was: a speed that moves mid-run is caught by
     * `narration_speed` provenance and by the run fingerprint, neither of which
     * consults this method.
     */
    public static function isEnforceable(?string $voiceId, ?string $localeProfile): bool
    {
        return self::isMeasured($voiceId, $localeProfile);
    }

    /**
     * Why the pace guard cannot be enforced on this run, or null if it can.
     *
     * The counterpart to `violation()`, and the reason that method is allowed
     * to stay quiet on an unmeasured pair. A check that cannot run must report
     * that it could not — the same rule `SpeechQuota` follows for an unreadable
     * balance, one level up. Returned as a sentence because the operator is
     * reading it at the moment they decide to spend, and "unmeasured" on its
     * own tells them nothing about what to do next.
     *
     * Deliberately NOT a refusal. A pace difference does not corrupt audio; it
     * changes a runtime estimate. Stopping a 270-scene run over an estimate,
     * using a figure from a different script, is a worse trade than letting the
     * run establish the figure — and the mixed-tempo damage this is sometimes
     * mistaken for is caught elsewhere, by `narration_speed` provenance and by
     * the run fingerprint, neither of which depends on this.
     */
    public static function unmeasured(?string $voiceId, ?string $localeProfile): ?string
    {
        if ($localeProfile === null || trim($localeProfile) === '') {
            return null;
        }

        if (self::isMeasured($voiceId, $localeProfile)) {
            return null;
        }

        $name = self::voiceName($voiceId) ?? (string) $voiceId;
        $others = array_keys(self::voice($voiceId)['locales'] ?? []);

        return sprintf(
            '%s has no measured reading pace for %s, so the pace guard cannot judge this run.
'
            .'  %s'.'
'
            .'  scripts are sized against %d wpm (the fallback) until one exists

'
            .'This is not a warning about quality — the audio is unaffected. It means the runtime '
            .'estimate for this story is a guess.
'
            .'When the narration batch finishes, run `php artisan narration:measure <story>` and paste '
            .'the result into render.narration.voices. The guard resumes at full strength for this '
            .'pair the moment it exists.',
            $name,
            $localeProfile,
            $others === []
                ? sprintf('this voice has no measured locale at all')
                : sprintf('measured elsewhere: %s — a different kind of prose, so not transferable', implode(', ', $others)),
            self::expectedWpm($voiceId, $localeProfile),
        );
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
    public static function violation(?string $voiceId, ?string $localeProfile, int $words, int $durationMs): ?string
    {
        if (! self::isReliableSample($words) || $durationMs <= 0) {
            return null;
        }

        $expected = self::expectedWpm($voiceId, $localeProfile);
        $actual = self::measure($words, $durationMs);
        $drift = ($actual - $expected) / $expected;
        $tolerance = (float) config('render.narration.pace_tolerance', 0.12);

        // A stale profile is a violation in its own right, whatever the drift
        // says. The expected figure is only true at the speed it was measured
        // at, so once the synthesizer moves, the comparison below is being made
        // against a number describing different audio — and it can PASS while
        // being meaningless, which is the version of this failure that is
        // hardest to ever notice. Re-measuring is one bake-off and one line.
        if (! self::profileMatchesConfiguredSpeed($voiceId, $localeProfile)) {
            return sprintf(
                "The measured pace for %s was taken at speed %.2f, but the synthesizer is set to %.2f.\n"
                ."  expected : %d wpm  (stale — it describes this voice at a different speed)\n"
                ."  actual   : %.0f wpm  (%d words in %.1f s)\n\n"
                .'Every runtime number downstream is derived from the expected figure, so this is not '
                .'a warning about accuracy — it is a comparison that cannot mean anything. Re-measure '
                .'with `php artisan narration:bakeoff <story>` and update render.narration.voices, or '
                .'put the speed back.',
                self::voiceName($voiceId) ?? (string) $voiceId,
                (float) self::measuredAtSpeed($voiceId, $localeProfile),
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
            self::isMeasured($voiceId, $localeProfile)
                ? sprintf(
                    'measured for %s on %s%s',
                    self::voiceName($voiceId) ?? $voiceId,
                    $localeProfile,
                    self::measuredOn($voiceId, $localeProfile) === null
                        ? ''
                        : ', '.self::measuredOn($voiceId, $localeProfile),
                )
                : sprintf(
                    'the fallback constant — this voice has no measured profile for %s',
                    $localeProfile ?? 'this story',
                ),
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
    private static function profile(?string $voiceId, ?string $localeProfile): array
    {
        $voice = self::voice($voiceId);

        if ($voice === [] || $localeProfile === null || trim($localeProfile) === '') {
            return [];
        }

        $measured = $voice['locales'][$localeProfile] ?? null;

        return is_array($measured) ? $measured : [];
    }

    /**
     * The voice's own record, independent of any locale.
     *
     * Split out because the NAME is a property of the narrator and the RATE is
     * not — the rate belongs to the narrator reading a particular kind of
     * script. Keeping them in one lookup is what let an en-US measurement
     * answer an en-CN question.
     *
     * @return array<string, mixed>
     */
    private static function voice(?string $voiceId): array
    {
        if ($voiceId === null || trim($voiceId) === '') {
            return [];
        }

        $voice = config('render.narration.voices.'.$voiceId);

        return is_array($voice) ? $voice : [];
    }
}
