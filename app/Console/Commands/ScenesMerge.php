<?php

namespace App\Console\Commands;

use App\Actions\MergeAdjacentScenes;
use App\Models\Scene;
use App\Models\Story;
use Illuminate\Console\Command;
use Throwable;

/**
 * Fold the scene after this one into it.
 *
 * Free: the narration is re-joined from the same sentences, so no word changes
 * and nothing that was billed becomes stale. What moves is where the scene
 * boundary falls.
 */
class ScenesMerge extends Command
{
    protected $signature = 'scenes:merge
        {story : Story slug or id.}
        {sequence : The earlier scene. The one after it is folded in.}
        {--keep-next-frame : Take the picture, motion and cast from the absorbed scene instead.}
        {--dry-run : Show the resulting narration and change nothing.}';

    protected $description = 'Merge a scene with the one after it. Free — narration stays verbatim.';

    public function handle(MergeAdjacentScenes $merge): int
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

        $sequence = (int) $this->argument('sequence');

        $into = Scene::where('story_id', $story->id)->where('sequence', $sequence)->first();
        $absorbed = Scene::where('story_id', $story->id)->where('sequence', $sequence + 1)->first();

        if ($into === null || $absorbed === null) {
            $this->error("Story has no scene {$sequence} or nothing after it.");

            return self::FAILURE;
        }

        $this->line('');
        $this->line(sprintf('  %d [%dw] %s', $into->sequence, str_word_count((string) $into->narration_text), $into->narration_text));
        $this->line(sprintf('  %d [%dw] %s', $absorbed->sequence, str_word_count((string) $absorbed->narration_text), $absorbed->narration_text));
        $this->line('');
        $this->line(sprintf(
            '  -> one scene of %d words, keeping the %s frame.',
            str_word_count((string) $into->narration_text) + str_word_count((string) $absorbed->narration_text),
            $this->option('keep-next-frame') ? 'ABSORBED' : 'earlier',
        ));

        if ($this->option('dry-run')) {
            $this->info('  Dry run — nothing changed.');

            return self::SUCCESS;
        }

        try {
            $merged = $merge->handle($into, $absorbed, (bool) $this->option('keep-next-frame'));
        } catch (Throwable $e) {
            $this->error('  '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            '  Merged into scene %d (%d words). Story now has %d scenes. Cost: $0.0000.',
            $merged->sequence,
            str_word_count((string) $merged->narration_text),
            $story->scenes()->count(),
        ));

        return self::SUCCESS;
    }
}
