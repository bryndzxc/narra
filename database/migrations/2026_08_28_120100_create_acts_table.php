<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Acts sit between stories and scenes, and that placement is structural.
 *
 * A 30-40 minute script cannot be generated in one call — it drifts, repeats
 * and contradicts itself. It is generated act by act, each call fed the outline
 * plus a running summary of the acts before it. Acts are also exactly what
 * YouTube chapters are built from, so this table solves both problems.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('acts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('story_id')->constrained()->cascadeOnDelete();

            $table->unsignedSmallInteger('sequence');

            // Doubles as the YouTube chapter title. Write it to work as both.
            $table->string('title');

            // Fed to the next act's generation call. This is the mechanism that
            // keeps 7,000 words coherent.
            $table->text('summary')->nullable();

            $table->longText('script')->nullable();

            // A 15-second opening hook is not enough at this length: every act
            // opens with a line engineered to carry the viewer forward. Gate 1
            // surfaces this flag so an unwritten re-hook is visible, not lost.
            $table->boolean('is_rehook_written')->default(false);

            // Filled after the render, from the scene timeline. Null until then
            // — chapters cannot be written before the video exists.
            $table->unsignedInteger('start_ms')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();

            $table->timestamps();

            $table->unique(['story_id', 'sequence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('acts');
    }
};
