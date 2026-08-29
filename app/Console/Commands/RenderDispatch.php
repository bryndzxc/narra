<?php

namespace App\Console\Commands;

use App\Actions\DispatchRenderPipeline;
use App\Models\Story;
use Illuminate\Console\Command;
use Throwable;

/**
 * Queue a story's render. Returns immediately — the workers do the work.
 */
class RenderDispatch extends Command
{
    protected $signature = 'render:dispatch
        {story : Story slug or id.}';

    protected $description = 'Dispatch the render pipeline for a story onto the render queue.';

    public function handle(DispatchRenderPipeline $dispatcher): int
    {
        $key = (string) $this->argument('story');

        $story = Story::query()
            ->where('slug', $key)
            ->orWhere('id', ctype_digit($key) ? (int) $key : 0)
            ->first();

        if ($story === null) {
            $this->error("No story matching '{$key}'. Run `php artisan render:import` first.");

            return self::FAILURE;
        }

        try {
            $result = $dispatcher->handle($story);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf('Queued %d scene clips for "%s".', $result['scenes'], $story->title));
        $this->line('  batch  : '.$result['batch_id']);
        $this->line('  queue  : '.config('render.queues.render'));
        $this->line('  status : '.$story->refresh()->status->value);
        $this->newLine();
        $this->line('The concat, subtitle and mux stages are chained off the batch completion callback.');
        $this->line('Watch it at /renders/'.$story->slug.' — there is no Horizon on this platform.');
        $this->newLine();
        $this->line('If nothing moves, no worker is running:');
        $this->line('  php artisan queue:work redis --queue=render --tries=1 --max-time=21600');

        return self::SUCCESS;
    }
}
