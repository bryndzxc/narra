<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What text this audio was actually made from.
 *
 * ---------------------------------------------------------------------------
 * THE GAP THIS CLOSES
 * ---------------------------------------------------------------------------
 *
 * `scene_audio` recorded WHO made the audio — provider, voice, speed, whether
 * the provider was a stand-in — and never WHAT WORDS were sent. So the only
 * thing that could speak to whether a file still matches its scene was
 * `scenes.approved_narration_hash`, which is a record of what the OPERATOR
 * signed off on, not a fact about the artifact.
 *
 * `ApproveScenesGate::clearStalePaidAssets()` therefore asked "has the text
 * changed since approval?" when the question it needed was "was this audio made
 * from this text?". Those agree right up until somebody repairs the text and
 * regenerates the audio BEFORE re-approving — at which point the approval record
 * is the stale thing, the audio is current, and the gate discards four freshly
 * paid files for being exactly right.
 *
 * That is the same axis distinction this project keeps finding: a claim about
 * the RECORD standing in for a claim about the ARTIFACT.
 *
 * Nullable, and null means UNKNOWN. It is never read as "matches" and never as
 * "differs" — see `clearStalePaidAssets()`, where an unknown row falls back to
 * exactly the behaviour it had before this column existed, so adding it discards
 * nothing that would not already have been discarded. A column whose absence
 * caused a purge would be a worse defect than the one it fixes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scene_audio', function (Blueprint $table): void {
            // sha256 hex, the same shape Scene::fingerprint() produces.
            $table->string('narration_text_hash', 64)->nullable()->after('narration_simulated');
        });
    }

    public function down(): void
    {
        Schema::table('scene_audio', function (Blueprint $table): void {
            $table->dropColumn('narration_text_hash');
        });
    }
};
