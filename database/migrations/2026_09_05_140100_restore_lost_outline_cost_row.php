<?php

use App\Enums\CostCategory;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The one billed call whose ledger row the truncation destroyed.
 *
 * Story 23's outline ran 87 seconds against claude-opus-5 and completed. The
 * insert that recorded it is what failed, so the money was spent and nothing on
 * the record said so — `stories.total_cost_usd` read 0.0000 on a story that had
 * cost fifteen cents.
 *
 * ---------------------------------------------------------------------------
 * WHY THIS IS A RESTORE AND NOT AN EDIT
 * ---------------------------------------------------------------------------
 *
 * `cost_entries` is write-once, and that rule is why story 21's narration is
 * still on record at $2.12 when it really cost about $4.24. **This is the other
 * case.** Nothing here changes a figure the ledger already states; it writes a
 * row that was never written, for a call that certainly happened. Non-negotiable
 * #4 is "every paid API call writes one row, no exceptions", and leaving the gap
 * would be the ledger being wrong in a way nobody can date — the exact thing the
 * write-once rule exists to avoid.
 *
 * ---------------------------------------------------------------------------
 * WHERE THE FIGURES COME FROM
 * ---------------------------------------------------------------------------
 *
 * Not reconstructed, not estimated, not inferred from a comparable call. Laravel
 * interpolates the bindings into a QueryException message, and `RenderJob::record`
 * stored that message verbatim on render job #4703, so every column of the lost
 * row survives in `render_jobs.error`:
 *
 *     values (23, anthropic, claude-opus-5, 0, generate_outline, text,
 *             10784, total_tokens, 0.152177,
 *             {"model":"claude-opus-5","input_tokens":2266,"output_tokens":5575,
 *              "cache_write_tokens":0,"cache_read_tokens":2943},
 *             2026-09-05 01:12:53)
 *
 * It checks against itself: 2266 + 5575 + 0 + 2943 = 10,784, which is the
 * quantity, and `total_tokens` is exactly the sum `CostUnit::TotalTokens`
 * documents. The neighbouring successful outline (story 22, $0.1538 on 9,343
 * tokens) is a plausibility check and not the source.
 *
 * `created_at` is the original 2026-09-05 01:12:53, not now. A ledger dated by
 * when it was repaired cannot answer when the money was spent.
 *
 * The row says it was restored, in `detail`. A reconstructed row that looks
 * identical to a directly-written one is a small false success of its own: the
 * figures are trustworthy and their PROVENANCE is different, and only the row
 * can carry that.
 *
 * Idempotent, and guarded on the exact row rather than on a count.
 */
return new class extends Migration
{
    private const STORY_ID = 23;

    private const CREATED_AT = '2026-09-05 01:12:53';

    public function up(): void
    {
        $exists = DB::table('cost_entries')
            ->where('story_id', self::STORY_ID)
            ->where('operation', 'generate_outline')
            ->where('created_at', self::CREATED_AT)
            ->exists();

        if ($exists) {
            return;
        }

        // Only if the story is still there. A migration that fails on a
        // developer machine where story 23 was deleted would block every later
        // migration over one historic row.
        if (! DB::table('stories')->where('id', self::STORY_ID)->exists()) {
            return;
        }

        DB::table('cost_entries')->insert([
            'story_id' => self::STORY_ID,
            'provider' => 'anthropic',
            'model' => 'claude-opus-5',
            'simulated' => 0,
            'operation' => 'generate_outline',
            'category' => 'text',
            'quantity' => 10784,
            'unit' => 'total_tokens',
            'usd_cost' => 0.152177,
            'detail' => json_encode([
                'model' => 'claude-opus-5',
                'input_tokens' => 2266,
                'output_tokens' => 5575,
                'cache_write_tokens' => 0,
                'cache_read_tokens' => 2943,
                // The row's own provenance, because it is not the same kind of
                // row as the ones beside it.
                'restored_from' => 'render_jobs#4703 error text',
                'restored_because' => "cost_entries.unit could not hold 'total_tokens'",
            ]),
            'created_at' => self::CREATED_AT,
        ]);

        // `stories.total_cost_usd` is maintained by a CostEntry::created model
        // event, and a query-builder insert does not fire one. Recomputed from
        // the rows rather than incremented, so it is right whatever it was.
        //
        // The category list is READ from the enum, unlike the schema literal in
        // the migration beside this one, and the difference is the whole lesson
        // of that file. A schema literal must be frozen: it describes what the
        // column was made to hold on the day it was made. A PREDICATE must be
        // live: "which categories count toward a video's cost" has exactly one
        // right answer and it is `countsTowardVideoCost()`. Retyping it here
        // would be the two-copies-of-one-rule shape that gave one narration
        // three different prices.
        $counted = array_values(array_map(
            fn (CostCategory $category): string => $category->value,
            array_filter(
                CostCategory::cases(),
                fn (CostCategory $category): bool => $category->countsTowardVideoCost(),
            ),
        ));

        DB::table('stories')->where('id', self::STORY_ID)->update([
            'total_cost_usd' => DB::table('cost_entries')
                ->where('story_id', self::STORY_ID)
                ->whereIn('category', $counted)
                ->sum('usd_cost'),
        ]);
    }

    /**
     * Deliberately not reversible.
     *
     * Down would mean deleting a record of real spend. The row describes money
     * that left the account; rolling the schema back does not un-spend it.
     */
    public function down(): void {}
};
