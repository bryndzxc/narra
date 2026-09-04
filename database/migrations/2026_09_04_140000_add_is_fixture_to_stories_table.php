<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A story that is deliberately never going to advance.
 *
 * ---------------------------------------------------------------------------
 * WHY THIS IS WORTH A COLUMN
 * ---------------------------------------------------------------------------
 *
 * Three stories on this machine exist to be measured against rather than to be
 * published, and every operator surface has been treating them as work:
 *
 *   `sample-story`            the Phase 0 fixture, parked at `rendered` — and
 *                             therefore a PERMANENT resident of "Waiting on
 *                             you", the one section on the dashboard that is
 *                             supposed to be the only actionable thing on it.
 *   `style-preview-fixture`   the pre-retune cast, and
 *   `style-preview-fixture-2` the post-retune cast. CLAUDE.md is explicit that
 *                             neither is a video and neither is ever
 *                             re-extracted: they are the measuring stick, and a
 *                             measuring stick that moves measures nothing. Both
 *                             sat in "Not moving" forever.
 *
 * **A section that always contains something it should not teaches you to skim
 * it**, and you skim it right past the day something real lands there. That is
 * the same failure as an alarm that is always on: not a wrong number, a true
 * one that has stopped being read.
 *
 * The note is a second column rather than a constant in the view because the
 * reason genuinely differs — one is a render-pipeline fixture, two are cast
 * measuring sticks — and a story's own page should be able to say WHICH
 * without the reader going to look it up.
 *
 * The backfill matches on slug and is idempotent: on any checkout without these
 * three it marks nothing, which is the correct behaviour for a fixture flag on
 * a machine that has no fixtures.
 */
return new class extends Migration
{
    /**
     * @var array<string, string>
     */
    private array $fixtures = [
        'sample-story' => 'The Phase 0 render fixture: twelve hand-made scenes with dummy stills and '
            .'audio, used to prove the render pipeline end to end without touching the network or '
            .'spending anything. It stops at Gate 3 on purpose — there is nothing here to publish.',

        'style-preview-fixture' => 'The style measuring stick, pre-retune. Holds the cast as it was '
            .'extracted BEFORE the hair, headwear, ageing-texture and build changes. Never '
            .'re-extracted and never advanced: a measuring stick that moves measures nothing.',

        'style-preview-fixture-2' => 'The style measuring stick, post-retune. Holds the cast extracted '
            .'AFTER those changes. Pointed at by `style:preview` alongside its predecessor — the pair '
            .'is what separates "the style changed" from "the descriptions changed", which is a '
            .'distinction two previews in a row cannot make.',
    ];

    public function up(): void
    {
        Schema::table('stories', function (Blueprint $table): void {
            $table->boolean('is_fixture')->default(false)->after('status');
            $table->text('fixture_note')->nullable()->after('is_fixture');
        });

        foreach ($this->fixtures as $slug => $note) {
            DB::table('stories')
                ->where('slug', $slug)
                ->update(['is_fixture' => true, 'fixture_note' => $note]);
        }
    }

    public function down(): void
    {
        Schema::table('stories', function (Blueprint $table): void {
            $table->dropColumn(['is_fixture', 'fixture_note']);
        });
    }
};
