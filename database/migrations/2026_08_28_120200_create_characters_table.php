<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Character consistency across 150-250 stills is the single biggest quality
 * risk in this format, and it gets worse the longer the video runs.
 *
 * The mechanism is a locked seed plus a stored reference image per character,
 * both of which live here so every image prompt for a scene can be built from
 * the same source of truth rather than from whatever the last prompt said.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('characters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('story_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->text('description')->nullable();

            // Locked per character, per story. Null until a generator assigns
            // one; once set it should not change mid-story.
            $table->unsignedBigInteger('seed')->nullable();

            $table->string('reference_image_path')->nullable();
            $table->text('style_notes')->nullable();

            $table->timestamps();

            // Names are how characters are referenced inside prompts, so two
            // characters sharing one in a story is a prompt bug waiting.
            $table->unique(['story_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('characters');
    }
};
