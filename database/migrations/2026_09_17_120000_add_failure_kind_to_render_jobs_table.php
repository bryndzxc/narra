<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What kind of failure a row records, and the facts its repair needs.
 *
 * The repair itself is never stored. It is built from these two columns by
 * App\Support\FailureRemedy when the page is read, so an old row is always
 * read beside the current code. See App\Enums\FailureKind.
 *
 * `failure_kind` is a VARCHAR, deliberately not a MySQL ENUM: the row this is
 * written to IS the failure record, and an ENUM that has not caught up with a
 * new case truncates the write — which is how a billed outline lost its ledger
 * row to CostUnit::TotalTokens. For the same reason it is not added to
 * SchemaEnumDrift::COLUMNS: there is nothing to drift.
 *
 * NULL on every row written before this existed, and read as Unclassified.
 * No backfill: at the time of writing one failed row exists (story 21's pace
 * refusal), and guessing a kind from a message would be recording a guess as
 * a fact.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('render_jobs', function (Blueprint $table) {
            $table->string('failure_kind', 64)->nullable()->after('error');
            $table->json('failure_facts')->nullable()->after('failure_kind');
        });
    }

    public function down(): void
    {
        Schema::table('render_jobs', function (Blueprint $table) {
            $table->dropColumn(['failure_kind', 'failure_facts']);
        });
    }
};
