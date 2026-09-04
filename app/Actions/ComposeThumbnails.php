<?php

namespace App\Actions;

use App\Enums\ActPhase;
use App\Enums\MetadataStatus;
use App\Models\Scene;
use App\Models\Story;
use App\Models\YoutubeMetadata;
use App\Services\Ffmpeg;
use App\Support\RenderWorkspace;
use App\Support\ThumbnailFraming;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Builds the thumbnail candidates from stills the story already owns.
 *
 * **Nothing here generates an image and nothing here bills.** A 270-scene story
 * has already paid for every frame it could want; buying another one to crop in
 * half would be spending money to avoid making a choice. That is why this is an
 * ordinary Action and not a provider behind a contract with a fake — there is
 * no network call to fake.
 *
 * The format is the channel's: two stills side by side, faces prominent, no
 * text. The title carries the hook, so the image does not have to.
 *
 * Three decisions, in order, and each is a heuristic that the operator
 * overrules by looking at the result:
 *
 *  1. **Which stills.** The flagged candidates first — `is_thumbnail_candidate`
 *     is the model's nomination at draft time and the operator's to edit at
 *     Gate 2. Ranked by ThumbnailFraming, which is a proxy for face size and
 *     says so. If the flagged pool cannot fill the compositions the net widens
 *     to the whole story, and the result SAYS it widened; a silent widening
 *     would make the Gate 2 flags look respected when they were not.
 *
 *  2. **Which pairs.** Two panels showing the same character in the same act is
 *     one still cut in half. The pair score prefers two different leads and,
 *     more importantly, the two ENDS of the arc — the humiliation on the left
 *     and the reversal on the right, which is how this niche's thumbnails
 *     actually read. Since the reversal phase exists that is a real question
 *     the data can answer: `acts.phase`. On a story outlined before it, the
 *     first third against the last third is the same idea with less to go on.
 *
 *  3. **Which order.** Earlier scene left, later scene right. English reads
 *     left to right and so does a before-and-after.
 *
 * Re-runnable by construction: it writes over the same four filenames and
 * replaces the stored options wholesale. There is nothing to bill twice.
 */
class ComposeThumbnails
{
    public function __construct(
        private readonly Ffmpeg $ffmpeg,
        private readonly ThumbnailFraming $framing,
    ) {}

    /**
     * @return array{
     *     composed: array<int, array<string, mixed>>,
     *     notes: array<int, string>,
     *     pool: int,
     *     widened: bool,
     * }
     */
    public function handle(Story $story, ?int $wanted = null): array
    {
        $config = (array) config('youtube.thumbnail');
        $wanted ??= (int) $config['candidates'];

        $notes = [];

        $scored = $this->rankStills($story, $wanted, $notes, $widened);

        if (count($scored) < 2) {
            throw new RuntimeException(sprintf(
                'This story has %d usable still, and a split panel needs two. Stills are generated '
                .'after Gate 2; a story with none has not run its asset stage yet.',
                count($scored),
            ));
        }

        $pairs = $this->pairs($scored, $wanted);
        $workspace = RenderWorkspace::for($story);
        $composed = [];

        foreach ($pairs as $index => $pair) {
            $key = sprintf('thumb-%d', $index + 1);
            $output = $workspace->path("thumbnails/{$key}.jpg");

            $sources = array_map(
                fn (array $still): string => $workspace->sourcePath((string) $still['image_path']),
                $pair['stills'],
            );

            foreach ($sources as $source) {
                // Checked before FFmpeg rather than after: `-i` on a missing
                // file is a wall of ffmpeg output, and the useful sentence is
                // which still is gone.
                if (! is_readable($source)) {
                    throw new RuntimeException(
                        "Cannot compose a thumbnail: the still {$source} is missing or unreadable. "
                        .'The scene row points at a file the asset stage did not leave behind.'
                    );
                }
            }

            $bytes = $this->compose($sources, $output, $config);

            $composed[] = [
                'key' => $key,
                'path' => $output,
                'bytes' => $bytes,
                'score' => $pair['score'],
                'reasons' => $pair['reasons'],
                'panels' => array_map(fn (array $still): array => [
                    'scene_id' => $still['scene_id'],
                    'sequence' => $still['sequence'],
                    'shot' => $still['shot'],
                    'cast' => $still['cast'],
                    'phase' => $still['phase'],
                    'score' => $still['score'],
                    'reasons' => $still['reasons'],
                ], $pair['stills']),
            ];
        }

        // Wholesale, never merged. A composition that is no longer in the list
        // must not survive as a selectable option pointing at a file the next
        // run overwrote with something else.
        $metadata = YoutubeMetadata::query()->firstOrCreate(
            ['story_id' => $story->id],
            ['status' => MetadataStatus::Pending],
        );

        $keys = array_column($composed, 'key');

        $metadata->update([
            'thumbnail_options' => $composed,
            // A selection that still exists is kept — re-composing to look at
            // the options again should not silently discard a decision. One
            // that no longer exists is cleared rather than left dangling.
            'thumbnail_selected' => in_array((string) $metadata->thumbnail_selected, $keys, true)
                ? $metadata->thumbnail_selected
                : null,
        ]);

        return [
            'composed' => $composed,
            'notes' => $notes,
            'pool' => count($scored),
            'widened' => $widened,
        ];
    }

    /**
     * Encode, then check the file against YouTube's cap and step down if over.
     *
     * The cap is theirs and has to be MET, not hoped for. At 1280x720 the first
     * rung measures a few hundred KB and the ladder will never be walked — but
     * a limit nothing enforces is a limit in name only, which is a sentence
     * this codebase has had to write about its own tag budget already.
     *
     * @param  array<int, string>  $sources
     * @param  array<string, mixed>  $config
     */
    private function compose(array $sources, string $output, array $config): int
    {
        $ladder = (array) $config['quality_ladder'];
        $cap = (int) $config['max_bytes'];
        $bytes = 0;

        foreach ($ladder as $quality) {
            $this->ffmpeg->composeSplitPanel(
                sources: $sources,
                output: $output,
                width: (int) $config['width'],
                height: (int) $config['height'],
                divider: (int) $config['divider_px'],
                dividerColor: (string) $config['divider_color'],
                quality: (int) $quality,
                timeout: (int) $config['timeout'],
            );

            clearstatcache(true, $output);
            $bytes = is_file($output) ? (int) filesize($output) : 0;

            if ($bytes > 0 && $bytes <= $cap) {
                return $bytes;
            }
        }

        throw new RuntimeException(sprintf(
            'The composed thumbnail is %d bytes at the lowest configured quality (q=%d), past '
            ."YouTube's %d byte limit:\n  %s\nLower a rung onto config/youtube.php thumbnail."
            .'quality_ladder, or reduce the dimensions.',
            $bytes,
            (int) end($ladder),
            $cap,
            $output,
        ));
    }

    /**
     * The stills worth cropping, best first.
     *
     * @param  array<int, string>  $notes
     * @return array<int, array<string, mixed>>
     */
    private function rankStills(Story $story, int $wanted, array &$notes, ?bool &$widened): array
    {
        $widened = false;

        $flagged = $this->scoreAll($story->scenes()->where('is_thumbnail_candidate', true)->get());

        // Two panels per composition, and a still may appear in at most two of
        // them — so four compositions need three distinct stills at the very
        // least, and more than that to look like four different thumbnails.
        $needed = min(6, max(3, $wanted + 1));

        if (count($flagged) >= $needed) {
            $notes[] = sprintf(
                '%d flagged thumbnail candidate(s), ranked by framing.',
                count($flagged),
            );

            return $flagged;
        }

        $widened = true;

        // Said out loud. A silent widening would make the Gate 2 flags look
        // respected when they were not, which is the reporting failure this
        // project keeps naming rather than a bug in the ranking.
        $notes[] = count($flagged) === 0
            ? 'No scene is flagged as a thumbnail candidate, so every still with a face in it was '
                .'considered. Flagging the frames you want at Gate 2 gives a better set than this.'
            : sprintf(
                'Only %d flagged candidate(s) — too few for %d distinct compositions — so the '
                .'search widened to every still in the story. The flagged ones still rank first '
                .'when the framing agrees.',
                count($flagged),
                $wanted,
            );

        $all = $this->scoreAll($story->scenes()->get());
        $flaggedIds = array_column($flagged, 'scene_id');

        // Flagged stills keep a thumb on the scale, because a nomination is an
        // editorial judgment and the score is a proxy. Not an override: scene
        // 82 of story 21 is a chair in an empty room and was flagged.
        foreach ($all as $index => $still) {
            if (in_array($still['scene_id'], $flaggedIds, true)) {
                $all[$index]['score'] += 12;
                $all[$index]['reasons'][] = 'flagged as a thumbnail candidate at Gate 2';
            }
        }

        usort($all, fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        return array_slice($all, 0, max($needed, 8));
    }

    /**
     * @param  Collection<int, Scene>  $scenes
     * @return array<int, array<string, mixed>>
     */
    private function scoreAll(Collection $scenes): array
    {
        // Eager-loaded, because scoring 270 scenes one characters() query at a
        // time is 270 queries for a page an operator presses a button on.
        $scenes->load(['characters', 'act']);

        $scored = [];

        foreach ($scenes as $scene) {
            $still = $this->framing->score($scene, $scene->characters->count());

            if (! $still['usable']) {
                continue;
            }

            $still['image_path'] = (string) $scene->image_path;
            $still['phase'] = $scene->act?->phase?->value;
            $scored[] = $still;
        }

        usort($scored, fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        return $scored;
    }

    /**
     * The best pairs, each still used at most twice.
     *
     * @param  array<int, array<string, mixed>>  $stills
     * @return array<int, array{stills: array<int, array<string, mixed>>, score: int, reasons: array<int, string>}>
     */
    private function pairs(array $stills, int $wanted): array
    {
        $pool = array_slice($stills, 0, 6);
        $last = max(array_column($stills, 'sequence'));

        $candidates = [];

        foreach ($pool as $i => $left) {
            foreach (array_slice($pool, $i + 1) as $right) {
                // Earlier left, later right: a before-and-after reads the way
                // the language does.
                [$a, $b] = $left['sequence'] <= $right['sequence'] ? [$left, $right] : [$right, $left];

                $candidates[] = $this->scorePair($a, $b, $last);
            }
        }

        usort($candidates, fn (array $x, array $y): int => $y['score'] <=> $x['score']);

        $used = [];
        $chosen = [];

        foreach ($candidates as $pair) {
            $ids = array_column($pair['stills'], 'scene_id');

            // Twice, not once. Once would need eight distinct stills for four
            // compositions and most stories do not have eight good ones; more
            // than twice and every option shows the same face.
            if (($used[$ids[0]] ?? 0) >= 2 || ($used[$ids[1]] ?? 0) >= 2) {
                continue;
            }

            $used[$ids[0]] = ($used[$ids[0]] ?? 0) + 1;
            $used[$ids[1]] = ($used[$ids[1]] ?? 0) + 1;

            $chosen[] = $pair;

            if (count($chosen) === $wanted) {
                break;
            }
        }

        return $chosen;
    }

    /**
     * @param  array<string, mixed>  $left
     * @param  array<string, mixed>  $right
     * @return array{stills: array<int, array<string, mixed>>, score: int, reasons: array<int, string>}
     */
    private function scorePair(array $left, array $right, int $lastSequence): array
    {
        $score = (int) $left['score'] + (int) $right['score'];
        $reasons = [];

        // The arc, if the outline has one. This is what the reversal phase
        // bought beyond the script: "before she left" against "after she looked
        // for me" is a thumbnail, and two acts of escalation is one picture
        // shown twice.
        $before = [ActPhase::Escalation->value, ActPhase::Departure->value];
        $after = [ActPhase::Search->value, ActPhase::Refusal->value];

        if (in_array($left['phase'], $before, true) && in_array($right['phase'], $after, true)) {
            $score += 30;
            $reasons[] = 'spans the reversal: the escalation on the left, the reversal on the right';
        } elseif ($left['phase'] !== null && $left['phase'] === $right['phase']) {
            $score -= 10;
            $reasons[] = sprintf('both panels are from the %s phase', (string) $left['phase']);
        } elseif ($lastSequence > 0) {
            // No phases — an outline written before the reversal existed. The
            // ends of the story are the same idea with less behind it.
            $gap = ((int) $right['sequence'] - (int) $left['sequence']) / $lastSequence;

            if ($gap >= 0.5) {
                $score += 18;
                $reasons[] = 'the two panels are from opposite ends of the story';
            } elseif ($gap < 0.1) {
                $score -= 12;
                $reasons[] = 'the two panels are from almost the same moment';
            }
        }

        return ['stills' => [$left, $right], 'score' => $score, 'reasons' => $reasons];
    }
}
