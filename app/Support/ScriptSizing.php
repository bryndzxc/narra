<?php

namespace App\Support;

use App\Models\Story;

/**
 * The word budget a script is written to, and the one place that decides it.
 *
 * ---------------------------------------------------------------------------
 * THE DEFECT
 * ---------------------------------------------------------------------------
 *
 * `targetWordsPerAct()` read `render.narration.words_per_minute` — the FALLBACK
 * constant, 160, the one whose own docblock says it is "no longer the answer" —
 * so a 35-minute midpoint asked for 5,600 words, and 5,600 words read by the
 * only narrator this channel has takes 28.4 minutes at the measured 197 wpm.
 * **Every script this pipeline wrote was under the 30-minute floor before a word
 * of it existed.** Story 9 hit its target — 5,781 against 5,600 — and still
 * landed 21 seconds short; it came that close only on a 3% generation overshoot.
 *
 * Three other places read the same constant and derived things from it: the
 * dispatch estimate, the two prompt figures in `ClaudeScriptWriter`, and
 * `story:write`'s reported runtime. **A constant corrected in one of four
 * places is the $2.12 / $4.24 / 42,017 shape** — one multiplier applied by
 * three pieces of code that never compared notes, and the same narration
 * carrying three different prices. So this exists as much to be the single
 * reader as to hold the arithmetic.
 *
 * ---------------------------------------------------------------------------
 * TWO QUESTIONS THAT LOOK LIKE ONE
 * ---------------------------------------------------------------------------
 *
 *   SIZING     "what rate should this script be WRITTEN to" — here.
 *   EXPECTATION "what rate will this narrator actually READ at" —
 *              `NarrationPace::expectedWpm()`, which is the guard's question.
 *
 * For a story written today they are the same number. For story 9 they are 160
 * and 197, and that gap is the entire reason `stories.sized_against_wpm` exists.
 *
 * **`NarrationPace` is not pointed at that column and must not be.** Its
 * question is whether the narration coming back reads at the rate we believe;
 * story 9 was sized at 160 and its narrator reads 197, so a guard comparing
 * narration against the frozen figure would find +23% on a 12% tolerance and
 * cancel every batch on a story that is entirely healthy. The column is
 * provenance for judging a SCRIPT, never a target for judging AUDIO.
 */
final class ScriptSizing
{
    /**
     * The rate this story's script is sized to.
     *
     * Frozen first, always. A story that has been written keeps the figure its
     * acts were written to, so a re-run — a partial one especially — cannot
     * size act 4 to a different budget from acts 1-3 and leave no trace.
     */
    public static function wpmFor(Story $story): int
    {
        if ($story->sized_against_wpm !== null) {
            return (int) $story->sized_against_wpm;
        }

        return NarrationPace::bestKnownWpm($story->voice_id, $story->locale_profile);
    }

    /**
     * The same, recorded on the story if it was not already.
     *
     * Only the generator calls this. Everything else asks `wpmFor()`, which
     * answers the identical question without writing — a page rendering an
     * estimate must not freeze provenance as a side effect of being looked at.
     */
    public static function freezeFor(Story $story): int
    {
        if ($story->sized_against_wpm !== null) {
            return (int) $story->sized_against_wpm;
        }

        $wpm = self::wpmFor($story);

        // forceFill for the reason approveGate() uses it: this is provenance,
        // not an attribute somebody assigns, so it is not fillable and cannot
        // arrive from a form.
        $story->forceFill(['sized_against_wpm' => $wpm])->save();

        return $wpm;
    }

    /**
     * How many words of narration a stretch of runtime is worth.
     *
     * -----------------------------------------------------------------------
     * WHY THIS IS HERE AND NOT ON `NarrationPace`
     * -----------------------------------------------------------------------
     *
     * The one consumer today is the hook: "state the betrayal within the first
     * ~20 seconds". That is an instruction to the WRITER about how much text it
     * may spend before the betrayal lands, so it is the sizing question, and it
     * travels in the same act 1 prompt that already carries a word target from
     * `targetWordsPerAct()`.
     *
     * **Deriving one from `wpmFor()` and the other from `bestKnownWpm()` would
     * put two beliefs about one narration in a single prompt.** That is the
     * $2.12 / $4.24 / 42,017 shape — one multiplier applied by pieces of code
     * that never compared notes — reproduced inside one file, which is if
     * anything harder to see than across three. So the seconds go through the
     * story's own frozen rate, and this is the only place the conversion is
     * written.
     *
     * What it does NOT claim is that the finished video says the betrayal
     * inside twenty seconds. It claims the script was WRITTEN to. The two agree
     * exactly as well as the sizing rate does, and `NarrationPace` is already
     * the thing that measures the disagreement — the same split this class
     * opens by drawing.
     *
     * The direction is the safe one wherever the two differ, which is every
     * shipped story. `bestKnownWpm()` takes the HIGHEST measured rate on the
     * locale, so a sizing rate sits at or below the reading rate: story 21 is
     * frozen at 160, so twenty seconds buys 53 words, and its narrator reads 53
     * words in 16.0 seconds. Measured against that story's real audio, 53 words
     * lands mid-scene-2 and scene 2 ends at 00:20.1. Sized short, plays early.
     */
    public static function wordsForSeconds(Story $story, float $seconds): int
    {
        return max(1, (int) round($seconds / 60 * self::wpmFor($story)));
    }

    /**
     * The runtime the hook has to state the betrayal inside.
     *
     * Config rather than a constant here for the reason every tunable in
     * `render.php` is: it is a number about the format, and the format is what
     * moves. The five beats are prose in the prompt; this is the only one of
     * them with arithmetic behind it, so it is the only one that can drift.
     */
    public static function hookBetrayalSeconds(): float
    {
        return (float) config('render.script.hook_betrayal_seconds', 20);
    }

    /** That runtime as a word budget for this story. */
    public static function hookBetrayalWords(Story $story): int
    {
        return self::wordsForSeconds($story, self::hookBetrayalSeconds());
    }

    /** The whole-script word budget, from the story's own runtime target. */
    public static function targetWords(Story $story): int
    {
        return (int) round(self::midpointMinutes($story) * self::wpmFor($story));
    }

    /** That budget split across the acts. */
    public static function targetWordsPerAct(Story $story, int $actCount): int
    {
        return (int) round(self::targetWords($story) / max(1, $actCount));
    }

    /**
     * The runtime a word count implies, at the best rate we have measured.
     *
     * NOT the frozen sizing rate, and that is the whole point of reporting it:
     * story 9 was sized at 160, so its own rate says 5,781 words run 36.1
     * minutes, and the narrator says 29.3. The second one is what happened.
     * Anything reporting a runtime to an operator wants the narrator's rate.
     *
     * NOT `expectedWpm()` either, which was the first version and was wrong in
     * a way a test caught: a story carrying an unmeasured voice id would have
     * been sized at 197 and estimated at 160 — two beliefs about one narration,
     * inside the change written to end exactly that. Every story from 3 to 12
     * carries `narrator-us-01`, so it was live rather than theoretical.
     */
    public static function minutesFor(Story $story, int $words): float
    {
        return $words / max(1, NarrationPace::bestKnownWpm($story->voice_id, $story->locale_profile));
    }

    /**
     * What the writer is measured to actually produce per act.
     *
     * The design point and the projection are two different questions and this
     * is the second one. `targetWords()` says what the script is ASKED for;
     * this says what comes back, which across five observations is ~1,100 words
     * whatever the ask was — fitted slope +0.30, so a hundred more words asked
     * buys about thirty.
     *
     * One measured act stands behind it. See config/render.php -> script.
     */
    public static function naturalActWords(): int
    {
        return max(1, (int) config('render.script.measured_act_words', 1123));
    }

    /** How far the target moves the writer, for a page that has to say so. */
    public static function targetResponseSlope(): float
    {
        return (float) config('render.script.target_response_slope', 0.30);
    }

    /** Where the measured act length came from, so the figure travels with it. */
    public static function naturalActWordsMeasuredOn(): ?string
    {
        $on = config('render.script.measured_act_words_on');

        return is_string($on) && $on !== '' ? $on : null;
    }

    /**
     * The word count this many acts is projected to actually come back at.
     *
     * NOT the target. A story asking 6,738 words across six acts is projected
     * to write 6,738 because the projection is act-count driven — the two
     * numbers coincide only when the target happens to equal the natural
     * length, and the whole point of showing both is that they usually do not.
     */
    public static function projectedWords(int $actCount): int
    {
        return self::naturalActWords() * max(1, $actCount);
    }

    /** And the runtime that projection implies, at the narrator's own rate. */
    public static function projectedMinutes(Story $story, int $actCount): float
    {
        return self::minutesFor($story, self::projectedWords($actCount));
    }

    /** Whether that runtime lands inside the story's own target window. */
    public static function withinWindow(Story $story, float $minutes): bool
    {
        return $minutes >= $story->target_duration_min && $minutes <= $story->target_duration_max;
    }

    private static function midpointMinutes(Story $story): float
    {
        return ($story->target_duration_min + $story->target_duration_max) / 2;
    }
}
