<?php

use App\Support\LocaleGuard;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Record which stories were written against the guidance that is here now —
 * and, just as deliberately, which ones cannot be shown to have been.
 *
 * ---------------------------------------------------------------------------
 * WHAT WAS CHECKED, RATHER THAN REMEMBERED
 * ---------------------------------------------------------------------------
 *
 * `config/locale.php` has been touched by exactly two commits, `e336a3e` and
 * `a3d64c7` (2026-09-04 08:35 +0800). The guidance blocks were extracted from
 * each and compared byte for byte against the working tree:
 *
 *   en-US   902 chars, IDENTICAL in both commits and in the working tree.
 *   en-CN  2415 chars, ABSENT in e336a3e, present in a3d64c7, and identical
 *          between a3d64c7 and the working tree.
 *
 * ---------------------------------------------------------------------------
 * THE COMMIT DATES DO NOT BOUND THE WORKING TREE, AND THAT IS THE WHOLE
 * REASON STORY 21 IS LEFT NULL
 * ---------------------------------------------------------------------------
 *
 * This repository commits in batches well after the work, so a commit date is
 * an upper bound on when a change existed and never a lower one. Story 21 is
 * proof: it is `en-CN`, created 2026-09-03 03:07, which is a full day BEFORE
 * the commit that first records the `en-CN` profile existing at all. The
 * profile plainly existed in the working tree already; what cannot be
 * established is whether its TEXT was byte-identical to what `a3d64c7` later
 * captured.
 *
 * So the backfill is split by what the evidence actually supports:
 *
 *   en-US, generated script     -> current digest. The text is identical in
 *                                  every commit that has ever touched the file,
 *                                  which is the same strength of claim the
 *                                  `sized_against_wpm` backfill made about 160.
 *   en-CN, created after a3d64c7 -> current digest. The file is provably
 *                                  unchanged from that commit to now, and the
 *                                  story falls inside that window. Story 23.
 *   en-CN, created before it     -> LEFT NULL. Story 21.
 *
 * **Marking story 21 with the current digest would have been the comfortable
 * answer and a false one.** It would assert that its acts were generated
 * against text nobody can show was in place, and the entire purpose of this
 * column is to stop exactly that kind of assumption being frozen into a row.
 * NULL is not an omission here; it is the accurate value, and it is what the
 * column's own semantics mean by unknown.
 *
 * It also does not cost what it looks like it costs. After the guidance moves,
 * story 21 reads "unknown", story 23 reads "the old en-CN digest" and new
 * stories read the new one — so 21 is still distinguishable from anything
 * written afterwards, which is what marking a "before" was for.
 *
 * ---------------------------------------------------------------------------
 * THE PREDICATE IS THE FACT, NOT A LIST OF IDS
 * ---------------------------------------------------------------------------
 *
 * A story with a generated act script was generated against its profile's
 * guidance, by construction — the guidance is injected into the outline, act,
 * cast and scene calls. Naming the ids instead would fix the stories somebody
 * happened to look at and leave the rest behind the same defect, which is what
 * the `sized_against_wpm` backfill found when it went looking: five stories,
 * not the two that had been noticed.
 *
 * Guarded on `whereNull` so a re-run cannot overwrite a value written since.
 */
return new class extends Migration
{
    /**
     * The commit that first records the `en-CN` guidance, and the earliest
     * moment from which its text is provably what it is now.
     */
    private const EN_CN_TEXT_PROVEN_FROM = '2026-09-04 08:35:13';

    public function up(): void
    {
        $guard = app(LocaleGuard::class);

        foreach ((array) config('locale.profiles', []) as $profile => $_) {
            $query = DB::table('stories')
                ->whereNull('locale_guidance_fingerprint')
                ->where('locale_profile', $profile)
                // A story with no generated script was written against no
                // guidance. `sample-story` carries fixture acts and should stay
                // null for ever.
                ->whereExists(function ($q): void {
                    $q->select(DB::raw(1))
                        ->from('acts')
                        ->whereColumn('acts.story_id', 'stories.id')
                        ->whereNotNull('acts.script');
                });

            // en-CN's text is only provable from the commit that first records
            // it. en-US's is identical in every commit the file has ever had,
            // so it needs no window.
            if ($profile === 'en-CN') {
                $query->where('created_at', '>=', self::EN_CN_TEXT_PROVEN_FROM);
            }

            $query->update(['locale_guidance_fingerprint' => $guard->fingerprintFor((string) $profile)]);
        }
    }

    /**
     * Deliberately a no-op.
     *
     * Rolling back would mean nulling rows, and nothing here can tell a row
     * this migration wrote from one written since by `GenerateOutline`. A
     * down() would therefore destroy provenance it cannot distinguish. The
     * schema migration's own down() drops the column outright, which is the
     * honest way to undo this.
     */
    public function down(): void
    {
        //
    }
};
