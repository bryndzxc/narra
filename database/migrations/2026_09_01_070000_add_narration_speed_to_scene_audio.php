<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The speed a scene was read at, as part of its provenance.
 *
 * Provenance already recorded WHO read a scene — provider and voice — and that
 * closed the hole where placeholder audio was indistinguishable from real. It
 * did not record HOW, and the first time the speed changed that gap showed:
 * sixty-nine scenes read at 1.0 were about to be kept alongside a hundred and
 * seventeen read at 0.9, because provider and voice matched and the text had
 * not changed. Same narrator, same words, two different tempos, in one video.
 *
 * That is the same failure as two different narrators, and it is invisible to
 * every check that existed — the ledger balances, the fingerprints match, the
 * files are all real audio from a real vendor.
 *
 * Backfilled to 1.0 for existing ElevenLabs rows, which is what the synthesizer
 * was configured at when they were generated. That is a fact about the past, and
 * it is what makes the sixty-nine correctly stale the moment the speed moves.
 * Simulated rows keep null: a stand-in has no tempo, and those rows are stale on
 * provider alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scene_audio', function (Blueprint $table) {
            // decimal, not float. The comparison is an equality test against a
            // config value, and a float that reads back as 0.9000000000001
            // would restale every scene in the story on every run.
            $table->decimal('narration_speed', 3, 2)->nullable()->after('narration_voice_id');
        });

        DB::table('scene_audio')
            ->whereNull('narration_speed')
            ->where('narration_simulated', false)
            ->update(['narration_speed' => 1.00]);
    }

    public function down(): void
    {
        Schema::table('scene_audio', function (Blueprint $table) {
            $table->dropColumn('narration_speed');
        });
    }
};
