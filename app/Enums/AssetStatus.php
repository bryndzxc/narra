<?php

namespace App\Enums;

/**
 * Lifecycle of one generated asset row — an audio track, or one scene's
 * narration and word timings.
 *
 * Deliberately shared between `audio_tracks` and `scene_audio`: both are
 * "something a provider produces for money, one file at a time", and both need
 * to be re-runnable in isolation. A bad sentence in scene 147 re-bills scene
 * 147, not the whole 40 minutes.
 */
enum AssetStatus: string
{
    case Pending = 'pending';
    case Generating = 'generating';
    case Ready = 'ready';
    case Failed = 'failed';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
