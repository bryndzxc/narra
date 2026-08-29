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
 * Never in the render chain, and that is deliberate. Scratch is what a re-run
 * reuses, and at Gate 3 the operator may well reject the render — throwing away
 * the clips and the padded PCM the moment the mux finishes would turn a
 * one-stage re-render into a full one. This is dispatched on request, after a
 * human has watched the video.
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
        $result = app(PurgeRenderScratch::class)->handle($workspace->renderRoot, $this->dryRun);

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
