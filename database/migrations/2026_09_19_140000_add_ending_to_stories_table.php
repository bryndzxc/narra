<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `stories.ending` — which of the two endings the last chapter is. See
 * App\Enums\StoryEnding for why it exists and why the operator sets it.
 *
 * A string, not a MySQL ENUM: a case added to the PHP enum needs no migration
 * and cannot drift from the column the way `CostUnit::TotalTokens` did.
 *
 * NOT BACKFILLED, and null is the honest value. Every story outlined before
 * this column was asked for an epilogue and, from story 37, her chapter as
 * well — neither ending, both. Null gets exactly the refusal act it had.
 * `RecentEndings` reads what those stories actually PRODUCED from their
 * chapters, labelled as read from the chapters rather than chosen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stories', function (Blueprint $table): void {
            $table->string('ending', 32)->nullable()->after('antagonist_regret');
        });
    }

    public function down(): void
    {
        Schema::table('stories', function (Blueprint $table): void {
            $table->dropColumn('ending');
        });
    }
};
