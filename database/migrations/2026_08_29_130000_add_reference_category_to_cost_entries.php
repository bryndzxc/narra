<?php

use App\Enums\CostCategory;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A third kind of spend: the character reference sheets.
 *
 * The same collision the `category` column was added to resolve, one step
 * further along. Two project rules meet again:
 *
 *   "Cost is logged per video. Every paid API call writes a row."
 *   "No paid asset generation may begin before scenes_approved."
 *
 * Reference sheets are paid image generation and they happen BEFORE Gate 2 is
 * approved, because the whole point of them is that the operator picks a face
 * while the scenes are still free to change. Under a two-category scheme they
 * would have to be either `text` — a lie, they are images and they bill — or
 * `asset`, which the guard refuses at `scenes_drafted`. Neither is acceptable:
 * one corrupts the money line, the other makes the feature impossible.
 *
 * So the Gate 2 line is stated more precisely than "assets". It was always
 * about committing 150-250 stills plus per-scene TTS against scenes no human
 * has read — an unrecoverable spend, made by a machine, at scale. A character
 * sheet is the opposite of each of those: about three dozen images, generated
 * by an explicit operator click, priced on screen first, and it is itself the
 * thing being reviewed at Gate 2. Same argument that already exempts `text`.
 *
 * `reference` therefore unlocks one status earlier — at `scenes_drafted`, the
 * moment the operator is standing at Gate 2 — and not one status before that.
 * See App\Enums\CostCategory.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cost_entries', function (Blueprint $table) {
            $table->enum('category', array_column(CostCategory::cases(), 'value'))
                ->default(CostCategory::Asset->value)
                ->change();
        });
    }

    public function down(): void
    {
        Schema::table('cost_entries', function (Blueprint $table) {
            $table->enum('category', ['text', 'asset'])
                ->default('asset')
                ->change();
        });
    }
};
