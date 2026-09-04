<?php

namespace App\Actions;

use App\Exceptions\DispatchRefusedException;
use App\Models\Character;
use App\Models\Story;
use App\Support\AlignerProbe;
use App\Support\NarrationPace;
use App\Support\SceneChangeSet;
use App\Support\StyleFingerprint;

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
 *   3. **Were the approved faces drawn in the look this story will render in?**
 *      A reference sheet conditions every still its character appears in, so a
 *      sheet from a retuned style does not produce one wrong image — it pulls
 *      30-90 stills toward a look the rest of the video is not in. Asked here
 *      because it is a question about what is ABOUT to be bought.
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
        bool $checkStyle = true,
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

        if ($checkStyle && $changes->needsImage->isNotEmpty()) {
            $notes = array_merge($notes, $this->assertReferenceStyle($story));
        }

        if ($changes->needsNarration->isNotEmpty()) {
            $notes = array_merge($notes, $this->reportPaceExpectation($story));
        }

        return $notes;
    }

    /**
     * What the pace guard will be able to say about this run, before it spends.
     *
     * A warning, never a refusal, and the asymmetry is the same one
     * `assertReferenceStyle` draws. A DRIFT is a positive reading — measured
     * audio disagreeing with a measured expectation — and it stops the batch
     * mid-run. An unmeasured pair claims nothing: this narrator has never read
     * this kind of script, so there is no expectation for it to disagree with.
     *
     * Refusing on that would be story 21's cancellation formalised. Passing
     * silently would be worse, because a runtime estimate built on the fallback
     * constant looks exactly like one built on a measurement. So it is said out
     * loud, here, where the operator is deciding whether to spend — the same
     * place the quota, the aligner and the style fingerprint are reported.
     *
     * @return array<int, array{level: string, message: string}>
     */
    private function reportPaceExpectation(Story $story): array
    {
        $unmeasured = NarrationPace::unmeasured($story->voice_id, $story->locale_profile);

        if ($unmeasured !== null) {
            return [self::warn($unmeasured)];
        }

        return [self::ok(sprintf(
            'pace expectation for %s on %s: %d wpm%s.',
            NarrationPace::voiceName($story->voice_id) ?? (string) $story->voice_id,
            (string) $story->locale_profile,
            NarrationPace::expectedWpm($story->voice_id, $story->locale_profile),
            NarrationPace::measuredOn($story->voice_id, $story->locale_profile) === null
                ? ''
                : ' ('.NarrationPace::measuredOn($story->voice_id, $story->locale_profile).')',
        ))];
    }

    /**
     * Refuse when a face was drawn in a look this story is no longer in.
     *
     * **Stale refuses; unknown warns.** That asymmetry is deliberate and it is
     * not the usual "absence reads as agreement" mistake — the difference is
     * what each state actually claims. A stale row is a POSITIVE reading: the
     * app recorded a fingerprint, compared it, and they differ, so the mismatch
     * is proven and the spend is certainly wasted. An unknown row predates the
     * column; the style string of the day was never recorded and cannot be
     * recovered, so there is nothing to compare and no honest way to clear it.
     *
     * Refusing on unknown would fire forever on every story generated before
     * the column existed, with no action that legitimately dismisses it, and an
     * alarm that cannot be cleared is an alarm that gets switched off. So it is
     * reported — every time, in those words, never as "fine" — and it does not
     * stop the dispatch.
     *
     * @return array<int, array{level: string, message: string}>
     */
    private function assertReferenceStyle(Story $story): array
    {
        $stale = [];
        $unknown = [];

        foreach ($story->characters()->get() as $character) {
            match ($character->referenceStyleState()) {
                Character::STYLE_STALE => $stale[] = $character->name,
                Character::STYLE_UNKNOWN => $unknown[] = $character->name,
                default => null,
            };
        }

        if ($stale !== []) {
            throw DispatchRefusedException::referencesInAnotherStyle($stale, StyleFingerprint::current());
        }

        if ($unknown !== []) {
            return [self::warn(sprintf(
                '%d character(s) have a reference sheet that predates style tracking, so the app '
                .'cannot say which look it was drawn in: %s. Unknown is not the same as agreeing — '
                .'if these were generated under a different art style, their stills will come back '
                .'in it.',
                count($unknown),
                implode(', ', $unknown),
            ))];
        }

        return [self::ok(sprintf(
            'every approved face was drawn in the configured art style (fingerprint %s).',
            StyleFingerprint::current(),
        ))];
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

    /** @return array{level: string, message: string} */
    private static function warn(string $message): array
    {
        return ['level' => 'warn', 'message' => $message];
    }
}
