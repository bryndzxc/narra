<?php

namespace App\Enums;

/**
 * A single scene's progress from text to finished clip.
 *
 * Scenes are where a partial failure has to stay visible: at 200 scenes, three
 * failed image generations must not fail the video, but they must not vanish
 * either. `Failed` is a resting state a scene can be retried out of.
 */
enum SceneStatus: string
{
    /** Written by the pipeline, not yet reviewed. Gate 2 is pending. */
    case Drafted = 'drafted';

    /** Approved at Gate 2. Paid asset generation is now permitted. */
    case Approved = 'approved';

    /** An asset job is in flight for this scene. */
    case Generating = 'generating';

    /** Image, narration and timings all present. Ready to render a clip. */
    case Ready = 'ready';

    /** An asset job failed. The batch continues; this scene is flagged. */
    case Failed = 'failed';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
