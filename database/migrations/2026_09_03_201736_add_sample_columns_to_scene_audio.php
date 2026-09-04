<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The true length of a scene's narration, in the only unit that does not lose
 * anything: samples, plus the rate they were recorded at.
 *
 * `duration_ms` was the pipeline's idea of how long a scene was, and it is
 * lossy by an amount that matters. 360002 samples at 24 kHz is 15.0000833 s;
 * stored as the integer 15000 it becomes exactly 450 frames, and the audio
 * needs 451. Padding turns into a trim and the ceil() guarantee the whole
 * render rests on is gone — see App\Support\AudioFrames.
 *
 * Nullable rather than backfilled here, on purpose. A migration must not open
 * 270 audio files, and a value invented from the column it is replacing would
 * carry the same rounding it exists to remove. The rows fill themselves: the
 * narration action writes both at generation, and the clip job probes and
 * persists once for any row that predates this.
 *
 * `duration_ms` stays. It is the honest raw-audio duration and the pace guard,
 * the estimates and the operator pages all read it. What it must never again be
 * is an input to a frame or sample computation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scene_audio', function (Blueprint $table): void {
            $table->unsignedBigInteger('samples')->nullable()->after('duration_ms');
            $table->unsignedInteger('sample_rate')->nullable()->after('samples');
        });
    }

    public function down(): void
    {
        Schema::table('scene_audio', function (Blueprint $table): void {
            $table->dropColumn(['samples', 'sample_rate']);
        });
    }
};
