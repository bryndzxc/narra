<?php

namespace App\Actions;

use App\Contracts\ScriptWriter;
use App\Enums\RenderStage;
use App\Enums\StoryStatus;
use App\Models\Character;
use App\Models\RenderJob;
use App\Models\Story;
use App\Support\CharacterTextGuard;
use App\Support\LocaleGuard;
use App\Support\Providers\CharacterCast;
use App\Support\Providers\CharacterProfile;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Extract the cast, and freeze how each of them looks.
 *
 * Runs before a single scene is drafted, and that ordering is the entire point.
 * Character consistency across 150-250 stills is the single biggest quality
 * risk in this project and it gets worse the longer the video runs. The
 * mechanism is that one description is written once and then pasted verbatim
 * into every prompt that character appears in — so it has to exist before the
 * prompts do. Scenes drafted first would each invent their own description of
 * the same person, which is the drift itself.
 *
 * This is text. It happens before a cent is spent on images, which is when it
 * is still free to fix.
 *
 * Idempotent: a story that already has a cast keeps it unless the caller asks
 * for a rebuild. Re-running bills, and a re-extraction that silently replaced
 * descriptions would invalidate every prompt already written against them.
 */
class ExtractCharacters
{
    public function __construct(
        private readonly ScriptWriter $writer,
        private readonly LocaleGuard $locale,
        private readonly RecordProviderCost $costs,
        private readonly CharacterTextGuard $text,
    ) {}

    /**
     * @return array{cast: CharacterCast|null, characters: int, kept: bool}
     */
    public function handle(Story $story, bool $rebuild = false): array
    {
        $this->assertReady($story);

        // Its own row, because this stage fails on its own and runs before
        // the one that used to be the only thing recording. See
        // RenderStage::ExtractCast: three dispatches died in here and the
        // render page reported nothing, because DraftScenes had not opened
        // its row yet and DraftSceneListJob::failed() had nothing to mark.
        return RenderJob::record(
            $story->id,
            RenderStage::ExtractCast,
            fn (RenderJob $job): array => $this->extract($story, $rebuild, $job),
        );
    }

    /**
     * @return array{cast: CharacterCast|null, characters: int, kept: bool}
     */
    private function extract(Story $story, bool $rebuild, RenderJob $job): array
    {
        if (! $rebuild && $story->characters()->exists()) {
            $job->note(sprintf(
                'Kept the %d character(s) already on this story. Nothing was billed.',
                $story->characters()->count(),
            ));

            return [
                'cast' => null,
                'characters' => $story->characters()->count(),
                'kept' => true,
            ];
        }

        $scripts = $story->acts()->orderBy('sequence')->pluck('script')
            ->filter(fn (?string $script): bool => trim((string) $script) !== '')
            ->values()
            ->all();

        if ($scripts === []) {
            throw new RuntimeException(
                'This story has no act scripts, so there is nothing to extract a cast from. Generate '
                .'the scripts first — the cast is read out of them, not out of the outline.'
            );
        }

        $cast = $this->extractWithRepair($story, $scripts, $job);

        $this->locale->assert(
            $cast->proseForInspection(),
            (string) $story->locale_profile,
            'character extraction'
        );

        DB::transaction(function () use ($story, $cast): void {
            // A rebuild replaces the cast rather than merging into it. Merging
            // would leave a character whose description was rewritten sitting
            // next to scenes still built from the old one, and nothing would
            // say which was which.
            $story->characters()->delete();

            foreach ($cast->characters as $profile) {
                Character::create([
                    'story_id' => $story->id,
                    'name' => $profile->name,
                    'description' => $profile->description,
                    'style_notes' => $profile->styleNotes,
                    // Assigned now, deterministically, rather than at image
                    // time. A locked seed is half the consistency mechanism and
                    // it costs nothing to fix here; deriving it from the story
                    // and the name means a rebuild of the same cast lands on
                    // the same seeds rather than quietly re-rolling every face.
                    'seed' => $this->seedFor($story, $profile),
                ]);
            }
        });

        return [
            'cast' => $cast,
            'characters' => count($cast->characters),
            'kept' => false,
        ];
    }

    /**
     * Extract, and give it one chance to fix character text it got wrong.
     *
     * A retry rather than a refusal because the failure is narrow, mechanical
     * and the model can see it once it is named. The alternative is a stage
     * that dies on a single stray word and leaves the operator to re-run it by
     * hand — for a defect they cannot see and did not cause.
     *
     * Bounded at one retry. A second failure means the instruction is not
     * landing, and re-rolling a prompt that is not working is how a $0.04 call
     * becomes a loop.
     *
     * Both attempts are billed and both write a cost row. The first call
     * happened; hiding it because its output was discarded would understate the
     * story by the cost of the attempt.
     *
     * @param  array<int, string>  $scripts
     */
    private function extractWithRepair(Story $story, array $scripts, RenderJob $job): CharacterCast
    {
        $notes = [];

        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $cast = $this->writer->characters($story, $scripts, $notes);

            // Recorded before anything is checked, because it has already been
            // billed whatever the answer turns out to be.
            $this->costs->handle($story, $cast->usage);

            $notes = $this->textProblems($cast);

            // A line per billed attempt. Without it the row says only that
            // the stage failed, and the thing an operator actually needs to
            // know is that it failed TWICE and bought two calls doing it.
            $job->note(sprintf(
                'Attempt %d: %d character(s), $%s. %s',
                $attempt,
                count($cast->characters),
                number_format($cast->usage->usdCost, 4),
                $notes === [] ? 'Clean.' : count($notes).' problem(s) to repair.',
            ));

            if ($notes === []) {
                return $cast;
            }
        }

        // Out of attempts. Refused rather than saved, because the whole point
        // of this field is that it is applied unconditionally — a bad one is
        // not a cosmetic flaw, it is a prop in every frame that character is
        // in. See CharacterTextGuard.
        $this->text->assert($cast->characters, 'character extraction');

        return $cast;
    }

    /**
     * Both fields, not one.
     *
     * This checked `style_notes` alone for two phases while `description` had
     * the identical defects sitting in production — two leads whose hair was
     * "usually" pulled back, and a man whose description said he walks with a
     * stiffness in one hip, each pasted into every frame they appear in. The
     * guard was aimed at one field while the same bug lived in the field beside
     * it, so it could not fire, and a check that cannot fire reads exactly like
     * a check that passed.
     *
     * @return array<int, string> Empty when the cast is clean.
     */
    private function textProblems(CharacterCast $cast): array
    {
        $problems = [];

        foreach ($cast->characters as $profile) {
            $violations = $this->text->violations($profile->styleNotes);

            if ($violations !== []) {
                $problems[] = sprintf(
                    '%s: style_notes "%s" — %s',
                    $profile->name,
                    $profile->styleNotes,
                    implode('; ', $violations),
                );
            }

            $violations = $this->text->descriptionViolations($profile->description);

            if ($violations !== []) {
                $problems[] = sprintf(
                    '%s: description "%s" — %s',
                    $profile->name,
                    $profile->description,
                    implode('; ', $violations),
                );
            }
        }

        return $problems;
    }

    /**
     * A stable seed per character per story.
     *
     * Deterministic on purpose. Random seeds would mean a re-extraction — after
     * an operator edits one description at Gate 2, say — silently re-rolls
     * every other character's face too, which is the opposite of what this
     * table is for. crc32 is not a security decision here; it just needs to be
     * spread out and repeatable.
     */
    /**
     * The locked seed for a character, derived from the story and the NAME.
     *
     * -----------------------------------------------------------------------
     * A NAME IS A GENERATION INPUT HERE, NOT A LABEL. READ THIS BEFORE ADDING A
     * WAY TO RENAME A CHARACTER.
     * -----------------------------------------------------------------------
     *
     * `crc32(slug|name)` is deterministic on purpose — a rebuild of the same
     * cast lands on the same seeds rather than quietly re-rolling every face,
     * which is the property the comment at the call site is about.
     *
     * The consequence is the part with no guard on it: **the seed is a pure
     * function of the name, so changing a character's name changes their
     * face.** A locked seed is half the consistency mechanism; the reference
     * sheet is the other half, and a new seed means the next still of that
     * character is generated from a different starting point than the 30-90
     * that came before it.
     *
     * **This is inert today, and only because nothing can rename a character.**
     * There is no rename in any Livewire component, no console command, and no
     * form — `name` arrives once, from `ExtractCharacters`, and a cast rebuild
     * re-reads it from the same act scripts. So the seed cannot move without
     * the scripts moving, and if the scripts moved the faces should change.
     *
     * If a rename is ever added, it is not a cosmetic edit and must not be
     * built as one. Three options, none of them free, and the choice belongs to
     * whoever needs the feature:
     *
     *  1. **Keep the seed, rename the row.** Stop deriving from the name and
     *     store the seed as an ordinary column, seeded once at extraction. The
     *     face survives a rename. It costs the rebuild property above — two
     *     extractions of the same cast would no longer agree — unless the
     *     column is preserved across a rebuild by matching on something else,
     *     which is the same identity problem one level along.
     *  2. **Let the seed move, and say so.** A rename becomes a face change,
     *     stated at the point of renaming, and every existing still of that
     *     character is stale in the way a retuned art style makes a reference
     *     sheet stale. `Character::referenceStyleState()` is the shape to copy.
     *  3. **Refuse a rename once stills exist.** Cheapest and probably right
     *     for this pipeline: the name is decided at the outline and frozen at
     *     extraction, and by the time anybody wants to change it there are
     *     150-250 paid stills conditioned on the face it produced.
     *
     * What must NOT happen is a rename that silently changes the seed, because
     * the cost is invisible until every still has been paid for — which is the
     * exact shape of the drift the reference mechanism exists to prevent.
     */
    private function seedFor(Story $story, CharacterProfile $profile): int
    {
        return crc32($story->slug.'|'.mb_strtolower(trim($profile->name)));
    }

    private function assertReady(Story $story): void
    {
        if ($story->status->rank() < StoryStatus::Scripted->rank()) {
            throw new RuntimeException(sprintf(
                "Cannot extract a cast from a story at '%s'. The cast is read out of the act scripts, "
                .'and those are written after Gate 1.',
                $story->status->value
            ));
        }
    }
}
