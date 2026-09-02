<?php

namespace App\Services;

use App\Exceptions\FfmpegException;
use App\Support\Directory;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * The one place in the codebase that builds an FFmpeg or FFprobe invocation.
 *
 * Two rules hold everywhere in here, and both exist because of Windows:
 *
 *  1. Arguments are passed to Symfony Process as an ARRAY. Never a shell
 *     string, never escapeshellarg() — Windows PHP's escapeshellarg() replaces
 *     `%` and `"` with spaces instead of escaping them, which corrupts silently
 *     and is exactly the kind of character an image prompt or story title
 *     carries. Array args sidestep the whole class of bug on every platform.
 *
 *  2. Every call carries an explicit timeout. Symfony Process enforces its own
 *     timeout in userland and does not need pcntl. `queue:work --timeout` DOES
 *     need pcntl, so on this platform it does nothing at all — the timeout
 *     passed here is the only real protection against a hung encode holding a
 *     worker forever.
 */
class Ffmpeg
{
    /** @var (callable():void)|null */
    private $heartbeat = null;

    private float $heartbeatInterval = 30.0;

    public function __construct(
        private readonly string $ffmpeg,
        private readonly string $ffprobe,
        private readonly int $probeTimeout,
    ) {}

    /**
     * Register a callback to be invoked periodically while a process runs.
     *
     * This exists for one reason. `queue:work --timeout` is enforced with a
     * pcntl alarm, and pcntl does not exist in Windows PHP, so a worker sitting
     * on a hung encode is invisible: no error, no recovery, no signal. A queued
     * job registers its render_jobs row's touch() here, and a row whose
     * updated_at has gone quiet is the only evidence a hung job ever produces.
     *
     * Deliberately on the wrapper rather than in the Actions: the Actions are
     * verified at full length and are not being reopened to thread a callback
     * through every signature.
     *
     * @param  (callable():void)|null  $callback
     */
    public function heartbeatUsing(?callable $callback, ?float $intervalSeconds = null): void
    {
        $this->heartbeat = $callback;
        $this->heartbeatInterval = $intervalSeconds ?? (float) config('render.heartbeat_seconds', 30);
    }

    /**
     * Escape a filesystem path for use INSIDE an FFmpeg filter graph.
     *
     * This is a different problem from argument escaping — Symfony Process
     * already handles that — and it is the one genuinely platform-specific
     * thing in the render pipeline. It lives here, in one method, so a future
     * move to Linux is a one-method change rather than a hunt.
     *
     * A Windows drive-letter colon has to survive two levels of FFmpeg parsing:
     * the filtergraph level (where `\` is special) and the filter-argument
     * level (where `:` separates options). Escaping for both means the literal
     * string handed to FFmpeg needs TWO backslashes:
     *
     *     Windows :  ass=E\\:/narra/storage/subs.ass
     *     Linux   :  ass=/srv/narra/storage/subs.ass
     *
     * Verified against ffmpeg N-119331 on Windows: zero backslashes and one
     * backslash both fail with "Unable to parse option value ... as image
     * size"; two and three work; four fails again.
     *
     * Backslashes are also normalised to forward slashes throughout, which
     * FFmpeg accepts on Windows and which keeps the same treatment working for
     * clips.txt entries fed to the concat demuxer.
     *
     * @param  bool|null  $windows  Forces platform behaviour. Tests pass this;
     *                              production leaves it null to auto-detect.
     */
    public static function escapeFilterPath(string $path, ?bool $windows = null): string
    {
        $windows ??= DIRECTORY_SEPARATOR === '\\';

        $path = str_replace('\\', '/', $path);

        if (! $windows) {
            return $path;
        }

        // Single-quoted PHP string: '\\\\' is two literal backslashes.
        if (preg_match('#^([A-Za-z]):(/.*)$#', $path, $matches) === 1) {
            return $matches[1].'\\\\'.':'.$matches[2];
        }

        return $path;
    }

    /**
     * Prepare a path for a `file` directive in a concat demuxer list.
     *
     * NOT the same transformation as escapeFilterPath(), despite both dealing
     * with Windows paths. A concat list is not a filter graph: the drive-letter
     * colon must be left ALONE here. Verified against ffmpeg N-119331 — the
     * escaped form fails outright with "Impossible to open 'E\\:/...'".
     *
     * Only two things are needed: forward slashes, and the demuxer's own
     * single-quote escaping for paths that contain a quote.
     */
    public static function concatListPath(string $path): string
    {
        $path = str_replace('\\', '/', $path);

        // The concat demuxer ends a quoted string at the first ', so a literal
        // one has to close, escape, and reopen. Slugged filenames should never
        // contain one, but paths reaching here are treated as hostile.
        return str_replace("'", "'\\''", $path);
    }

    /**
     * Write a concat demuxer list file.
     *
     * @param  array<int, string>  $files
     */
    public function writeConcatList(array $files, string $listPath): void
    {
        if ($files === []) {
            throw new FfmpegException('Refusing to write an empty concat list.');
        }

        $lines = [];

        foreach ($files as $file) {
            if (! is_readable($file)) {
                throw new FfmpegException("Concat list references an unreadable file: {$file}");
            }

            $lines[] = "file '".self::concatListPath($file)."'";
        }

        Directory::ensure($directory = dirname($listPath));

        file_put_contents($listPath, implode("\n", $lines)."\n");
    }

    /**
     * Concat demuxer with a stream copy. Every input must already share codec
     * parameters — this re-muxes, it does not re-encode.
     */
    public function concatCopy(string $listPath, string $outputPath, int $timeout): void
    {
        Directory::ensure($directory = dirname($outputPath));

        $this->run([
            '-y',
            '-loglevel', 'error',
            '-f', 'concat',
            // The list holds absolute paths, which the demuxer rejects without
            // this. The list is written by us, not by a user.
            '-safe', '0',
            '-i', $listPath,
            '-c', 'copy',
            $outputPath,
        ], $timeout);
    }

    /**
     * Sample count for an audio file.
     *
     * Declared by default: one ffprobe call reading the container's own stream
     * length. Deep decodes every packet, which on a 58-minute file is minutes
     * of work — see the verification note in config/render.php. The two agreed
     * in every measured case, which is what a container header is for.
     *
     * @param  bool|null  $deep  Null follows render.verify.deep.
     */
    public function sampleCount(string $file, ?bool $deep = null): int
    {
        return $this->deep($deep)
            ? $this->decodedSampleCount($file)
            : $this->presentedSampleCount($file);
    }

    /**
     * Decoded sample count — every packet walked, nothing trusted.
     *
     * This is the honest answer to "what is actually in this file", and it is
     * slow in proportion to length. Reach for it when a render looks wrong, not
     * on every render that looks right.
     */
    public function decodedSampleCount(string $file): int
    {
        $report = $this->run([
            '-i', $file,
            '-af', 'astats=metadata=1:reset=0',
            '-f', 'null',
            '-',
        ], (int) config('render.timeouts.probe', 600));

        // astats prints a block per channel and then an Overall block. The last
        // occurrence is the Overall one, which is what we want.
        if (preg_match_all('/Number of samples:\s*(\d+)/', $report, $matches) === 0) {
            throw new FfmpegException("Could not read a sample count from: {$file}");
        }

        return (int) end($matches[1]);
    }

    /**
     * Presented audio length in samples — what a player actually renders.
     *
     * Read from the container's declared stream duration, which for MP4 means
     * after the edit list has been applied.
     *
     * This is NOT interchangeable with sampleCount(). For PCM the two agree.
     * For a frame-based codec they do not: AAC encodes in 1024-sample frames
     * and prepends a priming delay, so a stream carrying exactly 7,148,610
     * samples is stored as 6,982 frames of raw data (7,149,568 samples) with an
     * `elst` atom trimming the difference. sampleCount() decodes the raw stream
     * and sees the padding; this reads the length the file declares, which is
     * what every player and YouTube's ingest honour.
     *
     * Use this for anything about delivered sync. Use sampleCount() when the
     * decoded samples themselves are the subject.
     */
    public function presentedSampleCount(string $file): int
    {
        $json = $this->probe([
            '-v', 'error',
            '-select_streams', 'a:0',
            '-show_entries', 'stream=duration_ts,time_base,sample_rate',
            '-of', 'json',
            $file,
        ]);

        $stream = json_decode($json, true)['streams'][0] ?? null;

        if ($stream === null || ! isset($stream['duration_ts'], $stream['time_base'], $stream['sample_rate'])) {
            throw new FfmpegException("No audio stream duration declared in: {$file}");
        }

        [$numerator, $denominator] = array_map('intval', explode('/', (string) $stream['time_base']));

        if ($denominator === 0) {
            throw new FfmpegException("Malformed audio time_base in: {$file}");
        }

        return (int) round(
            (int) $stream['duration_ts'] * $numerator / $denominator * (int) $stream['sample_rate']
        );
    }

    /**
     * Run FFmpeg. Returns stderr, which is where FFmpeg writes its report.
     *
     * @param  array<int, string>  $arguments
     * @param  int  $timeout  Seconds. Required — there is no safe default.
     */
    public function run(array $arguments, int $timeout, ?callable $onOutput = null): string
    {
        $process = new Process([
            $this->ffmpeg,
            '-hide_banner',
            '-nostdin',
            ...$arguments,
        ]);

        return $this->execute($process, $timeout, $onOutput);
    }

    /**
     * Run FFprobe and return stdout.
     *
     * @param  array<int, string>  $arguments
     */
    public function probe(array $arguments, ?int $timeout = null): string
    {
        $process = new Process([$this->ffprobe, '-hide_banner', ...$arguments]);

        $this->execute($process, $timeout ?? $this->probeTimeout);

        return trim($process->getOutput());
    }

    /**
     * Container-reported duration, in whole milliseconds.
     */
    /**
     * The sample rate an audio file is actually stored at.
     *
     * Needed because nothing else in the pipeline may assume the source rate
     * equals the render rate. It did assume that for an entire phase, silently
     * and correctly, because the only audio that ever reached the renderer came
     * from the fake synthesizer — which writes at render.audio.sample_rate by
     * construction. The first real vendor audio arrived at 24 kHz against a
     * 44.1 kHz render and the assumption broke. See PadSceneAudio.
     */
    public function sampleRate(string $file): int
    {
        $rate = (int) $this->probe([
            '-v', 'error',
            '-select_streams', 'a:0',
            '-show_entries', 'stream=sample_rate',
            '-of', 'default=noprint_wrappers=1:nokey=1',
            $file,
        ]);

        if ($rate <= 0) {
            throw new FfmpegException("Could not read a sample rate from: {$file}");
        }

        return $rate;
    }

    public function durationMs(string $file): int
    {
        $seconds = (float) $this->probe([
            '-v', 'error',
            '-show_entries', 'format=duration',
            '-of', 'default=noprint_wrappers=1:nokey=1',
            $file,
        ]);

        return (int) round($seconds * 1000);
    }

    /**
     * Frame count for a video file.
     *
     * Declared by default — the container's nb_frames, one ffprobe call, no
     * decoding. Deep walks the stream with -count_frames, which took 404 s on a
     * finished 58-minute render: a third of the entire mux stage, paid on every
     * render. Default fast, deep on request.
     *
     * @param  bool|null  $deep  Null follows render.verify.deep.
     */
    public function frameCount(string $file, ?bool $deep = null): int
    {
        return $this->deep($deep)
            ? $this->decodedFrameCount($file)
            : $this->declaredFrameCount($file);
    }

    /**
     * Decoded frame count. Walks every packet rather than trusting a header, so
     * it is authoritative and slow. Verification, never a hot loop over 200
     * clips, and never the default on a full-length file.
     */
    public function decodedFrameCount(string $file): int
    {
        return (int) $this->probe([
            '-v', 'error',
            '-select_streams', 'v:0',
            '-count_frames',
            '-show_entries', 'stream=nb_read_frames',
            '-of', 'default=noprint_wrappers=1:nokey=1',
            $file,
        ], (int) config('render.timeouts.probe', 600));
    }

    /**
     * Frame count as the container declares it.
     *
     * MP4 carries this directly in the sample table, so for everything this
     * pipeline writes — scene clips, the concat copy, the final mux — nb_frames
     * is present and is the number a player will honour.
     *
     * The fallback derives frames from the declared stream duration for
     * containers that omit nb_frames. It is deliberately not silent about
     * having nothing to read: a missing count fails rather than returning 0,
     * because 0 would sail straight through a frame-count assertion as a
     * mismatch and send the operator hunting the wrong bug.
     */
    public function declaredFrameCount(string $file): int
    {
        $json = $this->probe([
            '-v', 'error',
            '-select_streams', 'v:0',
            '-show_entries', 'stream=nb_frames,duration_ts,time_base,r_frame_rate',
            '-of', 'json',
            $file,
        ]);

        $stream = json_decode($json, true)['streams'][0] ?? null;

        if ($stream === null) {
            throw new FfmpegException("No video stream found in: {$file}");
        }

        if (isset($stream['nb_frames']) && ctype_digit((string) $stream['nb_frames'])) {
            return (int) $stream['nb_frames'];
        }

        $seconds = self::ratio($stream['time_base'] ?? null);
        $fps = self::ratio($stream['r_frame_rate'] ?? null);

        if (isset($stream['duration_ts']) && $seconds !== null && $fps !== null) {
            return (int) round((int) $stream['duration_ts'] * $seconds * $fps);
        }

        throw new FfmpegException(
            "Container declares no frame count for: {$file}. "
            .'Re-run the stage with --deep to count frames by decoding.'
        );
    }

    /**
     * Cheap proof that a file decodes rather than merely existing.
     *
     * A truncated MP4 can still declare a frame count, so the purge guard needs
     * more than metadata — but it does not need to decode 58 minutes to get it.
     * Seeking to the last second and decoding what is there exercises the index
     * and the tail of the stream, which is exactly where truncation shows.
     */
    public function decodesAtEnd(string $file, float $seconds): bool
    {
        try {
            $this->run([
                '-loglevel', 'error',
                '-ss', (string) max(0.0, $seconds - 1.0),
                '-i', $file,
                '-frames:v', '1',
                '-f', 'null',
                '-',
            ], (int) config('render.timeouts.probe', 600));
        } catch (FfmpegException) {
            return false;
        }

        return true;
    }

    /**
     * Resolve a `num/den` probe field to a float, or null if it is unusable.
     */
    private static function ratio(?string $value): ?float
    {
        if ($value === null || ! str_contains($value, '/')) {
            return null;
        }

        [$numerator, $denominator] = array_map('intval', explode('/', $value));

        return $denominator === 0 ? null : $numerator / $denominator;
    }

    /**
     * How a result was arrived at, for reports and manifests. A verification
     * number is worth less if nothing records which kind it was.
     */
    public function verificationDepth(?bool $deep = null): string
    {
        return $this->deep($deep) ? 'decoded' : 'declared';
    }

    private function deep(?bool $deep): bool
    {
        return $deep ?? (bool) config('render.verify.deep', false);
    }

    /**
     * Video stream and container facts in one call, decoded from JSON.
     *
     * @return array<string, mixed>
     */
    public function inspect(string $file): array
    {
        $json = $this->probe([
            '-v', 'error',
            '-select_streams', 'v:0',
            '-show_entries',
            'stream=codec_name,width,height,pix_fmt,r_frame_rate,avg_frame_rate,nb_frames,time_base,duration:format=duration,size,format_name',
            '-of', 'json',
            $file,
        ]);

        $decoded = json_decode($json, true);

        return [
            'stream' => $decoded['streams'][0] ?? [],
            'format' => $decoded['format'] ?? [],
        ];
    }

    public function version(): string
    {
        $line = strtok($this->probe(['-version']), "\n");

        return $line === false ? 'unknown' : trim($line);
    }

    private function execute(Process $process, int $timeout, ?callable $onOutput = null): string
    {
        // Rule 2. Symfony enforces this itself; nothing at the queue layer will.
        $process->setTimeout($timeout);

        try {
            // start() and poll, rather than run(), so a heartbeat can fire while
            // FFmpeg works. run() blocks until exit, and with -loglevel error a
            // long encode emits nothing to hang a callback off, so an output
            // callback would not be a heartbeat — it would be silence.
            $process->start($onOutput);

            $startedAt = microtime(true);
            $lastBeat = $startedAt;

            while ($process->isRunning()) {
                // Symfony's own timeout check, in userland, needing no pcntl.
                $process->checkTimeout();

                $now = microtime(true);

                if ($this->heartbeat !== null && $now - $lastBeat >= $this->heartbeatInterval) {
                    ($this->heartbeat)();
                    $lastBeat = $now;
                }

                // Tight at first so a two-second probe is not padded to a
                // quarter-second of polling, then relaxed: a 40-minute mux does
                // not need to be asked every 20 ms whether it is done.
                usleep($now - $startedAt < 2.0 ? 20_000 : 250_000);
            }

            $process->wait();
        } catch (ProcessTimedOutException) {
            throw FfmpegException::timedOut($process, (float) $timeout);
        }

        if (! $process->isSuccessful()) {
            throw FfmpegException::failed($process);
        }

        return $process->getErrorOutput();
    }
}
