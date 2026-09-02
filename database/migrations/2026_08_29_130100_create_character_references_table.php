<?php

use App\Enums\AssetStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every candidate face ever generated for a character, including the rejects.
 *
 * `characters.reference_image_path` holds the one that won. This table holds
 * the three or four it beat, and it exists rather than the winner simply being
 * written straight onto the character for three reasons:
 *
 *  1. **The rejects were billed.** Four candidates cost four generations and
 *     the spec's rule is that every paid call writes a cost row. A discarded
 *     candidate that left no trace anywhere would make the cost table
 *     unreconcilable against what actually happened — four charges, one image.
 *
 *  2. **Re-picking must be free.** An operator who chooses candidate 2, gets to
 *     scene 40 and decides candidate 4 was right should be able to switch for
 *     nothing. That is only true if candidate 4 is still on disk.
 *
 *  3. **Regeneration is an explicit act with a history.** Nothing is
 *     regenerated silently; a second round of candidates is a new `batch`
 *     rather than an overwrite of the first, so what was paid for stays
 *     visible.
 *
 * `provider_reference` is the provider's own handle for the image — an
 * ElevenLabs `asset_id`. Once a reference is uploaded there it can be cited by
 * id in all 199 scene calls instead of re-uploading a megabyte two hundred
 * times, so the id is worth persisting the moment it exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('character_references', function (Blueprint $table) {
            $table->id();
            $table->foreignId('character_id')->constrained()->cascadeOnDelete();

            // Which round of generation produced this, and where it sat in that
            // round. Together they are what the operator points at: "batch 2,
            // the third one".
            $table->unsignedSmallInteger('batch')->default(1);
            $table->unsignedSmallInteger('sequence');

            // The exact string that was sent. A face that came out wrong is
            // almost always a prompt that was wrong, and reconstructing the
            // prompt after the fact from config that has since been tuned is
            // guesswork.
            $table->longText('prompt');

            $table->unsignedBigInteger('seed')->nullable();

            $table->string('provider');
            $table->string('model')->nullable();

            // The provider's reusable handle, if it issues one.
            $table->string('provider_reference')->nullable();

            $table->string('image_path')->nullable();
            $table->unsignedSmallInteger('width')->nullable();
            $table->unsignedSmallInteger('height')->nullable();

            $table->enum('status', array_column(AssetStatus::cases(), 'value'))
                ->default(AssetStatus::Pending->value);

            // Denormalised from cost_entries so the sheet can show what it cost
            // without a join per candidate. cost_entries remains the ledger.
            $table->decimal('usd_cost', 10, 4)->default(0);

            $table->text('error')->nullable();

            // Exactly one per character is non-null; enforced in code, because
            // "at most one row per parent" is not a constraint MySQL expresses.
            $table->timestamp('selected_at')->nullable();

            $table->timestamps();

            $table->unique(['character_id', 'batch', 'sequence']);
            $table->index(['character_id', 'selected_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('character_references');
    }
};
