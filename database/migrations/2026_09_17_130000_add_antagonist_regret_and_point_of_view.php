<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The antagonist's regret, told in her own chapter at the end.
 *
 * ---------------------------------------------------------------------------
 * WHY THIS EXISTS
 * ---------------------------------------------------------------------------
 *
 * Our stories end on a corridor plea and a narrator epilogue. The reference
 * (docs/refence/transcript-3446.txt) ends on two point-of-view extras, and hers
 * is the one that lands: "Extra 1 — Sophia's POV" at 32:20, 1:45 long. It
 * turns on something the narrator never saw — Maria's phone call, a last chance
 * offered and thrown away — and then what the years look like from inside:
 * "The wedding dress I had once ordered still sat on the top shelf of my
 * closet."
 *
 * ---------------------------------------------------------------------------
 * `stories.antagonist_regret`
 * ---------------------------------------------------------------------------
 *
 * The last chance she was offered and threw away — who offered it, when, what
 * she said — and what her life looks like about a year after the refusal, in
 * things that can be drawn. A column, not a prompt line, for the running
 * thought's reason: the refusal act is written from five-sentence summaries,
 * so a chance nobody planned in the outline cannot be anchored to anything the
 * acts actually contain.
 *
 * Routed to the REFUSAL act only, and its scene call. An escalation act told
 * that someone offers her a way back stages the offer from the narrator's side
 * and spends the reveal.
 *
 * ABOUT A YEAR, NOT TWENTY. The operator's decision, 2026-09-17: her face is
 * drawn from one reference sheet at the age she is in the story, and ageing
 * texture is refused by CharacterTextGuard, so twenty years could only be
 * carried by objects. A year is drawable.
 *
 * ---------------------------------------------------------------------------
 * `chapters.point_of_view`
 * ---------------------------------------------------------------------------
 *
 * Null for the narrator, which is every chapter but one. The cast NAME of the
 * person telling it otherwise — the antagonist, on the one chapter at the end
 * of the refusal act. ONE chapter, not the reference's two: the second extra
 * is the new partner's, and no story has a partner yet.
 *
 * ONE VOICE. The spoken announcement marks the switch ("Extra. Amy Nie's point
 * of view."). A second voice touches voice_id, the pace guard and the
 * fingerprint, and two minutes of audio are not worth that.
 *
 * ---------------------------------------------------------------------------
 * THE AGE FLAG, FROZEN BY PREDICATE
 * ---------------------------------------------------------------------------
 *
 * Same shape as outlined_before_accomplice_and_thought: every story that has
 * acts NOW was outlined before the question was asked. GenerateOutline clears
 * it, typing the field in at Gate 1 ends the excuse, story:fork carries it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stories', function (Blueprint $table) {
            $table->text('antagonist_regret')->nullable()->after('refusal');
            $table->boolean('outlined_before_antagonist_regret')->default(false)->after('antagonist_regret');
        });

        Schema::table('chapters', function (Blueprint $table) {
            $table->string('point_of_view', 120)->nullable()->after('rehook_line');
        });

        DB::table('stories')
            ->whereExists(fn ($query) => $query->from('acts')->whereColumn('acts.story_id', 'stories.id'))
            ->update(['outlined_before_antagonist_regret' => true]);
    }

    public function down(): void
    {
        Schema::table('chapters', function (Blueprint $table) {
            $table->dropColumn('point_of_view');
        });

        Schema::table('stories', function (Blueprint $table) {
            $table->dropColumn(['antagonist_regret', 'outlined_before_antagonist_regret']);
        });
    }
};
