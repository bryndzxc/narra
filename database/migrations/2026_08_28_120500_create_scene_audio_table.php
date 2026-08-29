<?php

use App\Enums\AssetStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-scene narration, and the authoritative timeline arithmetic.
 *
 * Narration is generated per scene rather than as one 40-minute file, so a
 * single bad sentence re-bills one scene instead of the whole video. The
 * pieces are concatenated at mux time.
 *
 * The offset columns carry the rule that keeps a 35-minute video in sync:
 *
 *   frames        = ceil(audio_ms / 1000 * fps)   -- ceil, deliberately
 *   clip_duration = frames / fps                  -- exact, by construction
 *
 * `ceil` is correct BECAUSE the audio is padded to match, which guarantees
 * video >= audio for every scene so padding only ever adds silence — at most
 * one frame, ~33 ms at 30fps, inaudible and distributed rather than pooled.
 *
 * Offsets accumulate in integer frames and samples. Summing rounded
 * milliseconds compounds error scene by scene: without padding, ceil drifts
 * ~+3.3 s over 200 scenes. `offset_ms` is derived from `offset_frames` FOR
 * DISPLAY ONLY and must never be used for timing — the subtitle shift reads
 * `offset_samples`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scene_audio', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scene_id')->constrained()->cascadeOnDelete();
            $table->foreignId('audio_track_id')->constrained()->cascadeOnDelete();

            $table->string('audio_path')->nullable();

            // Word-level, scene-local. Whisper is run per scene: on a 40-minute
            // file it is slow and its word timestamps drift toward the end.
            $table->longText('timings_json')->nullable();

            /** Raw generated audio. */
            $table->unsignedInteger('duration_ms')->nullable();

            /** frames / fps, after padding with silence. Derived, stored for display. */
            $table->unsignedInteger('padded_duration_ms')->nullable();

            $table->unsignedInteger('frames')->nullable();

            // AUTHORITATIVE cumulative start positions, accumulated as integers.
            $table->unsignedInteger('offset_frames')->nullable();

            // Bigint: 40 minutes at 44.1 kHz is ~106M samples, and a series of
            // long videos should not be one schema change from overflowing.
            $table->unsignedBigInteger('offset_samples')->nullable();

            /** Derived from offset_frames. Display only — never timing. */
            $table->unsignedInteger('offset_ms')->nullable();

            $table->enum('status', array_column(AssetStatus::cases(), 'value'))
                ->default(AssetStatus::Pending->value);

            $table->timestamps();

            // One row per scene per track. Re-running a scene updates its row
            // rather than adding a second one — jobs must be idempotent, and a
            // duplicate here would silently double the narration.
            $table->unique(['scene_id', 'audio_track_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scene_audio');
    }
};
