<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `stories.premise_spine` — the seven spine answers the picked premise was
 * built on, kept so the outline is written from them.
 *
 * ---------------------------------------------------------------------------
 * WHAT DIED AT "USE THIS PREMISE", MEASURED
 * ---------------------------------------------------------------------------
 *
 * The premise generator returns, per candidate: a narrator, a cast, SEVEN
 * spine answers (PremiseCandidate::FIELDS) and the prose. Until 2026-09-20
 * `OutlineGate::usePremise()` stored the prose and the cast, and the seven
 * answers stayed in `premise_candidates` where only Gate 1's checks read them.
 *
 * So Gate 1 ran fourteen checks over seven answers, the operator picked on
 * what those checks said, and the outline — which is handed `stories.premise`
 * and nothing else — answered all seven again from the prose. Story 39's
 * candidate withheld "the only signer on the license renewal for the warehouse
 * lease"; the outline wrote an 8.4 million yuan Hamburg account. Neither is
 * wrong. Only one of them was checked and chosen.
 *
 * Four of the seven are at least tied to the prose by the premise checks
 * (betrayal_scene, antagonist_justification, withheld_information, departure,
 * by a two-word overlap). THREE ARE TIED TO NOTHING — accomplice_motive,
 * accomplice_performance and narrator_at_exposure are checked for their own
 * content and never for whether the prose carries them, so those three could
 * only ever have survived by luck.
 *
 * This is the third thing lost at that one function. The chosen cast and the
 * narrator's name were the first two (CLAUDE.md 3f, story 38).
 *
 * NOT an invariant, unlike the chosen cast. A name is checkable for identity
 * so `GenerateOutline` refuses an outline that drops one; a spine answer is
 * prose the outline has to EXPAND — into the exposure, the reversal beats and
 * the refusal it must fit — so it is handed over as the answers to build on
 * and nothing refuses a rewrite. The operator reads the result at Gate 1.
 *
 * Scoped like the chosen cast: read while the story has no acts. After that
 * the outline has answered, and its answers are the story's.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stories', function (Blueprint $table): void {
            $table->json('premise_spine')->nullable()->after('premise_candidates');
        });
    }

    public function down(): void
    {
        Schema::table('stories', function (Blueprint $table): void {
            $table->dropColumn('premise_spine');
        });
    }
};
