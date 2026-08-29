<?php

namespace App\Console\Commands;

use App\Actions\MuxFinalVideo;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Step 3b: produce final.mp4.
 */
class RenderMux extends Command
{
    protected $signature = 'render:mux
        {fixture=sample-story : Fixture directory on the fixtures disk.}
        {--force : Re-encode even if a complete final.mp4 already exists.}
        {--deep : Verify by decoding every packet instead of reading the container. Minutes, not milliseconds.}';

    protected $description = 'Mux padded narration and burned-in subtitles onto the silent video (step 3b).';

    public function handle(MuxFinalVideo $mux): int
    {
        $renders = Storage::disk('renders');
        $fixture = trim((string) $this->argument('fixture'), '/');
        $root = str_replace('\\', '/', $renders->path($fixture));

        $inputs = [
            'silent.mp4' => "{$root}/silent.mp4",
            'narration.wav' => "{$root}/narration.wav",
            'subs.ass' => "{$root}/subs.ass",
        ];

        foreach ($inputs as $label => $path) {
            if (! is_readable($path)) {
                $this->error("Missing {$label}. Run render:concat and render:subtitles first.");

                return self::FAILURE;
            }
        }

        if (! $renders->exists("{$fixture}/scene_audio.json")) {
            $this->error('Missing scene_audio.json. Run render:concat first.');

            return self::FAILURE;
        }

        $manifest = json_decode($renders->get("{$fixture}/scene_audio.json"), true);
        $expectedFrames = (int) $manifest['story']['total_frames'];

        $this->line('Muxing (re-encodes the full video — this is the longest step)...');

        try {
            $result = $mux->handle(
                silentPath: $inputs['silent.mp4'],
                audioPath: $inputs['narration.wav'],
                assPath: $inputs['subs.ass'],
                outputPath: "{$root}/final.mp4",
                expectedFrames: $expectedFrames,
                force: (bool) $this->option('force'),
                deep: $this->option('deep') ? true : null,
            );
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $fps = (int) config('render.video.fps');
        $rate = (int) config('render.audio.sample_rate');

        if ($result['skipped_encode']) {
            $this->line('  (existing final.mp4 already holds the expected frame count — verified, not re-encoded)');
        }

        $this->newLine();
        $this->line('  output         : '.$result['output_path']);
        $this->line(sprintf('  audio codec    : %s', $result['codec']));
        $this->line(sprintf(
            '  verified by    : %s%s',
            $result['verified_by'],
            $result['verified_by'] === 'declared' ? ' (container metadata — pass --deep to decode)' : ' (full decode)'
        ));
        $this->line(sprintf('  frames         : %d (expected %d)', $result['frames'], $result['expected_frames']));
        $this->line(sprintf('  video duration : %.6f s', $result['video_duration_seconds']));
        $this->line(sprintf('  audio duration : %.6f s', $result['audio_duration_seconds']));
        $this->line(sprintf('  audio samples  : %d (presented, after the edit list)', $result['audio_samples']));
        if ($result['decoded_samples'] !== null) {
            $this->line(sprintf(
                '  raw decode     : %d (+%d samples of priming and frame padding, never rendered)',
                $result['decoded_samples'],
                $result['aac_padding_samples']
            ));
        }

        if (! $result['skipped_encode']) {
            $this->line(sprintf('  encode time    : %.1f s', $result['elapsed_seconds']));
        }

        // Video: exact, and required to be.
        $this->newLine();
        $this->line('Video frame count (exact, no tolerance):');
        $this->line(sprintf('  %d frames in, %d frames out', $result['expected_frames'], $result['frames']));

        if (! $result['frames_exact']) {
            $this->error(sprintf('  MISMATCH of %+d frames.', $result['frames'] - $result['expected_frames']));

            return self::FAILURE;
        }

        $this->info('  EXACT.');

        // Audio: bounded by the codec, so state the bound rather than pretend.
        $this->newLine();
        $this->line('Audio against the exact PCM (integer cross-multiplication):');
        $this->line(sprintf('  audio_samples * fps  = %d * %d = %d', $result['audio_samples'], $fps, $result['audio_samples'] * $fps));
        $this->line(sprintf('  video_frames  * rate = %d * %d = %d', $result['frames'], $rate, $result['frames'] * $rate));

        if ($result['audio_exact']) {
            $this->info('  EXACT MATCH.');

            return self::SUCCESS;
        }

        if ($result['codec'] !== 'aac') {
            $this->error(sprintf(
                '  MISMATCH of %+d samples (%+.4f ms). A %s master carries the sample count '
                .'verbatim, so there is no codec quantisation to blame.',
                $result['audio_delta_samples'],
                $result['audio_delta_ms'],
                $result['codec']
            ));

            return self::FAILURE;
        }

        if (! $result['audio_within_one_aac_frame']) {
            $this->error(sprintf(
                '  MISMATCH of %+d samples (%+.4f ms) — beyond one AAC frame, so this is not codec quantisation.',
                $result['audio_delta_samples'],
                $result['audio_delta_ms']
            ));

            return self::FAILURE;
        }

        // Within one AAC frame. The exact guarantee lives at concat, in PCM,
        // where it is structural; this is the terminal cost of the single
        // lossy encode and does not accumulate.
        $this->warn(sprintf(
            '  %+d samples (%+.4f ms), within one AAC frame (1024 samples / 23.22 ms).',
            $result['audio_delta_samples'],
            $result['audio_delta_ms']
        ));
        $this->line('  AAC encodes in 1024-sample frames with a priming delay, so a sample-exact');
        $this->line('  duration is not achievable in this codec. Sync is guaranteed upstream: the');
        $this->line('  padded PCM concat asserts exactly, per scene, and this residual is a single');
        $this->line('  terminal boundary artifact rather than accumulating drift.');
        $this->line('  AAC ships deliberately: 192k mono is transparent for narration, YouTube');
        $this->line('  re-encodes on ingest anyway, and a PCM master would roughly double the');
        $this->line('  upload for no audible gain. Set RENDER_MASTER_CODEC=pcm if a sample-exact');
        $this->line('  master is ever wanted.');

        return self::SUCCESS;
    }
}
