<?php

namespace App\Actions;

use App\Enums\MotionPreset;
use App\Models\Scene;
use App\Models\Story;
use Illuminate\Support\Collection;

/**
 * The scene check, run at Gate 2.
 *
 * Gate 2 is the last free moment: everything past it bills, and images alone
 * are ~70% of a video's cost across 150-250 stills. A bad image prompt found
 * here is a text edit; found after approval it is a re-bill. So the things
 * worth checking are the ones an operator scrolling 200 scenes will not catch.
 *
 * Nothing here blocks approval. Judging a picture is the operator's job and a
 * guard that refused approval on a heuristic is a guard that gets removed.
 *
 * @see ValidateOutlineSpine for the same shape of check one gate earlier.
 */
class ValidateSceneDrafts
{
    /**
     * Words too common to count as evidence that a prompt is copying its line.
     *
     * Without this every prompt overlaps its narration on "the" and "and" and
     * the check reports nothing but noise.
     */
    private const STOPWORDS = [
        'the', 'a', 'an', 'and', 'or', 'but', 'of', 'to', 'in', 'on', 'at', 'by',
        'for', 'with', 'from', 'as', 'is', 'was', 'were', 'be', 'been', 'it',
        'its', 'this', 'that', 'these', 'those', 'he', 'she', 'they', 'i', 'we',
        'you', 'his', 'her', 'their', 'my', 'our', 'me', 'him', 'them', 'had',
        'has', 'have', 'not', 'no', 'so', 'up', 'out', 'over', 'into', 'then',
        'there', 'here', 'one', 'all', 'who', 'what', 'when', 'where', 'which',
    ];

    /**
     * @return array{warnings: array<int, string>, stats: array<string, mixed>}
     */
    public function handle(Story $story): array
    {
        $scenes = $story->scenes()->orderBy('sequence')->get();

        if ($scenes->isEmpty()) {
            return ['warnings' => [], 'stats' => []];
        }

        $warnings = [];

        $this->checkPromptsAreNotTranscriptions($scenes, $warnings);
        $this->checkPromptsUseTheCast($story, $scenes, $warnings);
        $this->checkSceneLengths($scenes, $warnings);
        $this->checkMotionVariety($scenes, $warnings);
        $this->checkHookAndThumbnails($scenes, $warnings);

        return ['warnings' => $warnings, 'stats' => $this->stats($scenes)];
    }

    /**
     * An image prompt that restates its narration.
     *
     * The failure this whole stage is shaped to avoid. A prompt that transcribes
     * its line produces a literal illustration of a sentence, and two hundred of
     * those in a row read as a slideshow of captions rather than a film. The
     * picture is what the camera is looking at while the words are said, not a
     * drawing of the words.
     *
     * Overlap is measured on content words only and against the FRAME rather
     * than the whole prompt, because the style block and the character
     * descriptions are identical everywhere and would flatten the signal.
     *
     * @param  Collection<int, Scene>  $scenes
     * @param  array<int, string>  $warnings
     */
    private function checkPromptsAreNotTranscriptions($scenes, array &$warnings): void
    {
        $threshold = (float) config('scenes.max_narration_overlap');
        $offenders = [];

        foreach ($scenes as $scene) {
            $frame = $this->frameOf((string) $scene->image_prompt);
            $narration = $this->contentWords((string) $scene->narration_text);
            $prompt = $this->contentWords($frame);

            if ($prompt === [] || $narration === []) {
                continue;
            }

            $shared = count(array_intersect($prompt, $narration));
            $ratio = $shared / count($prompt);

            if ($ratio > $threshold) {
                $offenders[] = $scene->sequence;
            }
        }

        if ($offenders === []) {
            return;
        }

        $warnings[] = sprintf(
            '%d scene(s) have an image prompt that mostly restates their narration (%s%s). A prompt '
            .'that transcribes its line produces a literal illustration of a sentence, and a run of '
            .'those reads as a slideshow of captions. The frame should be what the camera is looking '
            .'at while the line is spoken.',
            count($offenders),
            'scene '.implode(', ', array_slice($offenders, 0, 8)),
            count($offenders) > 8 ? ', ...' : ''
        );
    }

    /**
     * Prompts that describe a person without using the frozen description.
     *
     * The consistency mechanism only works if it is actually reached. A frame
     * naming somebody the cast does not contain resolves to no description at
     * all, and the generator invents a face for them — differently every time.
     *
     * @param  Collection<int, Scene>  $scenes
     * @param  array<int, string>  $warnings
     */
    private function checkPromptsUseTheCast(Story $story, $scenes, array &$warnings): void
    {
        $cast = $story->characters()->pluck('name');

        if ($cast->isEmpty()) {
            $warnings[] = 'This story has no characters, so no image prompt carries a fixed physical '
                .'description. Across 150-250 stills that is the single biggest quality risk in this '
                .'format — every prompt invents its own version of the same people.';

            return;
        }

        $withoutCast = $scenes->filter(
            fn (Scene $scene): bool => ! str_contains((string) $scene->image_prompt, 'described exactly')
        );

        // Cutaways are legitimate and wanted — an envelope on a doormat, a
        // driveway at dusk. It is only a problem when nearly everything is one.
        $share = $withoutCast->count() / max(1, $scenes->count());

        if ($share > 0.6) {
            $warnings[] = sprintf(
                '%d of %d scenes (%d%%) have nobody from the cast in them. Cutaways are wanted, but a '
                .'video that is mostly objects and empty rooms has no faces to carry it.',
                $withoutCast->count(),
                $scenes->count(),
                (int) round($share * 100)
            );
        }
    }

    /**
     * @param  Collection<int, Scene>  $scenes
     * @param  array<int, string>  $warnings
     */
    private function checkSceneLengths($scenes, array &$warnings): void
    {
        $min = (int) config('scenes.min_words');
        $max = (int) config('scenes.max_words');

        $short = $scenes->filter(fn (Scene $s): bool => str_word_count((string) $s->narration_text) < $min);
        $long = $scenes->filter(fn (Scene $s): bool => str_word_count((string) $s->narration_text) > $max);

        if ($short->isNotEmpty()) {
            $warnings[] = sprintf(
                '%d scene(s) are under %d words — roughly three seconds on screen. The Ken Burns move '
                .'never completes at that length and the cut reads as a flicker. Scenes %s.',
                $short->count(),
                $min,
                $short->pluck('sequence')->take(8)->implode(', ')
            );
        }

        if ($long->isNotEmpty()) {
            $warnings[] = sprintf(
                '%d scene(s) are over %d words — more than 25 seconds on one still. Scenes %s.',
                $long->count(),
                $max,
                $long->pluck('sequence')->take(8)->implode(', ')
            );
        }
    }

    /**
     * @param  Collection<int, Scene>  $scenes
     * @param  array<int, string>  $warnings
     */
    private function checkMotionVariety($scenes, array &$warnings): void
    {
        $counts = $scenes->countBy(fn (Scene $s): string => $s->motion_preset->value);
        $threshold = (float) config('scenes.motion_monotony_threshold');
        $total = $scenes->count();

        foreach ($counts as $preset => $count) {
            if ($count / $total > $threshold) {
                $warnings[] = sprintf(
                    '%d%% of scenes use %s. A single move repeated through a 35-minute video reads as '
                    .'mechanical, and the camera is the only thing moving in this format.',
                    (int) round($count / $total * 100),
                    $preset
                );
            }
        }

        $static = $counts[MotionPreset::Static->value] ?? 0;

        // Same number the scene-draft fallback enforces. A warning that fired
        // at a different share than the gate would tell the operator about
        // something the pipeline had already accepted, or stay silent about
        // something it had rejected.
        if ($static / $total > (float) config('scenes.static_share_threshold', 0.15)) {
            $warnings[] = sprintf(
                '%d scenes are static. A held beat works occasionally; at this share the video looks '
                .'like a broken slideshow.',
                $static
            );
        }
    }

    /**
     * @param  Collection<int, Scene>  $scenes
     * @param  array<int, string>  $warnings
     */
    private function checkHookAndThumbnails($scenes, array &$warnings): void
    {
        $hooks = $scenes->where('is_hook', true);

        if ($hooks->count() !== 1) {
            $warnings[] = sprintf(
                '%d scenes are flagged as the opening hook. There is exactly one opening.',
                $hooks->count()
            );
        } elseif ($hooks->first()->sequence !== 1) {
            $warnings[] = sprintf(
                'Scene %d is flagged as the hook but the video opens on scene 1.',
                $hooks->first()->sequence
            );
        }

        $thumbnails = $scenes->where('is_thumbnail_candidate', true)->count();
        $min = (int) config('scenes.thumbnail_candidates.min');

        if ($thumbnails < $min) {
            $warnings[] = 'No scene is flagged as a thumbnail candidate. Gate 4 will ask for one, and '
                .'an operator hunting for it there will pick whatever is nearest.';
        }
    }

    /**
     * @param  Collection<int, Scene>  $scenes
     * @return array<string, mixed>
     */
    private function stats($scenes): array
    {
        $words = $scenes->map(fn (Scene $s): int => str_word_count((string) $s->narration_text));

        return [
            'scenes' => $scenes->count(),
            'words' => $words->sum(),
            'avg_words' => round($words->avg(), 1),
            'min_words' => $words->min(),
            'max_words' => $words->max(),
            'motion' => $scenes->countBy(fn (Scene $s): string => $s->motion_preset->value)->all(),
            'thumbnail_candidates' => $scenes->where('is_thumbnail_candidate', true)->count(),
        ];
    }

    /**
     * The generated part of a prompt: everything before the assembled blocks.
     *
     * The style and the cast descriptions are identical in every prompt, so
     * including them would drown the signal this check is looking for.
     */
    private function frameOf(string $prompt): string
    {
        return trim(explode("\n\n", $prompt)[0] ?? '');
    }

    /**
     * @return array<int, string>
     */
    private function contentWords(string $text): array
    {
        $words = preg_split('/[^a-z]+/', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_unique(array_filter(
            $words,
            fn (string $word): bool => mb_strlen($word) > 2 && ! in_array($word, self::STOPWORDS, true)
        )));
    }
}
