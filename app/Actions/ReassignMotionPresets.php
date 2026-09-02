<?php

namespace App\Actions;

use App\Enums\MotionPreset;
use App\Models\Scene;
use App\Models\Story;
use Illuminate\Support\Collection;

/**
 * Re-cuts the camera across a story without touching a word of it.
 *
 * Free, and that is a property of the schema rather than a hope: `needsImage()`
 * keys on `image_prompt` and `needsNarration()` on `narration_text`, and motion
 * appears in neither. It appears only in `needsClip()`, which is CPU and not
 * money. So this rewrites one column, bills nothing, and invalidates nothing
 * that was paid for.
 *
 * It exists because a scene generator asked for a motion preset per scene picks
 * the safest answer too often. A re-draft came back with 30% of the video
 * holding still — against 11% from the model it replaced — and every existing
 * check passed, because they tested scene length and not what the camera was
 * doing.
 *
 * **Static is the scarce one.** Every other preset is a camera move; static is
 * the absence of one. Held deliberately it is the strongest beat in the format
 * — a face receiving news, a document being read, the moment before someone
 * answers. Used as a default it is a slideshow. So static is not distributed,
 * it is AWARDED: every scene is scored for whether it is genuinely a held beat,
 * and only the best-scoring few get it, capped under the configured share.
 *
 * Everything else is assigned by what the frame is looking at — a wide exterior
 * pans, a close interior pushes — with a hard rule against the same preset
 * twice in a row, because a run of identical moves reads as mechanical even
 * when the mix across the whole story is fine.
 */
class ReassignMotionPresets
{
    /**
     * Frames that earn a held camera. Scored, not matched: a frame that both
     * closes on a face AND has that face reading something is a better held
     * beat than one that merely happens indoors.
     */
    private const HELD_BEAT = [
        // The camera is already as close as it goes. Moving it competes with
        // the face.
        'close on' => 3,
        'tight shot' => 3,
        'tight on' => 3,
        'close two-shot' => 2,
        'extreme close' => 3,

        // Something is being read. The viewer needs the frame still enough to
        // read it too.
        'spreadsheet' => 2,
        'letter' => 2,
        'handwriting' => 2,
        'document' => 2,
        'the page' => 2,
        'printed' => 1,
        'screen' => 1,
        'photograph' => 2,
        'receipt' => 2,
        'will' => 1,

        // A held object, alone in frame.
        'lies open' => 2,
        'lying on' => 1,
        'flat on' => 1,
        'in her hand' => 1,
        'in his hand' => 1,
    ];

    /** Frames that want a lateral move: space to travel across. */
    private const LATERAL = [
        'wide', 'across the', 'parking lot', 'yard', 'street', 'from above',
        'lawn', 'hallway', 'corridor', 'room', 'kitchen', 'exterior',
        'seen from', 'in the background', 'far ', 'behind her', 'behind him',
    ];

    /**
     * @return array{changed: int, before: array<string, int>, after: array<string, int>, static_scenes: array<int, int>}
     */
    public function handle(Story $story, bool $apply = true): array
    {
        $scenes = $story->scenes()->orderBy('sequence')->get();

        if ($scenes->isEmpty()) {
            return ['changed' => 0, 'before' => [], 'after' => [], 'static_scenes' => []];
        }

        $before = $this->distribution($scenes);

        $held = $this->awardStatic($scenes);
        $assigned = $this->assignMoves($scenes, $held);

        $changed = 0;

        foreach ($scenes as $scene) {
            $preset = $assigned[$scene->id];

            if ($scene->motion_preset === $preset) {
                continue;
            }

            $changed++;

            if ($apply) {
                // One column. Narration and image_prompt are not read, not
                // written, and not touched — which is what makes this free.
                $scene->forceFill(['motion_preset' => $preset])->save();
            }
        }

        $scenes->each(fn (Scene $s) => $s->setAttribute('motion_preset', $assigned[$s->id]));

        return [
            'changed' => $changed,
            'before' => $before,
            'after' => $this->distribution($scenes),
            'static_scenes' => $held,
        ];
    }

    /**
     * Which scenes have earned a held camera.
     *
     * Capped below the threshold rather than at it. Landing exactly on the
     * ceiling means the next scene an operator flags static tips the story over
     * it; leaving headroom means the number is a target rather than a limit.
     *
     * @param  Collection<int, Scene>  $scenes
     * @return array<int, int> Scene ids, best first.
     */
    private function awardStatic(Collection $scenes): array
    {
        $ceiling = (float) config('scenes.static_share_threshold', 0.15);
        $budget = (int) floor($scenes->count() * $ceiling * 0.8);

        $scored = $scenes
            ->map(fn (Scene $scene): array => [
                'id' => $scene->id,
                'sequence' => $scene->sequence,
                'score' => $this->heldBeatScore((string) $scene->image_prompt),
            ])
            ->filter(fn (array $row): bool => $row['score'] > 0)
            // Best first, then earliest — a stable order, so running this twice
            // produces the same cut rather than reshuffling the video.
            ->sortBy([['score', 'desc'], ['sequence', 'asc']])
            ->take($budget);

        return $scored->pluck('id')->all();
    }

    private function heldBeatScore(string $prompt): int
    {
        // Only the frame. The cast block below it describes people, not the
        // camera, and every prompt carries it — scoring on it would give every
        // scene the same points.
        $frame = mb_strtolower(explode("\n\n", $prompt)[0]);

        $score = 0;

        foreach (self::HELD_BEAT as $needle => $weight) {
            if (str_contains($frame, $needle)) {
                $score += $weight;
            }
        }

        return $score;
    }

    /**
     * Assign a move to everything that did not earn a hold.
     *
     * @param  Collection<int, Scene>  $scenes
     * @param  array<int, int>  $held
     * @return array<int, MotionPreset> keyed by scene id
     */
    private function assignMoves(Collection $scenes, array $held): array
    {
        $heldIds = array_flip($held);
        $assigned = [];
        $previous = null;

        // Two rotations rather than one, so the choice of FAMILY comes from the
        // frame and only the direction alternates. A wide exterior that pushes
        // in looks like a mistake; a close interior that pans looks like a
        // camera operator who lost the subject.
        $lateral = [MotionPreset::PanRight, MotionPreset::PanLeft];
        $push = [MotionPreset::ZoomIn, MotionPreset::ZoomOut];

        $lateralAt = 0;
        $pushAt = 0;

        foreach ($scenes as $scene) {
            if (isset($heldIds[$scene->id])) {
                $assigned[$scene->id] = MotionPreset::Static;
                $previous = MotionPreset::Static;

                continue;
            }

            $family = $this->wantsLateral((string) $scene->image_prompt) ? $lateral : $push;

            $choice = $family[($family === $lateral ? $lateralAt : $pushAt) % 2];

            // Never the same move twice running. At 187 scenes a correct
            // overall mix can still contain a stretch of nine identical
            // push-ins, and the viewer sees the stretch, not the mix.
            if ($choice === $previous) {
                $choice = $family[(($family === $lateral ? $lateralAt : $pushAt) + 1) % 2];
            }

            if ($family === $lateral) {
                $lateralAt++;
            } else {
                $pushAt++;
            }

            $assigned[$scene->id] = $choice;
            $previous = $choice;
        }

        return $assigned;
    }

    private function wantsLateral(string $prompt): bool
    {
        $frame = mb_strtolower(explode("\n\n", $prompt)[0]);

        foreach (self::LATERAL as $needle) {
            if (str_contains($frame, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  Collection<int, Scene>  $scenes
     * @return array<string, int>
     */
    private function distribution(Collection $scenes): array
    {
        $counts = [];

        foreach (MotionPreset::cases() as $preset) {
            $counts[$preset->value] = 0;
        }

        foreach ($scenes as $scene) {
            $counts[$scene->motion_preset->value]++;
        }

        return $counts;
    }
}
