<?php

namespace App\Support;

use App\Models\RenderJob;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Keep the exact text the model sent.
 *
 * ---------------------------------------------------------------------------
 * WHY
 * ---------------------------------------------------------------------------
 *
 * A doubled escape in one act script took four steps to diagnose — coexisting
 * real em dashes, per-call consistency across `script` and `summary`, a
 * demonstration of both wire shapes through `json_decode`, and a sweep of every
 * text column in the database — and the conclusion was still an INFERENCE. The
 * app stored decoded values only, so the one thing that would have settled it in
 * a single grep, the bytes the model actually sent, did not exist anywhere.
 *
 * Rule 3 in CLAUDE.md is "keep one number that we did not compute". This is that
 * rule applied to text: everything else about a response is derived by us, and a
 * disagreement between a stored value and what arrived is unanswerable without
 * the thing that arrived.
 *
 * **Cost, measured rather than guessed.** Across the whole database history —
 * 132 Anthropic calls, eight stories — the raw responses come to 1.7 MB, or
 * about 0.3 MB gzipped; a mean of 220 KB raw and 44 KB gzipped per story, with
 * the largest story at 676 KB raw. Against a ~3 GB scratch budget per in-flight
 * video and a ~530 MB deliverable, that is free.
 *
 * **Files, not columns.** A longtext on `render_jobs` would bloat the table the
 * operator console reads on every page load, to hold something nobody reads
 * until something has already gone wrong.
 *
 * **Written BEFORE the decode**, which is the ordering that matters. A response
 * that fails to parse is exactly the one worth having, and archiving after a
 * successful decode would keep every payload except the interesting ones.
 */
final class ResponseArchive
{
    private const DISK = 'local';

    private const ROOT = 'responses';

    /**
     * Archive one raw response. Returns the path written, or null.
     *
     * Every failure is swallowed. This is evidence-keeping for a future
     * question, and a disk that is full or read-only must never be the reason a
     * paid call is thrown away after it has already been billed.
     */
    public static function store(string $operation, string $content): ?string
    {
        if (! (bool) config('providers.archive_responses', true)) {
            return null;
        }

        try {
            $job = RenderJob::current();

            $path = sprintf(
                '%s/%s/%s-%s-%s.json.gz',
                self::ROOT,
                $job?->story_id ?? '_unattached',
                $job?->id ?? date('Ymd-His'),
                preg_replace('/[^a-z0-9_]+/i', '-', $operation),
                substr(hash('xxh128', $content), 0, 8),
            );

            // Level 6: the default, and the difference between it and 9 on JSON
            // this shape is under a percent for several times the CPU.
            $gz = gzencode($content, 6);

            if ($gz === false) {
                return null;
            }

            Storage::disk(self::DISK)->put($path, $gz);

            return $path;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Every archived response for a story, absolute paths.
     *
     * @return array<int, string>
     */
    public static function forStory(int $storyId): array
    {
        try {
            return Storage::disk(self::DISK)->files(self::ROOT.'/'.$storyId);
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Delete a story's archived responses, reporting bytes freed.
     *
     * Called from `PurgeRenderScratch`, deliberately, so it is governed by that
     * Action's guard rather than by a second one: a story whose `final.mp4` does
     * not exist or does not decode keeps its payloads, because a story that
     * failed is precisely when somebody will want to read them.
     *
     * @return array{files: int, bytes: int}
     */
    public static function purge(int $storyId, bool $dryRun = false): array
    {
        $files = self::forStory($storyId);
        $bytes = 0;

        foreach ($files as $file) {
            try {
                $bytes += (int) Storage::disk(self::DISK)->size($file);

                if (! $dryRun) {
                    Storage::disk(self::DISK)->delete($file);
                }
            } catch (Throwable) {
                // Counted or not, a file we cannot stat is not worth failing a
                // purge that has already proved the render succeeded.
            }
        }

        return ['files' => count($files), 'bytes' => $bytes];
    }
}
