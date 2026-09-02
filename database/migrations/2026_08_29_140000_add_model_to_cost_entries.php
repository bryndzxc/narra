<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which model a row was billed against.
 *
 * `provider` said "anthropic" and that was enough while the whole pipeline ran
 * on one model. It stopped being enough the moment the four text calls were
 * split across Opus, Sonnet and Haiku: the table could still total a story
 * correctly but could no longer answer the question the split was made to
 * answer — what did moving the acts to Sonnet actually save.
 *
 * The spec's rule is that if "what did this video cost" cannot be answered in
 * one query, the feature is incomplete. A three-model pipeline whose ledger
 * records only the vendor meets the letter of that and not the point of it.
 *
 * It also makes the scene fallback legible. When a cheap model's sentence
 * ranges fail to tile an act, that act writes TWO rows — the discarded Haiku
 * attempt and the Sonnet retry. Without this column those two are
 * indistinguishable from a double-billing bug.
 *
 * Nullable, and left null on the rows already written: they were all Opus, but
 * back-filling a value nothing observed would be inventing data in a ledger.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cost_entries', function (Blueprint $table) {
            $table->string('model')->nullable()->after('provider');

            // "What did each model cost me on this story" is the query this
            // column exists for, so it is the index it gets.
            $table->index(['story_id', 'model']);
        });
    }

    public function down(): void
    {
        Schema::table('cost_entries', function (Blueprint $table) {
            $table->dropIndex(['story_id', 'model']);
            $table->dropColumn('model');
        });
    }
};
