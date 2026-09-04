<?php

namespace App\Actions;

use App\Contracts\ImageGenerator;
use App\Contracts\SpeechSynthesizer;
use App\Contracts\Transcriber;
use App\Enums\OperatorAction;
use App\Enums\SceneStatus;
use App\Enums\StoryStatus;
use App\Exceptions\GateViolationException;
use App\Jobs\GenerateSceneImageJob;
use App\Jobs\GenerateSceneNarrationJob;
use App\Jobs\TranscribeSceneTimingsJob;
use App\Models\Scene;
use App\Models\Story;
use App\Support\RunFingerprint;
use App\Support\SceneChangeSet;
use App\Support\SceneSelection;
use Illuminate\Bus\Batch;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use RuntimeException;

/**
 * Puts a story's paid asset generation on the queue.
 *
 *     GenerateSceneImageJob x N  +  GenerateSceneNarrationJob x N   (one batch)
 *          |
 *          +-- finished, however it went --> TranscribeSceneTimingsJob x M
 *                                            (a second batch, for the scenes
 *                                             that actually got audio)
 *                 |
 *                 +-- finished --> every scene reconciled; all ready ->
 *                                  assets_ready, otherwise parked at
 *                                  assets_generating with the failures flagged
 *
 * **This is a spend, not a gate crossing, and the distinction is the whole
 * reason it is a separate action from ApproveScenesGate.** Approving scenes is
 * a quality decision; authorising ~186 stills is a money decision, and they
 * should not be the same click. The decisive reason is retry: if dispatch were
 * folded into the gate, then re-running five failed images would mean reopening
 * Gate 2 — which puts the other 181 scenes at risk of regeneration to fix five.
 * Nothing here touches gate state. It can be pressed as many times as it takes.
 *
 * Re-running is cheap by construction. The outstanding set comes from
 * SceneChangeSet, the same computation the Gate 2 confirmation and the cost
 * estimate read, so a second press dispatches jobs only for what is actually
 * missing — and every action underneath is idempotent as a backstop, so even a
 * job that runs against a finished scene declines to bill.
 *
 * Timings are a second batch rather than a third slice of the first, because
 * they transcribe the audio file the narration stage writes. `finally` rather
 * than `then`: if three narrations out of 186 fail, the other 183 still have
 * audio worth transcribing, and gating the continuation on a clean batch would
 * strand them. That is the spec's batch failure policy — 3 failures out of 200
 * flag for retry, they do not fail the video.
 */
class DispatchAssetGeneration
{
    public function __construct(
        private readonly DiscardRenderArtifacts $artifacts,
        private readonly PreflightAssetDispatch $preflight,
    ) {}

    /**
     * @return array{
     *     batch_id: ?string,
     *     images: int,
     *     narrations: int,
     *     transcriptions: int,
     *     dispatched: int,
     *     status: string,
     *     notes: array<int, array{level: string, message: string}>
     * }
     */
    public function handle(
        Story $story,
        ?SceneSelection $only = null,
        bool $checkWorkers = true,
        bool $checkAligner = true,
        bool $checkStyle = true,
    ): array {
        // Fires before anything is queued. The cost rows assert this too, but
        // that guard fires after a provider has already billed.
        $story->assertPaidAssetsUnlocked('generate_scene_assets');

        // The SAME predicate the Gate 2 button consults, not a second
        // expression of the same idea. The two disagreed for a whole phase —
        // the button correctly hid itself past `rendered` while this action
        // accepted any status and failed at the transition — and the only
        // reason that was ever noticed is that an operator pressed it.
        if (OperatorAction::RegenerateAssets->refusalReason($story->status) !== null) {
            throw GateViolationException::actionUnavailable(OperatorAction::RegenerateAssets, $story->status);
        }

        $changes = SceneChangeSet::for($story, $only);

        $images = $changes->needsImage;
        $narrations = $changes->needsNarration;
        $transcriptions = $changes->needsTranscription;

        // Nothing outstanding. Not a no-op: this is the path a story takes when
        // every asset already exists and the operator is pressing the button to
        // find out, so it still reconciles and lands the status.
        if ($images->isEmpty() && $narrations->isEmpty() && $transcriptions->isEmpty()) {
            self::reconcile($story->id);

            return [
                'batch_id' => null,
                'images' => 0,
                'narrations' => 0,
                'transcriptions' => 0,
                'dispatched' => 0,
                'status' => (string) $story->fresh()?->status->value,
                'notes' => [],
            ];
        }

        // BEFORE any mutation, and that placement is the point.
        //
        // Everything below this line changes something an operator would have to
        // undo: files are unlinked, the story transitions, scenes are marked
        // Generating. A preflight that ran after any of it would leave a refused
        // dispatch having already discarded a render.
        //
        // It throws DispatchRefusedException rather than returning a warning.
        // The advisory version of this check already existed — narration:
        // preflight prints "restart your workers" — and an operator read it, and
        // 117 scenes were narrated at the wrong speed anyway. A check that can be
        // scrolled past is not a check.
        $notes = $this->preflight->handle($story, $changes, $checkWorkers, $checkAligner, $checkStyle);

        // Everything on the `renders` disk that describes the audio or the
        // stills about to be replaced.
        //
        // **This is unconditional now, and the condition it replaces was a real
        // bug.** It used to run only when the story had reached `rendering` or
        // later, on the reasoning that a story which had not rendered had no
        // render artifacts to discard. That reasoning is wrong, because the
        // render stages are individually re-runnable from the CLI: `render:clips`
        // writes 186 clips and 186 padded WAVs while the story sits at
        // `assets_generating`, and it is a normal thing to do — it is the free
        // stage, and running it early is how you find out the clips work.
        //
        // The consequence was 181 stale clips and 181 stale padded WAVs left on
        // disk across a narration change, each one built against audio that no
        // longer exists. Clips are filed by scene, so nothing would have
        // overwritten them; the concat assertion would have failed tens of
        // minutes into a re-render, and only if it got that far.
        //
        // Discarding when there is nothing to discard costs an `unlink()` that
        // returns false. Keeping a stale clip costs a render. So the check is
        // gone rather than widened: the stills and narration live on the
        // `assets` disk and this action cannot reach them, which is what makes
        // being liberal here safe by construction rather than by care.
        $this->artifacts->handle(
            story: $story,
            scenes: $narrations->merge($images)->unique('id'),
            // The whole-video artifacts encode the entire scene sequence and its
            // timeline, so any scene being regenerated invalidates all of them
            // together — including a final.mp4 that, left in place, is a
            // finished video an operator can sit down and approve at Gate 3 for
            // a story whose assets no longer match it.
            wholeVideo: true,
        );

        if ($story->status->rank() >= StoryStatus::Rendering->rank()) {
            // Chapters are derived from act timings, act timings come from the
            // render, and the render is about to be rebuilt — so every
            // timestamp in a drafted publish sheet is wrong. Marked rather than
            // deleted: it stays on screen and stays copyable in the meantime,
            // and Gate 4's consequences land on YouTube, outside this app.
            //
            // Still conditional, unlike the files above: a story that has never
            // rendered has no metadata to stale, and marking one that does not
            // exist is not free — it is a row nobody asked for.
            $story->youtubeMetadata?->markStale();
        }

        $target = OperatorAction::RegenerateAssets->targetStatus($story->status);

        if ($target !== null) {
            $story->transitionTo($target);
        }

        // Clears `Failed` on exactly the scenes being retried, and only those.
        // A scene not in this run keeps whatever it was — its failure is still
        // the operator's to see.
        $this->markGenerating($images, $narrations, $transcriptions);

        $storyId = $story->id;

        // The providers as resolved HERE, pinned into each job's payload.
        //
        // The quote the operator just approved was priced against these. The
        // work happens in a queue worker, which is a different process that
        // booted its own container — possibly before the provider changed. The
        // job carries what was promised so the worker can refuse rather than
        // silently substitute; see SceneAssetJob::assertProviderMatchesQuote().
        $imageProvider = app(ImageGenerator::class)->providerName();
        $speechProvider = app(SpeechSynthesizer::class)->providerName();

        // And the rest of what was promised, which the provider names do not
        // cover. A worker can hold the right provider and still be wrong about
        // the speed it reads at, the voice it reads with, and whether the pace
        // guard exists in its code at all — which is exactly the combination
        // that produced 117 scenes at 1.0 with no speed provenance.
        //
        // Captured here, in the process that has fresh code, and compared in the
        // worker against a value the worker recomputes for itself.
        $fingerprint = RunFingerprint::for($story);

        // Plain ints, not the object: this is captured into a serialised batch
        // callback and must survive the round trip without a model or a query
        // builder attached.
        $sequences = $only?->sequences;

        $first = collect()
            ->merge($images->map(fn (Scene $s): GenerateSceneImageJob => new GenerateSceneImageJob($storyId, $s->id, $imageProvider, $fingerprint)))
            ->merge($narrations->map(fn (Scene $s): GenerateSceneNarrationJob => new GenerateSceneNarrationJob($storyId, $s->id, $speechProvider, $fingerprint)))
            ->all();

        // Everything the first batch would do is already done, so go straight
        // to the stage that was waiting on it.
        if ($first === []) {
            $batchId = self::dispatchTimings($storyId, $sequences);

            return [
                'batch_id' => $batchId,
                'images' => 0,
                'narrations' => 0,
                'transcriptions' => $transcriptions->count(),
                'dispatched' => $transcriptions->count(),
                'status' => StoryStatus::AssetsGenerating->value,
                'notes' => $notes,
            ];
        }

        $batch = Bus::batch($first)
            ->name("scene-assets:{$story->slug}")
            ->onQueue((string) config('render.queues.assets'))
            ->allowFailures()
            // The selection travels into the callback as plain integers.
            //
            // Without it the second batch would recompute the outstanding set
            // for the WHOLE story and queue alignment for every scene — which
            // on a five-scene proving run means 181 jobs pointed at placeholder
            // silence, every one of them failing for a reason that has nothing
            // to do with the run being proved.
            ->finally(function (Batch $batch) use ($storyId, $sequences): void {
                self::dispatchTimings($storyId, $sequences);
            })
            ->dispatch();

        return [
            'batch_id' => $batch->id,
            'images' => $images->count(),
            'narrations' => $narrations->count(),
            'transcriptions' => $transcriptions->count(),
            'dispatched' => count($first),
            'status' => StoryStatus::AssetsGenerating->value,
            'notes' => $notes,
        ];
    }

    /**
     * The second batch: word timings for whatever now has audio on disk.
     *
     * Recomputed rather than carried from the first dispatch, deliberately. The
     * set that needs transcribing is not knowable before the narration stage
     * runs — a scene whose TTS call failed has no file to transcribe, and
     * queueing a job for it would turn one failure into two.
     *
     * Static because it runs inside a serialised batch callback, where the only
     * thing that survives is the story id.
     */
    public static function dispatchTimings(int $storyId, ?array $sequences = null): ?string
    {
        $story = Story::query()->find($storyId);

        if ($story === null) {
            return null;
        }

        $only = $sequences === null ? null : SceneSelection::parse(implode(',', $sequences));

        $pending = SceneChangeSet::for($story, $only)->needsTranscription
            // Audio has to exist before it can be transcribed. Scenes whose
            // narration failed are left flagged rather than queued to fail
            // again for a different reason.
            ->filter(fn (Scene $scene): bool => $scene->sceneAudio->contains(
                fn ($audio): bool => $audio->audio_path !== null
            ))
            ->values();

        if ($pending->isEmpty()) {
            self::reconcile($storyId);

            return null;
        }

        $transcriberProvider = app(Transcriber::class)->providerName();

        // Recomputed here rather than carried from the first dispatch, and that
        // is correct rather than lazy: this runs inside a batch CALLBACK, in
        // whichever worker happened to finish the narration batch last. That
        // worker is the one whose fingerprint the timing jobs should carry,
        // because it is the process actually queueing them.
        $fingerprint = RunFingerprint::for($story);

        $batch = Bus::batch(
            $pending->map(fn (Scene $s): TranscribeSceneTimingsJob => new TranscribeSceneTimingsJob($storyId, $s->id, $transcriberProvider, $fingerprint))->all()
        )
            ->name("scene-timings:{$story->slug}")
            ->onQueue((string) config('render.queues.assets'))
            ->allowFailures()
            ->finally(function (Batch $batch) use ($storyId): void {
                self::reconcile($storyId);
            })
            ->dispatch();

        return $batch->id;
    }

    /**
     * The authoritative pass: once nothing is in flight, decide what every
     * scene and the story actually are.
     *
     * The per-job status writes in SceneAssetJob are for the progress page
     * while the batch runs and are explicitly not trusted here — two jobs for
     * one scene finish concurrently and the loser can read the row before the
     * winner's write lands, leaving a complete scene marked `Generating`. This
     * runs when nothing else is writing, so it is the one that counts.
     *
     * A scene that is still incomplete now is `Failed`, not `Generating`:
     * nothing is generating any more, and `Failed` is the resting state a scene
     * can be retried out of. That also makes the retry set exactly the set the
     * button will re-dispatch, since both are "what SceneChangeSet still says
     * is outstanding".
     */
    public static function reconcile(int $storyId): void
    {
        $story = Story::query()->find($storyId);

        if ($story === null) {
            return;
        }

        // Outstanding is read from SceneChangeSet, NOT from
        // Scene::paidAssetsStillValid(), and the difference is not cosmetic.
        //
        // paidAssetsStillValid() compares text fingerprints only. It cannot see
        // that a scene's audio came from a stand-in, so after a limited run
        // against a newly-bound provider it would call all 186 scenes ready —
        // five of them genuinely narrated and 181 holding placeholder silence —
        // and transition the story to assets_ready. A finished-looking story
        // that is silent for 97% of its runtime is precisely the kind of
        // reported success this pipeline has been burned by.
        //
        // SceneChangeSet is provenance-aware and is already the definition of
        // outstanding used by the quote and the dispatch. Using it here makes
        // the retry set exactly the set the button will re-dispatch.
        $scenes = $story->scenes()->with('sceneAudio')->get();

        $changes = SceneChangeSet::for($story);
        $outstanding = $changes->needsImage
            ->merge($changes->needsNarration)
            ->merge($changes->needsTranscription)
            ->pluck('id')
            ->unique();

        $failed = 0;

        foreach ($scenes as $scene) {
            $ready = ! $outstanding->contains($scene->id);

            $failed += $ready ? 0 : 1;

            $scene->forceFill([
                'status' => $ready ? SceneStatus::Ready : SceneStatus::Failed,
            ])->save();
        }

        if ($failed > 0 || $scenes->isEmpty()) {
            // Parked, not failed. The story stays at assets_generating with its
            // broken scenes flagged, and the same button re-runs only those.
            return;
        }

        if ($story->status === StoryStatus::AssetsReady) {
            return;
        }

        self::landAssetsReady($story);
    }

    /**
     * Move a fully-generated story to `assets_ready`, or explain why not.
     *
     * This used to be a `transitionTo()` wrapped in `catch (Throwable) {}`, and
     * the catch was doing two jobs that look identical from inside it and are
     * not: absorbing a genuine race, and swallowing a transition the state
     * machine does not define.
     *
     * It was doing the second one. `rendered -> assets_ready` is not a legal
     * move, so regenerating assets on a rendered story reached here, threw, and
     * was silently discarded — leaving the story parked with every scene ready
     * and nobody told why it had not advanced. This is the callback that decides
     * `assets_ready`; a swallowed exception here is precisely where a silent
     * wrong state comes from, and this project has already had two of those.
     *
     * So the legality is checked rather than attempted, and the only statuses
     * treated as benign are the ones another actor can legitimately have moved
     * the story to while the batch was finishing. Anything else is a state
     * nobody predicted, and it is raised rather than hidden.
     */
    private static function landAssetsReady(Story $story): void
    {
        if ($story->canTransitionTo(StoryStatus::AssetsReady)) {
            try {
                $story->transitionTo(StoryStatus::AssetsReady);
            } catch (GateViolationException $e) {
                // The narrow race: another callback moved the story between the
                // check above and the write. Re-read and fall through to the
                // same judgement rather than assuming which one it was.
                self::reportUnexpectedLanding($story->fresh() ?? $story, $e);
            }

            return;
        }

        self::reportUnexpectedLanding($story, null);
    }

    /**
     * Decide whether a story that cannot reach `assets_ready` is a known
     * concurrent outcome or a bug.
     */
    private static function reportUnexpectedLanding(Story $story, ?GateViolationException $e): void
    {
        $benign = [
            // Already there — another batch callback won the race.
            StoryStatus::AssetsReady,
            // An operator reopened Gate 2 while the batch was finishing. Their
            // decision outranks this callback's.
            StoryStatus::ScenesDrafted,
            StoryStatus::ScenesApproved,
        ];

        if (in_array($story->status, $benign, true)) {
            return;
        }

        throw new RuntimeException(sprintf(
            'Every scene of story %s is generated, but the story is at "%s" and cannot move to '
            .'assets_ready from there.
This reconcile is what decides assets_ready, so it refuses '
            .'rather than returning quietly: a story with complete assets that never advances, and no '
            .'error anywhere, is the failure mode this guard exists for.',
            $story->slug ?? (string) $story->id,
            $story->status->value,
        ), 0, $e);
    }

    /**
     * @param  Collection<int, Scene>  ...$sets
     */
    private function markGenerating(Collection ...$sets): void
    {
        $ids = collect($sets)->flatMap(fn (Collection $set): array => $set->modelKeys())->unique()->all();

        if ($ids === []) {
            return;
        }

        Scene::query()->whereIn('id', $ids)->update(['status' => SceneStatus::Generating]);
    }
}
