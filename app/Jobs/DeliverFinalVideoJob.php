<?php

namespace App\Jobs;

use App\Actions\DeliverFinalVideo;
use App\Enums\RenderStage;
use App\Models\RenderJob;
use App\Models\Story;
use App\Support\RenderWorkspace;

/**
 * The last link: put the finished file where the operator will find it.
 *
 * **After the purge, not before it.** Both orders work — the purge keeps
 * `final.mp4` — so the question is only which failure is cheaper, and it is
 * this one. Delivery is the single stage that touches a path this app does not
 * control: an unplugged drive, an unmounted share, a folder mid-sync. A
 * delivery failure ahead of the purge would stop the chain and strand ~700 MB
 * of scratch on a render that actually succeeded, so the operator would pay
 * disk for somebody else's USB stick. Last, it costs nothing but its own row.
 *
 * It is a stage with a `render_jobs` row rather than three lines at the end of
 * the mux for the same reason. A failure with no row is a failure nobody sees,
 * and this is the stage most likely to fail for reasons that have nothing to do
 * with the video.
 *
 * `$tries = 1`, like every other render stage. A copy that failed because a
 * drive is not mounted will fail again in ten seconds, and retrying a 275 MB
 * copy three times against a full disk is not a recovery strategy.
 */
class DeliverFinalVideoJob extends RenderStageJob
{
    protected function stage(): RenderStage
    {
        return RenderStage::Deliver;
    }

    protected function run(Story $story, RenderWorkspace $workspace, RenderJob $job): array
    {
        $result = app(DeliverFinalVideo::class)->handle($story);

        // Not configured is a success with an explanation, never a silent one.
        // A blank row on the progress page beside "Deliver" would read as
        // "worked", and the whole point of the row is that the operator can
        // tell delivery-off from delivery-done.
        if (! $result['delivered']) {
            return [null, (string) $result['reason']];
        }

        return [$result['destination'], sprintf(
            '%s (%.1f MB)%s',
            $result['destination'],
            $result['bytes'] / 1048576,
            // Worth a sentence: a re-render lands on the same name, and the
            // operator may already have uploaded what was there.
            $result['replaced'] ? ' — replaced an existing file of the same name' : '',
        )];
    }
}
