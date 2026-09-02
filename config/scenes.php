<?php

use App\Enums\MotionPreset;

/*
|--------------------------------------------------------------------------
| Scene drafting
|--------------------------------------------------------------------------
|
| Gate 2's raw material: how an act script becomes 25-40 stills, and what an
| image prompt is allowed to be.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Scene length
    |--------------------------------------------------------------------------
    |
    | A scene is one still and one clip, so its length is the length of time a
    | viewer looks at one picture. Too long and it reads as a static slideshow;
    | too short and the Ken Burns move never completes and the render fans out
    | into hundreds of tiny encodes.
    |
    | 30 words at the configured narration rate is about 11 seconds, which sits
    | in the 8-16 second band the render pipeline was measured against. A
    | 5,800-word story lands near 190 scenes — inside the 150-250 the format
    | budgets for, and therefore inside the image spend it budgets for.
    |
    */

    'words_per_scene' => (int) env('SCENE_WORDS', 30),

    /*
    | Hard bounds, checked after the split. A one-word scene is a still nobody
    | can read a caption on; a 90-word scene is 34 seconds of one picture.
    */
    'min_words' => (int) env('SCENE_MIN_WORDS', 8),
    'max_words' => (int) env('SCENE_MAX_WORDS', 70),

    /*
    |--------------------------------------------------------------------------
    | When to stop trusting the cheap model
    |--------------------------------------------------------------------------
    |
    | The share of under-length scenes that makes an act worth re-drafting on
    | the fallback model. Measured, not guessed: on the same story and the same
    | act scripts, Opus produced 2 under-length scenes out of 199 (1%) and Haiku
    | produced 24 out of 196 (12%).
    |
    | This exists because the first version of the fallback checked only that
    | the sentence ranges tiled the act — and they did, all six times. Tiling is
    | the failure a cheap model does NOT have here; it counts fine. What it does
    | is chop the act into slivers: a four-word scene is about three seconds on
    | screen, the Ken Burns move never completes, and the cut reads as a
    | flicker. A quality gate that only checks the thing the model gets right is
    | not a quality gate.
    |
    | 5% sits above the good run and well below the bad one, so it fires on a
    | genuine collapse and not on one awkward act.
    |
    */
    'fallback_short_share' => (float) env('SCENE_FALLBACK_SHORT_SHARE', 0.05),

    /*
    |--------------------------------------------------------------------------
    | Art style
    |--------------------------------------------------------------------------
    |
    | Stated ONCE, here, and appended to every prompt by ImagePromptBuilder.
    |
    | Not per prompt, and that is the whole point. A style restated 200 times by
    | a language model is a style that drifts 200 times: by scene 90 the
    | wording has wandered, and with it the palette, the lens and the rendering.
    | The generator describes the FRAME; this file describes the LOOK; nothing
    | else is allowed to mention either.
    |
    | This is the channel's visual identity and it will be tuned often. Tuning
    | it here re-renders every prompt consistently rather than leaving half a
    | video in the old style.
    |
    */

    'art_style' => env('SCENE_ART_STYLE', implode(' ', [
        'Digital painting in a warm, grounded American realist style.',
        'Soft directional light, muted earth palette with one warm accent.',
        'Painterly brushwork, visible texture, slight film grain.',
        'Cinematic 16:9 composition with shallow depth of field.',
        'Restrained and unsentimental. No gloss, no gradients, no neon.',
    ])),

    /*
    | Appended after the style. Negative constraints, kept out of the model's
    | hands for the same reason the style is: a constraint the generator has to
    | remember is a constraint it eventually forgets.
    |
    | "No text" matters more than it looks — burned-in subtitles are the
    | format's visual signature, and a still with its own lettering fights them.
    */

    'constraints' => env('SCENE_CONSTRAINTS', implode(' ', [
        'No text, letters, numbers, captions, watermarks or signatures anywhere in the image.',
        'No modern brand logos. No collage, no split panels, no borders.',
        'One continuous scene, one moment.',
    ])),

    /*
    |--------------------------------------------------------------------------
    | Motion
    |--------------------------------------------------------------------------
    |
    | The generator picks a move per scene from what the frame actually is: a
    | wide establishing shot pans, a face pushes in. Weights below are only the
    | fallback used when it returns something unusable, and they exist so a
    | broken response degrades into variety rather than 200 identical zoom-ins.
    |
    | `static` is deliberately absent from the fallback rotation. It is a
    | legitimate choice the generator can make for a held beat, but as a default
    | it produces a video that looks like a broken slideshow.
    |
    */

    'motion_fallback' => [
        MotionPreset::ZoomIn->value,
        MotionPreset::PanRight->value,
        MotionPreset::ZoomOut->value,
        MotionPreset::PanLeft->value,
    ],

    /*
    | Above this share of one preset across a story, the video reads as
    | mechanical. Surfaced at Gate 2 as a warning, never enforced — an operator
    | may legitimately want a run of push-ins through an escalation.
    */

    'motion_monotony_threshold' => 0.55,

    /*
    | The share of `static` above which the video stops being a film of stills
    | and becomes a slideshow. Every other preset is a camera move; static is
    | the absence of one, so it monopolises differently from the rest and needs
    | its own, much lower ceiling.
    |
    | Measured: the expensive model left 21 of 199 scenes static (11%); the
    | cheap one left 56 of 187 (30%). 15% sits above the good run and well below
    | the bad one.
    |
    | Read in two places, deliberately the same number: the Gate 2 warning that
    | tells the operator, and the scene-draft fallback that re-runs the act. A
    | warning threshold and an enforcement threshold that could drift apart
    | would mean a story warned about something the gate had already accepted.
    */

    'static_share_threshold' => (float) env('SCENE_STATIC_SHARE', 0.15),

    /*
    |--------------------------------------------------------------------------
    | Thumbnail candidates
    |--------------------------------------------------------------------------
    |
    | Flagged at draft time so Gate 4 has something to recommend. The app does
    | not compose thumbnails; it nominates the still.
    |
    */

    'thumbnail_candidates' => [
        'min' => 1,
        'max' => (int) env('SCENE_THUMBNAIL_MAX', 6),
    ],

    /*
    |--------------------------------------------------------------------------
    | Prompt/narration overlap
    |--------------------------------------------------------------------------
    |
    | An image prompt that restates its narration produces a literal
    | illustration of a sentence, and 200 of those in a row read as a slideshow
    | of captions. The prompt should describe a composed frame the narration is
    | spoken OVER, not a picture of the words.
    |
    | Some overlap is correct and expected — if the line names a red truck, the
    | frame may well contain one. This threshold is the point past which the
    | prompt has stopped composing and started transcribing. Surfaced at Gate 2,
    | never enforced: judging a picture is the operator's job.
    |
    */

    'max_narration_overlap' => (float) env('SCENE_MAX_OVERLAP', 0.5),

];
