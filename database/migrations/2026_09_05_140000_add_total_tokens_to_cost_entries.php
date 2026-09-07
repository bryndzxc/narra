<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The column catches up with the enum, and the enum stops writing the column.
 *
 * ---------------------------------------------------------------------------
 * WHAT FAILED
 * ---------------------------------------------------------------------------
 *
 * `CostUnit::TotalTokens` was added in code with no migration. The first real
 * outline call after it — story 23, render job #4703 — ran for 87 seconds,
 * completed, and died writing its cost row:
 *
 *     SQLSTATE[01000]: Warning: 1265 Data truncated for column 'unit' at row 1
 *
 * `RecordProviderCost` runs before the transaction that writes the acts, so the
 * story got no outline either. **Billed spend, no record, no product** — which
 * is non-negotiable #4 failing in the one way it is written to prevent.
 *
 * ---------------------------------------------------------------------------
 * WHY NOBODY WROTE THE MIGRATION
 * ---------------------------------------------------------------------------
 *
 * Because for this column, adding a case really did used to be enough. The
 * create migration builds the MySQL ENUM from the PHP enum:
 *
 *     $table->enum('unit', array_column(CostUnit::cases(), 'value'));
 *
 * **So the schema is a function of the code AT MIGRATION TIME.** Anything that
 * migrates from scratch gets a column derived from today's enum and can never
 * disagree with it. Anything already migrated keeps the column it was given in
 * August. The two databases on this machine had literally different columns:
 *
 *     narra       enum('input_tokens','output_tokens','characters',...)
 *     narra_test  enum('total_tokens','output_tokens','characters',...)
 *
 * That is the whole of why 943 tests, known-answer fixtures and eight drills
 * were green. Not one of them was wrong; they were run against a schema
 * generated from the enum under test, so agreement was guaranteed by
 * construction and proved nothing. Identical in shape to the fake TTS deriving
 * its duration from the constant it was supposed to be checking.
 *
 * **And the finding was already on the record.** The migration three files back
 * — `add_evaluation_category_to_cost_entries`, 2026-09-03 — says it outright:
 * *"The value list is written out literally rather than read from the enum. The
 * two migrations before this one call CostCategory::cases(), which means their
 * meaning changes every time a case is added — a migration that is never edited
 * but does not say the same thing twice."* It was written about `category` and
 * applied only to `category`, in the same table, two days before the next enum
 * change walked into it on `unit`. One instance fixed by hand is not a
 * mechanism, and a documented finding with nothing enforcing it reads as
 * covered.
 *
 * `schema:enum-drift` is the mechanism. It reads the LIVE column rather than
 * the migration that made it, because that is the only number here we did not
 * compute ourselves.
 *
 * ---------------------------------------------------------------------------
 * WHAT THIS DOES
 * ---------------------------------------------------------------------------
 *
 * Adds `total_tokens`. Drops `input_tokens`, which the enum removed and which
 * has 0 rows — checked, not assumed, because dropping a value in use silently
 * truncates every row holding it.
 *
 * `output_tokens` STAYS, and that is not tidiness. 107 rows carry it, holding a
 * total rather than an output count, and `CostUnit` documents what they mean.
 * Dropping the case would make historic rows fail to cast; the ledger is
 * write-once and the mislabel is dated rather than erased.
 *
 * The list is LITERAL. This migration says the same thing in a year as it says
 * today, whatever `CostUnit` grows next.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cost_entries', function (Blueprint $table) {
            $table->enum('unit', [
                'total_tokens',
                'output_tokens',
                'characters',
                'images',
                'audio_seconds',
                'requests',
            ])->change();
        });
    }

    public function down(): void
    {
        // The column as it actually stood in the live database, which is what a
        // rollback has to restore — not what the create migration's source says,
        // since that expression no longer evaluates to what it once did.
        Schema::table('cost_entries', function (Blueprint $table) {
            $table->enum('unit', [
                'input_tokens',
                'output_tokens',
                'characters',
                'images',
                'audio_seconds',
                'requests',
            ])->change();
        });
    }
};
