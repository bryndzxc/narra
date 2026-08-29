<?php

namespace Tests\Unit;

use App\Actions\MuxFinalVideo;
use App\Services\Ffmpeg;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * The final file ships AAC, and the verification that proves it is sound reads
 * the container rather than decoding 40 minutes.
 *
 * Both are decisions rather than accidents, so both are pinned here:
 *
 *  - AAC because 192k mono is transparent for narration, YouTube re-encodes on
 *    ingest anyway, and PCM doubles the upload for no audible gain. The known
 *    cost is a sub-millisecond delta at the very end of the file. PCM stays
 *    reachable behind render.audio.master_codec for the day it is wanted.
 *  - Declared verification because decoding a finished 58-minute render took
 *    404 s — a third of the mux stage — on every render.
 */
class MuxAudioCodecTest extends TestCase
{
    public function test_the_default_master_is_aac_at_the_configured_bitrate(): void
    {
        $spy = $this->spyFfmpeg();

        $this->mux()->handle(...$this->inputs());

        $this->assertSame('aac', $this->valueAfter($spy->captured, '-c:a'));
        $this->assertSame('192k', $this->valueAfter($spy->captured, '-b:a'));
    }

    public function test_the_codec_flag_switches_the_master_to_pcm(): void
    {
        config(['render.audio.master_codec' => 'pcm']);

        $spy = $this->spyFfmpeg();

        $this->mux()->handle(...$this->inputs());

        $this->assertSame('pcm_s16le', $this->valueAfter($spy->captured, '-c:a'));

        // A bitrate is meaningless for PCM, and passing one invites FFmpeg to
        // interpret it against a codec that has no such setting.
        $this->assertNotContains('-b:a', $spy->captured);
    }

    public function test_an_unknown_master_codec_fails_before_a_multi_hour_encode(): void
    {
        config(['render.audio.master_codec' => 'flac']);

        $this->spyFfmpeg();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Expected 'aac' or 'pcm'");

        $this->mux()->handle(...$this->inputs());
    }

    public function test_a_pcm_master_gets_no_one_frame_allowance(): void
    {
        config(['render.audio.master_codec' => 'pcm']);

        // 29 samples short: the measured AAC boundary artifact. Under PCM there
        // is no lossy codec to blame it on, so it must not be waved through.
        $spy = $this->spyFfmpeg(presentedSamples: 100 * 1470 - 29);

        $result = $this->mux()->handle(...$this->inputs());

        $this->assertFalse($result['audio_exact']);
        $this->assertFalse($result['audio_within_one_aac_frame']);
        $this->assertSame(-29, $result['audio_delta_samples']);
        $this->assertSame($spy->presentedSamples, $result['audio_samples']);
    }

    public function test_aac_tolerates_the_terminal_boundary_artifact(): void
    {
        $this->spyFfmpeg(presentedSamples: 100 * 1470 - 29);

        $result = $this->mux()->handle(...$this->inputs());

        $this->assertFalse($result['audio_exact']);
        $this->assertTrue($result['audio_within_one_aac_frame']);
        $this->assertEqualsWithDelta(-0.6576, $result['audio_delta_ms'], 0.0001);
    }

    public function test_verification_reads_the_container_and_does_not_decode(): void
    {
        $spy = $this->spyFfmpeg();

        $result = $this->mux()->handle(...$this->inputs());

        $this->assertSame('declared', $result['verified_by']);
        $this->assertSame(0, $spy->decodeCalls, 'A healthy render must not pay for a full decode.');
        $this->assertNull($result['decoded_samples']);
        $this->assertNull($result['aac_padding_samples']);
    }

    public function test_deep_verification_decodes_on_request(): void
    {
        $spy = $this->spyFfmpeg();

        $result = $this->mux()->handle(...[...$this->inputs(), 'deep' => true]);

        $this->assertSame('decoded', $result['verified_by']);

        // Frames and samples, both by decoding — the slow path, on request.
        $this->assertSame(2, $spy->decodeCalls);
        $this->assertSame(147000, $result['decoded_samples']);
    }

    private function mux(): MuxFinalVideo
    {
        return app(MuxFinalVideo::class);
    }

    /**
     * A mux whose inputs exist and whose output never will — the spy answers
     * every question about the result, so nothing has to be encoded.
     *
     * @return array<string, mixed>
     */
    private function inputs(): array
    {
        $existing = __FILE__;

        return [
            'silentPath' => $existing,
            'audioPath' => $existing,
            'assPath' => $existing,
            'outputPath' => sys_get_temp_dir().'/never-written.mp4',
            'expectedFrames' => 100,
            'force' => true,
        ];
    }

    /**
     * @param  array<int, string>  $arguments
     */
    private function valueAfter(array $arguments, string $flag): string
    {
        $index = array_search($flag, $arguments, true);

        $this->assertNotFalse($index, "Expected {$flag} in the FFmpeg arguments.");

        return $arguments[$index + 1];
    }

    private function spyFfmpeg(?int $presentedSamples = null): Ffmpeg
    {
        $spy = new class('ffmpeg', 'ffprobe', 60) extends Ffmpeg
        {
            /** @var array<int, string> */
            public array $captured = [];

            public int $decodeCalls = 0;

            public int $presentedSamples = 147000;

            public function run(array $arguments, int $timeout, ?callable $onOutput = null): string
            {
                $this->captured = $arguments;

                return '';
            }

            public function declaredFrameCount(string $file): int
            {
                return 100;
            }

            public function decodedFrameCount(string $file): int
            {
                $this->decodeCalls++;

                return 100;
            }

            public function presentedSampleCount(string $file): int
            {
                return $this->presentedSamples;
            }

            public function decodedSampleCount(string $file): int
            {
                $this->decodeCalls++;

                return 147000;
            }
        };

        if ($presentedSamples !== null) {
            $spy->presentedSamples = $presentedSamples;
        }

        $this->app->instance(Ffmpeg::class, $spy);

        return $spy;
    }
}
