<?php

use App\Enums\MetadataStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A publish sheet whose chapter timestamps describe a render that is gone.
 *
 * Reopening Gate 2 invalidates act timings, and chapters are derived from them.
 * The sheet does not stop existing when that happens — it stays on screen, and
 * it stays copyable, with every timestamp in it now wrong. Gate 4 is the gate
 * whose consequences land outside this app, on YouTube, where a wrong chapter
 * list is not something the app can take back.
 *
 * So the sheet is marked, and `stale_at` records when. Clearing the mark takes
 * a regeneration that happened after a newer successful render — comparing
 * against this timestamp is what makes "regenerated post-render" checkable
 * rather than a promise.
 */
return new class extends Migration
{
    public function up(): void
    {
        // The enum column has to learn the new value before any row can hold
        // it. Raw DDL rather than ->change(): a Blueprint enum change rewrites
        // the column definition from scratch and would silently drop the
        // default that every existing row was created under.
        DB::statement(sprintf(
            "ALTER TABLE `youtube_metadata` MODIFY `status` ENUM(%s) NOT NULL DEFAULT '%s'",
            implode(', ', array_map(
                fn (string $value): string => "'".$value."'",
                array_column(MetadataStatus::cases(), 'value')
            )),
            MetadataStatus::Pending->value
        ));

        Schema::table('youtube_metadata', function (Blueprint $table) {
            $table->timestamp('stale_at')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('youtube_metadata', function (Blueprint $table) {
            $table->dropColumn('stale_at');
        });

        DB::statement("UPDATE `youtube_metadata` SET `status` = 'pending' WHERE `status` = 'stale'");

        DB::statement(sprintf(
            "ALTER TABLE `youtube_metadata` MODIFY `status` ENUM(%s) NOT NULL DEFAULT 'pending'",
            implode(', ', array_map(
                fn (string $value): string => "'".$value."'",
                array_filter(
                    array_column(MetadataStatus::cases(), 'value'),
                    fn (string $value): bool => $value !== 'stale'
                )
            ))
        ));
    }
};
