<?php

namespace App\Console\Commands;

use App\Actions\PurgeRenderScratch;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

class RenderPurge extends Command
{
    protected $signature = 'render:purge
        {fixture=sample-story : Fixture directory on the fixtures disk.}
        {--dry-run : List what would be removed without removing it.}';

    protected $description = 'Delete render scratch once final.mp4 exists and decodes.';

    public function handle(PurgeRenderScratch $purger): int
    {
        $root = str_replace('\\', '/', Storage::disk('renders')->path(trim((string) $this->argument('fixture'), '/')));

        try {
            $result = $purger->handle($root, (bool) $this->option('dry-run'));
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->line($result['dry_run'] ? 'Would purge:' : 'Purged:');

        foreach ($result['purged'] as $entry) {
            $this->line('  - '.$entry);
        }

        $this->newLine();
        $this->line('Kept: '.implode(', ', $result['kept']));

        $this->line(sprintf(
            'Scratch: %.1f MB -> %.1f MB (freed %.1f MB)',
            $result['bytes_before'] / 1048576,
            $result['bytes_after'] / 1048576,
            $result['bytes_freed'] / 1048576,
        ));

        return self::SUCCESS;
    }
}
