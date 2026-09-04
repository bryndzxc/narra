<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A fourth kind of spend: evaluating the channel rather than making a video.
 *
 * `style:preview`, `images:bakeoff` and `narration:bakeoff` all bill a real
 * vendor for a real file, and none of them is making a video. They had no
 * category, so they borrowed one — the first two took `reference`, the third
 * took `asset` — and inherited a gate written for a decision they are not
 * making.
 *
 * That bit in production. A style preview asks what a candidate look does to a
 * cast, so it wants the earliest story that HAS a cast, which is `scripted`.
 * `reference` unlocks at `scenes_drafted`, so the run generated an image,
 * billed for it, and threw a gate violation while writing the row: real spend,
 * no record. The workaround was to walk a scratch story forward through Gate 2
 * to satisfy a guard about something it was not doing — moving the measurement
 * until the result passes, which is the pattern this project keeps naming.
 *
 * `evaluation` is ungated, and that does not weaken Gate 2. The gate stops
 * 150-250 stills and per-scene TTS being committed against scenes nobody has
 * read; evaluation spend is a handful of files fired by an explicit operator
 * confirmation with the price on screen, written to a preview directory no
 * pipeline stage reads.
 *
 * It is also the one category kept OUT of `stories.total_cost_usd`. That column
 * answers "what did this video cost", and a style test borrows a story's cast
 * the way a lens test borrows an actor. Three separate docblocks already
 * promised a per-video total could exclude this spend "in one predicate" and
 * supplied no predicate; this is it. See App\Enums\CostCategory.
 *
 * No backfill. Existing `reference` rows written by a preview or a bake-off are
 * left where they are: they were spent, they are on record, and rewriting a
 * historical ledger to match a category that did not exist at the time would
 * make the table say something that was never true. The three borrowers are
 * fixed going forward.
 *
 * The value list is written out literally rather than read from the enum. The
 * two migrations before this one call `CostCategory::cases()`, which means
 * their meaning changes every time a case is added — a migration that is never
 * edited but does not say the same thing twice. This one says what it does.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cost_entries', function (Blueprint $table) {
            $table->enum('category', ['text', 'asset', 'reference', 'evaluation'])
                ->default('asset')
                ->change();
        });
    }

    public function down(): void
    {
        Schema::table('cost_entries', function (Blueprint $table) {
            $table->enum('category', ['text', 'asset', 'reference'])
                ->default('asset')
                ->change();
        });
    }
};
