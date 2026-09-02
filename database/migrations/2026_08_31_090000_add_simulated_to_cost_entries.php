<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mark which cost rows came from a stand-in rather than a vendor.
 *
 * `cost_entries` exists to answer "what did this video cost", and for one run
 * it could not: a story whose scene assets were generated entirely by fakes
 * carried $8.12 of asset spend that no vendor had ever billed, because the
 * fakes priced themselves from a config rate card so that fixture runs produced
 * a "realistic" breakdown.
 *
 * Fakes now report $0.00, which fixes the sum. This column fixes the other
 * half: a $0.00 row from a fake and a $0.00 row from a genuinely free real call
 * are different facts, and only one of them means "nothing left this machine".
 * With it, the true spend is `where simulated = 0` and stays true no matter
 * what a future stand-in is named.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cost_entries', function (Blueprint $table): void {
            $table->boolean('simulated')->default(false)->after('provider');
            $table->index(['story_id', 'simulated']);
        });
    }

    public function down(): void
    {
        Schema::table('cost_entries', function (Blueprint $table): void {
            $table->dropIndex(['story_id', 'simulated']);
            $table->dropColumn('simulated');
        });
    }
};
