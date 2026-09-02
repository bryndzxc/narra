<?php

namespace App\Actions;

use App\Models\Character;
use App\Models\Story;
use Illuminate\Support\Collection;

/**
 * Whether this story's cast is ready for the expensive stage.
 *
 * The same question ResolveSceneReferences asks per scene, asked once for the
 * whole story at the moment it can still be answered cheaply. Both are needed
 * and neither replaces the other:
 *
 *  - This one runs at Gate 2 and BLOCKS approval. It is the useful failure —
 *    nothing has been spent, and the operator is standing in front of the
 *    screen that fixes it.
 *  - The per-scene one runs inside the image job and is the backstop. It is a
 *    horrible place to find out, mid-batch, which is exactly why it must still
 *    exist: a story that reached it has already got past this check, and the
 *    only thing worse than failing there is not failing there.
 *
 * Only characters who actually appear in a scene are required to have a face.
 * A minor character extracted from the script but never put in a frame costs
 * nothing to leave unreferenced, and demanding a sheet for them would make the
 * gate refuse to open over an image no still will ever cite.
 */
class ValidateCharacterSheets
{
    /**
     * @return array{ready: bool, missing: Collection<int, Character>, scenes_blocked: int, unused: Collection<int, Character>}
     */
    public function handle(Story $story): array
    {
        $cast = $story->characters()->withCount('scenes')->orderBy('name')->get();

        $appearing = $cast->filter(fn (Character $c): bool => ($c->scenes_count ?? 0) > 0);

        $missing = $appearing->reject(fn (Character $c): bool => $c->hasUsableReference())->values();

        return [
            'ready' => $missing->isEmpty(),
            'missing' => $missing,
            'scenes_blocked' => $this->scenesBlocked($missing),
            // Not a failure, but worth surfacing: a character in the cast and in
            // no frame is usually a sign the scene generator quietly stopped
            // using somebody the outline thought was in the story.
            'unused' => $cast->filter(fn (Character $c): bool => ($c->scenes_count ?? 0) === 0)->values(),
        ];
    }

    /**
     * How many distinct scenes cannot be generated as things stand.
     *
     * Distinct, because two unreferenced characters sharing a frame block one
     * scene between them. The count is what makes the block concrete: "3
     * characters missing" is a chore, "3 characters missing, 118 scenes
     * blocked" is the reason the gate will not open.
     *
     * @param  Collection<int, Character>  $missing
     */
    private function scenesBlocked(Collection $missing): int
    {
        if ($missing->isEmpty()) {
            return 0;
        }

        return Character::whereIn('characters.id', $missing->pluck('id'))
            ->join('character_scene', 'character_scene.character_id', '=', 'characters.id')
            ->distinct()
            ->count('character_scene.scene_id');
    }
}
