<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When a published story's working assets were deliberately deleted.
 *
 * Without this, a cleared story is indistinguishable from a broken one. The
 * stills are gone and `scenes.image_path` is null, so every surface that asks
 * "does this scene have a picture" answers no — correctly — and the page then
 * reads as a story whose assets failed to generate. That is the false-success
 * shape with the sign flipped: a true reading presented as a problem, on a
 * console whose whole job is to say what needs doing.
 *
 * Same argument as `stories.is_fixture`, which exists so three parked stories
 * stop being counted as outstanding work: a state that is deliberate has to
 * SAY it is deliberate, where somebody would go looking.
 *
 * Two columns rather than one json blob, because both are read directly on a
 * page and neither wants parsing: the date the operator can check against
 * their upload, and the figure that answers "was it worth it".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stories', function (Blueprint $table): void {
            $table->timestamp('assets_cleared_at')->nullable()->after('approved_scene_digest');
            $table->unsignedBigInteger('assets_cleared_bytes')->nullable()->after('assets_cleared_at');
        });
    }

    public function down(): void
    {
        Schema::table('stories', function (Blueprint $table): void {
            $table->dropColumn(['assets_cleared_at', 'assets_cleared_bytes']);
        });
    }
};
