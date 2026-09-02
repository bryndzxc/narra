<?php

namespace App\Actions;

use App\Models\Scene;
use App\Models\Story;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Moving and removing scenes, with the numbering kept contiguous.
 *
 * An Action rather than component code because it carries two invariants that
 * have nothing to do with the UI:
 *
 *  1. `(story_id, sequence)` is unique, so a swap cannot be two updates — the
 *     first collides with the row it is swapping against. One side parks on a
 *     sentinel first, inside a transaction, because a half-done reorder leaves
 *     a scene list with a hole or a duplicate in it.
 *
 *  2. Scene numbers are the operator's vocabulary. They appear on the progress
 *     page, in failure messages, and in conversation. Deleting scene 2 out of
 *     five and leaving 1,3,4,5 makes every later reference ambiguous, so the
 *     rest are renumbered.
 */
class ReorderScenes
{
    /** Sequence numbers start here, so 0 is free to park on. */
    private const SENTINEL = 0;

    /**
     * Where a whole-story renumber parks its rows on the way past itself.
     *
     * High rather than negative, because `scenes.sequence` is an
     * unsignedSmallInteger: a negative park value is not a temporary state, it
     * is a range error that aborts the transaction. 30000 sits far above any
     * real scene count and far below the column's ceiling.
     */
    private const PARK_BASE = 30000;

    private const SEQUENCE_MAX = 65535;

    /**
     * Swap a scene with its neighbour. Returns its new position, or null if it
     * was already at the end it was asked to move toward.
     */
    public function move(Scene $scene, int $direction): ?int
    {
        $neighbour = $scene->story->scenes()
            ->where('sequence', $direction < 0 ? '<' : '>', $scene->sequence)
            ->orderBy('sequence', $direction < 0 ? 'desc' : 'asc')
            ->first();

        if ($neighbour === null) {
            return null;
        }

        return DB::transaction(function () use ($scene, $neighbour): int {
            $mine = $scene->sequence;
            $theirs = $neighbour->sequence;

            $scene->update(['sequence' => self::SENTINEL]);
            $neighbour->update(['sequence' => $mine]);
            $scene->update(['sequence' => $theirs]);

            return $theirs;
        });
    }

    /**
     * Delete a scene and close the gap behind it.
     */
    public function delete(Scene $scene): int
    {
        $sequence = $scene->sequence;
        $story = $scene->story;

        DB::transaction(function () use ($scene, $story): void {
            $scene->delete();

            $story->scenes()
                ->where('sequence', '>', $scene->sequence)
                ->orderBy('sequence')
                ->get()
                ->each(fn (Scene $later) => $later->update(['sequence' => $later->sequence - 1]));
        });

        return $sequence;
    }

    /**
     * Force the whole story back to 1..n in current order.
     *
     * Not used by the gate flow — it is the repair tool for a story whose
     * numbering was damaged by something else, which is exactly the situation
     * where hand-editing rows is tempting and wrong.
     */
    public function renumber(Story $story): int
    {
        return DB::transaction(function () use ($story): int {
            $scenes = $story->scenes()->orderBy('sequence')->get();

            // Everything to a disjoint range first: 1..n overlaps the numbers
            // currently in use, and the unique index does not care that the end
            // state would have been fine.
            //
            // The park range is HIGH, not negative. `scenes.sequence` is an
            // unsignedSmallInteger, so -1 is not a temporary value, it is a
            // "Numeric value out of range" that aborts the transaction. This
            // method was documented as a repair tool nothing in the gate flow
            // called, so it had never been run against the real column type.
            if ($scenes->count() > self::PARK_BASE) {
                throw new RuntimeException(sprintf(
                    'Cannot renumber %d scenes: the parking range starts at %d and the column tops '
                    .'out at %d, so the two would overlap.',
                    $scenes->count(),
                    self::PARK_BASE,
                    self::SEQUENCE_MAX,
                ));
            }

            foreach ($scenes as $index => $scene) {
                $scene->update(['sequence' => self::PARK_BASE + $index]);
            }

            foreach ($scenes as $index => $scene) {
                $scene->update(['sequence' => $index + 1]);
            }

            return $scenes->count();
        });
    }
}
