<?php

namespace App\Jobs;

use App\Actions\GenerateSceneImage;
use App\Contracts\ImageGenerator;
use App\Enums\RenderStage;
use App\Models\Scene;

/**
 * One scene's still. The stage that spends ~70% of a video's cost, one job at a
 * time so that three failures out of 186 are three retries rather than a
 * re-billed video.
 *
 * Thin, like every other stage job: the work is GenerateSceneImage, which is
 * where the character reference mechanism is finally called from.
 */
class GenerateSceneImageJob extends SceneAssetJob
{
    protected function stage(): RenderStage
    {
        return RenderStage::Images;
    }

    protected function resolvedProviderName(): string
    {
        return app(ImageGenerator::class)->providerName();
    }

    protected function generate(Scene $scene): array
    {
        $result = app(GenerateSceneImage::class)->handle($scene);

        return [$result['path'], $result['log']];
    }
}
