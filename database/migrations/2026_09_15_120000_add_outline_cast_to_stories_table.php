<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The people a story names, declared by the outline before the spine.
 *
 * ---------------------------------------------------------------------------
 * THE FINDING
 * ---------------------------------------------------------------------------
 *
 * Measured 2026-09-15 on the seven stories with a cast (21, 23, 25, 28, 32, 33,
 * 34): 72 characters, 8 to 13 per story. 55 of them (76%) were already named in
 * the outline's spine fields, 14 were added by the act writer, 3 were invented
 * by the extractor from a role label. Naming people in the premise changed
 * nothing — story 34's premise named nobody and got 9, story 33's named two and
 * got 12. Every character with a scene costs a reference sheet.
 *
 * The outline prompt was asking for the names: "name who is watching", "name
 * the occasion and the witnesses", and the act prompt's "put the witnesses in
 * the room and name them". Nothing counted them. `cast_age_profile` was the
 * only per-story cast text and it reached the extractor alone, after every act
 * was written.
 *
 * So the outline returns `cast` — name, role, relationship — as a required
 * schema field, first. See App\Support\OutlineCast.
 *
 * ---------------------------------------------------------------------------
 * JSON, NOT A TABLE AND NOT A MYSQL ENUM
 * ---------------------------------------------------------------------------
 *
 * A list of at most about eight rows that is written once by the outline,
 * edited as a whole at Gate 1 and read as a whole by three prompts. It has no
 * identity of its own to hang a foreign key on — a Character is what gets one,
 * later. And the role is validated against `CastRole` in PHP, so a new role is
 * not a migration and cannot drift from a column (`schema:enum-drift` exists
 * because `CostUnit::TotalTokens` did).
 *
 * ---------------------------------------------------------------------------
 * `outlined_before_cast`, FROZEN BY PREDICATE
 * ---------------------------------------------------------------------------
 *
 * The same shape as `outlined_before_betrayal_scene` and for the same reason:
 * an empty cast is exactly what a legacy story and a broken new outline share.
 * Every story that has acts NOW was outlined before this question existed.
 * `GenerateOutline` clears it; an operator typing a cast in at Gate 1 ends the
 * excuse without touching it; `story:fork` carries it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stories', function (Blueprint $table) {
            $table->json('outline_cast')->nullable()->after('cast_age_profile');
            $table->boolean('outlined_before_cast')->default(false)->after('outline_cast');
        });

        DB::table('stories')
            ->whereExists(fn ($query) => $query->from('acts')->whereColumn('acts.story_id', 'stories.id'))
            ->update(['outlined_before_cast' => true]);
    }

    public function down(): void
    {
        Schema::table('stories', function (Blueprint $table) {
            $table->dropColumn(['outline_cast', 'outlined_before_cast']);
        });
    }
};
