<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `stories.partner_end_state` — what the narrator and the future partner ARE
 * to each other by the end. See App\Enums\PartnerEndState for why it exists
 * and App\Support\PartnerEnding for the narrow scope it is read in.
 *
 * Story 39 is the instance: the operator's idea said "married her older
 * sister", and the outline's last act came back "Nancy introduces me to a room
 * as her partner" — a faithful rendering of the only words any stage offered,
 * which were "a couple", "together" and "a year on".
 *
 * A string, not a MySQL ENUM: a fifth state needs no migration and cannot
 * drift from the column the way `CostUnit::TotalTokens` did.
 *
 * NOT BACKFILLED, and null is the honest value in two different ways that the
 * column deliberately does not distinguish — outlined before the choice
 * existed, or a partner the outline invented rather than the premise. Neither
 * was ever asked, both get the widened vocabulary, and neither is reported as
 * a missing answer. Gate 1's readout says which one a story is.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stories', function (Blueprint $table): void {
            $table->string('partner_end_state', 32)->nullable()->after('ending');
        });
    }

    public function down(): void
    {
        Schema::table('stories', function (Blueprint $table): void {
            $table->dropColumn('partner_end_state');
        });
    }
};
