<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A filename-safe identity for a story's workspace on disk.
 *
 * Render output lives at `renders/<slug>/`, so this cannot be derived from the
 * title at use time: titles carry quotes, colons and em dashes, they reach the
 * filesystem, and Windows caps a path at 260 characters unless long paths are
 * enabled. Slugging once, at creation, and storing the result means every later
 * consumer gets the same safe string rather than re-deriving it and disagreeing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stories', function (Blueprint $table) {
            $table->string('slug', 64)->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('stories', function (Blueprint $table) {
            $table->dropUnique(['slug']);
            $table->dropColumn('slug');
        });
    }
};
