<?php

use App\Enums\MotionPreset;
use App\Enums\SceneStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One still, one line of narration, one clip. 150-250 of these per video.
 *
 * Everything an operator edits at Gate 2 is here, and everything paid for
 * afterwards hangs off it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scenes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('story_id')->constrained()->cascadeOnDelete();
            $table->foreignId('act_id')->constrained()->cascadeOnDelete();

            $table->unsignedSmallInteger('sequence');

            /** The 15-second opening. Its own flag because it is written differently. */
            $table->boolean('is_hook')->default(false);

            // Flagged by the operator at Gate 2. The metadata module recommends
            // this still for the thumbnail; the app does not compose the image.
            $table->boolean('is_thumbnail_candidate')->default(false);

            $table->longText('narration_text');

            // Reaches the filesystem eventually, and reaches a paid API before
            // that. Treated as hostile input everywhere it is used — sanitised
            // to a slug for filenames, never passed raw as a path.
            $table->longText('image_prompt')->nullable();

            $table->string('image_path')->nullable();

            // The RAW AUDIO duration, never the clip duration. Clip length is
            // derived — ceil(duration_ms / 1000 * fps) frames — and is never
            // stored twice, because two copies of a duration drift apart.
            $table->unsignedInteger('duration_ms')->nullable();

            $table->enum('motion_preset', array_column(MotionPreset::cases(), 'value'))
                ->default(MotionPreset::ZoomIn->value);

            $table->enum('status', array_column(SceneStatus::cases(), 'value'))
                ->default(SceneStatus::Drafted->value);

            $table->timestamps();

            $table->unique(['story_id', 'sequence']);
            $table->index(['act_id', 'sequence']);

            // The batch page's core question at 200 scenes: which ones failed.
            $table->index(['story_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scenes');
    }
};
