<?php

namespace App\Support\Providers;

/**
 * Word-level timings for one scene's narration.
 *
 * Word level, not sentence level, and not negotiable: the format's visual
 * signature is an ASS karaoke highlight that moves word by word, and sentence
 * timings cannot produce it.
 *
 * Scene-local. Offsetting into whole-video time happens later, from
 * `scene_audio.offset_samples` — transcribing a 40-minute file instead is slow
 * and drifts on word timestamps toward the end.
 */
final class Transcription
{
    /**
     * @param  array<int, array{word: string, start_ms: int, end_ms: int}>  $words
     */
    public function __construct(
        public readonly array $words,
        public readonly int $durationMs,
        public readonly ProviderUsage $usage,
    ) {}

    public function wordCount(): int
    {
        return count($this->words);
    }

    /** The transcript as text, for comparing against what was asked for. */
    public function text(): string
    {
        return implode(' ', array_column($this->words, 'word'));
    }
}
