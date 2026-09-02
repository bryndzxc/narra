<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The load-bearing structure of an aggrieved-narrator melodrama.
 *
 * The first pass at script generation produced competent literary fiction:
 * well-observed, well-written, and no reason to keep watching. That is a genre
 * failure, not a quality one, and it is not fixable by asking for "more
 * engaging" prose. The genre has an actual structure, and these columns are it:
 *
 *   narrator_grievance        Who wronged the narrator, and how. First person.
 *                             The narrator is the injured party, never an
 *                             observer of somebody else's injury.
 *
 *   antagonist_justification  The antagonist's own account of why they were
 *                             entitled to do it. This is the engine of the
 *                             format — the infuriating part is the EXCUSE, not
 *                             the villainy. An antagonist who knows they are
 *                             being cruel is a cartoon and switches the viewer
 *                             off; one who believes they are being reasonable
 *                             is what holds a 35-minute watch.
 *
 *   withheld_information      What the narrator knows that the antagonist does
 *                             not — a child, a second marriage, the money, the
 *                             truth. The asymmetry is what makes escalation
 *                             bearable to watch instead of merely unpleasant.
 *
 *   exposure_moment           Where it comes out, and in front of whom. The
 *                             payoff of this genre is exposure before
 *                             witnesses, not revenge. Witnesses are load-
 *                             bearing: the same reveal in private is a
 *                             different, worse video.
 *
 *   acts.escalation_beat      What this act makes worse. Each act compounds the
 *                             humiliation; none of them resolves it. An act
 *                             that resolves anything before the exposure has
 *                             spent the tension the rest of the video runs on.
 *
 * Nullable because a story exists before its outline does. The generator is
 * required to fill every one of them, and Gate 1 surfaces any that are missing
 * or thin — see ValidateOutlineSpine.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stories', function (Blueprint $table) {
            $table->text('narrator_grievance')->nullable()->after('premise');
            $table->text('antagonist_justification')->nullable()->after('narrator_grievance');
            $table->text('withheld_information')->nullable()->after('antagonist_justification');
            $table->text('exposure_moment')->nullable()->after('withheld_information');
        });

        Schema::table('acts', function (Blueprint $table) {
            $table->text('escalation_beat')->nullable()->after('summary');
        });
    }

    public function down(): void
    {
        Schema::table('stories', function (Blueprint $table) {
            $table->dropColumn([
                'narrator_grievance',
                'antagonist_justification',
                'withheld_information',
                'exposure_moment',
            ]);
        });

        Schema::table('acts', function (Blueprint $table) {
            $table->dropColumn('escalation_beat');
        });
    }
};
