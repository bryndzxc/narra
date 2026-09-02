<?php

namespace App\Actions;

use App\Models\Character;
use App\Models\Story;
use App\Support\CharacterSheetEstimate;
use App\Support\ReferenceRateCard;
use Illuminate\Support\Collection;

/**
 * Price the cast's reference sheets before any of them are generated.
 *
 * "Nothing is regenerated silently, because re-running a stage costs money" has
 * a corollary that only bites now that assets are involved: nothing is
 * GENERATED silently either. This is what the Gate 2 sheet reads to put a
 * dollar figure on screen before the button is live.
 *
 * Only characters with no usable reference are counted. A character whose face
 * is already picked and on disk costs nothing to leave alone, and including
 * them in the total would teach the operator that the number is theatre.
 */
class EstimateCharacterSheets
{
    public function __construct(private readonly ReferenceRateCard $rates) {}

    /**
     * @param  Character|null  $only  Price a single character's sheet rather than the cast's.
     */
    public function handle(Story $story, ?Character $only = null): CharacterSheetEstimate
    {
        $cast = $story->characters()->withCount('scenes')->orderBy('name')->get();

        $pending = $only !== null
            // A named character is priced whether or not they already have a
            // face: asking for this one specifically IS the regenerate path,
            // and quoting zero for it would be the silent regeneration the
            // spec forbids.
            ? $cast->where('id', $only->id)->values()
            : $this->withoutUsableReference($cast);

        return new CharacterSheetEstimate(
            pending: $pending,
            castSize: $cast->count(),
            candidatesEach: $this->candidates(),
            usdPerImage: $this->rates->usdPerImage(),
            scenesUnblocked: $this->scenesCovered($pending),
            scenesTotal: $story->scenes()->count(),
            provider: $this->rates->provider(),
            model: $this->rates->model(),
            rateIsDeclared: $this->rates->isDeclaredRatherThanBilled(),
        );
    }

    /**
     * The configured candidate count, clamped.
     *
     * A fat-fingered config value should not be able to authorise a hundred
     * images from a button labelled "generate sheet". Clamped in the estimate
     * as well as in the generator so the number quoted is the number spent.
     */
    public function candidates(): int
    {
        return max(1, min(
            (int) config('characters.candidates', 4),
            (int) config('characters.max_candidates', 6),
        ));
    }

    /**
     * @param  Collection<int, Character>  $cast
     * @return Collection<int, Character>
     */
    private function withoutUsableReference(Collection $cast): Collection
    {
        return $cast->reject(fn (Character $c): bool => $c->hasUsableReference())->values();
    }

    /**
     * How many distinct scenes gain a locked face if these sheets are bought.
     *
     * Distinct, not summed: two characters in the same frame unblock one scene
     * between them, and adding their scene counts would inflate the figure the
     * spend is being justified by.
     *
     * @param  Collection<int, Character>  $pending
     */
    private function scenesCovered(Collection $pending): int
    {
        if ($pending->isEmpty()) {
            return 0;
        }

        return Character::whereIn('characters.id', $pending->pluck('id'))
            ->join('character_scene', 'character_scene.character_id', '=', 'characters.id')
            ->distinct()
            ->count('character_scene.scene_id');
    }
}
