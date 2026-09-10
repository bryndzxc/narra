<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The locale guidance a story was actually generated against.
 *
 * ---------------------------------------------------------------------------
 * WHY THIS COLUMN HAS TO EXIST BEFORE THE GUIDANCE MOVES
 * ---------------------------------------------------------------------------
 *
 * `LocaleGuard::guidanceFor()` reads `config/locale.php` live, which answers
 * "what do we believe now". Nothing anywhere answers "what was THIS story
 * written against", and the two are about to stop agreeing: the `en-CN` naming
 * convention changes to English given names for the younger cast, and the
 * moment it does, every story outlined before it becomes unreconstructable.
 *
 * The guidance is a string in a config file rather than a value on a row, so an
 * edit to it leaves **no trace whatsoever**. Stories 21 and 23 are the "before"
 * of this change and nothing in the database would mark them as such — a state
 * strictly worse than the `sized_against_wpm` case that prompted this pattern,
 * because there at least the constant was readable in one place.
 *
 * So: the column first, the backfill second, the guidance third. That ordering
 * is not tidiness — steps one and two are recoverable and step three is not.
 *
 * ---------------------------------------------------------------------------
 * NULLABLE, AND NULL MEANS UNKNOWN
 * ---------------------------------------------------------------------------
 *
 * Never defaulted to the current digest. A default would make "nobody recorded
 * this" and "this was written against today's guidance" the same value, and
 * absence reading as agreement is this codebase's most-repeated failure.
 *
 * NULL is also the CORRECT and permanent answer for two real cases. A story
 * with no generated script — `sample-story`, whose acts came from a Phase 0
 * fixture — was written against no guidance at all. And story 21, whose
 * creation predates the only commit that records the `en-CN` text: see the
 * backfill migration, which explains at length why that one is left unknown
 * rather than assumed.
 *
 * ---------------------------------------------------------------------------
 * A DIGEST, AND NOTHING BRANCHES ON IT
 * ---------------------------------------------------------------------------
 *
 * Sixteen hex characters from `LocaleGuard::fingerprintFor()`, the same shape
 * as `StyleFingerprint`. It answers *was this written against what we have
 * now*, and deliberately not *what did it say* — git is for that.
 *
 * This is provenance, not a guard. It is NOT in `StyleFingerprint`, so writing
 * it stales no reference sheet; nothing refuses a dispatch on it and no worker
 * stands down for it. That is what makes a guidance edit cheap, and it is the
 * property most worth not losing later.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stories', function (Blueprint $table): void {
            // char(16), fixed width, beside the profile it is the provenance
            // for rather than at the end of the table.
            $table->char('locale_guidance_fingerprint', 16)
                ->nullable()
                ->after('locale_profile');
        });
    }

    public function down(): void
    {
        Schema::table('stories', function (Blueprint $table): void {
            $table->dropColumn('locale_guidance_fingerprint');
        });
    }
};
