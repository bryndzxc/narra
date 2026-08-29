<?php

use App\Enums\AssetStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One narration track for a story, in one language.
 *
 * Exactly one row is ever written in Phase 0 and Phase 1. The table is plural
 * from day one because localisation — one render, several audio tracks — is the
 * highest-leverage later feature, and retrofitting N tracks onto a schema built
 * for one is a migration nightmare rather than a feature.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audio_tracks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('story_id')->constrained()->cascadeOnDelete();

            $table->string('language', 16)->default('en-US');
            $table->string('voice_id')->nullable();

            // The full-length narration, concatenated from scene_audio in PCM.
            $table->string('audio_path')->nullable();

            // Word-level, whole-story timeline. Assembled from per-scene timings
            // shifted by each scene's offset; the per-scene rows stay the source
            // of truth so one bad sentence re-bills one scene.
            $table->longText('timings_json')->nullable();

            $table->unsignedInteger('duration_ms')->nullable();

            $table->enum('status', array_column(AssetStatus::cases(), 'value'))
                ->default(AssetStatus::Pending->value);

            $table->timestamps();

            $table->unique(['story_id', 'language']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audio_tracks');
    }
};
