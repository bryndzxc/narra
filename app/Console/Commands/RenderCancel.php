<?php

namespace App\Console\Commands;

use App\Actions\CancelRenderBatch;
use App\Exceptions\GateViolationException;
use App\Models\Story;
use Illuminate\Console\Command;

/**
 * Stop a running render batch.
 *
 * Horizon's dashboard has a cancel button; this platform does not have Horizon,
 * and a 200-scene batch queued by mistake is otherwise an hour of CPU nobody
 * can call off. Cancelling marks the batch, and each job checks that before it
 * starts — the one already in flight finishes, the other 190 do not start.
 *
 * The work itself moved to CancelRenderBatch so the Gate 3 page can press the
 * same button. This file is now what a command should be: argument parsing,
 * one Action call, and printing. Nothing about WHEN a cancel is allowed lives
 * here any more — that is OperatorAction::CancelRender, which the page consults
 * too, so the two cannot answer differently.
 */
class RenderCancel extends Command
{
    protected $signature = 'render:cancel {story : Story slug or id.}';

    protected $description = 'Cancel the in-flight render batch for a story.';

    public function handle(CancelRenderBatch $cancel): int
    {
        $key = (string) $this->argument('story');

        $story = Story::query()
            ->where('slug', $key)
            ->orWhere('id', ctype_digit($key) ? (int) $key : 0)
            ->first();

        if ($story === null) {
            $this->error("No story matching '{$key}'.");

            return self::FAILURE;
        }

        try {
            $result = $cancel->handle($story);
        } catch (GateViolationException $e) {
            $this->newLine();
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Cancelled %d batch(es), marked %d job row(s).',
            $result['batches'],
            $result['rows'],
        ));
        $this->line('A job already inside FFmpeg will finish; nothing new starts.');

        if ($result['landed'] !== null) {
            $this->line($result['reconciled']
                ? sprintf(
                    'Story reconciled to %s — the scenes that did not finish are flagged for retry.',
                    $result['landed']->value,
                )
                : sprintf('Story moved to %s — a re-run starts from there.', $result['landed']->value));
        }

        return self::SUCCESS;
    }
}
