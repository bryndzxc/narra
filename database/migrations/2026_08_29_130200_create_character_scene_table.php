<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which characters are in which frame.
 *
 * This was already being computed and then thrown away. DraftScenes resolved
 * the names a scene's frame mentioned against the stored cast, used them to
 * paste the right descriptions into the image prompt, and kept nothing — the
 * only surviving record of who is in scene 147 was the prose of the prompt
 * itself.
 *
 * That was survivable while a prompt was the only consumer. It stops being
 * survivable the moment a reference image has to be attached per character,
 * because the rule there is that a scene featuring a character with no
 * reference on file must fail loudly rather than quietly generate a face from
 * text. Answering "which characters does this scene feature" by grepping the
 * prompt for names would make the loudest guarantee in the feature depend on
 * substring matching against operator-editable text.
 *
 * So presence becomes data. It is written when scenes are drafted, backfilled
 * for stories drafted before this table existed, and read by the reference
 * resolver and by the Gate 2 approval check.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('character_scene', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scene_id')->constrained()->cascadeOnDelete();
            $table->foreignId('character_id')->constrained()->cascadeOnDelete();

            $table->timestamps();

            // A character is in a frame or is not. There is no "twice".
            $table->unique(['scene_id', 'character_id']);

            // "Every scene this character appears in" is the query behind both
            // the cost projection and the staleness check when a reference is
            // re-picked after stills already exist.
            $table->index('character_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('character_scene');
    }
};
