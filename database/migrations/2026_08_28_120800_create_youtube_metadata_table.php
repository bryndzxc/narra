<?php

use App\Enums\MetadataStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The publish sheet: everything a human needs to upload the video, in one place.
 *
 * Generated after the render, not alongside the script — chapters need real
 * timestamps and those only exist once the video does.
 *
 * Chapters are NOT stored here. They are derived from `acts`, which already
 * hold start_ms and duration_ms after the render, and storing them twice would
 * mean two answers to the same question.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('youtube_metadata', function (Blueprint $table) {
            $table->id();

            // One sheet per story. Regenerating rewrites this row.
            $table->foreignId('story_id')->unique()->constrained()->cascadeOnDelete();

            // Five variants, hook front-loaded — the left of a title is what
            // survives truncation in search and on mobile. All five are kept
            // alongside the pick, so over time this becomes data on what works.
            $table->json('title_options')->nullable();
            $table->string('title_selected', 100)->nullable();

            // 5,000 character limit. The opening 2-3 sentences are the payload:
            // they show in search and above the fold, and are written as a hook
            // rather than a summary.
            $table->longText('description')->nullable();

            $table->json('tags')->nullable();

            // 500-character budget across all tags, enforced in code rather
            // than silently truncated.
            $table->unsignedSmallInteger('tags_char_count')->default(0);

            /** 3-5 overlay phrases of 3-5 words. Text only — no image composition. */
            $table->json('thumbnail_text_options')->nullable();

            // The still the operator flagged at Gate 2. nullOnDelete: losing the
            // recommendation must never take the publish sheet with it.
            $table->foreignId('thumbnail_scene_id')->nullable()
                ->constrained('scenes')->nullOnDelete();

            $table->text('pinned_comment')->nullable();

            // The Gate 4 checklist, rendered as a checklist rather than prose:
            // synthetic-content disclosure, not-made-for-kids, category,
            // languages, scheduled time in ET, pinned comment drafted.
            $table->json('checklist_state')->nullable();

            $table->enum('status', array_column(MetadataStatus::cases(), 'value'))
                ->default(MetadataStatus::Pending->value);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('youtube_metadata');
    }
};
