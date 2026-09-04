<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Record which art style a reference face was drawn in.
 *
 * `ImagePromptBuilder` has claimed since Phase 2 that "if the channel's look is
 * retuned, the sheets are stale and the operator is told so rather than the
 * mismatch being absorbed silently". Nothing implemented that. There was no
 * column, no comparison and no surface — a documented guard that could not
 * fire, which is this project's most reliable defect shape, and it had a live
 * instance the day it was found: story 9's 38 reference images are painted
 * realism and the configured style is now anime.
 *
 * It matters because a reference is not decoration. Every still a character
 * appears in goes through the `edit` endpoint conditioned on that face, so a
 * sheet drawn in the old look does not merely look dated — it drags each of the
 * character's 30-90 stills back toward a style the rest of the video is not in,
 * and the result is one video in two looks for $6.51 of image spend.
 *
 * Nullable, and deliberately NOT backfilled. Every row that predates this
 * column is genuinely unknown: the style string of the day was not recorded and
 * cannot be recovered, and stamping the current fingerprint onto an image
 * generated under some other one would be fabricating provenance to make a
 * warning go away. NULL is reported as "unknown" everywhere it is read, never
 * as "fine" — the same rule scene_audio's speed provenance had to learn.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('character_references', function (Blueprint $table): void {
            // A short digest of the art style that was in force when this image
            // was generated. Short because it is only ever compared for
            // equality and shown to a human in a log line; the prompt column
            // beside it already holds the full text for forensics.
            $table->string('style_fingerprint', 32)->nullable()->after('prompt');
        });
    }

    public function down(): void
    {
        Schema::table('character_references', function (Blueprint $table): void {
            $table->dropColumn('style_fingerprint');
        });
    }
};
