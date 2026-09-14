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
     * How many words one chapter is meant to run, at this story's own rate.
     *
     * Through `wordsForSeconds()` for the reason the hook budget is: it rides
     * in the same act prompt as the act's word target, and a chapter budget
     * derived from a different rate than the act budget would be two beliefs
     * about one narration inside a single string.
     */
    public static function chapterTargetWords(Story $story): int
    {
        return self::wordsForSeconds($story, (float) config('chapters.target_seconds', 150));
    }

    /**
     * How many chapters an act of this length is expected to come back as.
     *
     * A projection for the prompt, not a bound — the bounds are
     * `chapters.min_per_act` and `max_per_act`, enforced after the call. The
     * writer is told the number its own target divides into, and told the
     * bounds beside it, because a 985-word ask that comes back at 1,100 (the
     * measured natural length) rounds from two chapters toward three, and
     * the writer is the only thing that knows which it wrote.
     */
    public static function chaptersPerAct(Story $story, int $targetWords): int
    {
        $min = max(1, (int) config('chapters.min_per_act', 2));
        $max = max($min, (int) config('chapters.max_per_act', 4));

        $projected = (int) round($targetWords / max(1, self::chapterTargetWords($story)));

        return max($min, min($max, $projected));
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
     * ---------------------------------------------------------------------
     * IT WAS NEVER A PROPERTY OF THE WRITER. IT IS A FUNCTION OF THE CHAPTER
     * COUNT, AND IT READ AS SETTLED BECAUSE NOBODY HAD VARIED THAT.
     * ---------------------------------------------------------------------
     *
     * For a phase this was "~1,100 words almost regardless of what the prompt
     * asks for" — a sentence about the MODEL, written from five observations
     * that varied the word target from 800 to 1,120 and found a slope of
     * +0.30. Every one of those five acts came back as one or two chapters,
     * because until 2026-09-13 the prompt STATED the chapter count and the
     * writer obeyed it absolutely. The variable was pinned in every
     * observation, so the constant looked like a law.
     *
     * Story 31 unpinned it — the count is derived by the writer from the
     * length it actually wrote — and the same premise, the same outline and
     * the same model returned three chapters per act and 1,473 words. **29%,
     * from a term nobody knew was in the expression.**
     *
     *     2 chapters/act   1,123 words
     *     3 chapters/act   1,473 words
     *
     * **A CONSTANT THAT IS ACTUALLY A FUNCTION OF SOMETHING NOBODY VARIED IS
     * THE SAME SHAPE AS A CHECK THAT CANNOT FIRE.** Both look settled for the
     * same reason: nothing has ever moved them, so nothing has ever disagreed
     * with them, and an absence of disagreement reads as confirmation. This
     * file's most repeated sentence is that absence is read as agreement, and
     * this is that sentence pointed at a measurement instead of at a guard —
     * five observations, all agreeing, all blind to the same held variable.
     *
     * Two points do not fit a line and none is fitted. What is recorded
     * instead is the CONDITION: `naturalActWordsMeasuredAtChapters()`, beside
     * the figure, so it cannot be read as unconditional again — and
     * `assertMeasurementStillHolds()` compares it against the configured
     * chapter budget, so moving `chapters.target_seconds` without
     * re-measuring goes red rather than silently invalidating every runtime
     * projection the console shows.
     *
     * See config/render.php -> script.
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

    /**
     * How many chapters per act the measured length was measured under.
     *
     * The condition the figure is only true given. See `naturalActWords()`
     * for why it exists: the length is a function of this, and for a phase
     * nobody knew it because this never moved.
     */
    public static function naturalActWordsMeasuredAtChapters(): int
    {
        return max(1, (int) config('render.script.measured_act_words_chapters', 3));
    }

    /**
     * Whether the measured act length is still being used under the
     * conditions it was measured in.
     *
     * The chapter budget and the measured act length are coupled and nothing
     * said so: `chapters.target_seconds` decides how many chapters an act of
     * a given length divides into, and the chapter count is a term in the
     * length. Move the budget without re-measuring and every runtime
     * projection on the console is quietly wrong — story 31's own numbers
     * would have read 25% short.
     *
     * So this is the disagreement made visible. It does not refuse anything
     * and it is not a guard on a call path; it is asserted by test, because
     * the failure it names is "somebody changed a config value and the
     * measurement beside it is now about a different world", which is a
     * thing to be told at build time rather than at dispatch.
     */
    public static function measurementStillHolds(Story $story): bool
    {
        return self::chaptersPerAct($story, self::naturalActWords())
            === self::naturalActWordsMeasuredAtChapters();
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
