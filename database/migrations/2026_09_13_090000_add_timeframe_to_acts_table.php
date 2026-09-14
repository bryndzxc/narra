<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether an act is set in the story's present or before it.
 *
 * ---------------------------------------------------------------------------
 * THE MEASUREMENT
 * ---------------------------------------------------------------------------
 *
 * Four rendered stories, timed off the scene offsets the render produced:
 *
 *   story   runtime   present-day action begins   inciting betrayal staged
 *   -----   -------   -------------------------   ------------------------
 *   21      40:36     0:00                        1:50
 *   25      39:08     1:04                        1:04
 *   23      40:57     10:38                       15:01
 *   28      43:27     17:31                       20:18
 *
 * Story 28 stages the 2015 betrayal in full (2:50-6:21) and the whole of act
 * 2 is the 2017 betrayal (7:47-17:31): 13:15 of staged history, 31% of the
 * video, two of the three escalation acts. The outline allocated it — its act
 * 2 summary reads "Act 2 tells the second betrayal in full" — and nothing at
 * Gate 1 could say that an escalation act was set eight years before the
 * story. Story 23 does the same with the Spring Festival table of that year.
 * Stories 21 and 25 open in the present and stay there, and 25 is the one
 * that works on every measure.
 *
 * ---------------------------------------------------------------------------
 * WHAT THE COLUMN HOLDS
 * ---------------------------------------------------------------------------
 *
 * `present` or `prior`, declared by the outline writer per act. Gate 1
 * refuses an escalation or departure act marked `prior`: a prior incident is
 * cited in one sentence, with its date, inside a present-day act, and the
 * line the antagonist said years ago is staged at its most recent saying.
 * Story 25 is the model — "a wife who earns more" is quoted at 0:18 and
 * staged at 9:17 in a present-day dinner, so the line still lands twice.
 *
 * The value list is written out literally rather than read from the enum. A
 * migration is never edited, and one that reads `ActTimeframe::cases()` would
 * mean something different every time a case was added — that is how
 * `cost_entries.unit` came to differ between two databases on one machine.
 * `SchemaEnumDrift::COLUMNS` names this column so the live column and the
 * enum are compared rather than assumed to agree.
 *
 * ---------------------------------------------------------------------------
 * NULL, AND WHAT IT MEANS
 * ---------------------------------------------------------------------------
 *
 * Nullable, and null is UNKNOWN. Every act on stories 9 through 28 was
 * outlined before the question was asked, and an anthology act has no
 * present to be set in. Gate 1 reports a phase story whose acts are all null
 * once, as an outline that predates the field, and never as present.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('acts', function (Blueprint $table) {
            $table->enum('timeframe', ['present', 'prior'])->nullable()->after('phase');
        });
    }

    public function down(): void
    {
        Schema::table('acts', function (Blueprint $table) {
            $table->dropColumn('timeframe');
        });
    }
};
