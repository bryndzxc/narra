<?php

namespace App\Actions;

use App\Contracts\ImageGenerator;
use App\Contracts\SpeechSynthesizer;
use App\Contracts\Transcriber;
use App\Exceptions\DispatchRefusedException;
use App\Models\Character;
use App\Models\Scene;
use App\Models\Story;
use App\Services\ElevenLabs\ElevenLabsSpeechSynthesizer;
use App\Support\AlignerProbe;
use App\Support\NarrationPace;
use App\Support\SceneChangeSet;
use App\Support\StyleFingerprint;
use Illuminate\Support\Collection;
use Throwable;

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
 *
 * ---------------------------------------------------------------------------
 * THE SECOND AXIS: THE ENVIRONMENT MOVED, versus THE STORY CANNOT COMPLETE
 * ---------------------------------------------------------------------------
 *
 * Every question above is the same KIND of question, and nobody noticed until a
 * dispatch walked past all of them. Worker code, aligner install, style
 * fingerprint — each asks *has something changed since this story was
 * prepared*. Not one asks *does this story carry what the stages I am about to
 * dispatch require*.
 *
 * Story 23 is the instance. `voice_id` was null; the operator dispatched; the
 * missing column became 257 identical per-scene failure rows after 256 stills
 * had already been bought, on a batch that could then never fire its completion
 * callback. Nothing was billed — the per-job refusal in `GenerateSceneNarration`
 * is correct and sits above the `synthesize()` call — and being downstream is
 * exactly what made it 257 of them instead of one.
 *
 * **And the field was already in hand.** `reportPaceExpectation()` read
 * `$story->voice_id` on that very dispatch and asked
 * `NarrationPace::unmeasured()` of it, which cannot tell *voice set but
 * unmeasured* from *no voice at all*. It emitted a warning whose first
 * character is a space, because the voice NAME interpolated to nothing:
 *
 *     " has no measured reading pace for en-CN, so the pace guard cannot judge…"
 *
 * So this was never a field the preflight could not reach. It was a check
 * holding the right value and asking a question of it that has no answer when
 * the value is absent — and answering, by design, with a warning. The blank was
 * the visible tell and nobody read it.
 *
 * The three checks added for it — bindings, narrator, allowance — are questions
 * 1 to 3 of the five that `narration:preflight` has asked, for free, since it
 * was written. Nothing called it. See that command's docblock; this class is
 * where those questions belong when a button is what is being pressed.
 *
 * **Scoped to the stages actually in the dispatch**, the way the aligner check
 * already was. A refusal about work that is not being done is how an operator
 * learns to reach for `--no-*-check` by reflex.
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

        /*
         * WHAT WOULD ACTUALLY RUN. First, because every question below it is
         * about a vendor, and asking them of a stand-in answers about nothing.
         */
        $notes = array_merge($notes, $this->reportBindings($changes));

        /*
         * CAN THE STORY COMPLETE THE STAGES BEING DISPATCHED — the axis this
         * class did not have.
         *
         * Every check that was here before asks whether the ENVIRONMENT has
         * moved since the story was prepared: worker code, aligner install, art
         * style fingerprint. Not one asked whether the story itself carries what
         * the stages need. A null `voice_id` is knowable from one column and it
         * became 257 identical per-scene failures, after 256 stills had been
         * bought, on a batch that could then never complete.
         *
         * SCOPED TO THE STAGES IN THIS DISPATCH, the way the aligner check
         * already is. An images-only run must not be refused for a missing
         * narrator: it would be a refusal about work that is not being done,
         * which is the fastest way to teach an operator to reach for the
         * --no-*-check flags by reflex.
         */
        if ($changes->needsNarration->isNotEmpty()) {
            $notes = array_merge(
                $notes,
                $this->assertNarratorIsReal($story, $changes->needsNarration->count()),
                $this->assertNarrationFits($changes->needsNarration),
                $this->reportPaceExpectation($story),
            );
        }

        if ($checkAligner && $changes->needsTranscription->isNotEmpty()) {
            $notes = array_merge($notes, $this->assertAlignerRuns($changes->needsTranscription->count()));
        }

        if ($checkStyle && $changes->needsImage->isNotEmpty()) {
            $notes = array_merge($notes, $this->assertReferenceStyle($story));
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


    /**
     * Is what would actually run a stand-in?
     *
     * Question 1 of the five, and the one with the largest instance behind it:
     * a missing `PROVIDER_IMAGE_GENERATOR` sent 186 stills to a fake while every
     * screen named a vendor and $8.12 went into the ledger for calls nobody
     * made. Resolved from the CONTAINER, never read from config — config says
     * what should be bound and only the container says what is, and the two
     * disagreeing silently is the whole of that incident.
     *
     * **A warning, never a refusal, and that is a deliberate limit on this
     * check.** Running against fakes is a legitimate thing to do — it is how
     * every fixture story in this database was made and how the render pipeline
     * was proven without spending a cent. A guard that refused it would be a
     * guard that has to be disabled to do ordinary work, which is a guard that
     * ends up disabled.
     *
     * Asked per stage, because the stand-ins are independent: a real image
     * generator with a fake synthesizer is a normal state half way through
     * binding providers, and only the stages actually in this dispatch are
     * relevant to it.
     *
     * @return array<int, array{level: string, message: string}>
     */
    private function reportBindings(SceneChangeSet $changes): array
    {
        $simulated = [];

        if ($changes->needsImage->isNotEmpty()) {
            $images = app(ImageGenerator::class);

            if ($images->isSimulated()) {
                $simulated[] = sprintf('stills (%s)', $images->providerName());
            }
        }

        if ($changes->needsNarration->isNotEmpty()) {
            $speech = app(SpeechSynthesizer::class);

            if ($speech->isSimulated()) {
                $simulated[] = sprintf('narration (%s)', $speech->providerName());
            }
        }

        if ($changes->needsTranscription->isNotEmpty()) {
            $transcriber = app(Transcriber::class);

            if ($transcriber->isSimulated()) {
                $simulated[] = sprintf('word timings (%s)', $transcriber->providerName());
            }
        }

        if ($simulated === []) {
            return [];
        }

        return [self::warn(sprintf(
            '%s in this run would be produced by a STAND-IN: %s. Nothing will be billed and the '
            .'files will be placeholders — which is legitimate, and is not what the ledger or the '
            .'progress page will look like. The one time this went unnoticed, 186 stills came back '
            .'as flat fills and $8.12 was recorded against calls nobody made.',
            count($simulated) === 1 ? 'One stage' : count($simulated).' stages',
            implode(', ', $simulated),
        ))];
    }

    /**
     * Is there a narrator, and is it a real one?
     *
     * Question 2 of the five, split into the two readings it actually holds,
     * because they are not the same severity of wrong and they are not fixed
     * the same way.
     *
     * **Null refuses.** Story 23: `voice_id` was null, the operator dispatched
     * 257 scenes, and the missing column became 257 identical failure rows —
     * one worker pickup per scene to re-discover a fact one query answers. The
     * per-job guard did its job and stopped before spending; being downstream is
     * what made it 257 of them.
     *
     * **A voice that is not on the account refuses too, and is worse.** A null
     * voice fails for free; a wrong id reaches the vendor and comes back 422 per
     * scene, three times each under `--tries=3`. The account's own list is what
     * answers it, which is one of the few facts in this app that we did not
     * compute ourselves.
     *
     * **The vendor being unreachable WARNS.** A list that could not be read is a
     * check that did not run, and this codebase treats those as failures rather
     * than passes — but the failure is in the instrument, not in the story, so
     * it is reported and does not stop a dispatch that may be perfectly fine.
     *
     * **A simulated synthesizer is skipped entirely.** Its voices are
     * placeholders by construction, so checking a story's id against them would
     * refuse every fixture run for a mismatch that means nothing. `reportBindings()`
     * is what says a stand-in is bound.
     *
     * @return array<int, array{level: string, message: string}>
     */
    private function assertNarratorIsReal(Story $story, int $scenes): array
    {
        $voiceId = trim((string) $story->voice_id);

        if ($voiceId === '') {
            throw DispatchRefusedException::storyHasNoVoice((string) $story->slug, $scenes);
        }

        $speech = app(SpeechSynthesizer::class);

        if ($speech->isSimulated()) {
            return [];
        }

        try {
            $voices = $speech->voices();
        } catch (Throwable $e) {
            return [self::warn(sprintf(
                'the %s voice list could not be read, so "%s" was not checked against the account: %s. '
                .'That is not the same as it being valid — a voice that is not on the account fails '
                .'every scene of a batch individually.',
                $speech->providerName(),
                $voiceId,
                $e->getMessage(),
            ))];
        }

        // An empty list is unreadable, not empty. A provider answering with no
        // voices at all cannot be distinguished from one whose filter excluded
        // them, and refusing on it would refuse every story.
        if ($voices === []) {
            return [self::warn(sprintf(
                'the %s account returned no voices, so "%s" was not checked against it. An empty list '
                .'is not evidence that the voice is wrong, and it is not evidence that it is right.',
                $speech->providerName(),
                $voiceId,
            ))];
        }

        $match = null;

        foreach ($voices as $voice) {
            if (($voice['id'] ?? null) === $voiceId) {
                $match = $voice;
                break;
            }
        }

        if ($match === null) {
            throw DispatchRefusedException::voiceNotOnAccount($voiceId, $speech->providerName(), $voices);
        }

        return [self::ok(sprintf('narrator "%s" is on the %s account.', $match['name'], $speech->providerName()))];
    }

    /**
     * Does the narration in this run fit the remaining allowance?
     *
     * Question 3 of the five, and the only one of them that can half-spend.
     *
     * On a plan without overage ElevenLabs does not bill past the allowance, it
     * STOPS — so the failure mode is not a surprise charge, it is scenes 1-170
     * narrated, 171 onward refused, and the allowance gone either way. **A cost
     * estimate structurally cannot see this**: it answers "what will this cost",
     * and on a subscription the marginal answer is $0.00 whichever side of the
     * limit the run lands. Only the vendor's own counter answers "does it fit",
     * which is what makes a network call at the button worth its latency.
     *
     * **Short refuses; unreadable warns**, which is `SpeechQuota::accommodates()`
     * returning null rather than true, deliberately, for exactly this. An API
     * key can synthesize perfectly well and still lack `user_read`, and "I could
     * not check" rendering as "you have plenty" is the substitution this whole
     * file exists to prevent.
     *
     * Scoped to the one provider that has the endpoint. Putting `quota()` on the
     * contract would force every future provider and every fake to invent an
     * answer to a question it cannot be asked — the same reasoning
     * `ProviderBalances` gives for reaching it through an instanceof.
     *
     * @param  Collection<int, Scene>  $pending
     * @return array<int, array{level: string, message: string}>
     */
    private function assertNarrationFits(Collection $pending): array
    {
        $speech = app(SpeechSynthesizer::class);

        if ($speech->isSimulated() || ! $speech instanceof ElevenLabsSpeechSynthesizer) {
            return [];
        }

        $characters = (int) $pending->sum(
            static fn (Scene $scene): int => mb_strlen(trim((string) $scene->narration_text)),
        );

        // Through the provider's own multiplier rather than a local copy of it.
        // One multiplier applied twice by three pieces of code that never
        // compared notes is how the same narration came to have three prices.
        $credits = $characters * $speech->creditsPerCharacter();

        try {
            $quota = $speech->quota();
        } catch (Throwable $e) {
            return [self::warn(sprintf(
                'the %s allowance could not be read, so this run was not checked against it: %s. '
                .'This run needs %s credits — on a plan without overage, running out does not cost '
                .'extra, it leaves the story half narrated.',
                $speech->providerName(),
                $e->getMessage(),
                number_format($credits),
            ))];
        }

        $fits = $quota->accommodates($credits);

        if ($fits === false) {
            throw DispatchRefusedException::narrationWillNotFit(
                $credits,
                (int) $quota->remaining(),
                $pending->count(),
                $quota->summary(),
            );
        }

        // Null, never false. An unreadable quota is a check that did not run.
        if ($fits === null) {
            return [self::warn(sprintf(
                'the allowance could not be read, so this run was not checked against it — %s. It '
                .'needs %s credits, and on a plan without overage running out leaves the story half '
                .'narrated with the allowance spent either way.',
                (string) $quota->unreadableReason,
                number_format($credits),
            ))];
        }

        return [self::ok(sprintf(
            'narration fits: %s credits needed, %s remaining (%s).',
            number_format($credits),
            number_format((float) $quota->remaining()),
            $quota->summary(),
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
