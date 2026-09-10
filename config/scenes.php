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
    | Preview a candidate before adopting it — `style:preview <story>
    | --style-file=...` renders four fixed frames against it and never writes it
    | back. Adopting one has a consequence the preview cannot show: every
    | character reference sheet already on disk was drawn in the PREVIOUS look,
    | and each of those faces conditions every still its character appears in.
    | The sheets are fingerprinted (see StyleFingerprint), so a retune marks
    | them stale and asset dispatch refuses until they are regenerated. That
    | refusal is the feature, not an obstacle: without it a retune produces one
    | video in two looks and bills for all of it.
    |
    */

    'art_style' => env('SCENE_ART_STYLE', implode(' ', [
        // "Grounded and realistic in proportion" was the phrase that made this
        // channel's output realistic-looking anime: a cel-shaded medium being
        // asked for photographic proportion. The medium is the point — this
        // niche runs on idealised character art — so the line now names the
        // look rather than apologising for it. The anti-chibi constraint at the
        // bottom is what keeps "idealised" from becoming "exaggerated"; the two
        // are different axes and only one of them was ever wanted.
        'Anime-style illustration in a polished modern television-anime look, drawn with idealised character art rather than photographic realism.',
        // Kept verbatim from the painted style that preceded this one. The
        // medium changed; the palette discipline is what makes this channel
        // look like one channel, and it had no reason to move with it.
        'Soft directional light, muted earth palette with one warm accent.',
        'Clean confident linework, flat cel shading with limited gradients, hand-painted background art.',
        // Idealised beauty as the default, and the reason it is a style line
        // rather than something each description asks for: it is a property of
        // the MEDIUM, not of any one character. Written per character it would
        // drift across 150-250 prompts and would also have to be repeated for
        // every antagonist, which is exactly where a generator starts editorialising.
        //
        // "Everyone, antagonists included" is load-bearing rather than
        // decorative. A generator given a character who is in the wrong will
        // draw them plain, heavy or unkempt to say so, and this genre depends
        // on the opposite: the brother-in-law who is taking the house is more
        // threatening for being handsome, and a story that telegraphs its
        // villain through their face has given away its own reveal.
        //
        // Note what is NOT named here. No real person, no actor, no existing
        // character — the treatment is described so the look is reproducible
        // from the words, and so a retune is an edit to a sentence rather than
        // a dependency on whatever a model happens to know about a name.
        'Every named character is drawn to the medium\'s ideal of beauty: large expressive eyes with clear catchlights, clean symmetrical features, smooth even skin, a refined jawline, and glossy hair rendered in individual strands with highlights.',
        'Men are tall and sharp-featured, well-groomed, with a confident upright bearing. Women have delicate features, expressive eyes and deliberately styled hair.',
        'This applies to antagonists exactly as it does to leads. Nobody is drawn plain, coarse or unflattering to signal that they are in the wrong.',
        // The cost of the line above, paid for immediately.
        //
        // Idealised faces converge — that is what idealisation IS, a pull
        // toward one ideal — so the thing that told three women apart at
        // distance is now doing more work with less help. Hair was already the
        // primary identifier (see characterSystemPrompt()); this says so in the
        // style too, because the style is the half of the prompt that cannot
        // drift.
        'Because idealised faces converge, hair carries the identification: each character\'s hair silhouette — its shape, length, parting and volume — must stay unmistakably their own and must separate them from everyone else in the frame at a distance where no facial detail is legible.',
        // The line that does the most work, and the hardest one to get right.
        //
        // The first version said adults are drawn at their TRUE age, "never
        // softened toward youth". That was written against a real failure — an
        // anime style draws everyone young unless told otherwise — and it
        // over-corrected, because it answered an anime problem with a
        // photographic rule. Anime convention already renders adults younger
        // than a photograph does; instructing it to draw a stated age exactly
        // means instructing it to draw the only thing it has for "old", which
        // is photoreal ageing texture. In the preview it read as intended for
        // the woman in her forties and turned a woman written as late sixties
        // into an eighty-five-year-old with drawn-on wrinkles and liver spots.
        //
        // So the baseline moves down about a decade and the RANGE does not
        // move at all. That distinction is the whole line. A uniform shift
        // keeps every gap intact — sixty still reads clearly older than
        // thirty-five — while a compression toward young would put the picture
        // in contradiction with narration that names ages and relationships,
        // and a picture that argues with the narrator is worse than one drawn
        // slightly wrong.
        //
        // Where age is allowed to live is stated too, because "younger" with no
        // mechanism just means "less of everything": hair colour, hairline,
        // face shape, neck and shoulder line, build. Those survive a wide shot.
        // Wrinkles do not, which is the same argument the character extraction
        // prompt makes about silhouette over texture — see characterSystemPrompt().
        //
        // This line compensates; it does not cast. A story whose plot turns on
        // a dead mother, memory care and an uncle with a cane is asking an
        // anime style to carry a cast it renders worst, and no wording here
        // fixes that. `stories.cast_age_profile` is where that is said upstream.
        'Apparent age follows anime convention rather than photography: adults read roughly a decade younger than their stated age — a character of forty reads as late twenties, a character of thirty as early twenties.',
        'That shift is uniform across the cast and never a compression: relative age must stay unmistakable and must agree with the narration, so someone written as sixty still reads clearly older than someone written as thirty-five.',
        'Age is carried by hair colour, hairline, face shape, neck and shoulder line and build — never by wrinkles, creases, liver spots, sagging or any other photoreal ageing texture.',
        // Idealisation is a second, different way for age to be flattened, and
        // it arrives from the opposite direction to the one the lines above
        // were written against. Drawing everyone beautiful must not mean
        // drawing everyone young: the ideal is age-appropriate, not uniform.
        'Idealisation does not erase age. An older character is drawn as a striking, well-kept older person — the ideal for their decade — never as a young one.',
        'Cinematic 16:9 composition with clear foreground, midground and background staging.',
        // "No sparkle" now sits four lines from "clear catchlights" and "hair
        // rendered with highlights", which is close enough for a generator to
        // resolve the pair by dropping both. The negative is narrowed to the
        // effects it always meant — overlays — so it cannot be read as
        // suppressing the character art the line above requires.
        'Restrained and unsentimental in tone and staging. No chibi or super-deformed proportions, no exaggerated anatomy, no speed lines, no glitter or sparkle overlays, no bloom or lens flare. Eye catchlights and hair highlights are character art, not effects, and stay.',
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
        // The style asks for idealised faces, which is the instruction most
        // likely to pull a generator toward a face it already knows. Said as a
        // constraint rather than trusted to the style: the look has to be
        // reproducible from the description, not borrowed from a likeness.
        'No real person\'s likeness and no existing fictional character: no actor, model, celebrity or recognisable character design.',
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
    | Flagged at draft time; ComposeThumbnails crops the flagged stills into
    | the split-panel candidates Gate 4 picks from, so this pool is what a
    | thumbnail is made of.
    |
    | PER ACT, NOT PER STORY. The cap used to be a story-wide `max` of 6,
    | applied in act order, and every story in the database filled it by act
    | 3: the departure, the search and the refusal never contributed a single
    | candidate, and the pair score's reversal bonus had never fired on real
    | data. A story-wide cap walked front to back is biased toward the front
    | by construction, whatever the number; a cap applied per act cannot be.
    | It is also the number the prompt already asks for — "at most N scenes in
    | this act" — read from here so the request and the keep cannot disagree.
    |
    | Nominations past the cap in an act are DROPPED, and the draft's job row
    | says how many and from which act. They used to be dropped in silence,
    | which is why nobody could see the pool was shaped like this.
    |
    */

    'thumbnail_candidates' => [
        'min' => 1,
        'per_act' => (int) env('SCENE_THUMBNAILS_PER_ACT', 2),
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
