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

    /*
     * Reading the cast out of the finished scripts.
     *
     * A stage of its own rather than part of DraftScenes, although one job
     * runs both. They fail separately and this one runs first, which is
     * exactly what made its absence expensive: three dispatches of story 21
     * died in extraction, before DraftScenes had opened its row, so nothing
     * recorded a failure and the render page showed a story that had simply
     * stopped after its act scripts. $0.35 of billed calls, three terminal
     * failures, and the only surface an operator has said nothing at all.
     *
     * The job deliberately did not paper over this by opening a DraftScenes
     * row on extraction's behalf — a row for a stage the Action thinks it is
     * not recording is a worse lie than a missing one. The fix is the stage
     * that was missing, not a borrowed row.
     */
    case ExtractCast = 'extract_cast';

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

    /*
     * Copying the finished file somewhere a human will find it.
     *
     * A stage rather than a side effect of the mux, because it is the one part
     * of the render that touches a path this app does not control — an external
     * drive, a synced folder, a share that may not be mounted. That fails in
     * ways the mux does not, and a failure with no row is a failure nobody
     * sees.
     */
    case Deliver = 'deliver';

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
