<?php

namespace Tests\Feature;

use App\Actions\PreflightAssetDispatch;
use App\Contracts\SpeechSynthesizer;
use App\Enums\SceneStatus;
use App\Enums\StoryStatus;
use App\Exceptions\DispatchRefusedException;
use App\Models\Act;
use App\Models\Scene;
use App\Models\Story;
use App\Services\ElevenLabs\ElevenLabsSpeechSynthesizer;
use App\Services\Fake\FakeSpeechSynthesizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * Can this story complete the stages this dispatch is about to queue?
 *
 * ---------------------------------------------------------------------------
 * THE AXIS, AND WHY IT WAS INVISIBLE
 * ---------------------------------------------------------------------------
 *
 * Every check `PreflightAssetDispatch` had before this file asks the same KIND
 * of question: *has the environment moved since this story was prepared* —
 * worker code, aligner install, art style fingerprint. Not one asked *does this
 * story carry what the stages need*.
 *
 * Story 23 walked past all of them. `voice_id` was null; the operator
 * dispatched; the missing column became **257 identical per-scene failure
 * rows** after 256 stills had already been bought, on a batch that could then
 * never fire its completion callback. Nothing was billed — the refusal in
 * `GenerateSceneNarration` sits above the `synthesize()` call and is correct —
 * and being downstream is precisely what made it 257 of them instead of one.
 *
 * **The field was already in hand.** `reportPaceExpectation()` read
 * `$story->voice_id` on that very dispatch and asked `NarrationPace::unmeasured()`
 * of it, which cannot tell *voice set but unmeasured* from *no voice at all*. It
 * emitted a warning beginning with a space, because the voice name interpolated
 * to nothing. A check holding the right value and asking a question that has no
 * answer when the value is absent — answering, by design, with a warning.
 *
 * ---------------------------------------------------------------------------
 * EVERY CASE IS A PAIR, AND THE SCOPING CASES MATTER MOST
 * ---------------------------------------------------------------------------
 *
 * A guard that refuses everything satisfies every red case here. The greens
 * that keep it honest are the ones where a refusal would be WRONG: an
 * images-only dispatch on a story with no narrator, a stand-in provider whose
 * voice list is placeholders, and a vendor that could not be reached. Each of
 * those is an ordinary state, and refusing on any of them is how an operator
 * learns to reach for `--no-*-check` by reflex.
 */
class DispatchPreconditionsTest extends TestCase
{
    use RefreshDatabase;

    // ---------------------------------------------------------------------
    // Question 2a — is there a narrator at all
    // ---------------------------------------------------------------------

    /**
     * RED. The shape story 23 took, refused once instead of 257 times.
     */
    public function test_a_story_with_no_narrator_is_refused_before_anything_queues(): void
    {
        $story = $this->storyNeedingNarration(voiceId: null);

        $this->expectException(DispatchRefusedException::class);
        $this->expectExceptionMessageMatches('/has no narrator/');

        app(PreflightAssetDispatch::class)->handle($story, checkWorkers: false, checkAligner: false, checkStyle: false);
    }

    /**
     * The refusal names the fix, and names it as something runnable.
     *
     * Kept separate from the case above because "it threw" and "it told the
     * operator what to do" are different claims, and the second is the entire
     * argument for moving the check upstream — the per-job version already said
     * the right words, 257 times, on a page nobody reads scene by scene.
     */
    public function test_the_refusal_names_the_command_that_fixes_it(): void
    {
        $story = $this->storyNeedingNarration(voiceId: null);

        try {
            app(PreflightAssetDispatch::class)->handle($story, checkWorkers: false, checkAligner: false, checkStyle: false);
            $this->fail('A story with no narrator should have been refused.');
        } catch (DispatchRefusedException $e) {
            $this->assertStringContainsString('voices:list --set='.$story->slug, $e->getMessage());
            $this->assertStringContainsString('scene(s) in this run need narration', $e->getMessage());
        }
    }

    /**
     * GREEN, as close to the red case as it can be made: the same story, a
     * voice on it.
     */
    public function test_a_story_with_a_narrator_is_not_refused(): void
    {
        $story = $this->storyNeedingNarration(voiceId: 'nPczCjzI2devNBz1zQrb');

        $notes = app(PreflightAssetDispatch::class)
            ->handle($story, checkWorkers: false, checkAligner: false, checkStyle: false);

        $this->assertIsArray($notes);
    }

    /**
     * GREEN, and the most important one in the file.
     *
     * A dispatch with no narration in it must not be refused for a missing
     * narrator. It would be a refusal about work that is not being done, and
     * the cost is not a wasted second — it is an operator learning that the
     * checks fire on things that do not matter, which is how `--no-*-check`
     * becomes reflex.
     *
     * Scoped exactly the way the aligner check already was.
     */
    public function test_an_images_only_dispatch_is_not_refused_for_a_missing_narrator(): void
    {
        $story = $this->storyNeedingNarration(voiceId: null);

        // Every scene already narrated and timed; only a still outstanding. The
        // change set is what decides, so this is set on the rows it reads.
        $this->giveEverySceneNarrationAndTimings($story);

        $notes = app(PreflightAssetDispatch::class)
            ->handle($story, checkWorkers: false, checkAligner: false, checkStyle: false);

        $this->assertIsArray($notes, 'An images-only dispatch was refused for a narrator it does not need.');
    }

    // ---------------------------------------------------------------------
    // Question 2b — is the narrator a real one
    // ---------------------------------------------------------------------

    /**
     * RED. A voice that is not on the account is worse than none, because it
     * fails at the vendor rather than here: 422 per scene, three times each
     * under --tries=3, reading like an outage rather than a typo.
     */
    public function test_a_voice_that_is_not_on_the_account_is_refused(): void
    {
        $story = $this->storyNeedingNarration(voiceId: 'not-a-real-voice');
        $this->bindRealSpeechProvider();
        $this->fakeAccount();

        $this->expectException(DispatchRefusedException::class);
        $this->expectExceptionMessageMatches('/is not a voice on the .* account/');

        app(PreflightAssetDispatch::class)->handle($story, checkWorkers: false, checkAligner: false, checkStyle: false);
    }

    /**
     * GREEN. The same account, the voice that is on it.
     */
    public function test_a_voice_on_the_account_passes_and_is_named(): void
    {
        $story = $this->storyNeedingNarration(voiceId: 'nPczCjzI2devNBz1zQrb');
        $this->bindRealSpeechProvider();
        $this->fakeAccount();

        $notes = app(PreflightAssetDispatch::class)
            ->handle($story, checkWorkers: false, checkAligner: false, checkStyle: false);

        $this->assertNotesContain($notes, 'ok', 'is on the elevenlabs account');
    }

    /**
     * GREEN. A vendor that cannot be reached warns; it does not refuse.
     *
     * A voice list that could not be read is a check that did not RUN, and this
     * codebase treats those as failures rather than passes — but the failure is
     * in the instrument, not in the story. Refusing would make an ElevenLabs
     * outage into a refusal to spend on IMAGES, which have nothing to do with
     * it.
     *
     * The pairing with the red case above is the whole point: "could not check"
     * must not read as "checked and fine", and must not read as "wrong" either.
     */
    public function test_a_vendor_that_cannot_be_reached_warns_rather_than_refusing(): void
    {
        $story = $this->storyNeedingNarration(voiceId: 'nPczCjzI2devNBz1zQrb');
        $this->bindRealSpeechProvider();

        Http::fake(['*/voices' => Http::response('nope', 500)]);

        $notes = app(PreflightAssetDispatch::class)
            ->handle($story, checkWorkers: false, checkAligner: false, checkStyle: false);

        $this->assertNotesContain($notes, 'warn', 'could not be read');
        $this->assertNotesContain($notes, 'warn', 'not the same as it being valid');
    }

    /**
     * GREEN. A stand-in is never refused, on any of these questions.
     *
     * Explicitly asserted rather than left to follow from the code, because it
     * is the constraint most likely to be lost in a later edit. Running against
     * fakes is how every fixture story here was made and how the render pipeline
     * was proven without spending a cent; a guard that refuses it is a guard
     * that has to be switched off to do ordinary work.
     *
     * -------------------------------------------------------------------
     * THE VOICE ID HERE IS LOAD-BEARING, AND THE FIRST ONE WAS NOT
     * -------------------------------------------------------------------
     *
     * This was written with `narrator-us-01`, which is ON the fake's voice
     * list — so it passed whether or not the simulated skip existed, and the
     * drill proved it: removing the skip left this test green while a
     * neighbouring case went red. A fixture that cannot express the failing
     * state makes the assertion vacuous however carefully it is worded, which
     * is a lesson this suite has now learned in several different shapes.
     *
     * So the id below is a REAL ElevenLabs voice, which is exactly what the
     * fake does not have. Without the skip, this is the refusal case.
     */
    public function test_a_simulated_synthesizer_is_never_refused_for_its_voice(): void
    {
        $this->app->instance(SpeechSynthesizer::class, $fake = new FakeSpeechSynthesizer);

        $voiceId = 'nPczCjzI2devNBz1zQrb';

        // The fixture can express the failure it is written about. Asserted,
        // because the first version of this test could not.
        $this->assertNotContains(
            $voiceId,
            array_column($fake->voices(), 'id'),
            'This voice is on the stand-in list, so the test cannot show the skip is doing anything.',
        );

        $story = $this->storyNeedingNarration(voiceId: $voiceId);

        $notes = app(PreflightAssetDispatch::class)
            ->handle($story, checkWorkers: false, checkAligner: false, checkStyle: false);

        $this->assertIsArray($notes);
    }

    /**
     * …and it SAYS a stand-in is bound, which is question 1.
     *
     * The pair to the case above: not refusing must not become not mentioning.
     * A missing `PROVIDER_IMAGE_GENERATOR` once sent 186 stills to a fake while
     * every screen named a vendor and $8.12 went into the ledger against calls
     * nobody made.
     */
    public function test_a_simulated_provider_is_reported_as_a_stand_in(): void
    {
        $story = $this->storyNeedingNarration(voiceId: 'narrator-us-01');
        $this->app->instance(SpeechSynthesizer::class, new FakeSpeechSynthesizer);

        $notes = app(PreflightAssetDispatch::class)
            ->handle($story, checkWorkers: false, checkAligner: false, checkStyle: false);

        $this->assertNotesContain($notes, 'warn', 'STAND-IN');
    }

    // ---------------------------------------------------------------------
    // Question 3 — does it fit
    // ---------------------------------------------------------------------

    /**
     * RED, and the only check here that can half-spend.
     *
     * On a plan without overage ElevenLabs does not bill past the allowance, it
     * STOPS. So the failure is not a surprise charge — it is scenes 1-170
     * narrated, 171 onward refused, and the allowance gone either way. A cost
     * estimate cannot see it: on a subscription the marginal answer is $0.00 on
     * both sides of the limit.
     */
    public function test_a_run_that_does_not_fit_the_allowance_is_refused(): void
    {
        $story = $this->storyNeedingNarration(voiceId: 'nPczCjzI2devNBz1zQrb');
        $this->bindRealSpeechProvider();
        // Two credits left against a run needing about a hundred.
        //
        // The first version left 100 remaining against ~98 needed, and the run
        // FIT — so the test failed for the right reason. Three short scenes is
        // not many characters, and a shortfall fixture has to leave a margin
        // smaller than the run rather than a round-looking number.
        $this->fakeAccount(used: 64_998, limit: 65_000);

        $this->expectException(DispatchRefusedException::class);
        $this->expectExceptionMessageMatches('/Short by/');

        app(PreflightAssetDispatch::class)->handle($story, checkWorkers: false, checkAligner: false, checkStyle: false);
    }

    /**
     * GREEN. The same run against an allowance that holds it, and the figure is
     * REPORTED — that number is the reason to run the check at all and it is
     * not visible anywhere else in the console.
     */
    public function test_a_run_that_fits_reports_what_is_left(): void
    {
        $story = $this->storyNeedingNarration(voiceId: 'nPczCjzI2devNBz1zQrb');
        $this->bindRealSpeechProvider();
        $this->fakeAccount(used: 0, limit: 65_000);

        $notes = app(PreflightAssetDispatch::class)
            ->handle($story, checkWorkers: false, checkAligner: false, checkStyle: false);

        $this->assertNotesContain($notes, 'ok', 'narration fits');
        $this->assertNotesContain($notes, 'ok', 'remaining');
    }

    /**
     * GREEN. An allowance that cannot be read warns and never refuses — and,
     * just as important, is never reported as fine.
     *
     * `SpeechQuota::accommodates()` returns null rather than true for exactly
     * this: an API key can synthesize perfectly well and still lack
     * `user_read`. "I could not check" rendering as "you have plenty" is the
     * substitution this whole class exists to prevent.
     */
    public function test_an_unreadable_allowance_warns_and_is_never_reported_as_fine(): void
    {
        $story = $this->storyNeedingNarration(voiceId: 'nPczCjzI2devNBz1zQrb');
        $this->bindRealSpeechProvider();

        Http::fake([
            '*/voices' => Http::response(['voices' => [[
                'voice_id' => 'nPczCjzI2devNBz1zQrb',
                'name' => 'Brian',
                'labels' => ['accent' => 'american'],
            ]]], 200),
            '*/user/subscription' => Http::response('forbidden', 401),
        ]);

        $notes = app(PreflightAssetDispatch::class)
            ->handle($story, checkWorkers: false, checkAligner: false, checkStyle: false);

        $this->assertNotesContain($notes, 'warn', 'could not be read');

        foreach ($notes as $note) {
            if ($note['level'] === 'ok') {
                $this->assertStringNotContainsString(
                    'narration fits',
                    $note['message'],
                    'An unreadable allowance was reported as fitting.',
                );
            }
        }
    }

    // ---------------------------------------------------------------------
    // The button
    // ---------------------------------------------------------------------

    /**
     * The free half: the same questions, asked without committing.
     *
     * `narration:preflight` has asked these five questions since it was written
     * and NOTHING in the app has ever called it — a guard reachable only from a
     * terminal, guarding the money button, on the app built so an operator
     * would not need a terminal.
     */
    public function test_the_check_button_reports_without_dispatching(): void
    {
        $story = $this->storyNeedingNarration(voiceId: 'nPczCjzI2devNBz1zQrb', status: StoryStatus::ScenesApproved);
        $this->bindRealSpeechProvider();
        $this->fakeAccount();

        \Livewire\Livewire::test(\App\Livewire\Gates\ScenesGate::class, ['story' => $story])
            ->call('checkReadiness')
            ->assertSet('readinessNotes', fn (array $notes): bool => $notes !== []);

        // Nothing queued and nothing billed — the whole point of the button.
        $this->assertSame(0, \App\Models\CostEntry::where('story_id', $story->id)->count());
        $this->assertSame(0, \App\Models\RenderJob::where('story_id', $story->id)->count());
    }

    /**
     * And a refusal from that button is TEXT, not a stack trace.
     */
    public function test_the_check_button_prints_a_refusal_rather_than_throwing(): void
    {
        $story = $this->storyNeedingNarration(voiceId: null, status: StoryStatus::ScenesApproved);

        \Livewire\Livewire::test(\App\Livewire\Gates\ScenesGate::class, ['story' => $story])
            ->call('checkReadiness')
            ->assertHasErrors('generation');
    }

    /**
     * The money press catches it too, and that catch was MISSING.
     *
     * `alignTimings()` and `draftScenes()` both had it; the button that spends
     * did not — so every refusal the preflight can raise reached the operator as
     * a stack trace on the one screen where the message is the entire point.
     *
     * It went unnoticed because the three refusals that existed all require a
     * broken machine to fire, and the button is pressed on a working one. A
     * missing narrator is an ordinary state, so the gap would have started
     * firing immediately.
     */
    public function test_the_generate_button_prints_a_refusal_rather_than_throwing(): void
    {
        $story = $this->storyNeedingNarration(voiceId: null, status: StoryStatus::ScenesApproved);

        \Livewire\Livewire::test(\App\Livewire\Gates\ScenesGate::class, ['story' => $story])
            ->call('generateAssets')
            ->assertHasErrors('generation');

        $this->assertSame(0, \App\Models\CostEntry::where('story_id', $story->id)->count());
    }

    // ---------------------------------------------------------------------
    // Fixture
    // ---------------------------------------------------------------------

    private function storyNeedingNarration(
        ?string $voiceId,
        StoryStatus $status = StoryStatus::ScenesApproved,
    ): Story {
        $story = Story::factory()->status($status)->create([
            'voice_id' => $voiceId,
            'locale_profile' => 'en-US',
        ]);

        $act = Act::factory()->for($story)->atSequence(1)->create();

        for ($i = 1; $i <= 3; $i++) {
            Scene::factory()->forAct($act)->atSequence($i)->create([
                'narration_text' => 'A sentence of narration for scene '.$i.', long enough to bill for.',
                'image_prompt' => 'A frame. '.$i,
                'status' => SceneStatus::Approved,
            ]);
        }

        return $story->refresh();
    }

    /**
     * Every scene already narrated and timed, so only the still is outstanding.
     *
     * Written against `scene_audio` rather than by setting a status, because
     * `SceneChangeSet` is provenance-aware and a status alone would not move it
     * — which is the property that makes the scoping case real rather than
     * arranged.
     */
    private function giveEverySceneNarrationAndTimings(Story $story): void
    {
        $track = $story->audioTracks()->firstOrCreate(
            ['language' => 'en-US'],
            ['voice_id' => $story->voice_id, 'status' => \App\Enums\AssetStatus::Ready],
        );

        foreach ($story->scenes()->get() as $scene) {
            $scene->sceneAudio()->create([
                'audio_track_id' => $track->id,
                'audio_path' => 'assets/'.$story->id.'/audio/scene-'.$scene->id.'.wav',
                'timings_json' => [['word' => 'a', 'start' => 0.0, 'end' => 0.4]],
                'duration_ms' => 1_000,
                'narration_simulated' => false,
                'narration_provider' => app(SpeechSynthesizer::class)->providerName(),
                'narration_voice_id' => $story->voice_id,
                'narration_speed' => \App\Support\NarrationPace::configuredSpeed(),
                'status' => \App\Enums\AssetStatus::Ready,
            ]);

            // The idempotency key. `needsNarration()` compares the approved
            // hash against the text's fingerprint, so audio alone is not enough
            // — without this the scene reads as never approved and the fixture
            // silently describes a story that still needs narrating. That is
            // what the assertion below caught on its first run, which is the
            // whole reason it is there.
            $scene->forceFill(['approved_narration_hash' => $scene->narrationFingerprint()])->save();
        }

        // Whatever remains outstanding, this fixture is only meaningful if
        // narration is NOT among it. Asserted rather than assumed — a fixture
        // that cannot express the state makes the case vacuous, which is the
        // way this suite has been fooled before.
        $changes = \App\Support\SceneChangeSet::for($story->refresh());

        $this->assertTrue(
            $changes->needsNarration->isEmpty(),
            'The scoping fixture still needs narration, so it cannot test an images-only dispatch.',
        );
    }

    private function bindRealSpeechProvider(): void
    {
        config([
            'providers.elevenlabs.api_key' => 'test-key',
            'providers.elevenlabs.base_url' => 'https://api.elevenlabs.io/v1',
        ]);

        $this->app->instance(SpeechSynthesizer::class, new ElevenLabsSpeechSynthesizer);
    }

    /**
     * The account, faked at the HTTP boundary rather than by mocking the
     * synthesizer — so the parsing of the vendor's own shapes is exercised too.
     */
    private function fakeAccount(int $used = 0, int $limit = 65_000): void
    {
        Http::fake([
            '*/voices' => Http::response(['voices' => [
                [
                    'voice_id' => 'nPczCjzI2devNBz1zQrb',
                    'name' => 'Brian - Deep, Resonant and Comforting',
                    'labels' => ['accent' => 'american'],
                ],
                [
                    'voice_id' => 'EXAVITQu4vr4xnSDxMaL',
                    'name' => 'Sarah - Mature, Reassuring, Confident',
                    'labels' => ['accent' => 'american'],
                ],
            ]], 200),
            '*/user/subscription' => Http::response([
                'tier' => 'starter',
                'character_count' => $used,
                'character_limit' => $limit,
                'can_extend_character_limit' => false,
            ], 200),
        ]);
    }

    /**
     * @param  array<int, array{level: string, message: string}>  $notes
     */
    private function assertNotesContain(array $notes, string $level, string $needle): void
    {
        foreach ($notes as $note) {
            if ($note['level'] === $level && str_contains($note['message'], $needle)) {
                $this->assertTrue(true);

                return;
            }
        }

        $this->fail(sprintf(
            'No %s note containing "%s". Got: %s',
            $level,
            $needle,
            json_encode(array_map(
                static fn (array $n): string => $n['level'].': '.mb_substr($n['message'], 0, 80),
                $notes,
            )),
        ));
    }
}
