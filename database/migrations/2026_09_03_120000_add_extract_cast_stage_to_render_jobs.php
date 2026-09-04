<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `extract_cast`, so a refused cast extraction can be recorded.
 *
 * The stage existed in the pipeline from the beginning and had no row of its
 * own. Nothing noticed until three dispatches of story 21 died inside it: the
 * job that runs it opens no row, `DraftScenes` opens its own and never got that
 * far, and `DraftSceneListJob::failed()` looks for a `draft_scenes` row to mark
 * failed and found none. So the render page showed `outline` succeeded,
 * `act_scripts` succeeded, and then nothing at all — for three terminal
 * failures and $0.35 of billed calls.
 *
 * That is the false-success pattern with money attached: a stage that never ran
 * leaves no failure row, and absence reads as agreement.
 *
 * Values are written out literally rather than read from the enum. The original
 * migration calls `RenderStage::cases()`, which means its meaning changes every
 * time a case is added — a migration that is never edited but does not say the
 * same thing twice. This one says what it does, and the list is the enum as of
 * this change.
 */
return new class extends Migration
{
    private const STAGES = [
        'outline', 'act_scripts', 'extract_cast', 'draft_scenes',
        'images', 'scene_narration', 'scene_timings',
        'scene_clips', 'concat', 'subtitles', 'mux', 'purge',
        'deliver', 'metadata',
    ];

    public function up(): void
    {
        Schema::table('render_jobs', function (Blueprint $table) {
            $table->enum('stage', self::STAGES)->change();
        });
    }

    public function down(): void
    {
        Schema::table('render_jobs', function (Blueprint $table) {
            $table->enum('stage', array_values(array_diff(self::STAGES, ['extract_cast'])))->change();
        });
    }
};
