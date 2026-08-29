<?php

namespace App\Console\Commands;

use App\Actions\RenderSceneClip;
use App\Enums\MotionPreset;
use App\Services\Ffmpeg;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Drives step 1 of the render pipeline over a fixture set.
 *
 * Deliberately stops at scene clips. Concat and mux are steps 2 and 3 and are
 * not built yet — this command exists so step 1 can be proven on its own.
 *
 * Rendering is CPU-bound. This runs the clips sequentially; the fan-out across
 * workers is a Phase 1 concern.
 */
class RenderClips extends Command
{
    protected $signature = 'render:clips
        {fixture=sample-story : Fixture directory on the fixtures disk.}
        {--scene=* : Render only these scene numbers.}
        {--force : Re-render clips that already exist.}
        {--skip-verify : Skip the frame-count check entirely.}
        {--shallow-verify : Read the declared frame count per clip instead of decoding it.}';

    protected $description = 'Render Ken Burns scene clips (step 1) from a fixture set.';

    public function handle(Ffmpeg $ffmpeg, RenderSceneClip $renderer): int
    {
        $fixtures = Storage::disk('fixtures');
        $fixture = trim((string) $this->argument('fixture'), '/');

        if (! $fixtures->exists("{$fixture}/timings.json")) {
            $this->error("No timings.json under fixtures/{$fixture}. Run fixtures:make first.");

            return self::FAILURE;
        }

        $timings = json_decode($fixtures->get("{$fixture}/timings.json"), true);

        if (! is_array($timings) || ! isset($timings['scenes'])) {
            $this->error("fixtures/{$fixture}/timings.json is not readable as a scene manifest.");

            return self::FAILURE;
        }

        $only = array_map('intval', (array) $this->option('scene'));
        $scenes = array_values(array_filter(
            $timings['scenes'],
            fn (array $scene): bool => $only === [] || in_array($scene['sequence'], $only, true),
        ));

        if ($scenes === []) {
            $this->error('No scenes matched.');

            return self::FAILURE;
        }

        $sourceRoot = str_replace('\\', '/', $fixtures->path($fixture));
        $outputRoot = str_replace('\\', '/', Storage::disk('renders')->path("{$fixture}/clips"));

        $this->line($ffmpeg->version());
        $this->line("  source : {$sourceRoot}");
        $this->line("  output : {$outputRoot}");
        $this->newLine();

        $rows = [];
        $failures = 0;
        $startedAt = microtime(true);

        foreach ($scenes as $scene) {
            $sequence = (int) $scene['sequence'];
            $slug = sprintf('scene-%03d', $sequence);
            $output = "{$outputRoot}/{$slug}.mp4";

            $expected = RenderSceneClip::framesFor(
                (int) $scene['duration_ms'],
                (int) config('render.video.fps')
            );

            // Idempotent by default: re-running must not redo finished work.
            if (file_exists($output) && ! $this->option('force')) {
                $rows[] = [$slug, $scene['motion_preset'], $scene['duration_ms'], $expected, '-', 'skipped', '-', '-', '-'];

                continue;
            }

            $this->output->write(sprintf('  %s  %-10s ', $slug, $scene['motion_preset']));

            try {
                $result = $renderer->handle(
                    imagePath: "{$sourceRoot}/{$scene['image']}",
                    outputPath: $output,
                    audioDurationMs: (int) $scene['duration_ms'],
                    motion: MotionPreset::from($scene['motion_preset']),
                );
            } catch (Throwable $e) {
                $failures++;
                $this->output->writeln('<error>FAILED</error>');
                $this->line('    '.str_replace("\n", "\n    ", $e->getMessage()));
                $rows[] = [$slug, $scene['motion_preset'], $scene['duration_ms'], $expected, '-', 'FAILED', '-', '-', '-'];

                continue;
            }

            $actual = $this->option('skip-verify')
                ? null
                // Deep by default here, and only here: a scene clip is seconds
                // long, its frame count is what every downstream offset is built
                // from, and zoompan disagreeing with d= is exactly the failure
                // this catches. The unaffordable decode is the full-length one.
                : $ffmpeg->frameCount($output, deep: ! $this->option('shallow-verify'));
            $matches = $actual === null ? 'unverified' : ($actual === $expected ? 'ok' : 'MISMATCH');

            if ($matches === 'MISMATCH') {
                $failures++;
            }

            $this->output->writeln(sprintf(
                '%6.1fs  %d frames  %s',
                $result['elapsed_seconds'],
                $actual ?? $expected,
                $matches
            ));

            $rows[] = [
                $slug,
                $scene['motion_preset'],
                $result['audio_duration_ms'],
                $expected,
                $actual ?? '-',
                $matches,
                sprintf('%.1f', $result['clip_duration_ms']),
                sprintf('%+.1f', $result['padding_ms']),
                $result['elapsed_seconds'].'s',
            ];
        }

        $this->newLine();
        $this->table(
            [
                'clip', 'motion', 'audio ms', 'frames (ceil)', 'frames (actual)',
                'check', 'clip ms', 'pad ms', 'render time',
            ],
            $rows
        );

        $this->line(sprintf('Total wall clock: %.1fs', microtime(true) - $startedAt));

        // The padding total is what the mux stage will have to add as silence.
        // Reported here so a surprise is visible now rather than at step 3.
        $padding = array_sum(array_map(
            fn (array $row): float => is_numeric($row[7]) ? (float) $row[7] : 0.0,
            $rows
        ));

        $this->line(sprintf(
            'Silence the mux will add across %d clips: %.1f ms (max one frame = %.1f ms each)',
            count($rows),
            $padding,
            1000 / (int) config('render.video.fps')
        ));

        if ($failures > 0) {
            $this->error("{$failures} clip(s) failed or mismatched.");

            return self::FAILURE;
        }

        $this->info('All clips rendered.');

        return self::SUCCESS;
    }
}
