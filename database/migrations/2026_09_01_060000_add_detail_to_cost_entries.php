<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The per-call breakdown a provider already reports, finally stored.
 *
 * `ProviderUsage` has carried a `detail` array since the money guard was
 * written — input vs output tokens, the model served by, the references cited,
 * and for TTS the vendor's own `character-cost` response header. Every provider
 * fills it in. `RecordProviderCost` then dropped it on the floor: the insert
 * lists nine columns and `detail` was never one of them.
 *
 * That went unnoticed because nothing had needed it yet. The first time it was
 * needed, it was needed badly — reconciling five real ElevenLabs calls against
 * the account's usage page, which is the one check that tells an operator
 * whether the number this app quotes is the number the vendor charges. The
 * ledger said 168 characters for a 336-character scene and there was no way to
 * tell, from the database, whether that was the vendor's count or ours.
 *
 * It is the same shape as everything else this project keeps finding: a
 * mechanism built in one phase whose consumer arrived in the next.
 *
 * Nullable, and old rows stay null. The detail of a call already made is gone —
 * it lived only in the response.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cost_entries', function (Blueprint $table) {
            // Not a normalised set of columns, deliberately: what a provider
            // reports about a call is provider-shaped and changes when a vendor
            // changes its response. `usd_cost` is the money and it is a real
            // decimal column; this is the audit trail behind it.
            $table->json('detail')->nullable()->after('usd_cost');
        });
    }

    public function down(): void
    {
        Schema::table('cost_entries', function (Blueprint $table) {
            $table->dropColumn('detail');
        });
    }
};
