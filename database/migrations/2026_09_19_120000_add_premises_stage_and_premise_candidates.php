<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The premise generator: a stage to record it, and a column to keep its output.
 *
 * `render_jobs.stage` gains `premises`. Values written out literally, as the
 * extract_cast migration explains: a migration that reads the enum means
 * something different every time a case is added.
 *
 * `stories.premise_candidates` holds the LATEST roll: the idea, whether it was
 * revenge-shaped and what it became, and the three candidates with the fields
 * the checks read. A re-roll replaces it; every roll keeps its own cost row.
 * The checks are NOT stored — Gate 1 recomputes them from these fields when
 * the page is read, so a check changed tomorrow reports on today's candidates
 * in tomorrow's terms, and nothing goes stale.
 *
 * The chosen premise is copied into `stories.premise` as text, which is the
 * artifact the outline reads — the stored-decision rule's passing case, like
 * `title_selected`.
 */
return new class extends Migration
{
    private const STAGES = [
        'premises',
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

        Schema::table('stories', function (Blueprint $table) {
            $table->json('premise_candidates')->nullable()->after('premise');
        });
    }

    public function down(): void
    {
        Schema::table('stories', function (Blueprint $table) {
            $table->dropColumn('premise_candidates');
        });

        Schema::table('render_jobs', function (Blueprint $table) {
            $table->enum('stage', array_values(array_diff(self::STAGES, ['premises'])))->change();
        });
    }
};
