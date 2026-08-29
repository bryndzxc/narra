<?php

namespace App\Contracts;

use App\Models\Scene;
use App\Support\Providers\SynthesizedSpeech;

/**
 * One scene's narration, in the story's voice.
 *
 * Per scene, never for the whole story — see SynthesizedSpeech. The voice is
 * stored on the story rather than passed per call by convention, so a channel
 * keeps one consistent narrator across every video, and it is American English
 * because the audience is.
 */
interface SpeechSynthesizer
{
    public function synthesize(Scene $scene, string $text, string $voiceId): SynthesizedSpeech;

    /**
     * Voices this provider offers, for the story-settings picker.
     *
     * @return array<int, array{id: string, name: string, locale: string}>
     */
    public function voices(): array;
}
