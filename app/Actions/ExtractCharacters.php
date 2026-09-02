<?php

namespace App\Actions;

use App\Contracts\ScriptWriter;
use App\Enums\StoryStatus;
use App\Models\Character;
use App\Models\Story;
use App\Support\LocaleGuard;
use App\Support\Providers\CharacterCast;
use App\Support\Providers\CharacterProfile;
use App\Support\StyleNotesGuard;
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
        private readonly StyleNotesGuard $styleNotes,
    ) {}

    /**
     * @return array{cast: CharacterCast|null, characters: int, kept: bool}
     */
    public function handle(Story $story, bool $rebuild = false): array
    {
        $this->assertReady($story);

        if (! $rebuild && $story->characters()->exists()) {
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

        $cast = $this->extractWithRepair($story, $scripts);

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
     * Extract, and give it one chance to fix a style_notes it got wrong.
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
    private function extractWithRepair(Story $story, array $scripts): CharacterCast
    {
        $notes = [];

        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $cast = $this->writer->characters($story, $scripts, $notes);

            // Recorded before anything is checked, because it has already been
            // billed whatever the answer turns out to be.
            $this->costs->handle($story, $cast->usage);

            $notes = $this->styleNoteProblems($cast);

            if ($notes === []) {
                return $cast;
            }
        }

        // Out of attempts. Refused rather than saved, because the whole point
        // of this field is that it is applied unconditionally — a bad one is
        // not a cosmetic flaw, it is a prop in every frame that character is
        // in. See StyleNotesGuard.
        $this->styleNotes->assert($cast->characters, 'character extraction');

        return $cast;
    }

    /**
     * @return array<int, string> Empty when the cast is clean.
     */
    private function styleNoteProblems(CharacterCast $cast): array
    {
        $problems = [];

        foreach ($cast->characters as $profile) {
            $violations = $this->styleNotes->violations($profile->styleNotes);

            if ($violations !== []) {
                $problems[] = sprintf(
                    '%s: style_notes "%s" — %s',
                    $profile->name,
                    $profile->styleNotes,
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
