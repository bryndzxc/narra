<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The betrayal as a SCENE: where the antagonist's justification is first said
 * aloud, to the narrator's face, in front of people, with the person it was
 * done with standing in the room.
 *
 * ---------------------------------------------------------------------------
 * THE FINDING
 * ---------------------------------------------------------------------------
 *
 * Seven stories measured (23, 25, 28, 29, 30, 31, 32). In all seven the
 * betrayal is either FOUND — a roommate's photos, a cc'd hotel booking — or
 * said in private at a kitchen table, and in all seven the justification is
 * first staged in private. Its first PUBLIC saying lands at 9-11 minutes
 * (25, 29-32) or never before the exposure (23, 28). No accomplice is in any
 * room with the narrator in any of them.
 *
 * The reference (docs/refence/transcript-3446.txt) stages it at 1:31-4:25,
 * directly after a 62-second cold open: she arrives late at a dinner of nine
 * people holding another man's hand, a witness asks "Who's this? Your younger
 * brother?", she says "He's my boyfriend" one word at a time, then justifies
 * it to his face — "I want to see a different view before I get married...
 * it won't affect our wedding" — and again to the witness after the man
 * leaves. The narrator answers back and still loses the round.
 *
 * The writer was OBEYING an instruction: hook beat 3 said the justification
 * lands "once here in a single sentence and again in act 2 or 3 at length".
 * Stories 29-32 did exactly that. So the instruction moves to chapter one and
 * this column says what chapter one is.
 *
 * ---------------------------------------------------------------------------
 * THE ACCOMPLICE IS PRESENT, AND DOES NOT HAVE TO SPEAK
 * ---------------------------------------------------------------------------
 *
 * Built against the material rather than a summary of it: the man she brings
 * never says a word in the whole video. He is stiff, smiles awkwardly, nods
 * and leaves when she whispers to him. His silence is what leaves her the one
 * talking. The field asks for him in the room; it does not ask him for lines.
 *
 * ---------------------------------------------------------------------------
 * `outlined_before_betrayal_scene`, AND WHY IT IS A COLUMN AND NOT AN INFERENCE
 * ---------------------------------------------------------------------------
 *
 * The two earlier legacy ages are inferred from the acts: every outline since
 * the reversal phase carries a phase the Action assigns, and every outline
 * since the timeframe carries a declaration the schema enumerates. This field
 * has no such tell. Its only marker is itself, and "empty" is exactly the
 * value a legacy story and a broken new outline share — one null carrying two
 * meanings, which is the shape `NameMatch` exists to split.
 *
 * So the fact is frozen here, at the one moment it is knowable: a story that
 * has acts NOW was outlined before this question existed. Sixteen stories.
 * Written by the predicate, not by a list of ids — a list is how a backfill
 * fixes the stories somebody looked at and leaves the rest behind the same
 * defect. `GenerateOutline` clears it when an outline is regenerated, and an
 * operator who types the field in by hand ends the excuse without touching
 * the flag. Nothing reads the flag except Gate 1's spine review.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stories', function (Blueprint $table) {
            // In narrative order: after the justification, where it is first said.
            $table->text('betrayal_scene')->nullable()->after('antagonist_justification');
            $table->boolean('outlined_before_betrayal_scene')->default(false)->after('betrayal_scene');
        });

        DB::table('stories')
            ->whereExists(fn ($query) => $query->from('acts')->whereColumn('acts.story_id', 'stories.id'))
            ->update(['outlined_before_betrayal_scene' => true]);
    }

    public function down(): void
    {
        Schema::table('stories', function (Blueprint $table) {
            $table->dropColumn(['betrayal_scene', 'outlined_before_betrayal_scene']);
        });
    }
};
