<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The accomplice as a person with a stake, and the narrator's running thought.
 *
 * ---------------------------------------------------------------------------
 * THE ACCOMPLICE: THREE COLUMNS, NOT ONE
 * ---------------------------------------------------------------------------
 *
 * Silence followed from having no stake: the first reference's accomplice is
 * a schoolmate asked to pretend (29:29) and never speaks; the Lydia
 * reference's Gerald wants the company shares, talks from 1:28, loses about
 * twelve times across four scenes shared with the narrator, and ends in
 * prison. See App\Support\AccompliceArc and CLAUDE.md 3g.
 *
 * Asked for as "a spine column with a motive, an act he performs and an
 * ending", and built as three columns because the three go to DIFFERENT acts:
 *
 *   accomplice_motive       every act — planted early, hidden from her
 *   accomplice_performance  every act — he talks in this voice, and wins,
 *                           until the narrator leaves
 *   accomplice_fall         the departure, search and refusal acts only
 *
 * One paragraph holding all three would hand the escalation acts his losses,
 * and a writer told in act 2 how the accomplice ends spends it in act 2 —
 * which is the narrator winning early, the one shape the arc decision in 3g
 * rules out. The routing is the reason for the split, not tidiness.
 *
 * All three are EMPTY when the cast declares no accomplice. That is a
 * legitimate story (a mother-in-law does it with nobody), and Gate 1 reads it
 * as such rather than as three missing fields.
 *
 * ---------------------------------------------------------------------------
 * `running_thought`
 * ---------------------------------------------------------------------------
 *
 * The narrator's one private joke: planted in chapter one, recurring in their
 * head, paid off in the refusal where it is said aloud once. A prompt line
 * alone could not do this — the refusal act is written from five-sentence
 * summaries of the acts before it, so a thought planted in act 1 does not
 * reach act 5 unless something carries it. This column carries it.
 *
 * ---------------------------------------------------------------------------
 * ONE AGE FLAG FOR BOTH, FROZEN BY PREDICATE
 * ---------------------------------------------------------------------------
 *
 * Both questions arrive in one outline revision, so one fact covers both: a
 * story that has acts NOW was outlined before either was asked. The same
 * shape as `outlined_before_betrayal_scene` and `outlined_before_cast`, for
 * the same reason — empty is exactly what a legacy story and a broken new
 * outline share. `GenerateOutline` clears it; typing any of the four fields
 * in at Gate 1 ends the excuse; `story:fork` carries it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stories', function (Blueprint $table) {
            // Narrative order: his stake and his act precede the betrayal
            // scene he speaks in; his fall runs beside the search.
            $table->text('accomplice_motive')->nullable()->after('antagonist_justification');
            $table->text('accomplice_performance')->nullable()->after('accomplice_motive');
            $table->text('accomplice_fall')->nullable()->after('reversal_beats');
            $table->text('running_thought')->nullable()->after('accomplice_fall');
            $table->boolean('outlined_before_accomplice_and_thought')->default(false)->after('running_thought');
        });

        DB::table('stories')
            ->whereExists(fn ($query) => $query->from('acts')->whereColumn('acts.story_id', 'stories.id'))
            ->update(['outlined_before_accomplice_and_thought' => true]);
    }

    public function down(): void
    {
        Schema::table('stories', function (Blueprint $table) {
            $table->dropColumn([
                'accomplice_motive',
                'accomplice_performance',
                'accomplice_fall',
                'running_thought',
                'outlined_before_accomplice_and_thought',
            ]);
        });
    }
};
