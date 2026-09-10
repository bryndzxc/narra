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
 *     one still cut in half. The pair score penalises the same faces on both
 *     panels — a term that this docblock claimed for a phase while nothing
 *     implemented it, and story 25 shipped Kevin beside Kevin in the same
 *     shirt — and, more importantly, prefers the two ENDS of the arc: the
 *     humiliation on the left and the reversal on the right, which is how this
 *     niche's thumbnails actually read. Since the reversal phase exists that is
 *     a real question the data can answer: `acts.phase`. On a story outlined
 *     before it, opposite ends of the STORY — measured against its last scene,
 *     not against the last scene that happened to be flagged — is the same
 *     idea with less to go on.
 *
 *  3. **Which order.** Earlier scene left, later scene right. English reads
 *     left to right and so does a before-and-after.
 *
 * Re-runnable by construction: it replaces the stored options wholesale and
 * removes any composition file the new set does not name. There is nothing to
 * bill twice.
 *
 * **A composition is keyed by WHAT IT SHOWS, never by its slot.** The keys
 * used to be `thumb-1..4`, positional, and the operator's pick was kept across
 * a re-compose whenever its key still existed — so story 23's pick `thumb-4`
 * survived a re-compose that put a different pair of stills in slot four, and
 * the record said the same thing while meaning a different picture. That is
 * the audio-provenance lesson one field over: a record has to describe the
 * artifact, not its position. The key is now the two scene ids, the option
 * carries a fingerprint of the two source files, and carrySelection() either
 * finds the same pair built from the same stills or clears the pick and SAYS
 * why.
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

        // The STORY's last scene, for the no-phase fallback. It used to be the
        // highest sequence in the ranked pool, which on story 21 was 107 of
        // 270 — so scene 20 against scene 107 was reported as "opposite ends
        // of the story" at 0.81 while spanning 32% of the video.
        $lastSequence = (int) $story->scenes()->max('sequence');

        $pairs = $this->pairs($scored, $wanted, $lastSequence);
        $workspace = RenderWorkspace::for($story);
        $composed = [];

        foreach ($pairs as $pair) {
            $sceneIds = array_map(fn (array $still): int => (int) $still['scene_id'], $pair['stills']);

            // What it shows, left then right. A slot number is a position in a
            // list the next run rewrites; two scene ids are a picture.
            $key = sprintf('s%d-s%d', $sceneIds[0], $sceneIds[1]);
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
                'scene_ids' => $sceneIds,
                // The two SOURCE files, hashed. A still can be regenerated
                // under the same scene id — a failed image retried, a Gate 2
                // re-approval — and then the same two scene ids name a
                // different picture. The scene pair says WHICH stills; this
                // says whether they are the stills the operator looked at.
                'stills_fingerprint' => $this->fingerprint($sources),
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

        // Files the new set does not name are removed, so the workspace holds
        // exactly the compositions the record describes and a stale file
        // cannot be served or delivered under a key that has moved on.
        $this->removeStaleCompositions($workspace, array_column($composed, 'key'));

        // Wholesale, never merged. A composition that is no longer in the list
        // must not survive as a selectable option pointing at a file the next
        // run overwrote with something else.
        $metadata = YoutubeMetadata::query()->firstOrCreate(
            ['story_id' => $story->id],
            ['status' => MetadataStatus::Pending],
        );

        $metadata->update([
            'thumbnail_options' => $composed,
            'thumbnail_selected' => $this->carrySelection($metadata, $composed, $notes),
        ]);

        return [
            'composed' => $composed,
            'notes' => $notes,
            'pool' => count($scored),
            'widened' => $widened,
        ];
    }

    /**
     * The operator's pick, carried across a re-compose by WHAT IT SHOWS.
     *
     * Three outcomes, and two of them are said in the notes:
     *
     *  - The same two scenes are in the new set, built from the same two files
     *    (or from files whose fingerprint the old record never held — NULL is
     *    unknown, and a pick is cleared because it demonstrably changed, never
     *    because we cannot prove it did not). Kept, under the new key.
     *  - The same two scenes are in the new set but a still was regenerated
     *    since the pick was made. Cleared, and the note says to look again.
     *  - The pair is not in the new set at all. Cleared, and the note names the
     *    scenes that were picked so the operator can find them at Gate 2.
     *
     * A pick recorded under a positional key from before this change is
     * resolved through the OLD option's panels, so `thumb-2` becomes the scene
     * pair slot two held when it was chosen — not slot two of the new set.
     *
     * @param  array<int, array<string, mixed>>  $composed
     * @param  array<int, string>  $notes
     */
    private function carrySelection(YoutubeMetadata $metadata, array $composed, array &$notes): ?string
    {
        $selected = trim((string) $metadata->thumbnail_selected);

        if ($selected === '') {
            return null;
        }

        $previous = collect((array) ($metadata->thumbnail_options ?? []))
            ->first(fn (array $o): bool => ($o['key'] ?? null) === $selected);

        if ($previous === null) {
            $notes[] = sprintf(
                'The recorded pick "%s" named no composition in the previous set, so it was cleared. '
                .'Pick again.',
                $selected,
            );

            return null;
        }

        $sceneIds = array_map(
            'intval',
            (array) ($previous['scene_ids'] ?? array_column((array) ($previous['panels'] ?? []), 'scene_id')),
        );
        $label = 'scenes '.implode(' + ', array_column((array) ($previous['panels'] ?? []), 'sequence'));

        $match = null;

        foreach ($composed as $option) {
            if ($option['scene_ids'] === $sceneIds) {
                $match = $option;
                break;
            }
        }

        if ($match === null) {
            $notes[] = sprintf(
                'Your pick (%s) is no longer among the compositions, so it was cleared rather than '
                .'left pointing at a different picture. Pick again.',
                $label,
            );

            return null;
        }

        $was = $previous['stills_fingerprint'] ?? null;

        if ($was !== null && $was !== $match['stills_fingerprint']) {
            $notes[] = sprintf(
                'Your pick (%s) is still in the set, but one of its stills has been regenerated since '
                .'you chose it, so it was cleared rather than assumed. Look at it again.',
                $label,
            );

            return null;
        }

        if ($match['key'] !== $selected) {
            $notes[] = sprintf(
                'Your pick was recorded as slot "%s" and is now recorded as what it shows, %s.',
                $selected,
                $label,
            );
        }

        return (string) $match['key'];
    }

    /**
     * @param  array<int, string>  $sources
     */
    private function fingerprint(array $sources): string
    {
        return substr(sha1(implode('|', array_map(
            fn (string $source): string => (string) sha1_file($source),
            $sources,
        ))), 0, 16);
    }

    /**
     * @param  array<int, string>  $keys
     */
    private function removeStaleCompositions(RenderWorkspace $workspace, array $keys): void
    {
        foreach (glob($workspace->path('thumbnails/*.jpg')) ?: [] as $file) {
            if (! in_array(basename($file, '.jpg'), $keys, true)) {
                @unlink($file);
            }
        }
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

        // The WHOLE story, not the top eight. The same truncation pairs() used
        // to apply one step later: a slice taken on the per-still score cannot
        // see the pair terms it feeds, so a late-act still just outside it
        // could never reach the reversal bonus written for it. 270 stills is
        // ~36,000 pairs of arithmetic on data already loaded.
        return $all;
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
            // Who, not just how many: the pair score asks whether two panels
            // show the same people, and a count cannot answer that.
            $still['cast_ids'] = $scene->characters->pluck('id')->map(fn ($id): int => (int) $id)->all();
            $still['cast_names'] = $scene->characters->pluck('name')->all();
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
    private function pairs(array $stills, int $wanted, int $lastSequence): array
    {
        // EVERY still in the pool, not the top six by framing. The slice was
        // taken before the pair score existed, so the +30 for spanning the
        // reversal was only ever applied to pairs that had already survived a
        // cut it had no say in — and a late-act flag reached a composition
        // only if it out-framed the escalation flags on its own. On a 14-flag
        // pool this is 91 pairs of arithmetic; the slice bounded a cost that
        // was never there.
        $candidates = [];

        foreach ($stills as $i => $left) {
            foreach (array_slice($stills, $i + 1) as $right) {
                // Earlier left, later right: a before-and-after reads the way
                // the language does.
                [$a, $b] = $left['sequence'] <= $right['sequence'] ? [$left, $right] : [$right, $left];

                $candidates[] = $this->scorePair($a, $b, $lastSequence);
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

        // The same people on both panels. Two panels of one person is one
        // still cut in half; two panels where everyone on one side is also on
        // the other reads the same way at thumbnail size, because the extra
        // person is the only thing distinguishing them and they are small.
        // Story 25 shipped Kevin beside Kevin in the same shirt, and story 23
        // offered the same two people three times, while this docblock said
        // the score "prefers two different leads" and nothing implemented it.
        //
        // -12 is CHOSEN, not measured — like every weight here — and sized to
        // decide a tie between equally framed stills without outranking the
        // framing itself: a same-face pair of two close-ups still beats a
        // different-face pair with an unstated shot in it.
        $leftCast = (array) ($left['cast_ids'] ?? []);
        $rightCast = (array) ($right['cast_ids'] ?? []);

        if ($leftCast !== [] && $rightCast !== []) {
            $shared = count(array_intersect($leftCast, $rightCast));

            if ($shared === count($leftCast) || $shared === count($rightCast)) {
                $score -= 12;
                $reasons[] = count($leftCast) === count($rightCast) && $shared === count($leftCast)
                    ? sprintf('the same face on both panels (%s)', implode(', ', (array) ($left['cast_names'] ?? [])))
                    : 'everyone on one panel is on the other too';
            }
        }

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
            //
            // Against the STORY's last scene. Measured against the pool's own
            // last scene it could only ever say how far apart the flags were,
            // and on story 21 every flag was inside the first 40% of the video.
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
