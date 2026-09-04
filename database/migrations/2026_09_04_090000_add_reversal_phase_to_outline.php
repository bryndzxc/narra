<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The half of the arc the first spine did not have.
 *
 * The four original spine columns describe how the narrator is wronged and
 * where it comes out. Watching story 21 back showed what that leaves out: it
 * runs escalation -> escalation -> exposure -> end, and the narrator holds
 * power for exactly one scene of two hundred and seventy. Nothing in the
 * database could say that was wrong — every act had a title, a summary and an
 * escalation beat, and the exposure had witnesses in it.
 *
 * What the genre actually does is:
 *
 *     escalation -> the narrator LEAVES -> the antagonist SEARCHES ->
 *     the narrator REFUSES -> end
 *
 * The reversal is a PHASE, not a scene, and each refusal inverts a specific
 * earlier humiliation. The reference channel's own framing of its videos —
 * "never expecting to see me and our son 5 years later" — is precisely this
 * gap, stated as the hook.
 *
 *   departure       How and when the narrator goes, and whether they announce
 *                   it. The announcement is the load-bearing detail and it is
 *                   load-bearing NEGATIVELY: a narrator who says they are
 *                   leaving cannot be searched for, and the search is the next
 *                   third of the video. Gate 1 flags an announced departure.
 *
 *   reversal_beats  What the antagonist does to find them, and what each
 *                   attempt costs HER. The mirror of the humiliation beats,
 *                   escalating, running the other way. A search that costs her
 *                   nothing is a montage; Gate 1 flags one.
 *
 *   refusal         What the narrator says when they are finally found, and
 *                   which earlier moment it answers. `exposure_moment` is the
 *                   public payoff; this is the private one, and it is the thing
 *                   viewers stay forty minutes for. A refusal that answers
 *                   nothing named earlier is a refusal about nothing, and Gate
 *                   1 flags that too.
 *
 *   acts.phase      Which of the four phases an act belongs to. Persisted
 *                   rather than derived, because the act SCRIPT generator reads
 *                   it: the previous prompt branched on "is this the last act"
 *                   and told every other act to end worse off than it started,
 *                   which is right for an escalation act and wrong for a search
 *                   act, where things get worse for the antagonist. A sequence
 *                   number cannot carry that.
 *
 * Nullable, like the first four, because a story exists before its outline
 * does — and `phase` is additionally null for anthology acts, where each act is
 * a self-contained story running the whole arc internally and a per-act phase
 * would be a lie about five different narrators.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stories', function (Blueprint $table) {
            // Beside the four they complete, and in narrative order: the
            // departure and the search happen between the escalation and the
            // exposure, and the refusal answers back across all of it.
            $table->text('departure')->nullable()->after('exposure_moment');
            $table->text('reversal_beats')->nullable()->after('departure');
            $table->text('refusal')->nullable()->after('reversal_beats');
        });

        Schema::table('acts', function (Blueprint $table) {
            // A string rather than a MySQL enum. The four phases are a
            // narrative structure that this project has already changed once,
            // and an enum column turns the next change into a migration that
            // rewrites the table. App\Enums\ActPhase is the authority.
            $table->string('phase', 20)->nullable()->after('sequence');
        });
    }

    public function down(): void
    {
        Schema::table('stories', function (Blueprint $table) {
            $table->dropColumn(['departure', 'reversal_beats', 'refusal']);
        });

        Schema::table('acts', function (Blueprint $table) {
            $table->dropColumn('phase');
        });
    }
};
