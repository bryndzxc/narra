<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The first thirty seconds, which nothing had ever asked a question about.
 *
 * ---------------------------------------------------------------------------
 * THE MEASUREMENT THIS COMES FROM
 * ---------------------------------------------------------------------------
 *
 * `scenes.is_hook` has existed since Phase 1. It marks WHICH scene the hook is
 * and has never once asked whether that scene does the job — and the outline
 * had no hook field at all, so the act 1 call received no instruction about the
 * opening beyond "do not open with scene-setting".
 *
 * Both shipped stories were then measured against the five beats this niche's
 * openings actually run. **Four of the five already exist in both stories, and
 * every one of them is two to seven minutes late.**
 *
 *   beat                        rent-will (12)   my-wife (21)   wanted
 *   -------------------------   --------------   ------------   --------
 *   betrayal dramatised         never (>=40 sc)  never          <= 0:20
 *   antagonist's exact words    6:44             2:08           <= 0:40
 *   small cold action           3:24             3:09           <= 0:40
 *   the promise                 exposure         exposure 3:35  departure
 *
 * Story 21 has the best cold action in the database — *I said, "Have a good
 * trip. I'll take you to the airport."* — at 3:09. Story 12 has the best cold
 * action of its own — *"Then I opened a spreadsheet and named it MOM EXPENSES
 * 2020"* — at 3:24. Neither story is missing the material and neither writer
 * failed an instruction.
 *
 * **That is the finding, and it decides what this column is for.** A writer
 * with no instruction about where the opening starts writes the chronological
 * beginning, because context is what you get by default: story 12 opens on a
 * pot boiled black on a stove in March 2020, and story 21 opens on the square
 * meterage of an apartment. This is not a capability that has to be added. It
 * is an instruction that was never given.
 *
 * ---------------------------------------------------------------------------
 * WHAT THE COLUMN HOLDS
 * ---------------------------------------------------------------------------
 *
 * The opening, as five beats:
 *
 *   1. ONE sentence of setup. Not two, and never a sentence about the
 *      structure of the video.
 *   2. The betrayal stated inside the first ~20 seconds of narration. The
 *      seconds are converted to words in exactly one place —
 *      `ScriptSizing::wordsForSeconds()` — at the story's own frozen sizing
 *      rate, which is the rate the act's word target is derived from. Two
 *      rates in one prompt is the $2.12 / $4.24 / 42,017 shape.
 *   3. Evidence in EXACT WORDS. `antagonist_justification` is where this
 *      already lives on every story here and it is used nowhere in either
 *      opening: story 12 has no antagonist speech at all until 6:44.
 *   4. One small, cold action by the narrator. Not a confrontation — the
 *      confrontation is act 6, and spending it here spends the video.
 *   5. A closing line promising the DEPARTURE.
 *
 * Beat 5 is the one Gate 1 checks, by overlap against `departure`, the way
 * `refusal` is checked against the moments it answers. A hook promising
 * revenge on a story whose payoff is a refusal is selling the wrong video —
 * the same mismatch class as the reversal phase, not a missing field.
 *
 * ---------------------------------------------------------------------------
 * COPYING, NOT MOVING
 * ---------------------------------------------------------------------------
 *
 * Beat 3 draws on the antagonist's justification; it does not take it. In this
 * genre the same line lands twice — once in the first thirty seconds as the
 * bait, and again in act 2 or 3 played out at length in the room it was said
 * in — and the second landing is stronger for the first. The prompt says draws
 * on, and says the act keeps it, because a generator told to "use it in the
 * hook" will dutifully spend it and leave act 2 paraphrasing itself.
 *
 * ---------------------------------------------------------------------------
 * NULLABLE, AND WHAT NULL MEANS
 * ---------------------------------------------------------------------------
 *
 * Nullable like the seven before it, because a story exists before its outline
 * does. Four stories carry a null it can never mean anything about — 9, 12, 20
 * and 21 were outlined before this field existed, and before the reversal
 * phase existed, so they have no `departure` for a hook to promise either.
 * They get ONE warning naming what is missing, joining the reversal sentence,
 * rather than a fresh per-field problem on a shipped video. See
 * ValidateOutlineSpine::predatesReversalPhase().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stories', function (Blueprint $table) {
            // First in narrative order: the hook is the first thirty seconds
            // and the grievance is what it opens in the middle of.
            $table->text('hook')->nullable()->after('cast_age_profile');
        });
    }

    public function down(): void
    {
        Schema::table('stories', function (Blueprint $table) {
            $table->dropColumn('hook');
        });
    }
};
