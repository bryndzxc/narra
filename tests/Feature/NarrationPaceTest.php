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

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('assets');

        config()->set('render.narration.words_per_minute', 160);
        config()->set('render.narration.pace_tolerance', 0.12);
        config()->set('render.narration.pace_min_words', 50);
        config()->set('render.narration.voices', [
            self::BRIAN => ['name' => 'Brian', 'words_per_minute' => 172, 'measured_at_speed' => 0.9],
        ]);
        config()->set('providers.elevenlabs.tts.voice_settings.speed', 0.9);
    }

    // -- The guard fires on the real failure ---------------------------------

    public function test_it_fires_on_the_exact_drift_that_survived_sixty_nine_scenes(): void
    {
        // Scene 1 of story 9, to the millisecond: 56 words of narration that the
        // script writer sized at 160 wpm (21,000 ms) came back from ElevenLabs
        // at 17,787 ms — 189 wpm, 18% fast. This is the call that should have
        // stopped the run, and did not.
        $violation = NarrationPace::violation(null, words: 56, durationMs: 17787);

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

        $this->assertNull(NarrationPace::violation(self::BRIAN, 56, $ms));
    }

    public function test_a_measured_voice_is_judged_against_its_own_rate_not_the_global_one(): void
    {
        // The whole reason the figure moved per-voice, using story 9's real
        // scene 1: 56 words in 17,787 ms is 189 wpm. That is 10% off Brian's
        // measured 172 — inside tolerance, a normal scene — and 18% off the
        // global fallback of 160, which is a stopped run. Same audio, two
        // verdicts, and only one of them is about anything real.
        $this->assertNull(NarrationPace::violation(self::BRIAN, 56, 17787));
        $this->assertNotNull(NarrationPace::violation('some-unmeasured-voice', 56, 17787));
    }

    public function test_it_says_which_number_is_an_assumption(): void
    {
        // "measured 172 for Brian" and "assumed 160 because nobody has measured
        // this voice" are very different claims and the operator has to be able
        // to tell them apart.
        $this->assertTrue(NarrationPace::isMeasured(self::BRIAN));
        $this->assertFalse(NarrationPace::isMeasured('unknown-voice'));
        $this->assertSame(172, NarrationPace::expectedWpm(self::BRIAN));
        $this->assertSame(160, NarrationPace::expectedWpm('unknown-voice'));
        $this->assertSame(160, NarrationPace::expectedWpm(null));

        $this->assertStringContainsString(
            'no measured profile',
            (string) NarrationPace::violation('unknown-voice', 56, 17787),
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
        $this->assertNull(NarrationPace::violation(null, 13, 3437));
    }

    public function test_drift_inside_the_tolerance_passes(): void
    {
        // 8% fast on a long scene: real variation between scenes, not a
        // mis-sized script.
        $ms = (int) round(60 / (172 * 1.08) * 60000);

        $this->assertNull(NarrationPace::violation(self::BRIAN, 60, $ms));
    }

    public function test_reading_too_slowl_y_is_caught_as_well(): void
    {
        // Both directions. A voice 20% slow overruns the format ceiling just as
        // a fast one undershoots the floor, and a guard that only looked for one
        // sign would be the "check the axis it is already strong on" mistake.
        $ms = (int) round(60 / (172 * 0.75) * 60000);

        $violation = NarrationPace::violation(self::BRIAN, 60, $ms);

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
        $this->assertNull(NarrationPace::violation(self::BRIAN, $words, $ms), 'a healthy run was cancelled');

        // And the contrast that makes the point: judged ALONE — which is what a
        // per-scene check does — scene 4 is a violation. Same audio, same
        // healthy run, opposite verdict, purely from the size of the window.
        config()->set('render.narration.pace_min_words', 25);

        $this->assertNotNull(
            NarrationPace::violation(self::BRIAN, 37, (int) round(37 / ($expected * 1.23) * 60000)),
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

        $this->assertNotNull(NarrationPace::violation(self::BRIAN, $words, $ms));
    }

    public function test_it_waits_for_enough_narration_before_judging(): void
    {
        // Below the threshold there is no verdict at all — not a pass, not a
        // failure. On story 9 the first scene alone clears it, so the drift that
        // cost sixty-nine scenes still stops the run on the first one.
        $this->assertFalse(NarrationPace::isReliableSample(49));
        $this->assertTrue(NarrationPace::isReliableSample(56));
        $this->assertNull(NarrationPace::violation(self::BRIAN, 20, 3000));
    }

    // -- A stale profile is worse than none ----------------------------------

    public function test_a_profile_measured_at_another_speed_says_so(): void
    {
        // The figure is only true at the speed it was measured at. If
        // ELEVENLABS_SPEED moves and the profile does not, the expectation is
        // stale — and a stale expectation is worse than none, because the check
        // built on it passes while being wrong.
        config()->set('providers.elevenlabs.tts.voice_settings.speed', 1.0);

        $this->assertFalse(NarrationPace::profileMatchesConfiguredSpeed(self::BRIAN));

        // Note the shape: at 189 wpm against Brian's 172 the DRIFT is only 10%,
        // inside tolerance. So without this rule the run would sail through on a
        // comparison that no longer describes the audio being made — passing
        // while meaningless, which is the version of this bug that never gets
        // noticed. It has to fire on the staleness itself, not on the drift.
        $violation = NarrationPace::violation(self::BRIAN, 56, 17787);

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
