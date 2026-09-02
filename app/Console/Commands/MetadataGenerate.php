<?php

namespace App\Console\Commands;

use App\Actions\GenerateMetadata;
use App\Contracts\MetadataWriter;
use App\Enums\MetadataStatus;
use App\Jobs\GenerateMetadataJob;
use App\Models\CostEntry;
use App\Models\Story;
use App\Models\YoutubeMetadata;
use App\Support\ModelRoster;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

/**
 * Write the publish sheet for a rendered story.
 *
 * Runs inline by default. It is three calls and cents, and the operator is
 * standing in front of it — queueing it would mean the same wait plus a worker
 * to babysit. `--queue` exists because the Gate 4 button needs the same work on
 * the `text` queue, and a command that can only queue or only run inline is a
 * command that gets a second copy of itself.
 *
 * It names the provider it resolved from the CONTAINER before spending, not the
 * one config says should be bound. Those are two different questions, and the
 * one time they disagreed this project generated 186 placeholder stills and
 * wrote $8.12 to the ledger against a vendor nobody had contacted.
 */
class MetadataGenerate extends Command
{
    protected $signature = 'metadata:generate
        {story : Story slug or id.}
        {--force : Overwrite a sheet that has already been written or approved.}
        {--queue : Dispatch to the text queue instead of running here.}
        {--yes : Skip the spend confirmation.}';

    protected $description = 'Write the YouTube publish sheet: titles, description, tags, thumbnail text, pinned comment.';

    public function handle(GenerateMetadata $generate, MetadataWriter $writer): int
    {
        try {
            $story = $this->resolveStory();
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $story->loadMissing('acts');

        $metadata = YoutubeMetadata::query()->firstOrCreate(
            ['story_id' => $story->id],
            ['status' => MetadataStatus::Pending]
        );

        $chapters = $metadata->chapters();

        $this->line('');
        $this->info("Story: {$story->title}");
        $this->line("  slug        {$story->slug}");
        $this->line("  status      {$story->status->value}");
        $this->line("  sheet       {$metadata->status->value}");
        $this->line('  chapters    '.count($chapters).' (from act timings)');

        // From the instance, never from config. `config('providers.…')` answers
        // what SHOULD be bound; only the object answers what IS.
        $this->line('  provider    '.$writer->providerName().($writer->isSimulated() ? '  [SIMULATED — nothing is billed and nothing is real]' : ''));

        foreach (app(ModelRoster::class)->lines(ModelRoster::METADATA_OPERATIONS) as $line) {
            $this->line('  '.$line);
        }

        $this->line('');

        foreach ($chapters as $chapter) {
            $this->line(sprintf('    %-8s %s', $chapter['timestamp'], $chapter['title']));
        }

        $this->line('');

        if (! $this->confirmSpend($writer)) {
            return self::FAILURE;
        }

        if ($this->option('queue')) {
            GenerateMetadataJob::dispatch($story->id, (bool) $this->option('force'));

            $this->info(sprintf(
                'Queued on "%s". Watch it at /renders/%s.',
                config('render.queues.text'),
                $story->slug,
            ));

            return self::SUCCESS;
        }

        $before = $story->costEntries()->count();
        $startedAt = microtime(true);

        try {
            $notes = $generate->handle($story, (bool) $this->option('force'));
        } catch (Throwable $e) {
            $this->line('');
            $this->error($e->getMessage());
            // Reported anyway: a stage that failed after two of three calls
            // still burned the tokens for those two, and a cost table that
            // drops the rows for failed stages cannot answer what a video cost.
            $this->report($story->fresh(), $before, $startedAt);

            return self::FAILURE;
        }

        foreach ($notes as $note) {
            $this->warn('  '.$note);
        }

        $this->report($story->fresh(), $before, $startedAt);

        return self::SUCCESS;
    }

    private function confirmSpend(MetadataWriter $writer): bool
    {
        if ($this->option('yes') || $writer->isSimulated()) {
            return true;
        }

        $this->warn(sprintf(
            'This makes 3 billed API calls against %s and writes a cost row for each.',
            app(ModelRoster::class)->summary(ModelRoster::METADATA_OPERATIONS),
        ));

        return $this->confirm('Continue?', true);
    }

    /**
     * What it actually cost, read from cost_entries.
     *
     * From the table rather than from a running total in memory, because the
     * table is the thing that has to be able to answer this.
     */
    private function report(Story $story, int $entriesBefore, float $startedAt): void
    {
        $entries = $story->costEntries()->orderBy('id')->get()->slice($entriesBefore)->values();
        $metadata = $story->youtubeMetadata()->first();

        $this->line('');
        $this->line(str_repeat('-', 72));
        $this->info('Publish sheet');
        $this->line(str_repeat('-', 72));

        if ($metadata !== null) {
            $this->table(['', ''], [
                ['status', $metadata->status->value],
                ['title variants', (string) count($metadata->title_options ?? [])],
                ['title selected', $metadata->title_selected ?? '(none — that is Gate 4\'s job)'],
                ['description', number_format(mb_strlen((string) $metadata->description)).' chars'],
                ['tags', count($metadata->tags ?? []).' tags, '.$metadata->tags_char_count.'/'
                    .config('youtube.limits.tags_chars').' chars'],
                ['thumbnail text', (string) count($metadata->thumbnail_text_options ?? [])],
                ['pinned comment', $metadata->pinned_comment !== null ? 'written' : '(none)'],
            ]);

            foreach ($metadata->title_options ?? [] as $i => $title) {
                $this->line(sprintf('  %d. [%3d] %s', $i + 1, mb_strlen($title), $title));
            }
        }

        $this->line('');
        $this->line('This run:');

        foreach ($entries as $entry) {
            /** @var CostEntry $entry */
            $this->line(sprintf(
                '  %-18s %-18s %9s tok   $%s%s',
                $entry->operation,
                (string) $entry->model,
                number_format((float) $entry->quantity),
                number_format((float) $entry->usd_cost, 4),
                $entry->simulated ? '   [simulated]' : '',
            ));
        }

        $this->line('');
        $this->line(sprintf('  this run        $%s', number_format((float) $entries->sum('usd_cost'), 4)));
        $this->line(sprintf('  story total     $%s', number_format((float) $story->costEntries()->sum('usd_cost'), 4)));
        $this->line(sprintf('  wall clock      %.1f s', microtime(true) - $startedAt));
        $this->line('');
        $this->line("Pick a title at Gate 4: /stories/{$story->slug}/metadata");
    }

    private function resolveStory(): Story
    {
        $key = (string) $this->argument('story');

        $story = Story::query()
            ->where('slug', $key)
            ->orWhere('id', ctype_digit($key) ? (int) $key : 0)
            ->first();

        if ($story === null) {
            throw new RuntimeException("No story matching '{$key}'.");
        }

        return $story;
    }
}
