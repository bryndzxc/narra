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
     */
    public function __construct(
        public readonly int $firstSentence,
        public readonly int $lastSentence,
        public readonly string $frame,
        public readonly array $charactersPresent = [],
        public readonly ?string $motionPreset = null,
        public readonly bool $isThumbnailCandidate = false,
    ) {}

    public function sentenceCount(): int
    {
        return $this->lastSentence - $this->firstSentence + 1;
    }
}
