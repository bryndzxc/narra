<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The composed thumbnails, and which one the operator picked.
 *
 * Gate 4 has handed over overlay text and a recommended still since Phase 1,
 * and composing them into an actual image was somebody opening an image editor
 * for every video. The sheet named the parts and produced no thumbnail, which
 * is a smaller version of the shape this project keeps finding: the app
 * describing work rather than doing it.
 *
 *   thumbnail_options   The compositions, as generated: the key, the two scenes
 *                       each panel came from, the workspace path, the score
 *                       that ranked it and the reasons behind that score.
 *                       Stored rather than recomputed because the operator's
 *                       pick has to keep meaning the same thing after a page
 *                       reload — and because the reasons are what make a bad
 *                       ranking visibly bad rather than merely an odd order.
 *
 *   thumbnail_selected  The chosen key. Picked at Gate 4 the way a title is,
 *                       and the selection is what gets copied to the delivery
 *                       folder beside the video as <slug>.jpg.
 *
 * `thumbnail_scene_id` stays exactly what it was: the single still the operator
 * flagged as representative. A composition is a PAIR, so it could not have gone
 * in that column without the column meaning two things.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('youtube_metadata', function (Blueprint $table) {
            $table->json('thumbnail_options')->nullable()->after('thumbnail_text_options');
            $table->string('thumbnail_selected', 32)->nullable()->after('thumbnail_options');
        });
    }

    public function down(): void
    {
        Schema::table('youtube_metadata', function (Blueprint $table) {
            $table->dropColumn(['thumbnail_options', 'thumbnail_selected']);
        });
    }
};
