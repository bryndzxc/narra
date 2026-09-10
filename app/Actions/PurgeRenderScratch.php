<?php

namespace App\Actions;

use App\Services\Ffmpeg;
use App\Support\ResponseArchive;
use RuntimeException;

/**
 * Deletes render scratch once the final MP4 exists and is sound.
 *
 * At 30-40 minutes, scratch runs to several gigabytes per in-flight video —
 * the scene clips, the padded per-scene PCM, the silent intermediate and the
 * full-length narration. Only the deliverable and the small derived manifests
 * are worth keeping; everything else is reproducible from the source assets.
 *
 * The guard matters more than the deletion: this must never run on a failed or
 * partial render, because scratch is what a re-run would otherwise reuse.
 */
class PurgeRenderScratch
{
    /** Deleted on success. Relative to the render root. */
    private const SCRATCH_DIRECTORIES = ['clips', 'padded'];

    private const SCRATCH_FILES = [
        'silent.mp4',
        'narration.wav',
        'narration.mp3',
        'clips.txt',
        'narration.txt',
    ];

    /** Kept: the deliverable, plus small artifacts Phase 1 metadata needs. */
    private const KEEP = ['final.mp4', 'subs.ass', 'scene_audio.json'];

    public function __construct(private readonly Ffmpeg $ffmpeg) {}

    /**
     * @return array{
     *     purged: array<int, string>,
     *     kept: array<int, string>,
     *     bytes_before: int,
     *     bytes_after: int,
     *     bytes_freed: int,
     *     dry_run: bool
     * }
     */
    public function handle(string $renderRoot, bool $dryRun = false, ?int $storyId = null): array
    {
        $final = $renderRoot.'/final.mp4';

        if (! is_readable($final)) {
            throw new RuntimeException(
                "Refusing to purge: {$final} does not exist. Scratch is only disposable "
                .'once the render has actually succeeded.'
            );
        }

        // Existence is not success — a truncated file exists too. Two cheap
        // checks stand in for the full decode that used to be here, which cost
        // 404 s on a 58-minute render:
        //
        //   1. The container declares frames at all. A file whose moov atom
        //      never got written fails right here.
        //   2. The tail actually decodes. Truncation shows at the end, not the
        //      beginning, so seeking there and decoding a frame is where the
        //      evidence is — and it costs a second rather than seven minutes.
        //
        // Scratch is what a re-run would otherwise reuse, so this guard is
        // allowed to be paranoid; it is not allowed to be slow on every render.
        $frames = $this->ffmpeg->declaredFrameCount($final);

        if ($frames < 1) {
            throw new RuntimeException("Refusing to purge: {$final} declares no video frames.");
        }

        $fps = (int) config('render.video.fps');

        if (! $this->ffmpeg->decodesAtEnd($final, $frames / $fps)) {
            throw new RuntimeException(
                "Refusing to purge: {$final} declares {$frames} frames but its tail does not "
                .'decode. The file is truncated or the render did not finish.'
            );
        }

        $before = $this->directorySize($renderRoot);
        $purged = [];

        foreach (self::SCRATCH_DIRECTORIES as $directory) {
            $path = $renderRoot.'/'.$directory;

            if (! is_dir($path)) {
                continue;
            }

            $files = glob($path.'/*') ?: [];
            $bytes = 0;

            foreach ($files as $file) {
                $bytes += is_file($file) ? (int) filesize($file) : 0;

                if (! $dryRun) {
                    @unlink($file);
                }
            }

            if (! $dryRun) {
                @rmdir($path);
            }

            $purged[] = sprintf('%s/ (%d files, %s)', $directory, count($files), $this->human($bytes));
        }

        foreach (self::SCRATCH_FILES as $file) {
            $path = $renderRoot.'/'.$file;

            if (! is_file($path)) {
                continue;
            }

            $bytes = (int) filesize($path);

            if (! $dryRun) {
                @unlink($path);
            }

            $purged[] = sprintf('%s (%s)', $file, $this->human($bytes));
        }

        $kept = array_values(array_filter(
            self::KEEP,
            fn (string $name): bool => is_file($renderRoot.'/'.$name)
        ));

        $after = $this->directorySize($renderRoot);

        /*
         * The archived raw model responses go with the scratch, and under this
         * Action's guard rather than a second one of their own.
         *
         * That placement is the decision. Everything above has already proved
         * the render succeeded — `final.mp4` exists, declares frames and decodes
         * at the tail — and a story that FAILED is precisely when somebody wants
         * to read what the model actually sent. A separate purge on a timer
         * would delete the evidence for exactly the runs that need it.
         *
         * Only when a story is named. `render:purge` can be pointed at a bare
         * directory, and guessing a story id from a path would be the kind of
         * plausible inference this codebase keeps paying for.
         */
        if ($storyId !== null) {
            $responses = ResponseArchive::purge($storyId, $dryRun);

            if ($responses['files'] > 0) {
                $purged[] = sprintf(
                    'responses/%d/ (%d files, %s)',
                    $storyId,
                    $responses['files'],
                    $this->human($responses['bytes']),
                );
            }
        }

        return [
            'purged' => $purged,
            'kept' => $kept,
            'bytes_before' => $before,
            'bytes_after' => $after,
            'bytes_freed' => $before - $after,
            'dry_run' => $dryRun,
        ];
    }

    public function directorySize(string $path): int
    {
        if (! is_dir($path)) {
            return 0;
        }

        $bytes = 0;

        foreach (glob($path.'/*') ?: [] as $entry) {
            $bytes += is_dir($entry) ? $this->directorySize($entry) : (int) filesize($entry);
        }

        return $bytes;
    }

    private function human(int $bytes): string
    {
        if ($bytes >= 1073741824) {
            return sprintf('%.2f GB', $bytes / 1073741824);
        }

        return sprintf('%.1f MB', $bytes / 1048576);
    }
}
