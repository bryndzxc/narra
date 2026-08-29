<?php

namespace App\Enums;

/**
 * One stage of the job pipeline, as recorded in `render_jobs.stage`.
 *
 * The whole pipeline is here, not just the FFmpeg half, because `render_jobs`
 * is the operator's only window into what a queue worker is doing. There is no
 * Horizon on this platform — `pcntl` and `posix` do not exist in Windows PHP —
 * so the Phase 1 batch page is built from this table plus `job_batches`, and a
 * stage that is not enumerated here is a stage that page cannot show.
 */
enum RenderStage: string
{
    // Free — text only, before Gate 2.
    case Outline = 'outline';
    case ActScripts = 'act_scripts';
    case DraftScenes = 'draft_scenes';

    // Paid. Nothing here may start before StoryStatus::ScenesApproved.
    case Images = 'images';
    case SceneNarration = 'scene_narration';
    case SceneTimings = 'scene_timings';

    // Local render. CPU, not money.
    case SceneClips = 'scene_clips';
    case Concat = 'concat';
    case Subtitles = 'subtitles';
    case Mux = 'mux';
    case Purge = 'purge';

    // Text again, but only after the render — chapters need real timestamps.
    case Metadata = 'metadata';

    /**
     * Whether this stage can spend money.
     *
     * Used to decide whether a stage may run at all, given the story's status.
     * The render stages are CPU-bound and free; the three provider stages are
     * not, and are gated.
     */
    public function isPaid(): bool
    {
        return in_array($this, [self::Images, self::SceneNarration, self::SceneTimings], true);
    }

    /**
     * Whether this stage fans out to one job per scene.
     *
     * These are the stages that need a batch and a progress page: at 200 scenes
     * a partial failure is invisible without one.
     */
    public function fansOut(): bool
    {
        return in_array(
            $this,
            [self::Images, self::SceneNarration, self::SceneTimings, self::SceneClips],
            true
        );
    }

    public function label(): string
    {
        return ucwords(str_replace('_', ' ', $this->value));
    }
}
