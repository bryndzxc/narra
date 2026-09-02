<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Who made this audio, and these timings.
 *
 * Added because "already generated" turned out to be under-specified the moment
 * a second implementation of either contract existed.
 *
 * Idempotency here is keyed on the narration TEXT — a scene whose words have not
 * changed since Gate 2 keeps its audio and is not re-billed, which is exactly
 * right and is what stops a resumed batch costing a second video. But it makes
 * no distinction between audio produced by a vendor and audio produced by a
 * stand-in. A story narrated entirely by FakeSpeechSynthesizer — 186 silent
 * WAVs — reports zero scenes outstanding, so binding a real provider and
 * pressing Generate assets does nothing at all, silently.
 *
 * That is the same failure the ProviderIdentity contract was written for, one
 * layer along: the app cannot tell what actually produced an artefact, so it
 * answers a question about the past using a fact about the present. The fix is
 * the same too — record what ran, on the row, at the time it ran.
 *
 * Nullable throughout, and the null is meaningful: it is "provenance unknown",
 * which is a third state and not a synonym for either provider. The staleness
 * rule treats unknown as NOT stale, following the principle already written into
 * Scene::narrationChanged() — never destroy an asset because its provenance is
 * unknown, only because it demonstrably changed. The backfill below is what
 * turns unknown into known for rows that already exist.
 *
 * Narration and timings are separate columns on one row because they are two
 * different providers writing to the same record: ElevenLabs makes the audio,
 * WhisperX times it, and either can be swapped without the other.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scene_audio', function (Blueprint $table) {
            $table->string('narration_provider')->nullable()->after('audio_path');
            $table->string('narration_voice_id')->nullable()->after('narration_provider');
            $table->boolean('narration_simulated')->nullable()->after('narration_voice_id');

            $table->string('timings_provider')->nullable()->after('timings_json');
            $table->boolean('timings_simulated')->nullable()->after('timings_provider');
        });

        $this->backfill();
    }

    /**
     * Recover the provenance of rows that already exist, from the ledger.
     *
     * `cost_entries` has recorded provider and `simulated` on every call since
     * the money guard was added, so the answer is already in the database — it
     * simply was not on the asset row. This reads it back per story, which is
     * the granularity that is actually reliable: a story's narration is
     * generated in one batch by one bound provider, and a story whose entries
     * disagree is left null rather than guessed at.
     *
     * Nothing is invented. A story with no narration cost rows at all keeps
     * null, and null preserves the asset.
     */
    private function backfill(): void
    {
        foreach ([
            ['synthesize_speech', 'narration_provider', 'narration_simulated'],
            ['transcribe', 'timings_provider', 'timings_simulated'],
            ['align_timings', 'timings_provider', 'timings_simulated'],
        ] as [$operation, $providerColumn, $simulatedColumn]) {
            $stories = DB::table('cost_entries')
                ->select('story_id', 'provider', 'simulated')
                ->where('operation', $operation)
                ->groupBy('story_id', 'provider', 'simulated')
                ->get()
                ->groupBy('story_id');

            foreach ($stories as $storyId => $rows) {
                // Disagreement means the story was narrated by more than one
                // provider and per-story is the wrong granularity for it. Left
                // unknown deliberately: a wrong answer here either re-bills a
                // paid asset or preserves a placeholder, and both are worse than
                // saying nothing.
                if ($rows->count() !== 1) {
                    continue;
                }

                $row = $rows->first();

                DB::table('scene_audio')
                    ->whereIn('scene_id', DB::table('scenes')->select('id')->where('story_id', $storyId))
                    ->update([
                        $providerColumn => $row->provider,
                        $simulatedColumn => (bool) $row->simulated,
                    ]);
            }
        }

        // The voice is on the story, not in the ledger, so it comes from there.
        // Only for rows whose narration provenance was just established — a row
        // with an unknown provider must not gain a known voice, or the pair
        // would read as half-recorded and the staleness rule reads both.
        DB::table('scene_audio')
            ->whereNull('narration_voice_id')
            ->whereNotNull('narration_provider')
            ->update([
                'narration_voice_id' => DB::raw(
                    '(select s.voice_id from scenes sc join stories s on s.id = sc.story_id '
                    .'where sc.id = scene_audio.scene_id)'
                ),
            ]);
    }

    public function down(): void
    {
        Schema::table('scene_audio', function (Blueprint $table) {
            $table->dropColumn([
                'narration_provider',
                'narration_voice_id',
                'narration_simulated',
                'timings_provider',
                'timings_simulated',
            ]);
        });
    }
};
