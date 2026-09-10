<?php

namespace App\Support;

use App\Models\Scene;

/**
 * How well one still would read at thumbnail size.
 *
 * **This is not face detection, and calling it that would be the documented-
 * guard mistake in a new place.** Nothing in this stack can find a face in a
 * JPEG: FFmpeg cannot, GD cannot, and buying a service that can would break the
 * one rule this feature has, which is that it costs nothing. So the score is
 * built from the two things the app already knows about a still and knows
 * exactly:
 *
 *   1. **Who is in it** — `scene_character`, recorded at draft time from the
 *      names the generator put in the frame, resolved against the stored cast.
 *      A scene with nobody in it has no face at any size. A scene with five
 *      people has five small ones.
 *
 *   2. **How it was framed** — the frame sentence at the top of `image_prompt`,
 *      which is the text that PRODUCED the picture. "Tight close on Lu Wenbin's
 *      face" and "the walnut chair now sits in an unfamiliar living room" are
 *      not guesses about the image; they are its instructions.
 *
 * Both can be wrong about the file on disk — a generator does not always obey —
 * so this ranks rather than decides. The compositions it produces are shown to
 * the operator, who is looking at the actual image, and the reasons are printed
 * beside each one so a bad ranking is visibly a bad ranking rather than an
 * unexplained order. That is the same split every guard in this project uses:
 * the check detects, the operator judges.
 *
 * The failure it exists to catch is the one that prompted it: a wide
 * establishing shot makes a poor thumbnail regardless of how good the frame is,
 * and story 21 has exactly that flagged as a thumbnail candidate — scene 82, a
 * chair in an empty room, nominated by the same model that wrote the scene.
 */
final class ThumbnailFraming
{
    /**
     * Framing language that means the subject fills the frame.
     *
     * Matched against the frame sentence only, never the whole prompt: the cast
     * block below it says "Mid-thirties, straight black hair" for every
     * character in every scene, and "face" appears in most descriptions. A
     * match there would score every still identically, which is the same as not
     * scoring at all.
     */
    private const CLOSE_MARKERS = [
        'tight close', 'close on', 'close-up', 'closeup', 'close up',
        'fills the frame', 'inches from', 'her face', 'his face', 'their face',
        'eyes fixed', 'eye level', 'over the shoulder',
    ];

    private const MID_MARKERS = [
        'mid shot', 'mid-shot', 'from the waist', 'across the table',
        'sits opposite', 'facing', 'leans', 'turns to', 'hands',
    ];

    /**
     * Framing language that means the people are small or absent.
     *
     * The strongest single signal in the list, and the reason the whole class
     * exists.
     */
    private const WIDE_MARKERS = [
        'wide', 'establishing', 'from the doorway', 'across the room',
        'in the distance', 'from above', 'aerial', 'from behind',
        'the street', 'the corridor', 'empty', 'unoccupied', 'deserted',
        'skyline', 'exterior', 'far side', 'silhouette', 'far end',
    ];

    /**
     * Score a still, with the reasoning that produced the score.
     *
     * @return array{
     *     scene_id: int,
     *     sequence: int,
     *     score: int,
     *     shot: string,
     *     cast: int,
     *     usable: bool,
     *     reasons: array<int, string>,
     *     frame: string,
     * }
     */
    public function score(Scene $scene, ?int $castCount = null): array
    {
        $frame = mb_strtolower($this->frameOf($scene));
        $cast = $castCount ?? $scene->characters()->count();

        $reasons = [];
        $score = 0;

        // -- Who is in it --------------------------------------------------
        //
        // Zero characters is near-disqualifying ON PURPOSE, and the scene
        // prompt now says the same thing. It did not always: the nomination
        // rule asked for "a face mid-reaction, or an object that raises a
        // question", the model obeyed, and this term then scored the envelope
        // it had been asked for at -40 — three of story 25's six flags, and a
        // composed pair of two empty desks at -90 still offered as an option.
        // This niche's thumbnails are faces; the prompt no longer asks for
        // objects, and a test holds the two texts side by side so they cannot
        // drift apart again without one of them going red.
        [$castScore, $castReason] = match (true) {
            $cast === 0 => [-40, 'nobody recorded in this frame'],
            $cast === 1 => [30, 'one character — the largest a face gets in this format'],
            $cast === 2 => [18, 'two characters'],
            $cast === 3 => [4, 'three characters, so each face is smaller'],
            default => [-10, sprintf('%d characters — every face is small', $cast)],
        };

        $score += $castScore;
        $reasons[] = $castReason;

        // -- How it was framed ---------------------------------------------
        //
        // Wide is tested FIRST, and the precedence is the whole judgement in
        // this block. "Tight close on her face, the wide street behind her"
        // scores wide, which is arguably wrong about the picture — but the two
        // errors are not symmetrical. A false `wide` demotes a good still that
        // three others will outrank anyway; a false `close` promotes a bad one
        // into a composition the operator then has to notice. Err toward the
        // one that costs a ranking rather than the one that costs a thumbnail.
        ['shot' => $shot, 'markers' => $markers] = $this->framing($frame);

        match ($shot) {
            'wide' => $score -= 25,
            'close' => $score += 30,
            'mid' => $score += 10,
            default => null,
        };

        $reasons[] = $shot === 'unstated'
            ? 'the frame does not state a shot scale'
            : sprintf('framed %s: "%s"', $shot, implode('", "', array_slice($markers, 0, 2)));

        // The opening still is engineered to stop a scroll, which is the same
        // job a thumbnail has. A small nudge, not a decision.
        if ($scene->is_hook) {
            $score += 6;
            $reasons[] = 'the opening hook frame';
        }

        return [
            'scene_id' => (int) $scene->id,
            'sequence' => (int) $scene->sequence,
            'score' => $score,
            'shot' => $shot,
            'cast' => $cast,
            // A still with no file is not a low-scoring still, it is not a
            // still. Excluded by the caller, with this as the reason.
            'usable' => trim((string) $scene->image_path) !== '',
            'reasons' => $reasons,
            'frame' => $this->frameOf($scene),
        ];
    }

    /**
     * The shot scale a frame states, and the words that said so.
     *
     * Extracted from score() so that it is asked rather than repeated. It is
     * now read by two callers with different jobs — this class ranks a still
     * for a thumbnail, and ValidateSceneDrafts asks Gate 2 whether a close
     * frame named anywhere for the camera to be — and two copies of a marker
     * list is how they come to disagree about what "close" means.
     *
     * The precedence is unchanged and the reasoning for it is in score(): wide
     * is tested first because a false `wide` costs a ranking and a false
     * `close` costs a thumbnail.
     *
     * @return array{shot: string, markers: array<int, string>}
     */
    public function framing(string $frame): array
    {
        $frame = mb_strtolower($frame);

        foreach (['wide' => self::WIDE_MARKERS, 'close' => self::CLOSE_MARKERS, 'mid' => self::MID_MARKERS] as $shot => $markers) {
            if (($hits = $this->hits($frame, $markers)) !== []) {
                return ['shot' => $shot, 'markers' => $hits];
            }
        }

        return ['shot' => 'unstated', 'markers' => []];
    }

    /**
     * The frame sentence: what the generator was asked to draw.
     *
     * `image_prompt` is the assembled prompt — frame, then the cast block, then
     * the art style, then the constraints — joined by blank lines by
     * ImagePromptBuilder::build(). The split convention belongs to that class,
     * so it is asked rather than repeated here.
     */
    public function frameOf(Scene $scene): string
    {
        return ImagePromptBuilder::frameFrom((string) $scene->image_prompt);
    }

    /**
     * @param  array<int, string>  $markers
     * @return array<int, string>
     */
    private function hits(string $text, array $markers): array
    {
        if (trim($text) === '') {
            return [];
        }

        return array_values(array_filter(
            $markers,
            // Whole words. The lesson from CharacterTextGuard, which had to
            // carry hacks like 'mic ' with a trailing space until it matched on
            // boundaries — and 'wide' inside "widened" is exactly that problem.
            fn (string $marker): bool => (bool) preg_match('/\b'.preg_quote($marker, '/').'\b/u', $text)
        ));
    }
}
