<?php

namespace App\Actions;

use App\Exceptions\DispatchRefusedException;
use App\Models\Story;
use App\Support\AlignerProbe;
use App\Support\SceneChangeSet;

/**
 * The checks that have to happen in the process holding the button, before a
 * single job is queued.
 *
 * **Why here and not in the job.** Every guard this project has added after an
 * incident has been a per-job guard, and each one worked exactly as designed
 * while the run it was protecting still went wrong. The reason is structural: a
 * job runs inside the worker, so a guard in a job is a guard the stale worker
 * gets to evaluate. The provider pin survives that — a stale worker resolving
 * the wrong provider still refuses — but it survives it by failing 186 scenes
 * one at a time, which is a detection, not a prevention.
 *
 * The dispatching process is the only one in the system with fresh code by
 * construction: the operator started it seconds ago. So it is the only place a
 * question about staleness can be asked and believed.
 *
 * Two questions, both of which have already cost this project a run:
 *
 *   1. **Would the workers do what I am about to pay for?** Delegated to
 *      AssertWorkersCurrent, which the render path uses too — it lives there
 *      rather than here because it was needed on a second queue within minutes
 *      of being written, and a guard wired into one caller out of two is this
 *      project's most reliable source of bugs.
 *
 *   2. **Can the free stage run?** Alignment costs nothing and runs second. When
 *      it cannot run, the money for the stage it depends on is already spent.
 *      This one is asset-specific, which is why it stays here.
 *
 * Both are refusals, and neither is skippable by default; the escape hatches are
 * named for what they give up rather than for being an override.
 */
class PreflightAssetDispatch
{
    public function __construct(private readonly AssertWorkersCurrent $workers) {}

    /**
     * @return array<int, array{level: string, message: string}>
     *                                                           Notes worth printing. Refusals throw; a `warn` is
     *                                                           something the operator should see but that must
     *                                                           not stop a dispatch.
     */
    public function handle(
        Story $story,
        ?SceneChangeSet $changes = null,
        bool $checkWorkers = true,
        bool $checkAligner = true,
    ): array {
        $changes ??= SceneChangeSet::for($story);
        $notes = [];

        $queue = (string) config('render.queues.assets');

        if ($checkWorkers) {
            $notes = array_merge($notes, $this->workers->handle($queue));
        }

        if ($checkAligner && $changes->needsTranscription->isNotEmpty()) {
            $notes = array_merge($notes, $this->assertAlignerRuns($changes->needsTranscription->count()));
        }

        return $notes;
    }

    /**
     * Refuse if the aligner cannot import, when the aligner is what would run.
     *
     * @return array<int, array{level: string, message: string}>
     */
    private function assertAlignerRuns(int $pending): array
    {
        if (! AlignerProbe::isActive()) {
            return [];
        }

        $probe = AlignerProbe::check();

        if (! $probe['ok']) {
            throw DispatchRefusedException::alignerUnavailable($probe, $pending);
        }

        return [self::ok(sprintf(
            'whisperx imports on %s (Python %s).',
            (string) ($probe['resolved'] ?? $probe['interpreter']),
            (string) ($probe['version'] ?? '?'),
        ))];
    }

    /** @return array{level: string, message: string} */
    private static function ok(string $message): array
    {
        return ['level' => 'ok', 'message' => $message];
    }
}
