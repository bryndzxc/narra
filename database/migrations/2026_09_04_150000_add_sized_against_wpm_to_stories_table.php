<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The reading rate a story's script was actually written to.
 *
 * ---------------------------------------------------------------------------
 * WHY THIS COLUMN HAS TO EXIST BEFORE THE TARGET MOVES
 * ---------------------------------------------------------------------------
 *
 * `GenerateActScripts::targetWordsPerAct()` reads `render.narration.words_per_minute`
 * live, which answers "what do we believe now". Nothing anywhere answers "what
 * was THIS script sized to", and the two are about to stop agreeing: the
 * constant is the FALLBACK 160, the measured narrator reads 197, and correcting
 * that is the next change.
 *
 * The moment it is corrected, every story written before it becomes
 * unreconstructable. `NarrationPace` would judge story 9's script against 197
 * when it was sized to 160, and there would be nothing in the record able to
 * say so — the guard would be measuring correctly and be certain about the
 * wrong thing, which is a failure this project has already had once and paid
 * for by cancelling a 270-scene batch at scene 2.
 *
 * So the column comes first, the backfill comes second, and the target moves
 * third. That ordering is not tidiness: steps one and two are recoverable and
 * step three is not.
 *
 * ---------------------------------------------------------------------------
 * NULLABLE, AND NULL MEANS UNKNOWN
 * ---------------------------------------------------------------------------
 *
 * Never defaulted to 160. A default would make "nobody recorded this" and "this
 * was sized at 160" the same value, and this codebase's standing rule is that
 * absence must not read as agreement — a NULL provenance column reading as
 * "fine" is row 3 of its own false-success table.
 *
 * NULL is also the CORRECT answer for a story that never had a script
 * generated: `sample-story` carries acts imported from a Phase 0 fixture and
 * was sized against nothing. That row should stay null forever, and a default
 * would have quietly claimed otherwise.
 *
 * Frozen once written, like `locale_profile` and for the same reason: the acts
 * are generated against it, so a value that moved afterwards would describe a
 * script that no longer exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stories', function (Blueprint $table): void {
            // unsignedSmallInteger: a reading rate is two or three digits and
            // cannot be negative. Placed beside the two other things that
            // determine the word budget rather than at the end of the table.
            $table->unsignedSmallInteger('sized_against_wpm')
                ->nullable()
                ->after('target_duration_max');
        });
    }

    public function down(): void
    {
        Schema::table('stories', function (Blueprint $table): void {
            $table->dropColumn('sized_against_wpm');
        });
    }
};
