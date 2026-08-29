<?php

namespace App\Enums;

/**
 * How a story's acts relate to one another. An operator choice per video.
 */
enum StoryFormat: string
{
    /** One continuous narrative across all acts. */
    case Single = 'single';

    /**
     * Three to five self-contained stories, one per act.
     *
     * Easier to write, lower coherence risk over 30-40 minutes, and the act
     * titles become natural chapter hooks. Recommended while the pipeline is
     * still being proven.
     */
    case Anthology = 'anthology';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
