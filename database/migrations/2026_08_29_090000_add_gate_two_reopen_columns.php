<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What was approved at Gate 2, so a reopen can tell a changed scene from a
 * scene the operator only read.
 *
 * Reopening Gate 2 after assets exist is the one flow where "regenerate
 * everything" is a real bill — 150-250 images at roughly 70% of a video's cost.
 * The rule is that reopening preserves what was already paid for: only a scene
 * whose paid INPUTS changed may be regenerated.
 *
 * That rules out `updated_at` and Eloquent's isDirty() as the test. Both move
 * when the operator ticks `is_thumbnail_candidate` or switches a motion preset,
 * and neither of those costs a cent to redo. Using them would re-bill every
 * still in the video for a checkbox.
 *
 * So the snapshot is taken at approval and covers exactly the fields a paid
 * provider reads, kept apart because they bill apart:
 *
 *   narration_text -> TTS and transcription     (and the clip, via duration_ms)
 *   image_prompt   -> image generation          (and the clip)
 *   motion_preset  -> the clip only. Free CPU, never a bill.
 *
 * The story-level digest covers add, delete and reorder in one value. Reorder
 * matters more than it looks: clips are named `scene-%03d` from `sequence`, so
 * swapping two scenes leaves each clip filed under the other's number.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scenes', function (Blueprint $table) {
            // sha256, hex. Null means "never approved", which reads the same as
            // "stale" everywhere it is used — a scene that has never been
            // through Gate 2 needs everything generating, which is correct.
            $table->char('approved_narration_hash', 64)->nullable()->after('status');
            $table->char('approved_image_hash', 64)->nullable()->after('approved_narration_hash');

            // Stored as the enum's value rather than hashed: it is one short
            // string, and a mismatch here costs CPU, not money. Keeping it
            // legible means the invalidation reason is readable in the row.
            $table->string('approved_motion_preset', 32)->nullable()->after('approved_image_hash');
        });

        Schema::table('stories', function (Blueprint $table) {
            // sha256 of the ordered id:sequence pairs at approval. Answers only
            // the whole-video question — are the clips still filed under the
            // right numbers, and does the concatenated video still match the
            // scene list. Never a reason to re-bill anything.
            $table->char('approved_scene_digest', 64)->nullable()->after('status');

            // Where a reopen came from, so the confirmation can name what it is
            // about to discard rather than warning in the abstract. Cleared on
            // re-approval.
            $table->string('reopened_from', 32)->nullable()->after('approved_scene_digest');
        });
    }

    public function down(): void
    {
        Schema::table('scenes', function (Blueprint $table) {
            $table->dropColumn(['approved_narration_hash', 'approved_image_hash', 'approved_motion_preset']);
        });

        Schema::table('stories', function (Blueprint $table) {
            $table->dropColumn(['approved_scene_digest', 'reopened_from']);
        });
    }
};
