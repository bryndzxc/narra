<?php

namespace App\Actions;

use App\Models\Character;
use App\Models\CharacterReference;
use App\Models\Scene;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The operator's pick: this face, from now on, in every still this character
 * appears in.
 *
 * Free, and re-doable for nothing. The candidates are already generated and
 * already on disk, so changing the pick is a database write — which is exactly
 * why all the rejects are kept. An operator who chooses candidate 2, reaches
 * scene 40 and decides candidate 4 was right should not have to pay to change
 * their mind.
 *
 * Two things it does beyond writing the column:
 *
 * **It refuses a reference that is not actually there.** A `ready` row whose
 * file has been deleted would set `reference_image_path` to a path that
 * resolves to nothing, and the next thing to notice would be the scene stage,
 * 199 images deep. The check is cheap here and expensive there.
 *
 * **It reports what re-picking invalidates.** Changing a face after stills
 * exist makes every still that character is in wrong — they show the old face.
 * This does not delete them, in keeping with how Gate 2's reopen behaves:
 * nothing is destroyed, the operator is told the count, and the staleness is
 * settled when the gate is approved again and the bill is shown.
 */
class SelectCharacterReference
{
    /**
     * @return int Scenes whose existing still no longer matches the chosen face.
     */
    public function handle(Character $character, CharacterReference $reference): int
    {
        if ($reference->character_id !== $character->id) {
            throw new RuntimeException(sprintf(
                'Reference %d belongs to another character. Picking it would pin the wrong face '
                .'into every still "%s" appears in.',
                $reference->id,
                $character->name,
            ));
        }

        if (! $reference->isUsable()) {
            throw new RuntimeException(sprintf(
                'Reference %d for "%s" is %s and its file is %s, so it cannot be the face the '
                .'stills are built from. Regenerate the sheet.',
                $reference->id,
                $character->name,
                $reference->status->value,
                $reference->exists() ? 'present' : 'missing from disk',
            ));
        }

        $invalidated = $this->stillsShowingTheOldFace($character, $reference);

        DB::transaction(function () use ($character, $reference): void {
            // Cleared across the whole character rather than only the previously
            // selected row. "At most one selected" is enforced in code because
            // MySQL cannot express it, and code that only clears the row it
            // believes is selected cannot repair the state where two are.
            $character->references()->update(['selected_at' => null]);

            $reference->forceFill(['selected_at' => now()])->save();

            $character->forceFill([
                'reference_image_path' => $reference->image_path,
                // The seed the winning candidate was actually generated with,
                // when there is one. The seed's job begins at the pick: it holds
                // THIS face steady across the stills. A seed left pointing at a
                // face nobody chose is worse than none.
                'seed' => $reference->seed ?? $character->seed,
            ])->save();
        });

        return $invalidated;
    }

    /**
     * Scenes with a still already generated that features this character.
     *
     * Counted, not deleted. Same posture as reopening Gate 2: destroy nothing,
     * say the number, and let the operator decide at the gate where the cost of
     * regenerating is shown.
     */
    private function stillsShowingTheOldFace(Character $character, CharacterReference $reference): int
    {
        if ($character->reference_image_path === null
            || $character->reference_image_path === $reference->image_path) {
            return 0;
        }

        return Scene::query()
            ->whereNotNull('image_path')
            ->whereHas('characters', fn ($q) => $q->whereKey($character->id))
            ->count();
    }
}
