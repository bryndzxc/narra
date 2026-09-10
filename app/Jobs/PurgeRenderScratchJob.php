<?php

namespace App\Jobs;

use App\Actions\PurgeRenderScratch;
use App\Enums\RenderStage;
use App\Models\RenderJob;
use App\Models\Story;
use App\Support\RenderWorkspace;

/**
 * Delete the scratch once the deliverable exists and decodes.
 *
 * The last link of the render chain, after the mux. This class existed for a
 * while dispatched by nobody — the spec said "scratch is purged on successful
 * render", the chain ended at the mux, and the only thing that ever ran a purge
 * was an operator typing `render:purge`. Which is to say it never ran: a
 * 30-40 minute render leaves roughly 700 MB of clips and padded PCM behind, and
 * at every-other-day uploads that is a disk rather than a housekeeping note.
 *
 * **Being in the chain is what makes it safe on the failure path.**
 * `Bus::chain` stops at the first failure, so a mux that threw never reaches
 * this at all. The Action then refuses on its own account unless `final.mp4`
 * exists AND its tail decodes — existence is not success, and a truncated file
 * exists. The chain decides whether this is REACHED; the guard decides whether
 * it PROCEEDS. Neither is trusted alone, because the thing being deleted is
 * exactly what a re-run would otherwise reuse.
 *
 * The trade, stated rather than buried: a render rejected at Gate 3 now
 * re-encodes every clip instead of re-running one stage. That was weighed
 * against the disk and the disk won. If rejections become common the answer is
 * to move this to Gate 3 approval — not to soften the guard.
 */
class PurgeRenderScratchJob extends RenderStageJob
{
    public function __construct(int $storyId, public bool $dryRun = false)
    {
        parent::__construct($storyId);
    }

    protected function stage(): RenderStage
    {
        return RenderStage::Purge;
    }

    protected function run(Story $story, RenderWorkspace $workspace, RenderJob $job): array
    {
        // The guard lives in the Action: it refuses to run unless final.mp4
        // exists AND its tail decodes, because existence is not success.
        $result = app(PurgeRenderScratch::class)->handle($workspace->renderRoot, $this->dryRun, $story->id);

        return [$workspace->path('final.mp4'), sprintf(
            '%s%s freed, kept: %s',
            $this->dryRun ? 'dry run, ' : '',
            $this->human($result['bytes_freed']),
            implode(', ', $result['kept'])
        )];
    }

    private function human(int $bytes): string
    {
        return $bytes >= 1073741824
            ? sprintf('%.2f GB', $bytes / 1073741824)
            : sprintf('%.1f MB', $bytes / 1048576);
    }
}
