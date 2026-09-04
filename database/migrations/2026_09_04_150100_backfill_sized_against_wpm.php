<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Every script already in the database was sized at 160 wpm. Say so, on the
 * record, before the number changes.
 *
 * ---------------------------------------------------------------------------
 * WHY 160 IS A FACT HERE AND NOT AN ASSUMPTION
 * ---------------------------------------------------------------------------
 *
 * `targetWordsPerAct()` has only ever read `render.narration.words_per_minute`,
 * and that constant is `env('NARRATION_WPM', 160)`. Checked rather than
 * remembered: it reads 160 in every commit that has ever touched
 * `config/render.php` — `e336a3e`, `10d4c87`, `a3d64c7` — there is no
 * `NARRATION_WPM` in `.env` or `.env.example`, and it resolves to 160 today. So
 * every generated act script in this database was written to a 5,600-word
 * budget derived from 160, by construction rather than by recollection.
 *
 * ---------------------------------------------------------------------------
 * FIVE STORIES, NOT TWO
 * ---------------------------------------------------------------------------
 *
 * The two shipped videos — 9 and 21 — are the ones this was noticed on, and
 * backfilling only those was the instruction. The data says otherwise: stories
 * 8, 12 and 20 carry generated act scripts too, and a story left null when the
 * target moves is exactly the unreconstructable case the column exists to
 * prevent. Naming the two by id would have fixed the two that were looked at
 * and left three behind the same defect.
 *
 * So the predicate is the fact rather than the ids: **a story has a generated
 * script, therefore it was sized at 160.** Anything the predicate does not
 * match is left NULL deliberately, and `sample-story` is the case that proves
 * the predicate is the right one — it has three acts with no scripts, imported
 * from a Phase 0 fixture, and was sized against nothing. Null is its true
 * answer, not an omission.
 *
 * Guarded on `whereNull` so a re-run cannot overwrite a value written since,
 * which is the freeze this column is for.
 */
return new class extends Migration
{
    /** The only value the word target has ever been derived from. */
    private const HISTORIC_WPM = 160;

    public function up(): void
    {
        DB::table('stories')
            ->whereNull('sized_against_wpm')
            ->whereExists(function ($query): void {
                $query->select(DB::raw(1))
                    ->from('acts')
                    ->whereColumn('acts.story_id', 'stories.id')
                    ->whereNotNull('acts.script');
            })
            ->update(['sized_against_wpm' => self::HISTORIC_WPM]);
    }

    /**
     * Deliberately a no-op.
     *
     * Rolling this back would mean nulling rows, and nothing here can tell a row
     * this migration wrote from one written since by `GenerateActScripts` — so a
     * down() would destroy provenance it cannot distinguish. The schema
     * migration's own down() drops the column outright, which is the honest way
     * to undo this.
     */
    public function down(): void
    {
        //
    }
};
