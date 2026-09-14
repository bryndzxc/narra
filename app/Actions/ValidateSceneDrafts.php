<?php

namespace App\Actions;

use App\Enums\MotionPreset;
use App\Models\Scene;
use App\Models\Story;
use App\Support\ImagePromptBuilder;
use App\Support\ThumbnailFraming;
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
        $this->checkCloseFramesNameTheirSetting($scenes, $warnings);
        $this->checkExpressionsAreNotHedged($scenes, $warnings);
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
     * Words that put the camera somewhere.
     *
     * Not a list of places — that is unbounded, and a check built on one
     * reports every frame set somewhere it had not thought of. These are the
     * constructions a frame uses to say there is a WORLD behind the subject,
     * plus the handful of interior nouns that carry a setting on their own.
     *
     * Matched whole-word for the reason CharacterTextGuard matches whole-word:
     * "behind" must not fire inside "behindhand", and "bar" must not fire
     * inside "barely".
     */
    private const SETTING_CUES = [
        // Spatial constructions — the reliable half. A frame that puts anything
        // behind, beyond or around the subject has stated a world.
        'behind', 'beyond', 'background', 'around', 'past', 'through',
        'over his shoulder', 'over her shoulder', 'over their shoulder',
        'framed by', 'reflected', 'blurred', 'out of focus', 'in the distance',
        'across', 'beside', 'above', 'below', 'under', 'outside', 'inside',

        // Interiors and exteriors common enough to be worth naming directly.
        'room', 'kitchen', 'bedroom', 'hallway', 'corridor', 'stairwell',
        'doorway', 'door', 'window', 'wall', 'table', 'desk', 'counter',
        'floor', 'ceiling', 'bed', 'chair', 'sofa', 'street', 'road',
        'pavement', 'sidewalk', 'car', 'bus', 'train', 'platform', 'office',
        'shop', 'store', 'market', 'restaurant', 'canteen', 'kitchenette',
        'yard', 'garden', 'courtyard', 'balcony', 'terrace', 'lobby',
        'station', 'terminal', 'hospital', 'church', 'temple', 'school',
        'dorm', 'dormitory', 'library', 'field', 'sky', 'wet market',

        // Light is staging. A frame that says where the light comes from has
        // said something about the space even when it names no noun.
        'lamp', 'lamplight', 'streetlamp', 'sunlight', 'daylight', 'moonlight',
        'fluorescent', 'neon', 'lit', 'light', 'lights', 'glow', 'dark', 'dim',
        'shadow', 'night', 'dusk', 'dawn', 'morning', 'afternoon', 'evening',

        // Added after running the check over four real stories and reading every
        // frame it flagged. Each of these was a genuine setting the list did not
        // know, and each was therefore reported as an absence.
        //
        // They are listed rather than generalised, and that is a deliberate
        // choice with a measurement behind it. The general version — any
        // preposition followed by a determiner — was tried and rejected: it
        // matches "in her fists", which clears story 21 scene 107, the one frame
        // this check was written from and the only one confirmed against the
        // image it produced. A rule that clears its own founding instance is not
        // a more general rule, it is a broken one.
        'crowd', 'patio', 'dock', 'pallet', 'reflection', 'porch', 'hall',
        'kerb', 'curb', 'aisle', 'stall', 'booth', 'bench', 'gate', 'garage',
    ];

    /**
     * Words that mean the frame is about somebody's face.
     *
     * The scope that decides whether this check is worth reading — see the
     * measurement in checkCloseFramesNameTheirSetting().
     */
    private const FACE_CUES = [
        'face', 'faces', 'eyes', 'eye', 'expression', 'mouth', 'jaw', 'brow',
        'brows', 'cheek', 'cheeks', 'chin', 'forehead', 'head', 'smile',
        'stare', 'staring', 'gaze', 'lips', 'profile',
    ];

    /**
     * A close frame with a character in it and nowhere for the camera to be.
     *
     * The failure this catches is specific and was measured before it was
     * written. Scene images are generated on the EDIT endpoint, conditioned on
     * the character's reference sheet — and that sheet is deliberately the most
     * boring image in the project: front-facing, head and shoulders, neutral,
     * on a "plain flat mid-grey background, completely empty" (see
     * config/characters.php). When a close frame names no setting, the model
     * fills the gap from the only picture it was handed, and what comes back is
     * the reference sheet with the scene's props added to it.
     *
     * The live instance: story 21 scene 107 asked for "Close on Wei Hongmei's
     * face, mouth set hard, eyes fixed on Lu Wenbin, the dish towel gripped
     * tight in her fists" and got her reference sheet holding a dish towel —
     * same grey void, same frontal framing, same wardrobe, in a scene set in a
     * kitchen. 20 of that story's 43 close frames name no setting.
     *
     * **Scoped to frames with a character in them, and that scope is what keeps
     * it quiet.** A reference is only attached when somebody is present, so a
     * close-up of a phone screen or a signature cannot fail this way — and
     * object close-ups are most of the close frames that name no place. Without
     * the scope this fires on the frames it has nothing to say about, which is
     * how an advisory column stops being read.
     *
     * An advisory, never a refusal. A tight two-shot with a genuinely empty
     * background is a legitimate picture, and judging one is the operator's job.
     *
     * @param  Collection<int, Scene>  $scenes
     * @param  array<int, string>  $warnings
     */
    private function checkCloseFramesNameTheirSetting($scenes, array &$warnings): void
    {
        $framing = app(ThumbnailFraming::class);
        $offenders = [];
        $close = 0;

        foreach ($scenes as $scene) {
            if ($scene->characters()->doesntExist()) {
                continue;
            }

            $frame = mb_strtolower(ImagePromptBuilder::frameFrom((string) $scene->image_prompt));

            if ($frame === '' || $framing->framing($frame)['shot'] !== 'close') {
                continue;
            }

            // A reference sheet is a HEAD-AND-SHOULDERS portrait, so it can only
            // bleed into a frame where a head fills the picture. A close-up of a
            // document, a phone screen or a pair of hands carries the character
            // on its cast list — the hand is theirs — and has no face for the
            // portrait to overwrite.
            //
            // Measured before this scope was added: without it the check flagged
            // 27 frames across four stories and nine were hands and paperwork.
            // That is the over-report direction, and an advisory column that is
            // half noise is one nobody finishes reading.
            if (! $this->mentions($frame, self::FACE_CUES)) {
                continue;
            }

            $close++;

            if ($this->mentions($frame, self::SETTING_CUES)) {
                continue;
            }

            $offenders[] = $scene->sequence;
        }

        if ($offenders === []) {
            return;
        }

        $warnings[] = sprintf(
            '%d of %d close frame(s) on a character\'s face name no setting (%s%s). A still is '
            .'generated conditioned on that character\'s reference sheet, which is a head-and-'
            .'shoulders portrait on an empty grey background — so a close frame that names nowhere '
            .'for the camera to be tends to come back as the reference sheet with the props added. '
            .'Naming the room, the light or what is behind them is a text edit here and a re-bill '
            .'after approval.',
            count($offenders),
            $close,
            'scene '.implode(', ', array_slice($offenders, 0, 8)),
            count($offenders) > 8 ? ', ...' : ''
        );
    }

    /**
     * Adverbs that make an expression render as no expression.
     *
     * Measured against the real generator rather than assumed. In the
     * expression-axis run, "jaw tight, eyes narrowed SLIGHTLY" — story 21 scene
     * 204 verbatim — came back indistinguishable from the neutral reference
     * portrait, while "brows drawn together, mouth set hard" from scene 14 came
     * back a hard glare. The hedge is the difference between the two.
     */
    private const HEDGES = [
        'slightly', 'faintly', 'faint', 'slight', 'somewhat', 'barely',
        'almost', 'nearly', 'subtly', 'subtle', 'mildly', 'vaguely',
        'a little', 'a touch', 'a hint of', 'half',
    ];

    /**
     * A hedged expression, which costs words and buys a blank face.
     *
     * THE REASON THIS IS A CHECK AND NOT A SENTENCE IN A PROMPT. The ban was
     * first written as a prompt bullet — "NEVER HEDGE AN EXPRESSION" — and
     * measured before and after across a re-drafted story:
     *
     *   | | hedged, as a share of peopled frames |
     *   |---|---|
     *   | before the rule | 3.2% |
     *   | after the rule | 15.3% |
     *
     * The forbidden thing got five times more common. Some of that is the
     * `expression` field arriving in the same change and tripling how many
     * frames carry an expression at all — per expression-bearing frame it went
     * 14% to 21% — but the direction is unambiguous either way: the request was
     * ignored. **A prompt request with no mechanism reads as a guard while doing
     * nothing**, which is this file's documented-guard defect, and it is worse
     * than not asking because the sentence looks like coverage.
     *
     * So the prompt keeps the request and this is the invariant, which is the
     * same split `CharacterTextGuard` uses against the extraction prompt.
     *
     * **An advisory, never a refusal, and the scope is what keeps it honest.**
     * Only the expression block is examined — never the frame — because a hedge
     * belongs to a face and "dust FAINT on the drawer's edge" or "gesturing
     * SLIGHTLY as he speaks" are neither wrong nor about an expression. Scoring
     * the whole frame was the first version of this measurement and it
     * over-counted by 4x: 13.4% reported against a true 3.2%.
     *
     * @param  Collection<int, Scene>  $scenes
     * @param  array<int, string>  $warnings
     */
    private function checkExpressionsAreNotHedged($scenes, array &$warnings): void
    {
        $offenders = [];
        $withExpression = 0;

        foreach ($scenes as $scene) {
            $expression = ImagePromptBuilder::expressionFrom((string) $scene->image_prompt);

            if ($expression === '') {
                continue;
            }

            $withExpression++;

            if ($this->mentionsHedge(mb_strtolower($expression))) {
                $offenders[] = $scene->sequence;
            }
        }

        if ($offenders === []) {
            return;
        }

        $warnings[] = sprintf(
            '%d of %d scene(s) with an expression hedge it (%s%s). "Slightly", "faintly" and '
            .'"barely" applied to a face measure as NO expression against this generator — the '
            .'still comes back with the neutral face of the character\'s reference portrait. '
            .'Naming the expression at the strength it actually is, or spending the words on the '
            .'room instead, is a text edit here and a re-bill after approval.',
            count($offenders),
            $withExpression,
            'scene '.implode(', ', array_slice($offenders, 0, 8)),
            count($offenders) > 8 ? ', ...' : ''
        );
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
     * Whole-word, because a substring match is how a list like this goes wrong.
     *
     * 'lit' inside "quality" and 'gate' inside "investigate" are the shape
     * CharacterTextGuard had to be rescued from, where the workaround was
     * trailing spaces that then failed at a line end.
     *
     * @param  array<int, string>  $cues
     */
    /**
     * A hedge as a WORD, not as half of a compound.
     *
     * `mentions()` matches `\b`, and a hyphen is a word boundary, so "half"
     * fired on "half-smile", "half-lidded" and "half-standing" — a named
     * expression twice, an expression and a posture. Story 33: four of the 21
     * scenes this advisory flagged were those, and on every story a
     * "half-smile" is an ordinary thing to write. An advisory that is wrong a
     * fifth of the time is one an operator learns to skim.
     *
     * Its own method rather than a change to `mentions()`, deliberately: that
     * matcher also decides the face and setting cues, and widening its boundary
     * there would make those two checks fire LESS — a quieter advisory arrived
     * at as a side effect of fixing a different one.
     */
    private function mentionsHedge(string $text): bool
    {
        foreach (self::HEDGES as $hedge) {
            if (preg_match('/(?<![\p{L}\p{N}-])'.preg_quote($hedge, '/').'(?![\p{L}\p{N}-])/u', $text)) {
                return true;
            }
        }

        return false;
    }

    private function mentions(string $text, array $cues): bool
    {
        foreach ($cues as $cue) {
            if (preg_match('/\b'.preg_quote($cue, '/').'\b/u', $text)) {
                return true;
            }
        }

        return false;
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
