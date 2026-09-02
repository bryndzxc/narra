<?php

namespace Tests\Feature\Providers;

use App\Actions\GenerateSceneNarration;
use App\Actions\RecordProviderCost;
use App\Contracts\SpeechSynthesizer;
use App\Contracts\Transcriber;
use App\Enums\CostCategory;
use App\Enums\CostUnit;
use App\Models\AudioTrack;
use App\Models\Scene;
use App\Models\SceneAudio;
use App\Models\Story;
use App\Services\ElevenLabs\ElevenLabsSpeechSynthesizer;
use App\Services\Fake\FakeTranscriber;
use App\Services\WhisperX\WhisperXTranscriber;
use App\Support\AssetRateCard;
use App\Support\Providers\ProviderUsage;
use App\Support\SceneChangeSet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/**
 * The two providers that make a rendered video audible.
 *
 * Until these existed the pipeline produced real illustrations over silence: a
 * 36-minute film with FakeSpeechSynthesizer's silent WAVs and FakeTranscriber's
 * synthetic timings underneath it. Both fakes are convincing — the durations are
 * derived from the text at a real narration rate and the word timings are
 * contiguous and non-uniform — which is exactly why nothing downstream
 * complained.
 *
 * What is pinned here, in order of how expensive it is to get wrong:
 *
 *  1. **The forced-alignment invariant.** WhisperX must refuse to run without
 *     the expected text. This is the whole reason the provider is alignment and
 *     not transcription, and a null slipping through would silently turn it back
 *     into the thing it exists not to be — subtitles carrying what a recogniser
 *     heard rather than what the operator approved at Gate 2.
 *
 *  2. **PCM, wrapped, exact.** The audio duration is arithmetic on a sample
 *     count, not a probe of a lossy container, because PadSceneAudio hard-fails
 *     when a probed duration and a decoded length disagree.
 *
 *  3. **Money.** Credits are not characters, the multiplier is per model, and a
 *     free-but-real provider is not a simulated one.
 *
 *  4. **The rate card answers at all.** Both of its speech and transcription
 *     arms were `default => throw` — the estimate would have died the moment a
 *     real provider was bound, on the screen whose entire job is to be seen
 *     before spending.
 */
class NarrationProviderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('assets');

        config()->set('providers.elevenlabs.api_key', 'sk_test');
        config()->set('providers.elevenlabs.base_url', 'https://api.elevenlabs.io/v1');

        // The rate card is PINNED here rather than inherited, and that is the
        // point rather than tidiness. These assertions are about the SHAPE of
        // the pricing — a per-model multiplier, flash at half of standard, an
        // unknown model assumed expensive — and reading the live card made them
        // depend on the operator's .env instead. They duly broke the moment a
        // measured correction landed there (multilingual_v2 bills 0.5 on this
        // account, not 1.0), which is a test reporting on configuration rather
        // than on behaviour.
        config()->set('providers.elevenlabs.tts.model', 'eleven_multilingual_v2');
        config()->set('providers.elevenlabs.tts.pricing.usd_per_credit', 6 / 30000);
        config()->set('providers.elevenlabs.tts.pricing.credits_per_character', [
            'eleven_multilingual_v2' => 1.0,
            'eleven_flash_v2_5' => 0.5,
        ]);
        config()->set('providers.elevenlabs.tts.pricing.default_credits_per_character', 1.0);
    }

    // -- The invariant the whole choice of provider rests on -----------------

    public function test_alignment_refuses_to_run_without_the_text_it_is_aligning(): void
    {
        // The failure mode this guard is FOR: called with a null expectedText,
        // whisperx would happily transcribe instead, and the .ass karaoke line
        // would carry whatever the model heard. One misheard proper noun puts a
        // word on screen that was never approved at Gate 2 and desynchronises
        // the highlight for the rest of the scene.
        //
        // The contract's signature allows null because a transcriber legitimately
        // might. This provider is not one, so it refuses rather than degrading.
        $path = $this->wavOnDisk(1000);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('forced alignment, not transcription');

        (new WhisperXTranscriber)->transcribe($path, null);
    }

    public function test_alignment_refuses_whitespace_as_text(): void
    {
        $path = $this->wavOnDisk(1000);

        $this->expectException(RuntimeException::class);

        (new WhisperXTranscriber)->transcribe($path, "  \n ");
    }

    // -- Audio that the render pipeline can actually measure ------------------

    public function test_raw_pcm_comes_back_wrapped_in_a_readable_wav_header(): void
    {
        // ElevenLabs returns pcm_* headerless. Written straight to disk that is
        // a file ffprobe cannot read — and GenerateSceneNarration probes it for
        // the duration the clip's frame count is ceil()'d from.
        $samples = 24000; // exactly one second at 24 kHz
        $this->fakeSpeech(str_repeat("\x00\x00", $samples));

        $speech = app(ElevenLabsSpeechSynthesizer::class)->synthesize(
            $this->scene(),
            'One second of narration.',
            'voice-abc',
        );

        $this->assertSame('audio/wav', $speech->mimeType);
        $this->assertSame('RIFF', substr($speech->bytes, 0, 4));
        $this->assertSame('WAVE', substr($speech->bytes, 8, 4));

        // The header must describe the samples, not merely be present.
        $this->assertSame(1, unpack('v', substr($speech->bytes, 22, 2))[1], 'mono');
        $this->assertSame(24000, unpack('V', substr($speech->bytes, 24, 4))[1], 'sample rate');
        $this->assertSame(16, unpack('v', substr($speech->bytes, 34, 2))[1], 'bit depth');
        $this->assertSame($samples * 2, unpack('V', substr($speech->bytes, 40, 4))[1], 'data size');
        $this->assertSame(44 + $samples * 2, strlen($speech->bytes));
    }

    public function test_duration_is_computed_from_the_sample_count_not_declared(): void
    {
        // Exact by construction. Raw PCM has a whole number of samples at a
        // known rate, so there is no gap between what the container says and
        // what decodes — which is the gap PadSceneAudio hard-fails on.
        $this->fakeSpeech(str_repeat("\x00\x00", 36000)); // 1.5s at 24 kHz

        $speech = app(ElevenLabsSpeechSynthesizer::class)
            ->synthesize($this->scene(), 'A second and a half.', 'voice-abc');

        $this->assertSame(1500, $speech->durationMs);
    }

    public function test_a_compressed_output_format_is_refused_rather_than_guessed_at(): void
    {
        // The failure mode: mp3_44100_128 would still download and still write a
        // playable file. It would also reintroduce the decoded-vs-declared gap
        // that choosing PCM exists to close, and the failure would surface as
        // PadSceneAudio's "padding would become a trim" — which reads as a
        // frame-count bug and is not one.
        config()->set('providers.elevenlabs.tts.output_format', 'mp3_44100_128');
        $this->fakeSpeech('not really an mp3');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('needs a pcm_* format');

        app(ElevenLabsSpeechSynthesizer::class)
            ->synthesize($this->scene(), 'Anything.', 'voice-abc');
    }

    public function test_an_empty_body_on_a_200_is_refused(): void
    {
        Http::fake(['*/text-to-speech/*' => Http::response('', 200)]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('empty body');

        app(ElevenLabsSpeechSynthesizer::class)
            ->synthesize($this->scene(), 'Anything.', 'voice-abc');
    }

    // -- Money ---------------------------------------------------------------

    public function test_credits_are_not_characters_and_the_multiplier_is_per_model(): void
    {
        $provider = app(ElevenLabsSpeechSynthesizer::class);

        config()->set('providers.elevenlabs.tts.model', 'eleven_multilingual_v2');
        $this->assertSame(1.0, $provider->creditsPerCharacter());
        $this->assertSame(100.0, $provider->creditsFor(str_repeat('a', 100)));

        // Half. A flat per-character figure in the rate card would price this
        // run at double and would keep doing so silently after a model switch.
        config()->set('providers.elevenlabs.tts.model', 'eleven_flash_v2_5');
        $this->assertSame(0.5, $provider->creditsPerCharacter());
        $this->assertSame(50.0, $provider->creditsFor(str_repeat('a', 100)));

        // An unknown model assumes the EXPENSIVE rate. Under-quoting is the
        // direction that hides; over-quoting is merely conservative.
        config()->set('providers.elevenlabs.tts.model', 'eleven_something_new');
        $this->assertSame(1.0, $provider->creditsPerCharacter());
    }

    public function test_the_vendors_own_character_count_wins_over_ours(): void
    {
        // Trust the bytes, not the request — the same rule the image side
        // learned when fal answered a 1024x1024 PNG request with a 1920x1920
        // JPEG. It is also the only way to find out whether stitched context is
        // billed, which is why the header lands in `detail` either way.
        $this->fakeSpeech(str_repeat("\x00\x00", 2400), ['character-cost' => '77']);

        $usage = app(ElevenLabsSpeechSynthesizer::class)
            ->synthesize($this->scene(), 'Twelve characters', 'voice-abc')
            ->usage;

        $this->assertSame(77.0, $usage->quantity);
        $this->assertSame(CostUnit::Characters, $usage->unit);
        $this->assertSame(17, $usage->detail['characters_sent']);
        $this->assertSame(77, $usage->detail['characters_charged_header']);
        $this->assertFalse($usage->simulated);
    }

    public function test_our_count_is_used_and_recorded_as_ours_when_no_header_comes_back(): void
    {
        $this->fakeSpeech(str_repeat("\x00\x00", 2400));

        $usage = app(ElevenLabsSpeechSynthesizer::class)
            ->synthesize($this->scene(), 'Twelve chars', 'voice-abc')
            ->usage;

        $this->assertSame(12.0, $usage->quantity);
        // Null, not absent: a reconciliation against the usage page has to know
        // which of the two numbers it is looking at.
        $this->assertNull($usage->detail['characters_charged_header']);
    }

    public function test_a_free_but_real_provider_is_not_a_simulated_one(): void
    {
        // The distinction the ledger rests on. `simulated` means "produced no
        // real artefact and contacted nobody" — not "was free". WhisperX
        // produces the timings the video ships with, on local hardware, for
        // nothing. Recording it as simulated would make `WHERE simulated = 0`
        // wrong in the opposite direction from the bug that motivated the flag.
        $transcriber = new WhisperXTranscriber;

        $this->assertFalse($transcriber->isSimulated());
        $this->assertSame('whisperx', $transcriber->providerName());
        $this->assertSame(0.0, $transcriber->usdPerMinute());

        $this->assertTrue(app(FakeTranscriber::class)->isSimulated());
    }

    public function test_a_zero_cost_row_from_a_real_provider_is_accepted(): void
    {
        // RecordProviderCost refuses a non-zero cost from a SIMULATED provider.
        // It must not refuse a zero cost from a real one, or whisperx could
        // never write a row at all — and a stage that writes no rows is
        // indistinguishable from a stage whose cost recording broke.
        // Past Gate 2: an Asset-category cost row is itself gated, so a draft
        // story cannot write one at all. That guard is doing its job here — it
        // is not what this test is about.
        $story = Story::factory()->paidAssetsUnlocked()->create();

        $entry = app(RecordProviderCost::class)->handle($story, new ProviderUsage(
            provider: 'whisperx',
            operation: 'align_timings',
            category: CostCategory::Asset,
            quantity: 12.5,
            unit: CostUnit::AudioSeconds,
            usdCost: 0.0,
            simulated: false,
        ));

        $this->assertSame('whisperx', $entry->provider);
        $this->assertFalse((bool) $entry->simulated);
        $this->assertSame('0.0000', (string) $entry->usd_cost);
    }

    public function test_the_provider_breakdown_reaches_the_ledger(): void
    {
        // ProviderUsage has carried `detail` since the money guard was written
        // and RecordProviderCost dropped it: the insert listed nine columns and
        // this was not one of them. Nothing needed it until a real bill had to
        // be reconciled — at which point the ledger said 168 characters for a
        // 336-character scene with no way to tell whether that number came from
        // the vendor or from us. It came from the vendor, and that is exactly
        // the fact this column exists to record.
        $story = Story::factory()->paidAssetsUnlocked()->create();
        $scene = Scene::factory()->for($story)->create([
            'sequence' => 1,
            'narration_text' => 'Twelve chars',
        ]);

        $this->fakeSpeech(str_repeat('  ', 2400), ['character-cost' => '6']);

        $usage = app(ElevenLabsSpeechSynthesizer::class)
            ->synthesize($scene, 'Twelve chars', 'voice-abc')->usage;

        $entry = app(RecordProviderCost::class)->handle($story, $usage);

        $detail = (array) $entry->fresh()->detail;

        // Both numbers, side by side. Either alone is unreconcilable.
        $this->assertSame(12, $detail['characters_sent']);
        $this->assertSame(6, $detail['characters_charged_header']);
        $this->assertSame('eleven_multilingual_v2', $detail['model']);
        $this->assertFalse($detail['stitched_context']);

        // And the billed quantity is the vendor's, not ours.
        $this->assertSame(6.0, (float) $entry->quantity);
    }

    // -- The rate card that would have thrown on the confirmation screen ------

    public function test_the_rate_card_can_price_the_real_providers(): void
    {
        // Both arms were `default => throw`. Bound for real, the Gate 2 estimate
        // panel and `assets:generate` would have died on the screen whose entire
        // purpose is to be read before money is spent.
        $this->app->instance(SpeechSynthesizer::class, app(ElevenLabsSpeechSynthesizer::class));
        $this->app->instance(Transcriber::class, new WhisperXTranscriber);

        $rates = app(AssetRateCard::class);

        // 1,000 characters x 1 credit x $0.0002 = $0.20
        $this->assertEqualsWithDelta(0.20, $rates->usdPerThousandSpeechCharacters(), 0.0001);
        $this->assertSame(0.0, $rates->usdPerTranscribedMinute());

        $this->assertSame('elevenlabs', $rates->speechProvider());
        $this->assertSame('whisperx', $rates->transcriberProvider());

        // And neither counts as a simulated stage, so the "SIMULATED" warning
        // does not fire on a run that is genuinely spending money.
        $this->assertNotContains('narration', $rates->simulatedStages());
        $this->assertNotContains('word timings', $rates->simulatedStages());
    }

    public function test_a_flash_model_halves_the_quoted_speech_rate(): void
    {
        $this->app->instance(SpeechSynthesizer::class, app(ElevenLabsSpeechSynthesizer::class));

        $full = app(AssetRateCard::class)->usdPerThousandSpeechCharacters();

        config()->set('providers.elevenlabs.tts.model', 'eleven_flash_v2_5');
        $half = app(AssetRateCard::class)->usdPerThousandSpeechCharacters();

        $this->assertEqualsWithDelta($full / 2, $half, 0.000001);
    }

    // -- Running out is an operating state, not a bug ------------------------

    public function test_an_exhausted_allowance_is_explained_as_one(): void
    {
        // The distinctive failure of this vendor on this plan. Free and Starter
        // have NO overage: generation stops rather than billing past the limit,
        // so a batch that crosses the line mid-run leaves the story
        // half-narrated with the allowance already gone. Reported as a bare
        // HTTP 401 it reads as a broken key, and the fix for that is a
        // different fix.
        Http::fake(['*/text-to-speech/*' => Http::response(
            '{"detail":{"status":"quota_exceeded","message":"you have 12 credits remaining"}}',
            401,
        )]);

        try {
            app(ElevenLabsSpeechSynthesizer::class)
                ->synthesize($this->scene(), 'Anything at all.', 'voice-abc');
            $this->fail('Expected the exhausted allowance to be refused.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('exhausted allowance, not a broken key', $e->getMessage());
            $this->assertStringContainsString('NO overage', $e->getMessage());
            // And the recovery, which is not obvious: re-pressing is safe.
            $this->assertStringContainsString('not re-billed', $e->getMessage());
        }
    }

    public function test_an_unreadable_quota_is_not_reported_as_a_healthy_one(): void
    {
        // A scoped key can synthesize perfectly and still be unable to read the
        // balance — `user_read` is a separate permission. "I could not check"
        // rendering as "you have plenty" is the same class of bug as config
        // standing in for the container: a check that reports success because
        // it could not run.
        Http::fake(['*/user/subscription' => Http::response(
            '{"detail":{"status":"missing_permissions","message":"missing the permission user_read"}}',
            401,
        )]);

        $quota = app(ElevenLabsSpeechSynthesizer::class)->quota();

        $this->assertFalse($quota->readable);
        $this->assertNull($quota->remaining());
        // Null, never true, and never false either — unknown is its own answer.
        $this->assertNull($quota->accommodates(1_000_000));
        $this->assertStringContainsString('user_read', (string) $quota->unreadableReason);
    }

    public function test_a_readable_quota_answers_whether_a_run_fits(): void
    {
        Http::fake(['*/user/subscription' => Http::response([
            'tier' => 'starter',
            'character_count' => 4000,
            'character_limit' => 30000,
            'can_extend_character_limit' => false,
        ])]);

        $quota = app(ElevenLabsSpeechSynthesizer::class)->quota();

        $this->assertTrue($quota->readable);
        $this->assertSame(26000, $quota->remaining());
        $this->assertTrue($quota->accommodates(25_999));
        $this->assertFalse($quota->accommodates(26_001));
        $this->assertStringContainsString('NO overage', $quota->summary());
    }

    // -- Identity ------------------------------------------------------------

    public function test_both_providers_name_themselves_as_the_ledger_will(): void
    {
        // providerName() is what lands in cost_entries.provider and on the
        // confirmation screen. A quote naming one vendor while the ledger names
        // another is the confusion ProviderIdentity exists to remove.
        $speech = app(ElevenLabsSpeechSynthesizer::class);

        $this->assertSame('elevenlabs', $speech->providerName());
        $this->assertFalse($speech->isSimulated());
        $this->assertSame('eleven_multilingual_v2', $speech->modelName());

        $this->fakeSpeech(str_repeat("\x00\x00", 2400));

        $usage = $speech->synthesize($this->scene(), 'Hello.', 'voice-abc')->usage;

        $this->assertSame($speech->providerName(), $usage->provider);
        $this->assertSame($speech->modelName(), $usage->model);
    }

    public function test_the_voice_list_is_filtered_to_the_audiences_accent(): void
    {
        // A US-audience channel stores one voice for the life of the channel.
        // A British narrator picked from an unfiltered list is a mistake that
        // survives every video made after it.
        Http::fake(['*/voices' => Http::response(['voices' => [
            ['voice_id' => 'us-1', 'name' => 'Brian', 'labels' => ['accent' => 'american', 'gender' => 'male']],
            ['voice_id' => 'gb-1', 'name' => 'Daniel', 'labels' => ['accent' => 'british', 'gender' => 'male']],
        ]])]);

        $voices = app(ElevenLabsSpeechSynthesizer::class)->voices();

        $this->assertCount(1, $voices);
        $this->assertSame('us-1', $voices[0]['id']);
        $this->assertSame('en-US', $voices[0]['locale']);
    }

    // -- Provenance: the reason a real provider gets to run at all ------------

    public function test_a_story_narrated_by_a_stand_in_is_wholly_outstanding_to_a_real_one(): void
    {
        // THE FAILURE THIS GUARD IS FOR, named as the spec requires.
        //
        // Idempotency is keyed on the narration TEXT — correct for "will this
        // cost money to redo", blind to "who made it". A story narrated end to
        // end by FakeSpeechSynthesizer has 186 silent WAVs whose text has not
        // changed since Gate 2, so before this it reported ZERO scenes
        // outstanding. Binding ElevenLabs and pressing Generate assets did
        // nothing at all, silently, and the operator's only evidence would have
        // been a video that was still silent.
        //
        // Note the axis: the text-fingerprint check is strong on change and
        // cannot fail here, so testing it would be theatre. Provenance is the
        // axis that was weak.
        $story = $this->storyNarratedBy('fake', 'narrator-us-01', simulated: true);

        $this->assertSame(0, SceneChangeSet::for($story)->needsNarration->count());

        $this->bindRealProviders();
        $story->forceFill(['voice_id' => 'real-voice-1'])->save();

        $changes = SceneChangeSet::for($story->fresh());

        $this->assertSame(3, $changes->needsNarration->count());
        // And the timings follow the audio: new audio means the old timings
        // describe a file that no longer exists.
        $this->assertSame(3, $changes->needsTranscription->count());
        $this->assertSame(0, $changes->preserved());
    }

    public function test_audio_of_unknown_provenance_is_preserved_rather_than_re_billed(): void
    {
        // The other direction, and the one that costs money to get wrong. A row
        // written before provenance was recorded, whose ledger entries could not
        // resolve it, reports NULL. Treating unknown as stale would re-bill
        // every paid asset in the database the first time a provider was
        // swapped. The rule is the one narrationChanged() already follows:
        // never destroy an asset because its provenance is unknown.
        $story = $this->storyNarratedBy(null, null, simulated: null);

        $this->bindRealProviders();

        $this->assertSame(0, SceneChangeSet::for($story->fresh())->needsNarration->count());
    }

    public function test_the_same_provider_and_voice_is_not_re_billed(): void
    {
        // A resumed batch must not be a repeated one. At ~$0.20 per 1,000
        // characters across 30,000, a change set that restaled its own output
        // would cost a second narration every time the button was pressed.
        $story = $this->storyNarratedBy('elevenlabs', 'real-voice-1', simulated: false);
        $story->forceFill(['voice_id' => 'real-voice-1'])->save();

        $this->bindRealProviders();

        $this->assertSame(0, SceneChangeSet::for($story->fresh())->needsNarration->count());
    }

    public function test_changing_the_narrator_restales_the_audio_it_did_not_narrate(): void
    {
        // A channel keeps one narrator. Audio generated under a voice the story
        // no longer uses would leave the finished video narrated by two
        // different people — audible to every viewer and invisible to every
        // cost check, because the text never changed.
        $story = $this->storyNarratedBy('elevenlabs', 'old-voice', simulated: false);
        $story->forceFill(['voice_id' => 'new-voice'])->save();

        $this->bindRealProviders();

        $this->assertSame(3, SceneChangeSet::for($story->fresh())->needsNarration->count());
    }

    public function test_the_action_does_not_skip_a_scene_the_change_set_flagged(): void
    {
        // The loop has to close. A change set that reports 186 outstanding while
        // the action skips all 186 as "already generated" is worse than either
        // being wrong alone: the batch runs, every job succeeds, nothing is
        // billed, and the story still has no real audio.
        $story = $this->storyNarratedBy('fake', 'narrator-us-01', simulated: true);
        $story->forceFill(['voice_id' => 'real-voice-1'])->save();

        $this->bindRealProviders();
        $this->fakeSpeech(str_repeat("\x00\x00", 24000));

        $scene = $story->scenes()->orderBy('sequence')->first();

        $result = app(GenerateSceneNarration::class)->handle($scene->fresh());

        $this->assertTrue($result['billed'], 'the scene was skipped as already generated');

        $audio = $scene->fresh()->sceneAudio()->first();
        $this->assertSame('elevenlabs', $audio->narration_provider);
        $this->assertSame('real-voice-1', $audio->narration_voice_id);
        $this->assertFalse((bool) $audio->narration_simulated);
        // Regenerated audio invalidates the timings that described the old file.
        $this->assertNull($audio->timings_json);
        $this->assertNull($audio->timings_provider);
    }

    /**
     * A story past Gate 2 whose scenes already carry narration of a given
     * provenance.
     */
    private function storyNarratedBy(?string $provider, ?string $voiceId, ?bool $simulated): Story
    {
        $story = Story::factory()->paidAssetsUnlocked()->create(['voice_id' => $voiceId]);
        $track = AudioTrack::factory()->for($story)->create(['voice_id' => $voiceId]);

        foreach (range(1, 3) as $sequence) {
            $scene = Scene::factory()->for($story)->create([
                'sequence' => $sequence,
                'narration_text' => "Narration for scene {$sequence}.",
                'image_path' => "9/stills/scene-{$sequence}.png",
            ]);

            // The snapshot Gate 2 approval takes: without it every scene is
            // "changed" on the text axis and the provenance axis is never
            // reached, which would make these tests pass for the wrong reason.
            $scene->forceFill([
                'approved_narration_hash' => $scene->narrationFingerprint(),
                'approved_image_hash' => $scene->imageFingerprint(),
                'approved_motion_preset' => $scene->motion_preset->value,
            ])->save();

            Storage::disk('assets')->put("narration/scene-{$scene->id}.wav", 'x');

            SceneAudio::factory()->create([
                'scene_id' => $scene->id,
                'audio_track_id' => $track->id,
                'audio_path' => "narration/scene-{$scene->id}.wav",
                'narration_provider' => $provider,
                'narration_voice_id' => $voiceId,
                'narration_simulated' => $simulated,
                'timings_json' => [['word' => 'Narration', 'start_ms' => 0, 'end_ms' => 500]],
                'timings_provider' => $provider,
                'timings_simulated' => $simulated,
            ]);
        }

        return $story->fresh();
    }

    /**
     * Override the testing environment's blanket fake binding.
     *
     * ProviderBindings forces every contract to its Fake under `testing`, on
     * purpose, so a misconfigured test gets a fake rather than a bill. These
     * tests are specifically about what happens when a real one is bound, so
     * they place the instances directly — and the HTTP client is faked, so
     * nothing leaves the machine either way.
     */
    private function bindRealProviders(): void
    {
        $this->app->instance(SpeechSynthesizer::class, app(ElevenLabsSpeechSynthesizer::class));
        $this->app->instance(Transcriber::class, new WhisperXTranscriber);
    }

    // -- Helpers -------------------------------------------------------------

    /** @param array<string, string> $headers */
    private function fakeSpeech(string $body, array $headers = []): void
    {
        Http::fake(['*/text-to-speech/*' => Http::response($body, 200, $headers)]);
    }

    private function scene(): Scene
    {
        $story = Story::factory()->create();

        return Scene::factory()->for($story)->create([
            'sequence' => 1,
            'narration_text' => 'Placeholder narration.',
        ]);
    }

    private function wavOnDisk(int $durationMs): string
    {
        $path = sys_get_temp_dir().'/narra-align-test.wav';
        $samples = (int) round($durationMs / 1000 * 24000);

        file_put_contents($path, 'RIFF'.pack('V', 36 + $samples * 2).'WAVEfmt '
            .pack('V', 16).pack('v', 1).pack('v', 1).pack('V', 24000)
            .pack('V', 48000).pack('v', 2).pack('v', 16)
            .'data'.pack('V', $samples * 2).str_repeat("\0", $samples * 2));

        return $path;
    }
}
