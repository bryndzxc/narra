<?php

namespace Tests\Feature;

use App\Actions\PadSceneAudio;
use App\Services\Ffmpeg;
use Tests\TestCase;

/**
 * Padding is exact when the source rate is NOT the render rate.
 *
 * **The failure this is named for.** `-af` filters run before the output
 * resampling that `-ar` implies, so `atrim=end_sample=N` counts samples at the
 * INPUT rate. The pipeline computed N at the RENDER rate and handed it to a
 * filter evaluating it in the source's domain. With a 24 kHz source against a
 * 44.1 kHz render that trimmed to 784,980 samples of 24 kHz audio — 32.7
 * seconds — which `-ar` then resampled up to 1,442,401 samples: exactly
 * target × 44100/24000, and a scene 84% too long.
 *
 * **Why nothing caught it for a whole phase.** Every existing padding test is
 * strong on the axis padding is strong on — that the sample count lands exactly
 * on frames × samplesPerFrame — and every one of them passed, because they were
 * fed audio from the fake synthesizer, which writes at
 * `render.audio.sample_rate` by construction. Source rate and render rate were
 * the same number in every test that had ever run, so the two domains coincided
 * and the filter was accidentally correct.
 *
 * That is the spec's guard rule exactly: a check that only tests the axis a
 * component is already strong on will always pass. The axis this file adds is
 * SOURCE RATE, and it is the axis the fake could never vary.
 */
class PadSceneAudioSampleRateTest extends TestCase
{
    private string $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = sys_get_temp_dir().'/narra-pad-'.bin2hex(random_bytes(4));
        @mkdir($this->workspace, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->workspace.'/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->workspace);

        parent::tearDown();
    }

    /**
     * A 24 kHz source — what ElevenLabs `pcm_24000` actually delivers — padded
     * into a 44.1 kHz render.
     */
    public function test_a_source_below_the_render_rate_is_padded_to_the_exact_frame_count(): void
    {
        $this->assertPadsExactly(sourceRate: 24000, frames: 534);
    }

    /**
     * And above it. This direction had a second bug behind the first: the
     * source-length guard compared a source-rate count against a render-rate
     * target, so a 48 kHz scene reported MORE samples than its frames allow and
     * failed as "padding would become a trim" while being entirely fine.
     */
    public function test_a_source_above_the_render_rate_is_not_mistaken_for_an_overlong_scene(): void
    {
        $this->assertPadsExactly(sourceRate: 48000, frames: 120);
    }

    /** The case that always worked, kept so the fix cannot regress it. */
    public function test_a_source_at_the_render_rate_still_works(): void
    {
        $this->assertPadsExactly(sourceRate: 44100, frames: 90);
    }

    /**
     * Pad a generated tone and assert the result is exactly frames / fps.
     */
    private function assertPadsExactly(int $sourceRate, int $frames): void
    {
        $ffmpeg = app(Ffmpeg::class);
        $renderRate = (int) config('render.audio.sample_rate');
        $fps = (int) config('render.video.fps');

        // Deliberately SHORTER than the frame count, so padding has real work to
        // do — the bug produced a file longer than the target, so a source that
        // already filled the frames would not have exercised it.
        $sourceSeconds = round(($frames / $fps) * 0.9, 3);

        $source = $this->workspace."/src-{$sourceRate}.wav";
        $output = $this->workspace."/padded-{$sourceRate}.wav";

        $ffmpeg->run([
            '-y', '-loglevel', 'error',
            '-f', 'lavfi',
            '-i', "sine=frequency=440:sample_rate={$sourceRate}:duration={$sourceSeconds}",
            '-ac', '1',
            '-c:a', 'pcm_s16le',
            $source,
        ], 120);

        $this->assertSame($sourceRate, $ffmpeg->sampleRate($source), 'Test fixture is not at the rate it claims.');

        $result = app(PadSceneAudio::class)->handle($source, $output, $frames);

        $expected = $frames * PadSceneAudio::samplesPerFrame($renderRate, $fps);

        // handle() already throws if this is wrong; asserted anyway so a
        // regression reads as a failed expectation rather than an exception.
        $this->assertSame($expected, $ffmpeg->sampleCount($output, deep: true));
        $this->assertSame($renderRate, $ffmpeg->sampleRate($output));
        $this->assertSame($sourceRate, $result['source_rate']);

        // The padding must be a rounding margin, not a rate error. The bug's
        // signature is padding on the order of the whole scene; correct padding
        // here is the 10% the fixture is deliberately short by, and never
        // negative.
        $this->assertGreaterThanOrEqual(0, $result['padding_samples']);
        $this->assertLessThan(
            $expected,
            $result['padding_samples'],
            'Padding of a whole scene is the sample-rate domain bug, not a rounding margin.',
        );
    }
}
