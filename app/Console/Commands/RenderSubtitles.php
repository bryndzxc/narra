<?php

namespace App\Console\Commands;

use App\Actions\GenerateAssSubtitles;
use App\Actions\VerifyAssSubtitles;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Step 3a: write subs.ass and verify it against the source manifests.
 *
 * Generation only. Nothing is burned in here.
 */
class RenderSubtitles extends Command
{
    protected $signature = 'render:subtitles
        {fixture=sample-story : Fixture directory on the fixtures disk.}
        {--preview=20 : Lines of the written file to print.}';

    protected $description = 'Generate the karaoke .ass subtitle file (step 3a) and verify it.';

    public function handle(GenerateAssSubtitles $generator, VerifyAssSubtitles $verifier): int
    {
        $fixtures = Storage::disk('fixtures');
        $renders = Storage::disk('renders');
        $fixture = trim((string) $this->argument('fixture'), '/');

        foreach (["{$fixture}/timings.json" => $fixtures, "{$fixture}/scene_audio.json" => $renders] as $file => $disk) {
            if (! $disk->exists($file)) {
                $this->error("Missing {$file}. Run fixtures:make and render:concat first.");

                return self::FAILURE;
            }
        }

        $timings = json_decode($fixtures->get("{$fixture}/timings.json"), true);
        $manifest = json_decode($renders->get("{$fixture}/scene_audio.json"), true);

        // Keyed by sequence so a reordered manifest cannot quietly misalign.
        $sceneAudio = [];
        foreach ($manifest['scenes'] as $entry) {
            $sceneAudio[(int) $entry['scene_sequence']] = $entry;
        }

        $outputPath = str_replace('\\', '/', $renders->path("{$fixture}/subs.ass"));

        // Sampled either side of generation only — the manifests are already
        // decoded above, so this isolates the writer rather than the JSON load.
        $peakBefore = memory_get_peak_usage(true);

        try {
            $result = $generator->handle(
                scenes: $timings['scenes'],
                sceneAudio: $sceneAudio,
                outputPath: $outputPath,
                title: (string) ($timings['story']['title'] ?? 'Narra'),
            );
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $peakAfter = memory_get_peak_usage(true);

        $this->line(sprintf(
            'Wrote %s — %d lines, %d words, %s bytes.',
            $result['output_path'],
            $result['lines'],
            $result['words'],
            number_format($result['bytes'])
        ));

        // The writer streams, so peak memory should be flat in the number of
        // words. At ~6,000 words a string-concatenating writer would show up
        // here as a growing delta; a streaming one should not move.
        $this->line(sprintf(
            'Memory: %.1f MB peak, %.1f MB attributable to generation (%d words, %d spacers, longest chunk %d).',
            $peakAfter / 1048576,
            max(0, $peakAfter - $peakBefore) / 1048576,
            $result['words'],
            $result['spacers'],
            $result['longest_chunk'],
        ));

        // The video is the authority on total duration, from the concat stage.
        // Passed as frames rather than milliseconds so the check can compare at
        // ASS's own centisecond resolution instead of rounding twice.
        $totalFrames = (int) $manifest['story']['total_frames'];
        $fps = (int) config('render.video.fps');

        $this->newLine();
        $this->line('Verifying the written file against timings.json and scene_audio.json:');
        $this->newLine();

        try {
            $checks = $verifier->handle($outputPath, $timings['scenes'], $sceneAudio, $totalFrames, $fps);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $failed = 0;

        foreach ($checks as $name => $check) {
            $this->line(sprintf(
                '  [%s] %-28s %s',
                $check['pass'] ? 'PASS' : 'FAIL',
                $name,
                $check['detail']
            ));

            foreach ($check['failures'] as $failure) {
                $this->line('         - '.$failure);
            }

            if (! $check['pass']) {
                $failed++;
            }
        }

        $this->newLine();

        if ($failed > 0) {
            $this->error("{$failed} check(s) failed.");

            return self::FAILURE;
        }

        $this->info(sprintf('All %d checks passed.', count($checks)));
        $this->newLine();

        $this->preview($outputPath, (int) $this->option('preview'));

        return self::SUCCESS;
    }

    private function preview(string $path, int $count): void
    {
        $this->line("First {$count} lines of ".basename($path).':');
        $this->newLine();

        $handle = fopen($path, 'rb');

        if ($handle === false) {
            return;
        }

        try {
            for ($i = 1; $i <= $count && ($line = fgets($handle)) !== false; $i++) {
                $this->line(sprintf('%3d | %s', $i, rtrim($line, "\r\n")));
            }
        } finally {
            fclose($handle);
        }
    }
}
