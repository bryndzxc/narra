<?php

namespace App\Console\Commands;

use App\Actions\ConcatSceneAudio;
use App\Actions\ConcatVideoClips;
use App\Actions\PadSceneAudio;
use App\Actions\RenderSceneClip;
use App\Services\Ffmpeg;
use App\Support\SceneAudioManifest;
use App\Support\SceneTimeline;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Step 2 of the render pipeline: concat.
 *
 * Both streams, because the exact-duration assertion needs padded audio to
 * exist before it can mean anything.
 *
 * Deliberately stops before mux and subtitles.
 */
class RenderConcat extends Command
{
    protected $signature = 'render:concat
        {fixture=sample-story : Fixture directory on the fixtures disk.}
        {--force : Rebuild padded audio and joined output that already exist.}
        {--deep : Verify the joined files by decoding rather than by reading the container.}';

    protected $description = 'Concat scene clips and padded scene audio (step 2), asserting exact durations.';

    public function handle(
        Ffmpeg $ffmpeg,
        PadSceneAudio $padder,
        ConcatVideoClips $concatVideo,
        ConcatSceneAudio $concatAudio,
    ): int {
        $fixtures = Storage::disk('fixtures');
        $fixture = trim((string) $this->argument('fixture'), '/');

        if (! $fixtures->exists("{$fixture}/timings.json")) {
            $this->error("No timings.json under fixtures/{$fixture}. Run fixtures:make first.");

            return self::FAILURE;
        }

        $timings = json_decode($fixtures->get("{$fixture}/timings.json"), true);
        $scenes = $timings['scenes'] ?? [];

        if ($scenes === []) {
            $this->error('Fixture manifest has no scenes.');

            return self::FAILURE;
        }

        $sourceRoot = str_replace('\\', '/', $fixtures->path($fixture));
        $renderRoot = str_replace('\\', '/', Storage::disk('renders')->path($fixture));

        $fps = (int) config('render.video.fps');
        $rate = (int) config('render.audio.sample_rate');
        $samplesPerFrame = PadSceneAudio::samplesPerFrame($rate, $fps);
        $deep = $this->option('deep') ? true : null;

        $this->line("  clips  : {$renderRoot}/clips");
        $this->line("  output : {$renderRoot}");
        $this->line(sprintf('  %d fps, %d Hz, %d samples per frame', $fps, $rate, $samplesPerFrame));
        $this->newLine();

        try {
            $plan = $this->buildPlan($ffmpeg, $scenes, $sourceRoot, $renderRoot, $fps, $samplesPerFrame, $deep);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        // ---- Pad each scene to exactly frames / fps --------------------------
        $this->line('Padding scene audio to frame boundaries...');

        foreach ($plan as $i => $scene) {
            if (file_exists($scene['padded_path']) && ! $this->option('force')
                && $ffmpeg->sampleCount($scene['padded_path']) === $scene['padded_samples']) {
                $plan[$i]['padding_ms'] = null; // already correct, left alone

                continue;
            }

            try {
                $result = $padder->handle($scene['source_audio'], $scene['padded_path'], $scene['frames']);
            } catch (Throwable $e) {
                $this->error($e->getMessage());

                return self::FAILURE;
            }

            $plan[$i]['padding_ms'] = $result['padding_ms'];
            $plan[$i]['source_samples'] = $result['source_samples'];
        }

        $this->info('  all scenes padded exactly.');
        $this->newLine();

        // ---- Concat both streams ---------------------------------------------
        try {
            $video = $concatVideo->handle(
                clipPaths: array_column($plan, 'clip_path'),
                expectedFrames: array_column($plan, 'frames'),
                outputPath: "{$renderRoot}/silent.mp4",
                listPath: "{$renderRoot}/clips.txt",
                deep: $deep,
            );

            $this->info(sprintf(
                'Video: %d clips -> silent.mp4, %d frames, %.6f s',
                $video['clips'], $video['actual_frames'], $video['duration_seconds']
            ));

            $audio = $concatAudio->handle(
                paddedPaths: array_column($plan, 'padded_path'),
                expectedSamples: array_column($plan, 'padded_samples'),
                wavPath: "{$renderRoot}/narration.wav",
                mp3Path: "{$renderRoot}/narration.mp3",
                listPath: "{$renderRoot}/narration.txt",
                deep: $deep,
            );

            $this->info(sprintf(
                'Audio: %d scenes -> narration.wav, %d samples, %.6f s',
                $audio['scenes'], $audio['actual_samples'], $audio['duration_seconds']
            ));
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        // ---- The exact assertion ---------------------------------------------
        //
        // Compared as integers, cross-multiplied, so no float ever enters the
        // decision: samples/rate == frames/fps  <=>  samples*fps == frames*rate.
        $lhs = $audio['actual_samples'] * $fps;
        $rhs = $video['actual_frames'] * $rate;

        $this->newLine();
        $this->line(sprintf('Exact duration assertion (integer, no tolerance, verified by %s):', $video['verified_by']));
        $this->line(sprintf('  audio_samples * fps = %d * %d = %d', $audio['actual_samples'], $fps, $lhs));
        $this->line(sprintf('  video_frames  * rate = %d * %d = %d', $video['actual_frames'], $rate, $rhs));

        if ($lhs !== $rhs) {
            $this->error(sprintf(
                'MISMATCH of %+d (%.4f ms). With padding this is a by-construction guarantee, '
                .'so this is a bug, not tolerance.',
                $lhs - $rhs,
                ($audio['actual_samples'] / $rate - $video['actual_frames'] / $fps) * 1000
            ));

            return self::FAILURE;
        }

        $this->info('  EXACT MATCH.');
        $this->newLine();

        $this->writeManifest($fixture, $plan, $video, $audio, $fps, $rate);
        $this->report($plan, $video, $audio, $rate, $fps);

        return self::SUCCESS;
    }

    /**
     * Resolve every scene into the numbers the rest of the command works from,
     * and refuse to continue if step 1's output disagrees with them.
     *
     * @param  array<int, array<string, mixed>>  $scenes
     * @return array<int, array<string, mixed>>
     */
    private function buildPlan(
        Ffmpeg $ffmpeg,
        array $scenes,
        string $sourceRoot,
        string $renderRoot,
        int $fps,
        int $samplesPerFrame,
        ?bool $deep = null,
    ): array {
        $resolved = [];

        foreach ($scenes as $scene) {
            $slug = sprintf('scene-%03d', $scene['sequence']);
            $clip = "{$renderRoot}/clips/{$slug}.mp4";

            if (! is_readable($clip)) {
                throw new \RuntimeException("Missing clip {$clip}. Run render:clips first.");
            }

            $audioMs = (int) $scene['duration_ms'];
            $frames = RenderSceneClip::framesFor($audioMs, $fps);
            $actualFrames = $ffmpeg->frameCount($clip, $deep);

            // The padding target is derived from the frame count, so a stale
            // clip would pad the audio to the wrong length and desync silently.
            if ($actualFrames !== $frames) {
                throw new \RuntimeException(sprintf(
                    '%s holds %d frames but its audio needs %d. The clip is stale — '
                    .'re-run `render:clips --force`.',
                    $slug, $actualFrames, $frames
                ));
            }

            $resolved[] = [
                'sequence' => (int) $scene['sequence'],
                'act_sequence' => (int) $scene['act_sequence'],
                'slug' => $slug,
                'clip_path' => $clip,
                'source_audio' => "{$sourceRoot}/{$scene['audio']}",
                'padded_path' => "{$renderRoot}/padded/{$slug}.wav",
                'audio_duration_ms' => $audioMs,
                'frames' => $frames,
                'padding_ms' => null,
                'source_samples' => null,
            ];
        }

        // Offsets, padded durations and totals all come from here so the
        // arithmetic lives in one tested place rather than inline in a command.
        $timeline = new SceneTimeline(
            array_column($resolved, 'frames'),
            $fps,
            $samplesPerFrame * $fps,
        );

        foreach ($timeline->entries() as $i => $entry) {
            $resolved[$i] = array_merge($resolved[$i], $entry);
        }

        return $resolved;
    }

    /**
     * @param  array<int, array<string, mixed>>  $plan
     * @param  array<string, mixed>  $video
     * @param  array<string, mixed>  $audio
     */
    private function writeManifest(string $fixture, array $plan, array $video, array $audio, int $fps, int $rate): void
    {
        // The encoder lives in SceneAudioManifest so the queued concat job and
        // this command emit identical bytes rather than two careful copies.
        Storage::disk('renders')->put("{$fixture}/scene_audio.json", SceneAudioManifest::encode(
            $plan,
            $fps,
            $rate,
            (int) $video['actual_frames'],
            (int) $audio['actual_samples'],
        ));
    }

    /**
     * @param  array<int, array<string, mixed>>  $plan
     * @param  array<string, mixed>  $video
     * @param  array<string, mixed>  $audio
     */
    private function report(array $plan, array $video, array $audio, int $rate, int $fps): void
    {
        $rows = [];
        $naive = 0;
        $worstDivergence = 0;

        foreach ($plan as $scene) {
            // What offset_ms would have been if it were computed the naive way,
            // by summing already-rounded padded_duration_ms values.
            $divergence = $naive - $scene['offset_ms'];
            $worstDivergence = max($worstDivergence, abs($divergence));

            $rows[] = [
                $scene['slug'],
                $scene['audio_duration_ms'],
                $scene['frames'],
                $scene['padded_duration_ms'],
                $scene['offset_ms'],
                $scene['offset_frames'],
                $scene['offset_samples'],
                $scene['padding_ms'] === null ? 'kept' : sprintf('%+.1f', $scene['padding_ms']),
            ];

            $naive += $scene['padded_duration_ms'];
        }

        $this->table(
            ['scene', 'audio ms', 'frames', 'padded ms', 'offset ms', 'offset frames', 'offset samples', 'pad ms'],
            $rows
        );

        $this->line(sprintf(
            'Naive offsets (summing rounded padded_duration_ms) would diverge by up to %d ms; '
            .'offset_ms is derived from exact cumulative frames instead.',
            $worstDivergence
        ));

        $this->newLine();
        $this->line(sprintf('  total frames   : %d', $video['actual_frames']));
        $this->line(sprintf('  video duration : %.6f s', $video['duration_seconds']));
        $this->line(sprintf('  audio duration : %.6f s', $audio['duration_seconds']));
        $this->line(sprintf('  total samples  : %d', $audio['actual_samples']));

        $this->newLine();
        $this->line(sprintf(
            'narration.mp3 (single encode from the exact PCM): %d samples %s, %+d vs target (%+.1f ms).',
            $audio['mp3_samples'],
            // Worth naming: a declared MP3 length is the presented one, and an
            // MP3 always decodes to MORE than it presents — encoder delay plus
            // padding. Read `+0` here as "the container agrees with the PCM",
            // not as "MP3 carries sample counts exactly". Run --deep for the
            // decoded number, which is the one that made concatenating padded
            // MP3s a bad idea in the first place.
            $audio['verified_by'],
            $audio['mp3_delta_samples'],
            $audio['mp3_delta_samples'] / ($rate / 1000)
        ));
    }
}
