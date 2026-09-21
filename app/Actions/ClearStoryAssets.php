<?php

namespace App\Actions;

use App\Enums\StoryStatus;
use App\Models\Character;
use App\Models\Scene;
use App\Models\Story;
use App\Support\DeliveryFolder;
use App\Support\RenderWorkspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Delete the working assets of a story that has been uploaded.
 *
 * ---------------------------------------------------------------------------
 * WHAT THIS IS FOR, AND WHAT IT IS NOT
 * ---------------------------------------------------------------------------
 *
 * `PurgeRenderScratch` already runs on every successful render and takes the
 * scene clips, the padded PCM, the silent intermediate, the full-length
 * narration and the archived model responses. It is correct and this does not
 * replace it. What it leaves behind is everything a re-render would need:
 *
 *     stills       assets/{id}/stills/          1.24 GB over 14 stories
 *     narration    assets/{id}/narration/       1.47 GB
 *     sheets       characters/{id}/              130 MB
 *     thumbnails   renders/{slug}/thumbnails/      5 MB
 *
 * Those are kept for as long as a story might be re-rendered. Once the video
 * is on YouTube it will not be, and 2.84 GB per fourteen videos is the price
 * of keeping the option.
 *
 * ---------------------------------------------------------------------------
 * `final.mp4` IS NOT A WORKING ASSET AND IS NOT DELETED BY DEFAULT
 * ---------------------------------------------------------------------------
 *
 * It is 76% of the disk and it is the tempting one. It is also, on this
 * machine, THE ONLY COPY for thirteen of fourteen published stories: `deliver`
 * ran and succeeded for all of them, wrote to the delivery folder, and the
 * operator uploaded and removed the files afterwards. `render_jobs.output_path`
 * still names those paths and they resolve to nothing.
 *
 * So the master is deleted only with `includeFinal`, and only when a delivered
 * copy is present AND the same size. Nothing here is allowed to remove the
 * last copy of a finished video on the strength of a status column.
 *
 * ---------------------------------------------------------------------------
 * THE PATHS ARE NULLED, AND THAT IS THE POINT RATHER THAN TIDINESS
 * ---------------------------------------------------------------------------
 *
 * `Scene::needsImage()` and `needsNarration()` read the ROW, not the disk. A
 * story whose stills are deleted while `image_path` still names them is a
 * story the pipeline believes is complete: `assets:generate --estimate`
 * reports nothing pending and $0.00, and the absence surfaces three stages
 * later inside a render. That is the half-believed row this project has paid
 * for twice — the half-cleared `scene_audio` row, and `scenes.duration_ms`
 * restored in one table of two.
 *
 * Nulling them makes every reader correct by construction, at the cost of the
 * story now reading as though it never had assets. `stories.assets_cleared_at`
 * is what answers that, the way `is_fixture` answers it for a parked story.
 *
 * NO ROW IS DELETED. Not a scene, not a character, not a cost entry, not a
 * render job. The ledger is write-once and the scene list is the record of
 * what was made.
 */
class ClearStoryAssets
{
    /** Kept in the render root: the metadata sheet reads them and both are tiny. */
    public const KEEP_IN_RENDER_ROOT = ['final.mp4', 'subs.ass', 'scene_audio.json'];

    /**
     * @return array{
     *     story_id: int,
     *     dry_run: bool,
     *     groups: array<int, array{label: string, files: int, bytes: int, detail: string}>,
     *     bytes: int,
     *     files: int,
     *     kept: array<int, string>,
     *     final_kept_because: ?string
     * }
     */
    public function handle(Story $story, bool $dryRun = true, bool $includeFinal = false): array
    {
        $this->assertReady($story);

        $workspace = RenderWorkspace::for($story);
        $groups = [];
        $kept = [];

        // Through the configured disks rather than storage_path(), so these
        // follow a moved root and so a test can point them somewhere that is
        // not the machine's real asset store.
        $assets = Storage::disk((string) config('render.assets.disk', 'assets'));
        $characters = Storage::disk('characters');

        $groups[] = $this->directory(
            'stills',
            $assets->path($story->id.'/stills'),
            $dryRun,
            sprintf('%d scene(s) lose their picture', $story->scenes()->whereNotNull('image_path')->count()),
        );

        $groups[] = $this->directory(
            'narration',
            $assets->path($story->id.'/narration'),
            $dryRun,
            'word timings are in the database and are NOT touched',
        );

        $groups[] = $this->directory(
            'reference sheets',
            $characters->path((string) $story->id),
            $dryRun,
            sprintf('%d character(s) lose their reference', $story->characters()->count()),
        );

        $groups[] = $this->directory(
            'composed thumbnails',
            $workspace->path('thumbnails'),
            $dryRun,
            'the delivered .jpg is not in here and is not touched',
        );

        // The master, under its own condition.
        $finalKeptBecause = null;
        $final = $workspace->path('final.mp4');

        if (! is_file($final)) {
            $finalKeptBecause = 'there is no final.mp4 in the render root.';
        } elseif (! $includeFinal) {
            $finalKeptBecause = 'not asked for. It is the master, and on this machine it is the '
                .'only copy for most published stories — pass --include-final to reconsider it.';
        } elseif (($delivered = $this->deliveredCopy($story)) === null) {
            $finalKeptBecause = sprintf(
                'REFUSED: no delivered copy of "%s.mp4" exists. The delivery stage may have run and '
                .'the file been moved or removed since — `render_jobs.output_path` is not evidence '
                .'that a file is there now. This would be the last copy.',
                $story->slug,
            );
        } elseif (filesize($delivered) !== filesize($final)) {
            $finalKeptBecause = sprintf(
                'REFUSED: the delivered copy is %s and this one is %s. A copy that is not the same '
                .'size is not a copy, and which one is right is not this command\'s call.',
                DeliveryFolder::human((int) filesize($delivered)),
                DeliveryFolder::human((int) filesize($final)),
            );
        } else {
            $groups[] = $this->files('final.mp4 (delivered copy verified)', [$final], $dryRun, $delivered);
        }

        foreach (self::KEEP_IN_RENDER_ROOT as $name) {
            if (is_file($workspace->path($name)) && ($name !== 'final.mp4' || $finalKeptBecause !== null)) {
                $kept[] = $name;
            }
        }

        $groups = array_values(array_filter($groups, static fn (array $g): bool => $g['files'] > 0));

        if (! $dryRun) {
            $this->forgetPaths($story);

            $story->forceFill([
                'assets_cleared_at' => now(),
                'assets_cleared_bytes' => (int) ($story->assets_cleared_bytes ?? 0)
                    + array_sum(array_column($groups, 'bytes')),
            ])->save();
        }

        return [
            'story_id' => (int) $story->id,
            'dry_run' => $dryRun,
            'groups' => $groups,
            'bytes' => array_sum(array_column($groups, 'bytes')),
            'files' => array_sum(array_column($groups, 'files')),
            'kept' => $kept,
            'final_kept_because' => $finalKeptBecause,
        ];
    }

    /**
     * Every story a sweep is allowed to touch.
     *
     * `published`, which is the same question `assertReady()` asks one story at
     * a time rather than a second, looser rule written for the bulk path. A
     * sweep that could reach a story the single-story command refuses would be
     * two decisions read apart with real files behind them.
     *
     * Fixtures are NOT excluded and that is deliberate: none of them is
     * published, so the status already answers it, and an extra filter here
     * would be a refusal the single-story path does not have.
     *
     * @return Builder<Story>
     */
    public function eligibleQuery(): Builder
    {
        return Story::query()
            ->where('status', StoryStatus::Published)
            ->orderBy('id');
    }

    /** @return Collection<int, Story> */
    public function eligible(): Collection
    {
        return $this->eligibleQuery()->get();
    }

    /**
     * Bytes as the operator reads them.
     *
     * One copy, because three surfaces print it: the command, the sweep
     * button, and the banner on a cleared story's page — which used to carry
     * the arithmetic inline in Blade, where nothing could see it.
     */
    public static function human(int $bytes): string
    {
        if ($bytes >= 1073741824) {
            return sprintf('%.2f GB', $bytes / 1073741824);
        }

        if ($bytes >= 1048576) {
            return sprintf('%.0f MB', $bytes / 1048576);
        }

        return sprintf('%.0f KB', $bytes / 1024);
    }

    /**
     * What a sweep would take, story by story.
     *
     * ---------------------------------------------------------------------
     * THERE IS NO `includeFinal` ON THIS METHOD OR ON `clearAll()`, AND THAT
     * IS THE POINT RATHER THAN A DEFAULT SOMEBODY COULD OVERRIDE
     * ---------------------------------------------------------------------
     *
     * Deleting a master is a decision about ONE video: whether its delivered
     * copy is really on disk now, and whether the thing in the delivery folder
     * is the same file. Both are answered by looking, per story. A flag that
     * could ask fourteen of those questions in one press would be answering
     * them all with one press, which is how the last copy of something gets
     * deleted — and on this machine thirteen of fourteen published stories have
     * no second copy at all.
     *
     * So the masters are not reachable from here by any argument. The terminal
     * flag on a single story is the only way, and it refuses on its own terms.
     *
     * Stories with nothing left on disk come back separately rather than as
     * zero-byte rows: an already-cleared story is not one this would act on,
     * and counting it in would make a sweep look bigger than it is.
     *
     * @return array{
     *     take: array<int, array{story: Story, result: array<string, mixed>}>,
     *     nothing: array<int, Story>,
     *     bytes: int,
     *     files: int
     * }
     */
    public function survey(): array
    {
        $take = [];
        $nothing = [];

        foreach ($this->eligible() as $story) {
            $result = $this->handle($story, dryRun: true, includeFinal: false);

            if ($result['bytes'] > 0) {
                $take[] = ['story' => $story, 'result' => $result];
            } else {
                $nothing[] = $story;
            }
        }

        return [
            'take' => $take,
            'nothing' => $nothing,
            'bytes' => array_sum(array_map(static fn (array $r): int => $r['result']['bytes'], $take)),
            'files' => array_sum(array_map(static fn (array $r): int => $r['result']['files'], $take)),
        ];
    }

    /**
     * Clear every eligible story, and report what actually went.
     *
     * It surveys AGAIN rather than taking a list from the caller, so what is
     * deleted is derived from the disk at the moment of deletion and never from
     * a snapshot the operator was shown some seconds earlier. The figures
     * returned are what was taken rather than what was predicted; if the two
     * differ, the difference is visible instead of smoothed over.
     *
     * Pressing it twice is harmless by construction — the second sweep surveys
     * a disk the first one emptied and finds nothing to take. Nothing here buys
     * anything, so unlike the character sheets there is no claim to stake and
     * no lock to take.
     *
     * A story refused mid-sweep does not stop the rest. The only refusal
     * reachable is a status that moved between the survey and the delete, and
     * abandoning twelve good stories over the thirteenth would be a worse
     * answer than naming it.
     *
     * @return array{
     *     cleared: array<int, array{story: Story, result: array<string, mixed>}>,
     *     refused: array<int, array{story: Story, why: string}>,
     *     nothing: array<int, Story>,
     *     bytes: int,
     *     files: int
     * }
     */
    public function clearAll(): array
    {
        $survey = $this->survey();
        $cleared = [];
        $refused = [];

        foreach ($survey['take'] as $entry) {
            $story = $entry['story'];

            try {
                // Re-read, because a status can move between the survey and
                // this line and `assertReady()` has to judge the row as it is
                // now rather than as it was when the list was drawn.
                $story->refresh();

                $cleared[] = [
                    'story' => $story,
                    'result' => $this->handle($story, dryRun: false, includeFinal: false),
                ];
            } catch (RuntimeException $e) {
                $refused[] = ['story' => $story, 'why' => $e->getMessage()];
            }
        }

        return [
            'cleared' => $cleared,
            'refused' => $refused,
            'nothing' => $survey['nothing'],
            'bytes' => array_sum(array_map(static fn (array $r): int => $r['result']['bytes'], $cleared)),
            'files' => array_sum(array_map(static fn (array $r): int => $r['result']['files'], $cleared)),
        ];
    }

    /**
     * PAST GATE 4, and nothing weaker.
     *
     * `published` is the only status that means the operator has been through
     * the metadata sheet and taken the file away. Every earlier one is a story
     * that may still be rendered, and `rendered` in particular is a story
     * sitting AT Gate 3 waiting to be watched — deleting its stills would
     * strand it with no way forward but paying for them again.
     */
    private function assertReady(Story $story): void
    {
        if ($story->status !== StoryStatus::Published) {
            throw new RuntimeException(sprintf(
                'Story %d is at "%s". Working assets are cleared only past Gate 4, because every '
                .'earlier status is a story that might still be rendered — and re-rendering after '
                .'this means buying the stills and the narration again.',
                $story->id,
                $story->status->value,
            ));
        }
    }

    /** The delivered file, if one is actually on disk now. */
    private function deliveredCopy(Story $story): ?string
    {
        $root = DeliveryFolder::configured();

        if ($root === null || $story->slug === null) {
            return null;
        }

        // The operator files uploaded videos into subfolders, so the search is
        // by name anywhere under the delivery root rather than at its top.
        foreach ([$root.'/'.$story->slug.'.mp4', $root.'/Done/'.$story->slug.'.mp4'] as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        if (! is_dir($root)) {
            return null;
        }

        $wanted = mb_strtolower($story->slug.'.mp4');

        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        ) as $file) {
            if ($file->isFile() && mb_strtolower($file->getFilename()) === $wanted) {
                return $file->getPathname();
            }
        }

        return null;
    }

    /**
     * Every column naming a file this just deleted.
     *
     * The rows stay. `scene_audio` keeps `samples`, `duration_ms` and
     * `timings_json`, which are facts about the narration rather than pointers
     * to it — `narration:measure` reads them, so the measured reading rates
     * survive a clear.
     */
    private function forgetPaths(Story $story): void
    {
        Scene::query()->where('story_id', $story->id)->update(['image_path' => null]);

        // Through the scene ids rather than the HasManyThrough relation: an
        // UPDATE with a join behaves differently across drivers, and this has
        // to be the same statement on MySQL and on the SQLite the suite runs.
        \App\Models\SceneAudio::query()
            ->whereIn('scene_id', Scene::query()->where('story_id', $story->id)->select('id'))
            ->update(['audio_path' => null]);

        $characters = Character::query()->where('story_id', $story->id)->pluck('id');

        Character::query()->whereIn('id', $characters)->update(['reference_image_path' => null]);

        \App\Models\CharacterReference::query()
            ->whereIn('character_id', $characters)
            ->update(['image_path' => null]);
    }

    /** @return array{label: string, files: int, bytes: int, detail: string} */
    private function directory(string $label, string $path, bool $dryRun, string $detail): array
    {
        if (! is_dir($path)) {
            return ['label' => $label, 'files' => 0, 'bytes' => 0, 'detail' => $detail];
        }

        $files = [];

        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)
        ) as $file) {
            if ($file->isFile()) {
                $files[] = $file->getPathname();
            }
        }

        $group = $this->files($label, $files, $dryRun, $detail);

        if (! $dryRun) {
            $this->removeEmptyTree($path);
        }

        return $group;
    }

    /**
     * @param  array<int, string>  $files
     * @return array{label: string, files: int, bytes: int, detail: string}
     */
    private function files(string $label, array $files, bool $dryRun, string $detail): array
    {
        $bytes = 0;

        foreach ($files as $file) {
            $bytes += (int) @filesize($file);

            if (! $dryRun) {
                @unlink($file);
            }
        }

        return ['label' => $label, 'files' => count($files), 'bytes' => $bytes, 'detail' => $detail];
    }

    private function removeEmptyTree(string $path): void
    {
        foreach (glob($path.'/*', GLOB_ONLYDIR) ?: [] as $child) {
            $this->removeEmptyTree($child);
        }

        @rmdir($path);
    }
}
