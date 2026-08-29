<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

/**
 * Builds the Phase 0 fixture set: numbered stills, matching per-scene audio,
 * word-level timings, and act boundaries.
 *
 * Nothing here touches the network and nothing here costs money. The stills are
 * drawn with GD; the audio is synthesised by FFmpeg from lavfi sources.
 *
 * The scene pool below is 12 entries. Asking for more cycles the pool, which is
 * how the spec's full-length (35 minute) sanity render gets its input:
 *
 *     php artisan fixtures:make --scenes=260 --path=long-story
 */
class MakeFixtures extends Command
{
    protected $signature = 'fixtures:make
        {--scenes=12 : How many scenes to emit. Values above the pool size cycle it.}
        {--path=sample-story : Destination, relative to the fixtures disk root.}
        {--force : Overwrite an existing fixture directory.}';

    protected $description = 'Generate the Phase 0 fixture set (PNGs, MP3s, timings.json, acts.json). Offline, free.';

    private const WIDTH = 1920;

    private const HEIGHT = 1080;

    private const FPS = 30;

    /** Words per second used to size a scene from its narration length. */
    private const WORDS_PER_SECOND = 2.4;

    /** Silence held before the first word and after the last, in ms. */
    private const LEAD_IN_MS = 300;

    private const LEAD_OUT_MS = 400;

    private const FONT = 'C:/Windows/Fonts/arialbd.ttf';

    /**
     * Three self-contained stories, four scenes each — the `anthology` format.
     * Act titles double as YouTube chapter titles, so they are written to work
     * as both. Placeholder prose, but US-set and free of the denylisted idiom
     * so the fixture never teaches the pipeline a bad habit.
     */
    private const ACTS = [
        [
            'title' => "The Last Shift at Miller's Diner",
            'summary' => 'A nineteen-year waitress works the final night of a diner that closed without telling her.',
            'colour' => [28, 42, 74],
        ],
        [
            'title' => 'What the Storm Left on Route 9',
            'summary' => 'A man drives into a flooded county with a truck bed of water and a list of names.',
            'colour' => [74, 34, 28],
        ],
        [
            'title' => 'The Girl Who Waited for the 6:15',
            'summary' => 'For eleven years a girl meets a train she never boards. On the last run, it waits for her.',
            'colour' => [30, 62, 44],
        ],
    ];

    private const SCENES = [
        // Act 1
        ['act' => 1, 'motion' => 'zoom_in', 'hook' => true, 'thumb' => true,
            'text' => 'Nobody told Dana that the diner was closing. She found out the way everyone in Cedar Falls found out anything, from a hand lettered sign taped to the inside of the front window.'],
        ['act' => 1, 'motion' => 'pan_left', 'hook' => false, 'thumb' => false,
            'text' => "She had worked the counter at Miller's for nineteen years. Long enough to know which regulars took their coffee black, and which ones only said they did."],
        ['act' => 1, 'motion' => 'zoom_out', 'hook' => false, 'thumb' => false,
            'text' => 'The last customer came in at four minutes to closing. Snow on his shoulders, a paperback in his coat pocket, and a twenty already folded in his hand.'],
        ['act' => 1, 'motion' => 'static', 'hook' => false, 'thumb' => false,
            'text' => 'He ordered the same thing he had ordered every Thursday since the fall of nineteen eighty six. Dana wrote it down anyway. Some things you write down so they stay real.'],

        // Act 2
        ['act' => 2, 'motion' => 'zoom_in', 'hook' => false, 'thumb' => false,
            'text' => 'The storm took the power lines first, then the bridge, and by Tuesday morning Route Nine ended in a wall of water where the Hollis farm used to be.'],
        ['act' => 2, 'motion' => 'pan_right', 'hook' => false, 'thumb' => true,
            'text' => 'Marcus drove out anyway. He had a truck bed full of bottled water and a list of names his mother had written on the back of a church bulletin.'],
        ['act' => 2, 'motion' => 'zoom_out', 'hook' => false, 'thumb' => false,
            'text' => 'Half the houses on the ridge were dark. The other half had candles in the windows, which in that county meant come in, we have coffee, stay as long as you need.'],
        ['act' => 2, 'motion' => 'zoom_in', 'hook' => false, 'thumb' => false,
            'text' => 'He found the Hollis boy sitting on a mailbox post, holding a dog that had no business surviving the night, and refusing, politely, to be rescued first.'],

        // Act 3
        ['act' => 3, 'motion' => 'pan_left', 'hook' => false, 'thumb' => false,
            'text' => 'Every weekday for eleven years, a girl in a yellow raincoat stood at the end of the platform and waited for the six fifteen train out of Trenton.'],
        ['act' => 3, 'motion' => 'static', 'hook' => false, 'thumb' => false,
            'text' => 'She never boarded it. The conductors knew her by sight, and after a while they stopped asking, the way you stop asking about weather you cannot change.'],
        ['act' => 3, 'motion' => 'zoom_in', 'hook' => false, 'thumb' => false,
            'text' => 'Her father had taken that train to work on a Tuesday morning and had not come home, and the schedule was the only thing about him that had stayed reliable.'],
        ['act' => 3, 'motion' => 'zoom_out', 'hook' => false, 'thumb' => false,
            'text' => 'On the last day the station ran that route, the six fifteen stopped anyway. It held the doors open for a full minute. Then it went on without her, and she let it.'],
    ];

    public function handle(): int
    {
        if (! extension_loaded('gd')) {
            $this->error('The gd extension is required to draw the fixture stills.');

            return self::FAILURE;
        }

        if (! is_readable(self::FONT)) {
            $this->error('Font not found: '.self::FONT);

            return self::FAILURE;
        }

        foreach (['ffmpeg', 'ffprobe'] as $binary) {
            if (! $this->binaryExists($binary)) {
                $this->error("{$binary} was not found on PATH.");

                return self::FAILURE;
            }
        }

        $disk = Storage::disk('fixtures');
        $relative = trim((string) $this->option('path'), '/');

        if ($disk->exists($relative) && ! $this->option('force')) {
            $this->error("{$relative} already exists. Pass --force to overwrite.");

            return self::FAILURE;
        }

        $disk->makeDirectory($relative);
        $root = str_replace('\\', '/', $disk->path($relative));

        $count = max(1, (int) $this->option('scenes'));
        $this->info("Writing {$count} scenes to {$root}");

        $scenes = [];
        $offsetMs = 0;
        $bar = $this->output->createProgressBar($count);
        $bar->start();

        for ($i = 0; $i < $count; $i++) {
            $spec = self::SCENES[$i % count(self::SCENES)];
            $sequence = $i + 1;
            $slug = sprintf('scene-%03d', $sequence);

            // Audio is generated first, then probed. Word timings are fitted to
            // the duration FFmpeg actually produced rather than the duration we
            // asked for — MP3 frame granularity makes those differ by a few ms,
            // and a fixture that disagrees with its own audio is worse than no
            // fixture at all.
            $targetSeconds = $this->targetSeconds($spec['text']);
            $this->writeTone($root."/{$slug}.mp3", $targetSeconds, $sequence);
            $durationMs = $this->probeDurationMs($root."/{$slug}.mp3");

            $this->writeStill(
                $root."/{$slug}.png",
                $sequence,
                $spec,
                self::ACTS[$spec['act'] - 1],
                $durationMs
            );

            $scenes[] = [
                'sequence' => $sequence,
                'act_sequence' => $spec['act'],
                'image' => "{$slug}.png",
                'audio' => "{$slug}.mp3",
                'is_hook' => $spec['hook'],
                'is_thumbnail_candidate' => $spec['thumb'],
                'motion_preset' => $spec['motion'],
                'duration_ms' => $durationMs,
                'duration_frames' => (int) ceil($durationMs / 1000 * self::FPS),
                'offset_ms' => $offsetMs,
                'narration_text' => $spec['text'],
                'words' => $this->timeWords($spec['text'], $durationMs),
            ];

            $offsetMs += $durationMs;
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $totalMs = $offsetMs;

        $disk->put("{$relative}/timings.json", $this->encode([
            'story' => [
                'title' => 'Sample Story (Phase 0 Fixture)',
                'format' => 'anthology',
                'locale_profile' => 'en-US',
                'voice_id' => 'fixture-en-us-narrator',
                'fps' => self::FPS,
                'width' => self::WIDTH,
                'height' => self::HEIGHT,
                'scene_count' => count($scenes),
                'total_duration_ms' => $totalMs,
            ],
            'scenes' => $scenes,
        ]));

        $disk->put("{$relative}/acts.json", $this->encode([
            'acts' => $this->buildActs($scenes),
        ]));

        $this->info(sprintf(
            'Done. %d scenes, %s total (%d ms).',
            count($scenes),
            $this->humanDuration($totalMs),
            $totalMs
        ));

        return self::SUCCESS;
    }

    /**
     * Act boundaries, derived from the scenes rather than declared separately —
     * the two can then never drift apart. start_ms and duration_ms are what the
     * metadata module turns into YouTube chapters.
     */
    private function buildActs(array $scenes): array
    {
        $acts = [];
        $previous = null;
        $cycles = [];

        foreach ($scenes as $scene) {
            $poolAct = (int) $scene['act_sequence'];

            // Grouped by CONSECUTIVE runs, not by act number. Above 12 scenes
            // the pool cycles and the act numbers repeat 1,2,3,1,2,3 — keying
            // on the number alone would merge every act-1 block in the video
            // into a single act with overlapping scene ranges and a duration
            // that double-counts. Chapters must be contiguous and disjoint.
            if ($poolAct !== $previous) {
                $definition = self::ACTS[$poolAct - 1];
                $cycles[$poolAct] = ($cycles[$poolAct] ?? 0) + 1;

                $acts[] = [
                    'sequence' => count($acts) + 1,
                    // Titles double as YouTube chapter titles and must be
                    // distinct, so a repeated pool entry is numbered.
                    'title' => $cycles[$poolAct] === 1
                        ? $definition['title']
                        : sprintf('%s (part %d)', $definition['title'], $cycles[$poolAct]),
                    'summary' => $definition['summary'],
                    // Act 1 opens on the 15-second hook; the rest open on a
                    // re-hook. Gate 1 surfaces this flag for review.
                    'is_rehook_written' => true,
                    'scene_from' => $scene['sequence'],
                    'scene_to' => $scene['sequence'],
                    'start_ms' => $scene['offset_ms'],
                    'duration_ms' => 0,
                ];

                $previous = $poolAct;
            }

            $index = count($acts) - 1;
            $acts[$index]['scene_to'] = $scene['sequence'];
            $acts[$index]['duration_ms'] += $scene['duration_ms'];
        }

        return $acts;
    }

    /** Size a scene from its word count, with a little breathing room. */
    private function targetSeconds(string $text): float
    {
        $words = count($this->splitWords($text));

        return round($words / self::WORDS_PER_SECOND + 1.2, 3);
    }

    /**
     * Distribute word timings across the real audio duration, weighting each
     * word by its length so the result varies the way speech does. Words are
     * contiguous — word N ends exactly where word N+1 begins — because ASS
     * karaoke {\k} durations must tile the line with no gaps.
     */
    private function timeWords(string $text, int $durationMs): array
    {
        $words = $this->splitWords($text);
        $speech = max(1, $durationMs - self::LEAD_IN_MS - self::LEAD_OUT_MS);

        $weights = array_map(fn (string $w): int => mb_strlen($w) + 1, $words);
        $totalWeight = array_sum($weights);

        $timed = [];
        $cursor = self::LEAD_IN_MS;

        foreach ($words as $i => $word) {
            $isLast = $i === count($words) - 1;

            // The last word absorbs the rounding remainder so the timings end
            // exactly on the speech window rather than a few ms short.
            $end = $isLast
                ? self::LEAD_IN_MS + $speech
                : (int) round(self::LEAD_IN_MS + $speech * array_sum(array_slice($weights, 0, $i + 1)) / $totalWeight);

            $timed[] = [
                'word' => $word,
                'start_ms' => $cursor,
                'end_ms' => max($cursor + 1, $end),
            ];

            $cursor = max($cursor + 1, $end);
        }

        return $timed;
    }

    private function splitWords(string $text): array
    {
        return preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    /**
     * A sine tone, one pitch per scene, fading at both ends so scene joins are
     * audible as pitch changes rather than as clicks. Stands in for narration.
     */
    private function writeTone(string $path, float $seconds, int $sequence): void
    {
        // Walk a scale so consecutive scenes are easy to tell apart by ear.
        $frequencies = [220, 247, 262, 294, 330, 349, 392, 440, 494, 523, 587, 659];
        $frequency = $frequencies[($sequence - 1) % count($frequencies)];
        $fadeStart = max(0, $seconds - 0.08);

        $this->ffmpeg([
            '-y',
            '-f', 'lavfi',
            '-i', sprintf('sine=frequency=%d:duration=%.3f:sample_rate=44100', $frequency, $seconds),
            '-af', sprintf('volume=0.22,afade=t=in:st=0:d=0.08,afade=t=out:st=%.3f:d=0.08', $fadeStart),
            '-ac', '1',
            '-c:a', 'libmp3lame',
            '-b:a', '192k',
            $path,
        ]);
    }

    private function probeDurationMs(string $path): int
    {
        $process = new Process([
            'ffprobe',
            '-v', 'error',
            '-show_entries', 'format=duration',
            '-of', 'default=noprint_wrappers=1:nokey=1',
            $path,
        ]);

        // Symfony Process enforces this itself, in userland. The queue-level
        // --timeout flag does nothing on Windows, so this is the real guard.
        $process->setTimeout(60);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }

        return (int) round(((float) trim($process->getOutput())) * 1000);
    }

    private function ffmpeg(array $arguments): void
    {
        // Array arguments, never a shell string: Windows PHP's escapeshellarg()
        // silently eats % and " rather than escaping them.
        $process = new Process(array_merge(['ffmpeg', '-hide_banner', '-loglevel', 'error'], $arguments));
        $process->setTimeout(300);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }
    }

    private function binaryExists(string $binary): bool
    {
        $process = new Process([$binary, '-version']);
        $process->setTimeout(30);
        $process->run();

        return $process->isSuccessful();
    }

    /**
     * A flat colour frame with the scene number drawn large. The inset border is
     * not decoration: it is the reference that makes Ken Burns motion, and any
     * off-by-one in duration_frames, visible on playback.
     */
    private function writeStill(string $path, int $sequence, array $spec, array $act, int $durationMs): void
    {
        $image = imagecreatetruecolor(self::WIDTH, self::HEIGHT);

        [$r, $g, $b] = $act['colour'];
        // Step the act's base colour per scene so no two stills are identical.
        $shift = ($sequence - 1) * 11 % 46;
        $background = imagecolorallocate($image, min(255, $r + $shift), min(255, $g + $shift), min(255, $b + $shift));
        $white = imagecolorallocate($image, 255, 255, 255);
        $muted = imagecolorallocate($image, 190, 198, 210);
        $accent = imagecolorallocate($image, 255, 214, 102);

        imagefilledrectangle($image, 0, 0, self::WIDTH - 1, self::HEIGHT - 1, $background);

        // Inset frame — motion is obvious when this rectangle drifts or scales.
        imagesetthickness($image, 6);
        imagerectangle($image, 60, 60, self::WIDTH - 61, self::HEIGHT - 61, $muted);
        imagesetthickness($image, 2);
        imageline($image, (int) (self::WIDTH / 2), 60, (int) (self::WIDTH / 2), 140, $muted);
        imageline($image, (int) (self::WIDTH / 2), self::HEIGHT - 140, (int) (self::WIDTH / 2), self::HEIGHT - 61, $muted);

        $number = sprintf('%02d', $sequence);
        $this->centreText($image, $number, 320, (int) (self::HEIGHT / 2 + 90), $white);
        $this->centreText($image, 'SCENE '.$number, 46, 260, $accent);
        $this->centreText($image, mb_strtoupper($act['title']), 40, self::HEIGHT - 300, $muted);
        $this->centreText($image, sprintf(
            'act %d  -  %s  -  %.2fs  -  %d frames',
            $spec['act'],
            $spec['motion'],
            $durationMs / 1000,
            (int) ceil($durationMs / 1000 * self::FPS)
        ), 32, self::HEIGHT - 220, $muted);

        imagepng($image, $path, 6);
        imagedestroy($image);
    }

    private function centreText($image, string $text, int $size, int $baselineY, int $colour): void
    {
        $box = imagettfbbox($size, 0, self::FONT, $text);
        $width = $box[2] - $box[0];

        imagettftext($image, $size, 0, (int) ((self::WIDTH - $width) / 2), $baselineY, $colour, self::FONT, $text);
    }

    private function encode(array $payload): string
    {
        return json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";
    }

    private function humanDuration(int $ms): string
    {
        return sprintf('%d:%02d', intdiv($ms, 60000), intdiv($ms % 60000, 1000));
    }
}
