<?php

namespace App\Jobs;

use App\Actions\GenerateSceneNarration;
use App\Contracts\SpeechSynthesizer;
use App\Enums\RenderStage;
use App\Models\Scene;

/**
 * One scene's narration audio.
 *
 * Fans out alongside the stills rather than after them: nothing about a still
 * informs a TTS call, and serialising the two would double the wall clock of
 * the stage for no reason. Word timings are the one that has to wait, because
 * it reads the file this job writes.
 */
class GenerateSceneNarrationJob extends SceneAssetJob
{
    protected function stage(): RenderStage
    {
        return RenderStage::SceneNarration;
    }

    protected function resolvedProviderName(): string
    {
        return app(SpeechSynthesizer::class)->providerName();
    }

    protected function generate(Scene $scene): array
    {
        $result = app(GenerateSceneNarration::class)->handle($scene);

        return [$result['path'], $result['log']];
    }
}
