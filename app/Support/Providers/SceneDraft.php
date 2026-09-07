<?php

namespace App\Support\Providers;

/**
 * One scene, as proposed by the generator.
 *
 * Note what is NOT here: the narration text. Scenes are addressed by SENTENCE
 * RANGE into the act script, and the narration is sliced out of that script in
 * PHP.
 *
 * That is deliberate and it is the important decision in this stage. The script
 * was approved by an operator at Gate 1 and it is the text a paid TTS call will
 * read aloud; a model asked to reproduce ~1,000 words verbatim will
 * occasionally paraphrase, drop a clause, or fix what it thinks is a typo, and
 * none of that is visible in a diff nobody runs. Asking for indices instead
 * makes the narration verbatim BY CONSTRUCTION — the only thing that can go
 * wrong is a range that does not line up, which is loud and checkable.
 */
final class SceneDraft
{
    /**
     * @param  int  $firstSentence  1-indexed, inclusive.
     * @param  int  $lastSentence  1-indexed, inclusive.
     * @param  string  $frame  What is IN the picture — subject, setting,
     *                         expression, light. Not a restatement of the line.
     * @param  array<int, string>  $charactersPresent  Names from the cast. Their
     *                                                 fixed descriptions are
     *                                                 pasted in by the builder.
     * @param  string  $expression  What the faces in this frame are DOING, named
     *                              plainly. Empty when nobody is in the frame.
     *
     * `expression` is its own field rather than a sentence the frame is trusted
     * to contain, and the reason is measured. Across 657 peopled frames in four
     * finished stories, 44.1% mentioned a face at all and only **22.2% named
     * what it was doing** — so more than three quarters of the frames with a
     * person in them carried no expression instruction, and the still came back
     * with the neutral expression of the character's reference portrait.
     *
     * The system prompt had asked for expression in prose the whole time. This
     * is the same argument the outline schema makes about
     * `antagonist_justification`: a model asked in prose for five things will
     * reliably give four when one is awkward, and a required field cannot be
     * skipped, only answered badly — which is something a person can see.
     */
    public function __construct(
        public readonly int $firstSentence,
        public readonly int $lastSentence,
        public readonly string $frame,
        public readonly array $charactersPresent = [],
        public readonly ?string $motionPreset = null,
        public readonly bool $isThumbnailCandidate = false,
        public readonly string $expression = '',
    ) {}

    public function sentenceCount(): int
    {
        return $this->lastSentence - $this->firstSentence + 1;
    }
}
