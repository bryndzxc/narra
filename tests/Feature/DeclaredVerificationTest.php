<?php

namespace Tests\Feature;

use App\Services\Ffmpeg;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Verification defaults to reading what the container declares rather than
 * decoding the file, because decoding a finished 58-minute render cost 404 s —
 * a third of the mux stage, on every render.
 *
 * That default is only safe while the declared numbers actually equal the
 * decoded ones. This is the test that keeps it honest: it runs both paths over
 * real render output and asserts they agree. If a future container or encoder
 * change makes nb_frames unreliable, this fails and the default has to be
 * reconsidered — rather than a render quietly being verified against fiction.
 *
 * Tagged `render` since it needs FFmpeg and real output on disk.
 */
#[Group('render')]
class DeclaredVerificationTest extends TestCase
{
    private const FIXTURE = 'sample-story';

    public function test_declared_and_decoded_frame_counts_agree_on_a_real_render(): void
    {
        $ffmpeg = app(Ffmpeg::class);
        $video = $this->renderArtifact('silent.mp4');

        $this->assertSame(
            $ffmpeg->decodedFrameCount($video),
            $ffmpeg->declaredFrameCount($video),
            'The container is declaring a frame count the stream does not hold. '
            .'Declared verification is no longer safe as a default.'
        );
    }

    public function test_declared_and_decoded_sample_counts_agree_on_the_pcm_master(): void
    {
        $ffmpeg = app(Ffmpeg::class);
        $audio = $this->renderArtifact('narration.wav');

        // WAV declares its length in sample units, so this is not a rounding
        // question — the exact-duration assertion at concat rests on it.
        $this->assertSame(
            $ffmpeg->decodedSampleCount($audio),
            $ffmpeg->presentedSampleCount($audio),
            'Declared and decoded PCM lengths differ, which should be impossible.'
        );
    }

    public function test_the_default_path_does_not_decode(): void
    {
        $probe = new class('ffmpeg', 'ffprobe', 60) extends Ffmpeg
        {
            public int $decodeCalls = 0;

            public function decodedFrameCount(string $file): int
            {
                $this->decodeCalls++;

                return 0;
            }

            public function declaredFrameCount(string $file): int
            {
                return 1;
            }
        };

        config(['render.verify.deep' => false]);
        $this->assertSame(1, $probe->frameCount('anything'));
        $this->assertSame(0, $probe->decodeCalls);

        // The flag is the operator's escape hatch when a render looks wrong.
        config(['render.verify.deep' => true]);
        $probe->frameCount('anything');
        $this->assertSame(1, $probe->decodeCalls);
    }

    private function renderArtifact(string $name): string
    {
        $path = str_replace('\\', '/', Storage::disk('renders')->path(self::FIXTURE.'/'.$name));

        if (! is_readable($path)) {
            $this->markTestSkipped("Missing {$name}. Run `php artisan render:concat` first.");
        }

        return $path;
    }
}
