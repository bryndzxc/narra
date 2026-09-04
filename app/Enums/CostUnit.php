<?php

namespace App\Enums;

/**
 * What a `cost_entries.quantity` is counting.
 *
 * Providers bill in different units and the raw quantity is meaningless
 * without one — 12,000 is a fine number of tokens and an alarming number of
 * images. Stored per row so "what did this video cost, and where did it go"
 * stays answerable in one query.
 *
 * ---------------------------------------------------------------------------
 * TWO VINTAGES OF TOKEN ROW, AND THE OLDER ONE IS MISLABELLED
 * ---------------------------------------------------------------------------
 *
 * **Every anthropic row written before `TotalTokens` existed carries
 * `output_tokens` and holds the TOTAL** — input + output + cache_read +
 * cache_write. Verified exactly on story 21's rows: `4220 + 3752 + 2203 + 0 =
 * 10175`, recorded as 10,175 under `output_tokens`. On a `generate_act_script`
 * call the real output was 3,651, so reading those rows as output tokens
 * overstates by about 2.6x.
 *
 * `cost_entries` is write-once — a ledger that edits itself is worth less than
 * one that is wrong in a way you can date — so the old rows keep the old label
 * and this note is what makes them readable. **That is the same defect and the
 * same remedy as `stories.sized_against_wpm`**: a figure whose meaning changed
 * is unreconstructable unless something on the record says which meaning
 * applied when. The dividing line is the first row carrying `total_tokens`.
 *
 * What the mislabel actually cost, so the scale is on the record too: three of
 * the four readers hard-code the word "tok" and were correct either way, and no
 * page reads `cost_entries` at all. The one wrong surface was
 * `ProviderUsage::summary()`, which renders `$unit->value` and is printed twice
 * per act by `story:write` — it read `9581 output_tokens` for a call whose
 * output was 3,651.
 */
enum CostUnit: string
{
    /**
     * Every token a call consumed: input, output, cache read and cache write.
     *
     * The number that means something when scanning the table, which is why the
     * recorder always wrote it. The per-class split lives in `detail` and always
     * has.
     *
     * There is deliberately no `InputTokens` beside this. One existed, was never
     * written by anything, and sat on the open list for a phase as "use it or
     * drop it" — **the enum was modelling a per-class split the recorder never
     * intended to write.** Splitting a call into four rows would mean
     * apportioning `usd_cost` four ways, and nothing has ever asked for that;
     * the split is a detail of one billed call, not four billed things.
     */
    case TotalTokens = 'total_tokens';

    /**
     * Output tokens only.
     *
     * No live writer. Kept because rows exist under it — see the note above,
     * where it means something other than what it says — and dropping the case
     * would make those rows fail to cast.
     */
    case OutputTokens = 'output_tokens';

    case Characters = 'characters';
    case Images = 'images';
    case AudioSeconds = 'audio_seconds';
    case Requests = 'requests';

    public function label(): string
    {
        return ucwords(str_replace('_', ' ', $this->value));
    }
}
