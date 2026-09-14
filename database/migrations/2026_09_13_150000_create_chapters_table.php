<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Chapters, as a unit UNDER the act.
 *
 * ---------------------------------------------------------------------------
 * THE MEASUREMENT
 * ---------------------------------------------------------------------------
 *
 * A working video in this niche (34:46) announces fourteen chapters, about
 * 2:29 each. Four rendered stories here have six acts each, 5:54 to 9:44,
 * mean 6:22 to 7:15 — chapters 2.6-2.9x longer, five re-hook boundaries
 * against about fifteen, and the first boundary at 6:45-7:47 on a format
 * whose one measured failure is a vertical retention drop inside the first
 * three minutes.
 *
 * ---------------------------------------------------------------------------
 * WHY A TABLE UNDER ACTS, AND NOT FOURTEEN ACTS
 * ---------------------------------------------------------------------------
 *
 * The act writer returns ~1,100 words whatever it is asked (fitted slope
 * +0.30 across five observations), because the act prompt's machinery — a
 * beat, a re-hook, staging, exact words and dates, a summary — is what
 * produces that length. Fourteen acts of it is a 75-minute video, fourteen
 * sequential calls each carrying up to thirteen running summaries, and Gate
 * 1 with fourteen panels to read. So the act stays the generation and
 * coherence unit, and the chapter is what an act is returned AS: two or
 * three of them, each with a title and its own re-hook.
 *
 * ---------------------------------------------------------------------------
 * WHAT THE TABLE HOLDS, AND WHAT IT DELIBERATELY DOES NOT
 * ---------------------------------------------------------------------------
 *
 * `first_sentence` is a 1-indexed offset into the act's script, in the same
 * unit DraftScenes addresses scenes in (SentenceSplitter), so a chapter
 * boundary is a sentence index rather than a second copy of the prose. The
 * act's `script` stays the concatenated text every existing consumer reads.
 *
 * `start_ms` and `duration_ms` are filled by the render from scene offsets,
 * exactly as `acts.start_ms` is, and null until then: a chapter timestamp
 * cannot exist before the video does.
 *
 * `sequence` is WITHIN THE ACT, unique on (act_id, sequence), so rewriting
 * one act (`story:write --acts-only=4`) replaces that act's chapters without
 * renumbering the story — the story-wide number is derived from act order
 * wherever it is displayed.
 *
 * SchemaTest used to assert that no `chapters` table exists, on the rule
 * that chapters were derived from acts rather than stored twice. That rule
 * stands and this table does not break it: nothing here is a copy of an act
 * row. The chapter is now the unit, the act is the container, and the
 * timestamps are still the render's numbers, written once.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chapters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('story_id')->constrained()->cascadeOnDelete();
            $table->foreignId('act_id')->constrained()->cascadeOnDelete();

            // Within the act. See the docblock.
            $table->unsignedSmallInteger('sequence');

            // The YouTube chapter title. Bounded by Chapter::TITLE_MAX_CHARS,
            // which is YouTube's 100, enforced against the decoded response.
            $table->string('title');

            // The chapter's opening line, quoted back by the writer. Null is
            // "none written", which Gate 1 surfaces the way it surfaces an
            // act with no re-hook.
            $table->text('rehook_line')->nullable();

            // 1-indexed sentence offset into acts.script. Chapter 1 of every
            // act is sentence 1.
            $table->unsignedSmallInteger('first_sentence');

            // Filled by the render. Null until then.
            $table->unsignedInteger('start_ms')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();

            $table->timestamps();

            $table->unique(['act_id', 'sequence']);
        });

        Schema::table('scenes', function (Blueprint $table) {
            // Which chapter a scene's narration falls in — the chapter whose
            // first_sentence is the last one at or before the scene's own
            // first sentence. Null on every scene drafted before chapters
            // existed, and set null (not deleted) when an act is rewritten
            // and its chapters replaced: a scene outliving its chapter is a
            // scene about to be re-drafted, not a scene to lose.
            $table->foreignId('chapter_id')->nullable()->after('act_id')
                ->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('scenes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('chapter_id');
        });

        Schema::dropIfExists('chapters');
    }
};
