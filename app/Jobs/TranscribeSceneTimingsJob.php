<?php

namespace App\Jobs;

use App\Actions\TranscribeSceneTimings;
use App\Contracts\Transcriber;
use App\Enums\RenderStage;
use App\Models\Scene;

/**
 * One scene's word-level timings.
 *
 * The one asset stage that cannot start with the others: it transcribes the
 * audio file the narration stage writes, so it is dispatched as a second batch
 * once the first has finished — for whichever scenes actually got audio, not
 * only if every one of them did.
 */
class TranscribeSceneTimingsJob extends SceneAssetJob
{
    protected function stage(): RenderStage
    {
        return RenderStage::SceneTimings;
    }

    protected function resolvedProviderName(): string
    {
        return app(Transcriber::class)->providerName();
    }

    protected function generate(Scene $scene): array
    {
        $result = app(TranscribeSceneTimings::class)->handle($scene);

        return [null, $result['log']];
    }
}
