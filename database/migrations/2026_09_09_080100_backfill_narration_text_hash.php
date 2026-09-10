<?php

use App\Models\Scene;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Backfill the only four rows whose audio can be PROVEN to match its text.
 *
 * ---------------------------------------------------------------------------
 * WHY FOUR AND NOT ALL OF THEM
 * ---------------------------------------------------------------------------
 *
 * Every other row in this table is genuinely unknown. The audio was synthesised
 * before anything recorded the words it was made from, and no evidence exists
 * that could reconstruct it — the file is a WAV, the text has been editable at
 * Gate 2 the whole time, and a hash written from today's text would be an
 * ASSERTION that they match rather than a record that they do. That is exactly
 * the substitution this column exists to end, so it is not made here.
 *
 * These four are different, and the difference is measured rather than argued:
 *
 *   story 25, scenes 169, 180, 193, 201 — re-narrated at 07:32-07:33 on
 *   2026-09-09 from the repaired text, then discarded from the row (not from
 *   disk) by a Gate 2 re-approval whose approval record still described the
 *   pre-repair text.
 *
 * The files were probed with ffprobe and every sample count matches the value
 * that survived in the row:
 *
 *   scene 2711  587,372 samples @ 24 kHz   scene 2735  353,315
 *   scene 2722  235,172                    scene 2743  672,078
 *
 * That is an external reading agreeing with an internal one — the only kind of
 * agreement worth anything here — and it establishes that the WAV on disk is the
 * post-repair render and not the pre-repair one.
 *
 * Scoped by id for that reason. There is no general predicate for "this audio
 * provably matches this text"; if there were, this column would be unnecessary.
 * On any database without these rows it is a no-op.
 *
 * The hash comes from `Scene::fingerprint()` rather than being typed out,
 * because it has to agree with what `needsNarration()` compares against. A
 * schema literal is frozen and a predicate is live: retyping the predicate is
 * the shape that gave one narration three prices.
 */
return new class extends Migration
{
    /** Verified against ffprobe. scene_id => expected sample count. */
    private const PROVEN = [2711 => 587372, 2722 => 235172, 2735 => 353315, 2743 => 672078];

    public function up(): void
    {
        foreach (self::PROVEN as $sceneId => $samples) {
            $scene = Scene::query()->find($sceneId);

            if ($scene === null) {
                continue;
            }

            /*
             * Re-check the evidence at migration time rather than trusting the
             * comment above. If the row no longer carries the sample count this
             * was written for, something has changed since and the claim this
             * backfill is about to make is no longer supported.
             */
            DB::table('scene_audio')
                ->where('scene_id', $sceneId)
                ->where('samples', $samples)
                ->update(['narration_text_hash' => $scene->narrationFingerprint()]);
        }
    }

    public function down(): void
    {
        DB::table('scene_audio')
            ->whereIn('scene_id', array_keys(self::PROVEN))
            ->update(['narration_text_hash' => null]);
    }
};
