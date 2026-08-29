<?php

namespace App\Support\Providers;

/**
 * One scene's narration audio.
 *
 * Per scene, never per story. One giant TTS call for 40 minutes means one bad
 * sentence forces a full re-bill; per-scene audio is re-generatable in
 * isolation and concatenated at mux time.
 *
 * `durationMs` is the RAW audio duration and is what `scenes.duration_ms`
 * stores. The clip length is derived from it — ceil(ms / 1000 * fps) frames —
 * and is never stored twice.
 */
final class SynthesizedSpeech
{
    public function __construct(
        public readonly string $bytes,
        public readonly string $mimeType,
        public readonly int $durationMs,
        public readonly string $voiceId,
        public readonly ProviderUsage $usage,
    ) {}
}
