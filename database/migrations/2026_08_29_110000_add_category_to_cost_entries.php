<?php

use App\Enums\CostCategory;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Separates "this cost money" from "this committed a paid asset".
 *
 * CostEntry's guard asserted the story had passed Gate 2 before ANY row could
 * be written. That enforced the money line correctly and, in doing so, made
 * "every paid API call writes a row" unachievable for the text stages: script
 * generation runs at `draft` through `scripted` and bills real tokens, so it
 * had to either skip its row or crash.
 *
 * Gate 2 was never about money in general — it is about 150-250 stills plus
 * per-scene TTS committed before a human has read a scene. Outline tokens
 * spent to produce the text the operator reviews at Gate 1 are the opposite of
 * that mistake. See App\Enums\CostCategory.
 *
 * Existing rows are all asset spend: nothing could have been written before
 * Gate 2, which is precisely the bug being fixed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cost_entries', function (Blueprint $table) {
            $table->enum('category', array_column(CostCategory::cases(), 'value'))
                ->default(CostCategory::Asset->value)
                ->after('operation');
        });

        DB::table('cost_entries')->update(['category' => CostCategory::Asset->value]);

        Schema::table('cost_entries', function (Blueprint $table) {
            // "What did this video cost, and where did it go" is the question
            // this table exists to answer, and after Phase 2 the first cut of
            // that answer is text-vs-assets.
            $table->index(['story_id', 'category']);
        });
    }

    public function down(): void
    {
        Schema::table('cost_entries', function (Blueprint $table) {
            $table->dropIndex(['story_id', 'category']);
            $table->dropColumn('category');
        });
    }
};
