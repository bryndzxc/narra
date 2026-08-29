<?php

namespace App\Console\Commands;

use App\Actions\ImportFixtureStory;
use Illuminate\Console\Command;
use Throwable;

/**
 * Load a Phase 0 fixture set into the database so the queue can drive it.
 */
class RenderImport extends Command
{
    protected $signature = 'render:import
        {fixture=sample-story : Fixture directory on the fixtures disk.}';

    protected $description = 'Import a fixture set into stories/acts/scenes so the render can be queued.';

    public function handle(ImportFixtureStory $import): int
    {
        try {
            $result = $import->handle((string) $this->argument('fixture'));
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $story = $result['story'];

        $this->line(sprintf(
            '%s story #%d "%s" (slug %s)',
            $result['reimported'] ? 'Updated' : 'Imported',
            $story->id,
            $story->title,
            $story->slug
        ));

        $this->line(sprintf('  %d acts, %d scenes', $result['acts'], $result['scenes']));
        $this->line('  status : '.$story->status->value);
        $this->line('  gates  : 1 and 2 approved by the import, standing in for the operator');
        $this->newLine();
        $this->line("Next: php artisan render:dispatch {$story->slug}");

        return self::SUCCESS;
    }
}
