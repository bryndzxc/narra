<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Give the 117 rows with no recorded speed the speed they were actually read at.
 *
 * **Why these are NULL when the earlier migration backfilled everything.** That
 * migration ran, and then a queue worker that had booted BEFORE it wrote 117 more
 * rows through a `record()` which, in that worker's loaded code, had no speed
 * column at all. So the NULLs are not rows that predate provenance — they are
 * rows written after it by a process that could not see it.
 *
 * **Why 1.00 is the right value and not a guess.** It was measured from the audio
 * rather than inferred from config. Across all 186 scenes: 5,830 words in
 * 1,775,676 ms, which is 197.0 wpm. Brian at speed 0.9 was measured at 172 wpm;
 * at 1.0 he reads ~197. The 69 rows already stamped 1.00 read 195.9 wpm and these
 * 117 read 197.7 — the same voice at the same tempo, within noise of each other.
 * The provenance split was a recording artefact, never a difference in the audio.
 *
 * **This does not cause a regeneration.** Read alongside ELEVENLABS_SPEED moving
 * to 1.0, it does the opposite: config now matches what is on disk, so
 * `narrationProvenanceStale()` finds nothing stale and no scene is re-billed.
 * That is a deliberate decision recorded here because the alternative was live —
 * re-narrating all 186 at 0.9 would have cost 15,303 credits, the entire
 * remaining monthly allowance, for four minutes of runtime.
 *
 * **The point is honesty, not the re-run.** A NULL here reads as "unknown", and
 * every provenance check in this codebase correctly refuses to act on unknown —
 * so a NULL is preserved, and preserved reads as fine. That is precisely how 117
 * scenes at the wrong speed reported themselves complete. Filling them in means
 * the next time the speed moves, these 117 stale loudly with the other 69 instead
 * of silently passing.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Non-simulated only, matching the earlier backfill. A stand-in has no
        // tempo, and those rows are stale on provider alone.
        DB::table('scene_audio')
            ->whereNull('narration_speed')
            ->where('narration_simulated', false)
            ->whereNotNull('audio_path')
            ->update(['narration_speed' => 1.00]);
    }

    /**
     * Irreversible, deliberately.
     *
     * Down() would have to restore NULLs, and it cannot know WHICH rows were
     * null before this ran — the 117 are indistinguishable from the 69 now, which
     * is the entire point of having run it. Nulling all of them would destroy
     * provenance that was correct before this migration existed.
     */
    public function down(): void
    {
        // Intentionally empty. See the docblock.
    }
};
