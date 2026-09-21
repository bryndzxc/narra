<?php

namespace App\Console\Commands;

use App\Actions\ClearStoryAssets;
use App\Models\Story;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Reclaim the disk a finished story is still holding.
 *
 * A COMMAND AND NOT A BUTTON, on the operator's instruction and for a reason
 * worth keeping: this file's standing question is whether the repair a finding
 * names has a button, and the answer here is that nothing reports this. There
 * is no finding, no gate and no decision surface — it is housekeeping on a
 * story whose work is over, done deliberately and rarely, and a button for it
 * would sit on a page whose job is the next video.
 *
 * DRY RUN IS THE DEFAULT. Deleting is the flagged path, not the ordinary one,
 * because the thing being deleted cannot be rebuilt without paying for it
 * again: a story's stills and narration are ~70% of what it cost.
 */
class StoryClearAssets extends Command
{
    protected $signature = 'story:clear-assets
        {story? : id or slug. Omit it and pass --all to sweep every published story.}
        {--all : Every published story at once. Never touches a master.}
        {--apply : Actually delete. Without this it only prints what it would take.}
        {--include-final : Also delete the workspace final.mp4, but only when a delivered copy is there and matches. One story at a time only.}';

    protected $description = 'Delete the working assets of an uploaded story, keeping every row.';

    public function handle(ClearStoryAssets $clear): int
    {
        $all = (bool) $this->option('all');
        $key = trim((string) $this->argument('story'));
        $dryRun = ! $this->option('apply');

        if ($all && $key !== '') {
            $this->error('Pass a story or --all, not both. One of them is about this story and the other is about every finished one, and guessing which you meant is not this command\'s call.');

            return self::FAILURE;
        }

        if (! $all && $key === '') {
            $this->error('Name a story (id or slug), or pass --all to sweep every published story.');

            return self::FAILURE;
        }

        /*
         * --include-final IS A SINGLE-STORY FLAG AND SAYS SO RATHER THAN BEING
         * QUIETLY IGNORED.
         *
         * Ignoring it would be the kinder-looking option and the wrong one: an
         * operator who typed it asked for something, and a sweep that took the
         * flag, did not honour it and reported success would teach them the
         * masters had been considered. They were not. Deleting one is a
         * decision about whether THAT video's delivered copy is on disk now,
         * and it is answered by looking.
         */
        if ($all && $this->option('include-final')) {
            $this->error('--include-final is a single-story flag and --all will not carry it. A master is deleted only after checking that video\'s delivered copy is really there and really matches, which is a decision per story. Run it by name.');

            return self::FAILURE;
        }

        if ($all) {
            return $this->sweep($clear, $dryRun);
        }

        $story = Story::query()
            ->where('id', ctype_digit($key) ? (int) $key : 0)
            ->orWhere('slug', $key)
            ->first();

        if ($story === null) {
            $this->error("No story matches \"{$key}\".");

            return self::FAILURE;
        }

        try {
            $result = $clear->handle($story, $dryRun, (bool) $this->option('include-final'));
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->line('');
        $this->line(sprintf('  <options=bold>%s</>  (story %d, %s)', $story->title, $story->id, $story->status->value));

        if ($story->assetsCleared() && $dryRun) {
            $this->line(sprintf(
                '  <comment>Already cleared %s, freeing %s.</comment>',
                $story->assets_cleared_at->toDayDateTimeString(),
                $this->human((int) $story->assets_cleared_bytes),
            ));
        }

        $this->line('');

        if ($result['groups'] === []) {
            $this->line('  Nothing on disk to take.');
        }

        foreach ($result['groups'] as $group) {
            $this->line(sprintf(
                '  %-38s %6d files  %10s',
                $group['label'],
                $group['files'],
                $this->human($group['bytes']),
            ));
            $this->line(sprintf('  <fg=gray>%38s  %s</>', '', $group['detail']));
        }

        if ($result['groups'] !== []) {
            $this->line('');
            $this->line(sprintf(
                '  %-38s %6d files  <options=bold>%10s</>',
                $dryRun ? 'WOULD FREE' : 'FREED',
                $result['files'],
                $this->human($result['bytes']),
            ));
        }

        if ($result['final_kept_because'] !== null) {
            $this->line('');
            $this->line('  <comment>final.mp4 kept</comment> — '.$result['final_kept_because']);
        }

        if ($result['kept'] !== []) {
            $this->line('  Kept in the render root: '.implode(', ', $result['kept']));
        }

        $this->line('');

        if ($dryRun) {
            $this->line('  <comment>Dry run.</comment> Nothing was deleted. Re-run with --apply to take it.');
            $this->line('  Every scene, character, cost and render-job row is kept either way; the');
            $this->line('  path columns are emptied so nothing claims a file that is gone.');

            return self::SUCCESS;
        }

        $this->info('  Cleared. The story page will say so rather than reporting missing files.');
        $this->line('  Re-rendering this story now means buying its stills and narration again.');

        return self::SUCCESS;
    }

    /**
     * Every published story at once.
     *
     * The dry run and the apply print the SAME list from the same survey, so
     * what is read before the press and what goes are one description of one
     * thing. The apply prints what was actually taken rather than what the
     * survey predicted — they agree on a quiet machine and the difference is
     * worth seeing on a busy one.
     */
    private function sweep(ClearStoryAssets $clear, bool $dryRun): int
    {
        $result = $dryRun ? $clear->survey() : $clear->clearAll();
        $take = $dryRun ? $result['take'] : $result['cleared'];

        $this->line('');
        $this->line('  <options=bold>Published stories with working assets on disk</>');
        $this->line('');

        if ($take === []) {
            $this->line('  Nothing to take. Every published story has already been cleared, or never');
            $this->line('  generated the assets in the first place.');
        }

        foreach ($take as $entry) {
            /** @var \App\Models\Story $story */
            $story = $entry['story'];

            $this->line(sprintf(
                '  %4d  %-44s %6d files  %10s',
                $story->id,
                mb_strimwidth((string) $story->title, 0, 44, '…'),
                $entry['result']['files'],
                $this->human($entry['result']['bytes']),
            ));
        }

        if ($take !== []) {
            $this->line('  '.str_repeat('-', 78));
            $this->line(sprintf(
                '  %-50s %6d files  <options=bold>%10s</>',
                $dryRun
                    ? sprintf('WOULD FREE across %d story(s)', count($take))
                    : sprintf('FREED across %d story(s)', count($take)),
                $result['files'],
                $this->human($result['bytes']),
            ));
        }

        $this->line('');

        if ($result['nothing'] !== []) {
            $this->line(sprintf(
                '  <fg=gray>%d published story(s) had nothing left to take and are not in the list above.</>',
                count($result['nothing']),
            ));
        }

        // Said on every run, because it is the difference between this and what
        // an operator looking at a 13 GB storage folder is probably hoping for.
        $this->line('  <fg=gray>Masters are not in this. --include-final is a single-story flag, and on this</>');
        $this->line('  <fg=gray>machine final.mp4 is the only copy of most of these videos.</>');

        foreach ($result['refused'] ?? [] as $refusal) {
            $this->line('');
            $this->warn(sprintf('  Skipped story %d: %s', $refusal['story']->id, $refusal['why']));
        }

        $this->line('');

        if ($dryRun) {
            $this->line('  <comment>Dry run.</comment> Nothing was deleted. Re-run with --all --apply to take it.');
            $this->line('  Every scene, character, cost and render-job row is kept either way; the');
            $this->line('  path columns are emptied so nothing claims a file that is gone.');

            return self::SUCCESS;
        }

        if ($take !== []) {
            $this->info('  Cleared. Each of those pages will say so rather than reporting missing files.');
            $this->line('  Re-rendering any of them now means buying its stills and narration again.');
        }

        return self::SUCCESS;
    }

    /** One owner for the figure, so the command and the page cannot round differently. */
    private function human(int $bytes): string
    {
        return ClearStoryAssets::human($bytes);
    }
}
