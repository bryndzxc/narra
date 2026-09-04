<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The intended age range of the cast, stated rather than compensated for.
 *
 * The art style constant carries one line about how age is drawn, and for a
 * while that line was doing a job it cannot do. It was written to stop an anime
 * style rendering a sixty-eight-year-old widow as a thirty-year-old, so it said
 * adults are drawn at their true age and never softened toward youth — a
 * photographic rule handed to a medium that is not photographic. The result was
 * the opposite failure: the only thing the model has for "old" is photoreal
 * ageing texture, so a woman written as late sixties came back at eighty-five
 * with drawn-on wrinkles and liver spots.
 *
 * That line is now written for the convention rather than against it. But a
 * style constant is one string appended to every prompt in every story, and it
 * was being asked to absorb something that varies per story: WHO IS IN THIS
 * ONE. A cast of spouses in their thirties and a cast built around a dead
 * mother, memory care and an uncle with a cane are different casting problems,
 * and no single sentence about rendering is the right answer to both.
 *
 * So the age range gets said where it actually differs. `cast_age_profile` is
 * optional operator text — "spouses in their late twenties and thirties,
 * workplace and marriage settings, nobody over forty" — read by the character
 * extraction prompt, which is the one place a character's age is decided and
 * frozen. Every still that character appears in is built from that description,
 * so this is upstream of 150-250 images rather than a correction applied to
 * them.
 *
 * Nullable, and null means exactly nothing: the extractor reads the ages out of
 * the script as it always has. This states an intention when there is one; it
 * does not invent one when there is not.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stories', function (Blueprint $table) {
            // After `premise` because it is read alongside it: both are the
            // operator's editorial input about what the video is, written
            // before anything is generated and edited at Gate 1.
            $table->text('cast_age_profile')->nullable()->after('premise');
        });
    }

    public function down(): void
    {
        Schema::table('stories', function (Blueprint $table) {
            $table->dropColumn('cast_age_profile');
        });
    }
};
