<?php

/*
|--------------------------------------------------------------------------
| Character reference sheets
|--------------------------------------------------------------------------
|
| The half of the consistency mechanism that is not a seed.
|
| A locked seed keeps a face stable only while everything around it holds
| still. It does not survive the prompt changing, and the prompt changes every
| scene by design — pose, lighting, background and who else is in frame are
| the whole point of a scene. A reference image is the part that does not move:
| the same pixels of the same face, cited by every one of the 150-250 stills
| that character appears in.
|
| CLAUDE.md names character drift across that many stills as the single biggest
| quality risk in the project. This file is the answer to it.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Candidates per character
    |--------------------------------------------------------------------------
    |
    | Generated as a set, so the operator chooses rather than accepts.
    |
    | The count is a direct multiplier on the sheet's bill and nothing else, so
    | it is worth being honest about what it buys. One candidate is not a
    | choice, it is a coin toss that has to be re-flipped by regenerating. Four
    | is enough that one of them is usually right, which is what stops the
    | regenerate button being the normal path. Beyond four the operator is
    | picking between near-duplicates and paying for the privilege.
    |
    */

    'candidates' => (int) env('CHARACTER_CANDIDATES', 4),

    /*
    | Hard ceiling on one generate click, whatever the count above says. A
    | fat-fingered config value should not be able to authorise a hundred
    | images from a button labelled "generate sheet".
    */
    'max_candidates' => (int) env('CHARACTER_MAX_CANDIDATES', 6),

    /*
    |--------------------------------------------------------------------------
    | The reference frame
    |--------------------------------------------------------------------------
    |
    | Deliberately the most boring image in the project.
    |
    | A reference is not artwork and is never seen by a viewer. Its only job is
    | to be an unambiguous statement of what this person looks like, so every
    | property that would make it a nicer picture makes it a worse reference:
    | dramatic light hides bone structure, a three-quarter turn hides half the
    | face, a background full of detail gives the scene generator things to copy
    | that have nothing to do with the character.
    |
    | Front-facing, neutral, evenly lit, plain background. Nothing else.
    |
    */

    'reference_frame' => env('CHARACTER_REFERENCE_FRAME', implode(' ', [
        'Character reference sheet portrait.',
        'A single person, alone in the frame, facing the camera directly, head and shoulders,',
        'neutral relaxed expression, mouth closed, eyes open and looking at the camera.',
        'Flat even frontal lighting with no dramatic shadow and no rim light.',
        'Plain flat mid-grey background, completely empty.',
        'No other people, no props, no text, no border, no collage, no multiple views.',
    ])),

    /*
    | Appended after the frame and the description. The art style block is
    | shared with scene prompts and lives in config/scenes.php — stated once,
    | there, for the same reason it is stated once for scenes: a look restated
    | per prompt is a look that drifts per prompt. A reference rendered in a
    | different style from the stills it conditions is worse than no reference.
    */

    'inherit_scene_style' => (bool) env('CHARACTER_INHERIT_STYLE', true),

    /*
    |--------------------------------------------------------------------------
    | Output size
    |--------------------------------------------------------------------------
    |
    | Square and smaller than a still, on purpose. A reference is cropped to a
    | head and shoulders, so 1024x1024 spends every pixel on the face rather
    | than on the 16:9 emptiness either side of it — and most providers price
    | by resolution.
    |
    */

    'width' => (int) env('CHARACTER_REFERENCE_WIDTH', 1024),
    'height' => (int) env('CHARACTER_REFERENCE_HEIGHT', 1024),

    /*
    |--------------------------------------------------------------------------
    | Where sheets are stored
    |--------------------------------------------------------------------------
    |
    | The `characters` disk, not `renders`. Render scratch is purged on a
    | successful render and a reference has to outlive that: it is cited by
    | every re-render, every reopened Gate 2, and every still regenerated
    | months later. A reference deleted by a scratch purge would silently
    | reintroduce exactly the drift it exists to prevent.
    |
    */

    'disk' => env('CHARACTER_DISK', 'characters'),

];
