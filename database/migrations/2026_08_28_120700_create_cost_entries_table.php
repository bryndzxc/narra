<?php

use App\Enums\CostUnit;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every paid API call writes one row. No exceptions.
 *
 * The bar is that "what did this video cost" is answerable in one query — if it
 * is not, the feature that spent the money is incomplete. Image generation is
 * expected to be ~70% of the total, so the provider/operation breakdown is what
 * makes the number actionable rather than merely alarming.
 *
 * Rows are immutable: written once, at the moment of the call, never updated.
 * Hence `created_at` alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cost_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('story_id')->constrained()->cascadeOnDelete();

            /** Free text: the provider is a Phase 2 decision, not a schema one. */
            $table->string('provider', 64);

            /** What was asked of it — 'generate_image', 'transcribe', and so on. */
            $table->string('operation', 64);

            $table->decimal('quantity', 12, 4);

            $table->enum('unit', array_column(CostUnit::cases(), 'value'));

            // decimal, never float. Money.
            $table->decimal('usd_cost', 10, 4);

            $table->timestamp('created_at')->useCurrent();

            $table->index(['story_id', 'provider']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cost_entries');
    }
};
