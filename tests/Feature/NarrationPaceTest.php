<?php

namespace Tests\Feature;

use App\Contracts\SpeechSynthesizer;
use App\Contracts\Transcriber;
use App\Enums\StoryStatus;
use App\Models\AudioTrack;
use App\Models\Scene;
use App\Models\SceneAudio;
use App\Models\Story;
use App\Services\ElevenLabs\ElevenLabsSpeechSynthesizer;
use App\Services\WhisperX\WhisperXTranscriber;
use App\Support\NarrationPace;
use App\Support\SceneChangeSet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The narrator has to read at the rate the script was written for.
 *
 * The failure this exists for, stated as it actually happened: the script writer
 * is handed a per-act word target DERIVED from a words-per-minute constant, the
 * runtime estimate is derived from the same constant, and nothing ever asked
 * whether the voice honoured it. It could not — the only synthesizer that
 * existed was a fake whose durations are computed FROM that constant, so the two
 * agreed by construction and every timing test in the suite passed against a
 * number that had never met a vendor.
 *
 * The first real voice read 22% fast. A 5,781-word story written for 36:08 came
 * back at 29:41, under the format's floor, and the only thing that caught it was
 * a person dividing words by minutes sixty-nine paid scenes in.
 *
 * Note which axis is tested here. Whether the constant EXISTS was never the
 * problem — 160 sat in config through the whole run. Whether anything compared
 * it to reality was the problem, so that is what these assert.
 */
class NarrationPaceTest extends TestCase
{
    use RefreshDatabase;

    private const BRIAN = 'nPczCjzI2devNBz1zQrb';

    /** The locale profile Brian is measured on in these tests. */
    private const LOCALE = 'en-US';

    /** A locale he is not. Story 21's, and the reason the key gained a second half. */
    private const UNMEASURED_LOCALE = 'en-CN';

    /** A pair pinned to story 9's real rate, for the sample-size tests. */
    private const MEASURED_AT_197 = 'en-197';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('assets');

        config()->set('render.narration.words_per_minute', 160);
        config()->set('render.narration.pace_tolerance', 0.12);
        config()->set('render.narration.pace_min_words', 50);
        config()->set('render.narration.voices', [
            self::BRIAN => [
                'name' => 'Brian',
                'locales' => [
                    // Measured for ONE locale on purpose. The pair is the key,
                    // and every test below that passes a different locale is
                    // asserting that an unmeasured pair is not judged.
                    self::LOCALE => ['words_per_minute' => 172, 'measured_at_speed' => 0.9],
                    // Story 9's real figure, at the speed it was read, so the
                    // sample-size tests are run against a rate that existed
                    // rather than one invented to make them pass.
                    self::MEASURED_AT_197 => ['words_per_minute' => 197, 'measured_at_speed' => 1.0],
                ],
            ],
        ]);
        config()->set('providers.elevenlabs.tts.voice_settings.speed', 0.9);
    }

    // -- Detection and enforcement are separate questions --------------------

    /**
     * Story 21, reduced. Brian is measured — on en-US, at 197 wpm across 186
     * real scenes — and that figure says nothing about how he reads en-CN.
     *
     * The old lookup was keyed on the voice alone, so it answered "measured,
     * 197" to a question about different prose, the guard was as certain as if
     * it knew, and a 270-scene batch died at scene 2.
     */
    public function test_a_voice_measured_on_one_locale_is_not_measured_on_another(): void
    {
        $this->assertTrue(NarrationPace::isMeasured(self::BRIAN, self::LOCALE));
        $this->assertFalse(NarrationPace::isMeasured(self::BRIAN, self::UNMEASURED_LOCALE));

        // And the borrowed figure does not leak across as the expectation.
        $this->assertSame(172, NarrationPace::expectedWpm(self::BRIAN, self::LOCALE));
        $this->assertSame(160, NarrationPace::expectedWpm(self::BRIAN, self::UNMEASURED_LOCALE));
    }

    /**
     * The correction to the first attempt at this fix, which is worth keeping
     * as a test because it was nearly shipped.
     *
     * Making the guard SILENT on an unmeasured pair looks reasonable and
     * removes the story 9 coverage entirely: that run had no voice profile at
     * all, and the expected figure it was judged against was the fallback
     * constant its script had been sized to. Detection must not depend on
     * whether the narrator has been profiled.
     */
    public function test_an_unmeasured_pair_is_still_measured_and_still_reported(): void
    {
        $violation = NarrationPace::violation(self::BRIAN, self::UNMEASURED_LOCALE, 56, 17787);

        $this->assertNotNull($violation, 'an unmeasured pair must still be detected');
        $this->assertStringContainsString('no measured profile', $violation);
        $this->assertStringContainsString(self::UNMEASURED_LOCALE, $violation);
    }

    /** But it is not grounds for destroying the run. */
    public function test_only_a_measured_pair_is_grounds_for_stopping_the_run(): void
    {
        $this->assertTrue(NarrationPace::isEnforceable(self::BRIAN, self::LOCALE));
        $this->assertFalse(NarrationPace::isEnforceable(self::BRIAN, self::UNMEASURED_LOCALE));
        $this->assertFalse(NarrationPace::isEnforceable(null, self::LOCALE));
    }

    /**
     * And the absence is stated at the point of spending, not left to be
     * inferred from a guard that stayed quiet.
     */
    public function test_an_unmeasured_pair_says_so_in_words(): void
    {
        $this->assertNull(NarrationPace::unmeasured(self::BRIAN, self::LOCALE));

        $said = NarrationPace::unmeasured(self::BRIAN, self::UNMEASURED_LOCALE);

        $this->assertNotNull($said);
        $this->assertStringContainsString('Brian', $said);
        $this->assertStringContainsString(self::UNMEASURED_LOCALE, $said);
        // Names the locale it IS measured on, so the operator can see the
        // figure exists and why it is not being borrowed.
        $this->assertStringContainsString(self::LOCALE, $said);
        $this->assertStringContainsString('narration:measure', $said);
    }

    /**
     * THE FIGURE IN THAT SENTENCE HAS TO BE THE FIGURE IT NAMES.
     *
     * It said "scripts are sized against 160 wpm (the fallback)" and printed
     * the guard's figure. Scripts are sized by `bestKnownWpm()`, so on any
     * locale with a measurement the claim was false — story 33, Sarah on
     * en-CN, was sized at 199 and the estimate beside the sentence read 199,
     * on the screen where the spend is authorised.
     *
     * RED is the old claim on exactly that shape: an unmeasured voice on a
     * locale another voice IS measured on. GREEN names three figures as what
     * they are.
     */
    public function test_an_unmeasured_voice_is_told_the_rate_its_script_was_actually_sized_against(): void
    {
        $sarah = 'EXAVITQu4vr4xnSDxMaL';
        $story = Story::factory()->create(['voice_id' => $sarah, 'locale_profile' => self::MEASURED_AT_197]);
        $story->forceFill(['sized_against_wpm' => 197])->save();

        $said = (string) NarrationPace::unmeasured($sarah, self::MEASURED_AT_197, $story->refresh());

        $this->assertStringNotContainsString('sized against 160', $said, 'the sentence claims a sizing rate the script was never written to');
        $this->assertStringContainsString('this script was sized against 197 wpm', $said);
        $this->assertStringContainsString('runtime estimates borrow 197 wpm', $said);
        $this->assertStringContainsString('the pace guard has only the 160 wpm fallback', $said);
    }

    /**
     * The builder above is only true on the spend screen if the preflight hands
     * it the story. Asserted at the call site on comment-stripped source — the
     * `truncationMessage()` lesson: a correct builder can be unreachable from
     * the one caller that matters.
     */
    public function test_the_preflight_hands_the_story_to_the_sentence(): void
    {
        $code = '';

        foreach (token_get_all((string) file_get_contents(app_path('Actions/PreflightAssetDispatch.php'))) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $code .= is_array($token) ? $token[1] : $token;
        }

        $this->assertStringContainsString(
            'NarrationPace::unmeasured($story->voice_id, $story->locale_profile, $story)',
            $code,
        );
    }

    public function test_with_nothing_measured_on_the_locale_it_says_both_use_the_fallback(): void
    {
        $story = Story::factory()->create(['voice_id' => self::BRIAN, 'locale_profile' => self::UNMEASURED_LOCALE]);

        $said = (string) NarrationPace::unmeasured(self::BRIAN, self::UNMEASURED_LOCALE, $story->refresh());

        $this->assertStringContainsString('runtime estimates and the pace guard both use the 160 wpm fallback', $said);
        // Null sizing is unknown, and says so rather than printing a number.
        $this->assertStringContainsString('the rate this script was sized against is not recorded', $said);
    }

    // -- The guard fires on the real failure ---------------------------------

    public function test_it_fires_on_the_exact_drift_that_survived_sixty_nine_scenes(): void
    {
        // Scene 1 of story 9, to the millisecond: 56 words of narration that the
        // script writer sized at 160 wpm (21,000 ms) came back from ElevenLabs
        // at 17,787 ms — 189 wpm, 18% fast. This is the call that should have
        // stopped the run, and did not.
        $violation = NarrationPace::violation(null, self::LOCALE, words: 56, durationMs: 17787);

        $this->assertNotNull($violation, 'the drift that cost 69 scenes went undetected');
        $this->assertStringContainsString('+18%', $violation);
        $this->assertStringContainsString('160 wpm', $violation);
        $this->assertStringContainsString('189 wpm', $violation);
    }

    public function test_the_same_scene_passes_once_the_voice_is_measured_and_slowed(): void
    {
        // The fix, measured rather than asserted: Brian at 0.9 reads 172 wpm, so
        // 56 words take ~19.5 s. Against a profile that knows that, this is fine.
        $ms = (int) round(56 / 172 * 60000);

        $this->assertNull(NarrationPace::violation(self::BRIAN, self::LOCALE, 56, $ms));
    }

    public function test_a_measured_voice_is_judged_against_its_own_rate_not_the_global_one(): void
    {
        // The whole reason the figure moved per-voice, using story 9's real
        // scene 1: 56 words in 17,787 ms is 189 wpm. That is 10% off Brian's
        // measured 172 — inside tolerance, a normal scene — and 18% off the
        // global fallback of 160, which is a stopped run. Same audio, two
        // verdicts, and only one of them is about anything real.
        $this->assertNull(NarrationPace::violation(self::BRIAN, self::LOCALE, 56, 17787));
        $this->assertNotNull(NarrationPace::violation('some-unmeasured-voice', self::LOCALE, 56, 17787));
    }

    public function test_it_says_which_number_is_an_assumption(): void
    {
        // "measured 172 for Brian" and "assumed 160 because nobody has measured
        // this voice" are very different claims and the operator has to be able
        // to tell them apart.
        $this->assertTrue(NarrationPace::isMeasured(self::BRIAN, self::LOCALE));
        $this->assertFalse(NarrationPace::isMeasured('unknown-voice', self::LOCALE));
        $this->assertSame(172, NarrationPace::expectedWpm(self::BRIAN, self::LOCALE));
        $this->assertSame(160, NarrationPace::expectedWpm('unknown-voice', self::LOCALE));
        $this->assertSame(160, NarrationPace::expectedWpm(null, self::LOCALE));

        $this->assertStringContainsString(
            'no measured profile',
            (string) NarrationPace::violation('unknown-voice', self::LOCALE, 56, 17787),
        );
    }

    // -- And does not fire on noise ------------------------------------------

    public function test_a_short_scene_is_not_a_verdict(): void
    {
        // Scene 3 of story 9: 13 words in 3,437 ms reads 227 wpm — 42% off the
        // global constant, and meaningless. Where the sentence breaks fall
        // dominates a sample this small, and an alarm that fires on noise is an
        // alarm that gets ignored, which is worse than no alarm.
        $this->assertFalse(NarrationPace::isReliableSample(13));
        $this->assertNull(NarrationPace::violation(null, self::LOCALE, 13, 3437));
    }

    public function test_drift_inside_the_tolerance_passes(): void
    {
        // 8% fast on a long scene: real variation between scenes, not a
        // mis-sized script.
        $ms = (int) round(60 / (172 * 1.08) * 60000);

        $this->assertNull(NarrationPace::violation(self::BRIAN, self::LOCALE, 60, $ms));
    }

    public function test_reading_too_slowl_y_is_caught_as_well(): void
    {
        // Both directions. A voice 20% slow overruns the format ceiling just as
        // a fast one undershoots the floor, and a guard that only looked for one
        // sign would be the "check the axis it is already strong on" mistake.
        $ms = (int) round(60 / (172 * 0.75) * 60000);

        $violation = NarrationPace::violation(self::BRIAN, self::LOCALE, 60, $ms);

        $this->assertNotNull($violation);
        $this->assertStringContainsString('-25%', $violation);
    }

    public function test_one_fast_scene_does_not_cancel_a_healthy_run(): void
    {
        // THE FAILURE THIS SHAPE IS FOR, and it nearly shipped the other way.
        //
        // Story 9's real scenes read 189, 201, 233 and 206 wpm as neighbours —
        // a 23% spread from nothing but sentence length and how much short
        // dialogue each carries. Scene 4 alone is 22% off the story average
        // while the story average is fine.
        //
        // A per-scene check tight enough to catch a 22% SYSTEMATIC drift would
        // have cancelled this batch at scene four. Cumulative measurement is
        // what makes the guard usable, and an alarm that fires on healthy runs
        // is an alarm that gets switched off.
        $expected = 172;

        // Four scenes whose individual rates vary wildly around a healthy mean.
        $scenes = [
            [56, $expected * 0.99],
            [42, $expected * 1.06],
            [37, $expected * 1.23],   // the outlier that would have tripped it
            [49, $expected * 1.09],
        ];

        $words = 0;
        $ms = 0;

        foreach ($scenes as [$w, $wpm]) {
            $words += $w;
            $ms += (int) round($w / $wpm * 60000);
        }

        // Together they are a normal story, and the guard stays quiet.
        $this->assertNull(NarrationPace::violation(self::BRIAN, self::LOCALE, $words, $ms), 'a healthy run was cancelled');

        // And the contrast that makes the point: judged ALONE — which is what a
        // per-scene check does — scene 4 is a violation. Same audio, same
        // healthy run, opposite verdict, purely from the size of the window.
        config()->set('render.narration.pace_min_words', 25);

        $this->assertNotNull(
            NarrationPace::violation(self::BRIAN, self::LOCALE, 37, (int) round(37 / ($expected * 1.23) * 60000)),
            'scene 4 alone should read as a violation — that is why the check is cumulative',
        );
    }

    public function test_systematic_drift_still_stops_the_run(): void
    {
        // The other half: cumulative measurement must not blunt the guard. Every
        // scene 22% fast is exactly what happened, and it has to fire.
        $words = 0;
        $ms = 0;

        foreach ([56, 42, 37, 49] as $w) {
            $words += $w;
            $ms += (int) round($w / (172 * 1.22) * 60000);
        }

        $this->assertNotNull(NarrationPace::violation(self::BRIAN, self::LOCALE, $words, $ms));
    }

    public function test_it_waits_for_enough_narration_before_judging(): void
    {
        // Below the threshold there is no verdict at all — not a pass, not a
        // failure. On story 9 the first scene alone clears it, so the drift that
        // cost sixty-nine scenes still stops the run on the first one.
        $this->assertFalse(NarrationPace::isReliableSample(49));
        $this->assertTrue(NarrationPace::isReliableSample(56));
        $this->assertNull(NarrationPace::violation(self::BRIAN, self::LOCALE, 20, 3000));
    }

    // -- The sample size, which is what actually cancelled the batch ---------

    public function test_story_twenty_ones_cancellation_reproduced_and_the_key_was_not_the_cause(): void
    {
        // The most useful test in this file, because it retires an explanation
        // that was believed for a session.
        //
        // Story 21's batch died at scene 2: 73 words, running average ~225 wpm,
        // judged against Brian's en-US figure of 197. The diagnosis was that
        // the KEY was wrong — a measurement of one kind of prose answering a
        // question about another — and the locale dimension was added.
        //
        // The full measurement is now in: en-CN is 199.49 wpm across 270
        // scenes, 1.26% from en-US. So the key was measuring almost nothing,
        // and the proof is below — with en-CN recorded at its own true rate,
        // the SAME two scenes still cancel the batch at a 50-word threshold.
        //
        // What was actually wrong is the sample. At 73 words the running
        // average's own noise, measured on both finished stories, is ±12.6%
        // against a 12% tolerance: the guard was measuring where the sentence
        // breaks happened to fall, not how fast anybody read.
        config()->set('render.narration.voices', [
            self::BRIAN => [
                'name' => 'Brian',
                'locales' => [
                    'en-US' => ['words_per_minute' => 197, 'measured_at_speed' => 1.0],
                    'en-CN' => ['words_per_minute' => 199, 'measured_at_speed' => 1.0],
                ],
            ],
        ]);
        config()->set('providers.elevenlabs.tts.voice_settings.speed', 1.0);

        // Scenes 1 and 2 of story 21, as they really read.
        $words = 73;
        $ms = (int) round($words / 225 * 60000);

        config()->set('render.narration.pace_min_words', 50);

        $this->assertNotNull(
            NarrationPace::violation(self::BRIAN, 'en-CN', $words, $ms),
            'the old threshold cancels the batch even with the locale key correct — '
            .'which is the point: the key was never what fired',
        );

        config()->set('render.narration.pace_min_words', 1000);

        $this->assertNull(
            NarrationPace::violation(self::BRIAN, 'en-CN', $words, $ms),
            'two scenes is not a sample',
        );
    }

    public function test_the_shipped_threshold_survives_the_noise_both_real_stories_carry(): void
    {
        // The measurement the default is sized from. Running-average deviation
        // from each story's own final rate, at 1,000 cumulative words:
        //
        //     story  9 : +2.3%   (reached at scene 30 of 186)
        //     story 21 : +1.9%   (reached at scene 34 of 270)
        //
        // A fifth of the tolerance, so a healthy run has room; and reached with
        // 85% of a 270-scene batch unspent, so a sick one is still caught early.
        // The profile is at speed 1.0, so the synthesizer has to be too — a
        // stale-speed profile is refused before the drift is even looked at,
        // and that refusal is correct and would mask what this asserts.
        config()->set('providers.elevenlabs.tts.voice_settings.speed', 1.0);

        $expected = 197;

        foreach ([0.023, -0.023, 0.019, -0.019] as $noise) {
            $words = 1000;
            $ms = (int) round($words / ($expected * (1 + $noise)) * 60000);

            $this->assertNull(
                NarrationPace::violation(self::BRIAN, self::MEASURED_AT_197, $words, $ms),
                sprintf('a healthy run drifting %+.1f%% was cancelled', $noise * 100),
            );
        }
    }

    public function test_the_shipped_threshold_still_catches_the_drift_it_exists_for(): void
    {
        // The other half, and the one that matters: a bigger window must not
        // blunt the guard. Story 9's real defect was a script sized at 160 wpm
        // read at 197 — +23%, an order of magnitude past the noise floor at
        // this sample size.
        config()->set('providers.elevenlabs.tts.voice_settings.speed', 1.0);

        $words = 1000;
        $ms = (int) round($words / (197 * 1.23) * 60000);

        $violation = NarrationPace::violation(self::BRIAN, self::MEASURED_AT_197, $words, $ms);

        $this->assertNotNull($violation);
        $this->assertStringContainsString('+23%', $violation);
    }

    // -- A stale profile is worse than none ----------------------------------

    public function test_a_profile_measured_at_another_speed_says_so(): void
    {
        // The figure is only true at the speed it was measured at. If
        // ELEVENLABS_SPEED moves and the profile does not, the expectation is
        // stale — and a stale expectation is worse than none, because the check
        // built on it passes while being wrong.
        config()->set('providers.elevenlabs.tts.voice_settings.speed', 1.0);

        $this->assertFalse(NarrationPace::profileMatchesConfiguredSpeed(self::BRIAN, self::LOCALE));

        // Note the shape: at 189 wpm against Brian's 172 the DRIFT is only 10%,
        // inside tolerance. So without this rule the run would sail through on a
        // comparison that no longer describes the audio being made — passing
        // while meaningless, which is the version of this bug that never gets
        // noticed. It has to fire on the staleness itself, not on the drift.
        $violation = NarrationPace::violation(self::BRIAN, self::LOCALE, 56, 17787);

        $this->assertNotNull($violation, 'a stale profile passed silently');
        $this->assertStringContainsString('taken at speed 0.90', $violation);
        $this->assertStringContainsString('set to 1.00', $violation);
        $this->assertStringContainsString('stale', $violation);
    }

    // -- Speed is provenance -------------------------------------------------

    public function test_changing_the_speed_restales_audio_read_at_the_old_one(): void
    {
        // The hole this closes: the same narrator reading the same words at two
        // tempos in one video. Provider matches, voice matches, text unchanged,
        // ledger balances — and it is as audible as two different narrators.
        $story = $this->storyWithAudioAt(1.0);

        config()->set('providers.elevenlabs.tts.voice_settings.speed', 1.0);
        $this->assertSame(0, SceneChangeSet::for($story->fresh())->needsNarration->count());

        config()->set('providers.elevenlabs.tts.voice_settings.speed', 0.9);
        $this->assertSame(3, SceneChangeSet::for($story->fresh())->needsNarration->count());
    }

    public function test_audio_of_unknown_speed_is_still_preserved(): void
    {
        // Same rule as every other provenance field: unknown is not stale.
        // Never re-bill a paid asset on a guess.
        $story = $this->storyWithAudioAt(null);

        config()->set('providers.elevenlabs.tts.voice_settings.speed', 0.9);

        $this->assertSame(0, SceneChangeSet::for($story->fresh())->needsNarration->count());
    }

    /**
     * Bind a provider that names itself `elevenlabs`.
     *
     * ProviderBindings forces every contract to its Fake under `testing`, on
     * purpose — a misconfigured test gets a fake rather than a bill. These two
     * tests are about provenance comparison, so the bound provider has to be the
     * one the audio claims to come from; against a fake every row reads stale on
     * the provider alone and the speed comparison is never reached.
     */
    private function bindElevenLabs(): void
    {
        $this->app->instance(
            SpeechSynthesizer::class,
            app(ElevenLabsSpeechSynthesizer::class),
        );
        $this->app->instance(
            Transcriber::class,
            new WhisperXTranscriber,
        );
    }

    private function storyWithAudioAt(?float $speed): Story
    {
        $this->bindElevenLabs();

        $story = Story::factory()->create(['voice_id' => self::BRIAN]);
        $story->forceFill(['status' => StoryStatus::ScenesApproved])->save();

        $track = AudioTrack::factory()->for($story)->create(['voice_id' => self::BRIAN]);

        foreach (range(1, 3) as $sequence) {
            $scene = Scene::factory()->for($story)->create([
                'sequence' => $sequence,
                'narration_text' => "Narration for scene {$sequence}.",
            ]);

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
                'narration_provider' => 'elevenlabs',
                'narration_voice_id' => self::BRIAN,
                'narration_speed' => $speed,
                'narration_simulated' => false,
                'timings_json' => [['word' => 'Narration', 'start_ms' => 0, 'end_ms' => 500]],
                'timings_provider' => 'whisperx',
                'timings_simulated' => false,
            ]);
        }

        return $story->fresh();
    }
}
