<?php

namespace App\Jobs;

use App\Enums\SceneStatus;
use App\Exceptions\NarrationPaceException;
use App\Exceptions\StaleWorkerException;
use App\Models\RenderJob;
use App\Models\Scene;
use App\Models\Story;
use App\Support\RenderWorkspace;
use App\Support\RunFingerprint;
use Throwable;

/**
 * Base for the three paid fan-out stages: stills, narration, word timings.
 *
 * What it adds to RenderStageJob is small and all of it is about money and
 * visibility rather than about the work:
 *
 *  - **The `assets` queue, not `render`.** These wait on somebody else's HTTP
 *    server; a scene clip saturates a CPU. Sharing one queue would let a
 *    40-minute mux hold 186 image calls behind it, which is the thing the
 *    three-queue split exists to prevent.
 *
 *  - **A per-scene status.** `SceneStatus::Generating` and `Failed` had no
 *    writer anywhere in the app before this, which meant a partial failure was
 *    invisible: at 186 scenes, three failed stills must not fail the video, but
 *    they must not vanish either. This is what makes the retry set findable.
 *
 * `$tries = 1` is inherited and matters more here than it does on a render
 * stage. An automatic retry of a call that already billed is an automatic
 * second charge, and the operator never asked for either.
 *
 * The status written per job is for the progress page while the batch runs.
 * It is deliberately NOT the authority: two of these jobs run concurrently for
 * the same scene, so the last one to finish can read the row before the other's
 * write lands and leave a complete scene marked `Generating`. The batch's
 * completion callback reconciles every scene wholesale once nothing is in
 * flight, and that pass is what decides whether the story reaches
 * `assets_ready`. See DispatchAssetGeneration.
 */
abstract class SceneAssetJob extends RenderStageJob
{
    /**
     * The provider the operator was quoted, captured at dispatch.
     *
     * Public and not readonly for the same reason as the parent's properties:
     * Laravel rehydrates a queued job by writing them back through reflection.
     */
    public ?string $expectedProvider = null;

    /**
     * The whole set of code and config facts the dispatch was made under.
     *
     * The provider pin above is the first version of this idea and it is kept
     * rather than replaced, because it produces a much better message for the
     * case it does cover. This is the same idea widened to the settings that
     * actually vary — see RunFingerprint.
     *
     * Public and not readonly for the same reflection reason as everything else
     * on a queued job.
     *
     * @var array<string, scalar|null>|null
     */
    public ?array $expectedFingerprint = null;

    /**
     * @param  array<string, scalar|null>|null  $expectedFingerprint
     */
    public function __construct(
        int $storyId,
        ?int $sceneId = null,
        ?string $expectedProvider = null,
        ?array $expectedFingerprint = null,
    ) {
        parent::__construct($storyId, $sceneId);

        $this->expectedProvider = $expectedProvider;
        $this->expectedFingerprint = $expectedFingerprint;
    }

    protected function queueName(): string
    {
        return (string) config('render.queues.assets');
    }

    /** What this stage's contract resolves to inside THIS process. */
    abstract protected function resolvedProviderName(): string;

    /**
     * Refuse to run if the worker would use a different provider than the one
     * the operator approved.
     *
     * The quote is computed in the web or CLI process; the work happens in a
     * queue worker, which is a different, long-lived process that loaded its
     * code and its container when it started. A worker running since before a
     * `.env` edit or a deploy is still bound to the old provider, and nothing
     * in the dispatch can see that.
     *
     * That is not hypothetical. A run quoted at $6.51 against `fal` was
     * executed by a worker started hours earlier: 185 of 186 stills came back
     * as flat-fill placeholders from a stand-in, the ledger gained $8.08 of
     * spend nobody made, and the only reason it was noticed was a vendor
     * dashboard showing zero credits consumed. The expected provider now
     * travels in the job payload, so the worker can catch its own staleness
     * instead of the operator catching it afterwards.
     *
     * Fails the scene rather than silently substituting. One loud failure on
     * job one is recoverable; 185 quiet substitutions are a rollback.
     */
    private function assertProviderMatchesQuote(): void
    {
        $actual = $this->resolvedProviderName();

        if ($this->expectedProvider === null || $this->expectedProvider === $actual) {
            return;
        }

        // Facts only; the restart is named at display time, from the queue.
        throw new StaleWorkerException(sprintf(
            'This job was queued against provider "%s" but this worker resolves "%s". Almost '
            .'certainly a queue worker started before the provider was changed — a worker holds the '
            .'code and container it booted with, so a .env edit or a deploy does not reach it. '
            .'Nothing was generated and nothing was billed.',
            $this->expectedProvider,
            $actual,
        ), $this->queueName());
    }

    /**
     * Refuse to run if this worker disagrees with the dispatch about HOW.
     *
     * The provider pin above compares one string, and that was enough for the
     * incident it was written for. It was not enough for the next one: a worker
     * booted before the narration speed setting, the `narration_speed` column
     * and the pace guard existed narrated 117 scenes at 1.0 instead of 0.9 and
     * recorded no speed provenance at all. The provider name was correct
     * throughout, so the pin passed, and every other defence lived downstream of
     * the stale code and could not fire.
     *
     * The comparison is against a fingerprint recomputed HERE, from this
     * worker's own config and its own boot-time code marker, so a worker cannot
     * pass by reporting what it was told.
     *
     * Null is not a mismatch. A job queued before fingerprints existed carries
     * none, and refusing those would strand work that is already on the queue —
     * the same rule the provenance checks follow: never destroy or refuse on
     * unknown, only on demonstrably different.
     */
    private function assertFingerprintMatchesQuote(Story $story): void
    {
        if ($this->expectedFingerprint === null) {
            return;
        }

        $actual = RunFingerprint::for($story);
        $diff = RunFingerprint::diff($this->expectedFingerprint, $actual);

        if ($diff === []) {
            return;
        }

        throw new StaleWorkerException(RunFingerprint::explain($diff), $this->queueName());
    }

    /**
     * Generate this scene's one asset.
     *
     * @return array{0: ?string, 1: ?string} [output path, one-line log]
     */
    abstract protected function generate(Scene $scene): array;

    protected function run(Story $story, RenderWorkspace $workspace, RenderJob $job): array
    {
        /** @var Scene $scene */
        $scene = Scene::query()->with('sceneAudio')->findOrFail($this->sceneId);

        try {
            $this->assertProviderMatchesQuote();
            $this->assertFingerprintMatchesQuote($story);

            $result = $this->generate($scene);
        } catch (Throwable $e) {
            // Flagged here rather than only in failed(), because whether that
            // hook fires depends on the queue driver and this must not. A scene
            // the operator cannot find is a scene they cannot retry.
            $this->flagFailed();

            // A pace mismatch is the ONE failure that is not this scene's
            // problem. The batch policy everywhere else is right — three failed
            // stills out of 186 must not fail a video — but that policy assumes
            // failures are independent, and this one is not: the narrator is
            // reading at the wrong rate for every scene in the story, so the
            // other 185 calls would each bill, each succeed, and each be
            // discarded for the same reason.
            //
            // That is not hypothetical. It is what happened: a 22% pace error
            // ran for sixty-nine paid scenes before a person noticed. Cancelling
            // here is what turns that into one.
            // A stale worker is the same shape of failure as a pace mismatch and
            // gets the same treatment. It is not this scene's problem: THIS
            // WORKER is wrong about every scene it will pick up, so letting the
            // batch continue means the remaining 185 jobs each fail identically
            // — or worse, that a second, current worker completes some of them
            // and the story ends up half generated one way and half the other.
            //
            // That mixture is the actual damage. 117 scenes at the wrong speed
            // sitting beside 69 at the right one is a video with two tempos, and
            // it is invisible to every check that looks at one scene.
            if ($e instanceof NarrationPaceException || $e instanceof StaleWorkerException) {
                $this->batch()?->cancel();
            }

            throw $e;
        }

        $this->settle($scene);

        return $result;
    }

    /**
     * Promote the scene to `Ready` once all three of its paid assets are
     * present and current; otherwise it is still mid-generation.
     *
     * Read fresh, because this job has just written one of the three and the
     * in-memory relation predates it.
     */
    private function settle(Scene $scene): void
    {
        $fresh = $scene->fresh(['sceneAudio']);

        if ($fresh === null) {
            return;
        }

        $fresh->forceFill([
            'status' => $fresh->paidAssetsStillValid() ? SceneStatus::Ready : SceneStatus::Generating,
        ])->save();
    }

    /**
     * Backstop for the case run() cannot catch: a worker killed mid-call, a
     * serialisation error. Without it the scene would sit at `Generating`
     * forever and the retry set would never include it.
     */
    public function failed(?Throwable $e): void
    {
        parent::failed($e);

        $this->flagFailed();
    }

    private function flagFailed(): void
    {
        if ($this->sceneId === null) {
            return;
        }

        Scene::query()->whereKey($this->sceneId)->update(['status' => SceneStatus::Failed]);
    }
}
