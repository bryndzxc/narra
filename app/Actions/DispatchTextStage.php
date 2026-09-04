<?php

namespace App\Actions;

use App\Enums\OperatorAction;
use App\Exceptions\GateViolationException;
use App\Jobs\DraftSceneListJob;
use App\Jobs\WriteStoryJob;
use App\Models\Story;

/**
 * Puts a free-of-gates, not-free-of-money text stage on the `text` queue.
 *
 * Two stages, one class, because they need exactly the same two things before
 * they queue anything and a second copy of that pair is how the pair drifts:
 *
 *   1. **The capability predicate**, from OperatorAction — the same one the
 *      command consults. This is the entire reason this class exists rather
 *      than a `dispatch()` call inside a Livewire method. `assets:generate`
 *      and its own button disagreed for a whole phase because they each had
 *      their own opinion about status, and the only reason it was ever found
 *      is that an operator pressed the thing.
 *
 *   2. **The worker check**, in this process, before the dispatch. The
 *      dispatching process is the only one in the system with fresh code by
 *      construction — the operator started it seconds ago — so it is the only
 *      place a question about staleness can be asked and believed. A stale
 *      worker is a refusal. An absent one is a warning, because nothing is
 *      lost: the job waits.
 *
 * The warning matters more here than anywhere else in the app. `text` was, for
 * two phases, a queue in config and in the setup docs that received nothing at
 * all — an operator following the instructions ran a worker that could never
 * get a job. Now it receives the stage that starts every video, and "queued"
 * and "queued, and nothing is listening" must not read the same on a page whose
 * whole purpose is to replace watching a terminal.
 */
class DispatchTextStage
{
    public function __construct(private readonly AssertWorkersCurrent $workers) {}

    /**
     * Outline, then act scripts.
     *
     * @param  array<int, int>  $actsOnly
     * @return array{queue: string, notes: array<int, array{level: string, message: string}>}
     */
    public function writeScript(
        Story $story,
        ?int $actCount = null,
        array $actsOnly = [],
        bool $outlineOnly = false,
        bool $checkWorkers = true,
    ): array {
        $notes = $this->preflight($story, OperatorAction::WriteScript, $checkWorkers);

        WriteStoryJob::dispatch($story->id, $actCount, $actsOnly, $outlineOnly);

        return ['queue' => $this->queue(), 'notes' => $notes];
    }

    /**
     * Cast, then scenes.
     *
     * @param  array<int, int>  $onlyActs
     * @return array{queue: string, notes: array<int, array{level: string, message: string}>}
     */
    public function draftScenes(
        Story $story,
        bool $rebuild = false,
        array $onlyActs = [],
        bool $castOnly = false,
        bool $checkWorkers = true,
    ): array {
        $notes = $this->preflight($story, OperatorAction::DraftSceneList, $checkWorkers);

        DraftSceneListJob::dispatch($story->id, $rebuild, $onlyActs, $castOnly);

        return ['queue' => $this->queue(), 'notes' => $notes];
    }

    /**
     * @return array<int, array{level: string, message: string}>
     */
    private function preflight(Story $story, OperatorAction $action, bool $checkWorkers): array
    {
        if ($action->refusalReason($story->status) !== null) {
            throw GateViolationException::actionUnavailable($action, $story->status);
        }

        return $checkWorkers ? $this->workers->handle($this->queue()) : [];
    }

    private function queue(): string
    {
        return (string) config('render.queues.text');
    }
}
