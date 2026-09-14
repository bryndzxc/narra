<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How the narrator comes to be in the room for the exposure, and what only
 * they can produce there.
 *
 * ---------------------------------------------------------------------------
 * THE FINDING: WHEN A DOCUMENT CAN PRODUCE THE WITHHELD INFORMATION, THE
 * NARRATOR IS LEFT 800 KM AWAY AND THE PUBLIC PAYOFF ARRIVES AS HEARSAY
 * ---------------------------------------------------------------------------
 *
 * Three phase stories, measured on the refusal act:
 *
 *   story   exposure                          narrator present   face-to-face
 *   -----   -------------------------------   ----------------   ------------
 *   23      34:40-39:06, told secondhand       no                 1:23
 *   25      32:56-37:32                        yes, raises hand   1:36
 *   28      38:20-40:30, told secondhand       no                 2:57
 *
 * In 23 the withheld information is produced by a developer's account manager
 * and a roommate; in 28 by an automated bank notice. Both spines wrote the
 * narrator out of the room and the act writer obeyed: "I was eight hundred
 * kilometers away that night, and I did not hear about any of it for nine
 * days." Story 25's `withheld_information` needs the narrator's BODY — the
 * trust "requires the settlor physically present to authorize a vote" — so
 * the writer brought him back, uninvited, in a work jacket at the service
 * door, and it is the strongest moment in the four videos.
 *
 * So the lever is upstream of the exposure: the outline prompt now asks what
 * the narrator must produce IN PERSON, and this column asks how they come to
 * be there. Gate 1 checks the second against the first by overlap, the way
 * the refusal is checked against the moments it answers.
 *
 * ---------------------------------------------------------------------------
 * FOUND VERSUS APPEARS
 * ---------------------------------------------------------------------------
 *
 * Indifference over revenge held up: story 25's narrator does not retaliate,
 * he raises his hand. What did not hold up was reading "gone" as "absent from
 * the payoff". The rule is not that she finds him. The search fails, and he
 * chooses the moment he is seen — at the exposure, in the room, unexpected —
 * and she reaches him afterwards because he came. Nothing about the search
 * cost is weakened by that; the reference channel's own framing, "never
 * expecting to see me", is a narrator turning up.
 *
 * ---------------------------------------------------------------------------
 * NULL, AND WHAT IT MEANS
 * ---------------------------------------------------------------------------
 *
 * Nullable like the eight before it. Stories 22 through 28 were outlined
 * after the reversal phase and before this field, so on them null means "not
 * asked" and Gate 1 says so once, beside the act timeframe that arrived in the
 * same change. Stories 9, 12, 20 and 21 have no departure for the narrator to
 * come back from and are covered by the pre-phase warning already. An
 * operator who types one in by hand ends the excuse and gets the ordinary
 * per-field checks back.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stories', function (Blueprint $table) {
            // In narrative order: after where it comes out, how the narrator
            // comes to be there.
            $table->text('narrator_at_exposure')->nullable()->after('exposure_moment');
        });
    }

    public function down(): void
    {
        Schema::table('stories', function (Blueprint $table) {
            $table->dropColumn('narrator_at_exposure');
        });
    }
};
