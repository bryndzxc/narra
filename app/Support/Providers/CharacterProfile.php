<?php

namespace App\Support\Providers;

/**
 * One recurring character, and the exact words that will describe them in
 * every image prompt they appear in.
 *
 * `description` is not a summary of the character. It is the literal string
 * that gets pasted into 150-250 prompts, so it is written to be pasted:
 * physical, fixed, and free of anything that changes between scenes. Age,
 * build, hair, face, habitual clothing. Never mood, never posture, never what
 * they are doing — those belong to the frame and change every scene.
 *
 * Character consistency across a long video is the single biggest quality risk
 * in this format and it gets worse the longer the video runs. The mechanism is
 * that this text never varies. A generator that re-describes a character per
 * scene has already lost: by scene 90 the wording has drifted and the face has
 * drifted with it.
 */
final class CharacterProfile
{
    public function __construct(
        public readonly string $name,
        /** Fixed physical description. Reused verbatim in every prompt. */
        public readonly string $description,
        /**
         * Habitual clothing. CLOTHING ONLY — not props.
         *
         * It used to say "wardrobe and recurring props", and a reference
         * bake-off showed what that costs. The extractor wrote "often holding
         * a phone or a handheld microphone" for a character who uses a
         * microphone in exactly one scene, and because this text is pasted
         * verbatim into every prompt he appears in, the generator put a
         * microphone in a parking-lot argument four acts before the speech.
         *
         * A cardigan is on her in every scene; a microphone is in his hand in
         * one. Only the first kind of thing belongs to the character. Anything
         * "often" true is by definition not always true, and this field is
         * always applied.
         */
        public readonly string $styleNotes = '',
        /** How central they are: 'lead', 'supporting', 'minor'. */
        public readonly string $importance = 'supporting',
    ) {}
}
