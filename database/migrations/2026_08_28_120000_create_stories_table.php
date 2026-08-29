<?php

use App\Enums\StoryFormat;
use App\Enums\StoryStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The root of everything. One row per video.
 *
 * `status` is the gate machine — see App\Enums\StoryStatus, which is the only
 * definition of which moves are legal and where the four human gates sit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stories', function (Blueprint $table) {
            $table->id();

            $table->string('title');
            $table->longText('premise')->nullable();

            $table->enum('format', array_column(StoryFormat::cases(), 'value'))
                ->default(StoryFormat::Single->value);

            // Data, not hardcoded prose. The US-audience rules — American
            // settings, imperial units, no Filipino idiom — hang off this.
            $table->string('locale_profile', 16)->default('en-US');

            // One consistent narrator per channel, so a viewer hears the same
            // voice across videos.
            $table->string('voice_id')->nullable();

            $table->unsignedSmallInteger('target_duration_min')->default(30);
            $table->unsignedSmallInteger('target_duration_max')->default(40);

            $table->enum('status', StoryStatus::values())->default(StoryStatus::Draft->value);

            // UTC. Displayed in both PHT and ET, because peak US viewing is
            // early morning in Manila and that is exactly how a publish time
            // gets fumbled.
            $table->timestamp('target_publish_at')->nullable();

            // Denormalised sum of cost_entries. decimal, never float — money.
            $table->decimal('total_cost_usd', 10, 4)->default(0);

            $table->timestamps();

            $table->index('status');
            $table->index('target_publish_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stories');
    }
};
