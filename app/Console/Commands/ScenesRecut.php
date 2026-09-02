<?php

namespace App\Console\Commands;

use App\Actions\ReassignMotionPresets;
use App\Enums\MotionPreset;
use App\Models\Story;
use Illuminate\Console\Command;

/**
 * Re-cut the camera across a story. Costs nothing.
 *
 * Deliberately its own command rather than a flag on `story:scenes`, because it
 * is the one scene-level edit that does not bill. `story:scenes --rebuild`
 * re-runs the generator and re-bills every act; this rewrites one column and
 * bills nothing, and the two should not be one keystroke apart.
 *
 * Runs at `scenes_approved` without reopening Gate 2, and that is correct
 * rather than a loophole: the gate exists to authorise SPEND, and motion is not
 * a paid input. `needsImage()` keys on the image prompt and `needsNarration()`
 * on the narration text; motion appears in neither, only in `needsClip()`,
 * which is CPU. Reopening the gate to change it would imply a cost that is not
 * there and would put the story back through an approval it already passed.
 */
class ScenesRecut extends Command
{
    protected $signature = 'scenes:recut
        {story : Story slug or id.}
        {--dry-run : Show the new distribution and change nothing.}';

    protected $description = 'Reassign motion presets across a story. Free — no generation, no re-bill.';

    public function handle(ReassignMotionPresets $recut): int
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

        $result = $recut->handle($story, apply: ! $this->option('dry-run'));

        $total = max(array_sum($result['after']), 1);

        $this->line('');
        $this->info($this->option('dry-run') ? 'Proposed re-cut' : 'Re-cut applied');
        $this->line('  story   '.$story->slug);
        $this->line('  scenes  '.$total);
        $this->line('');

        $this->line(sprintf('  %-12s %14s   %14s', '', 'before', 'after'));

        foreach (MotionPreset::cases() as $preset) {
            $before = $result['before'][$preset->value] ?? 0;
            $after = $result['after'][$preset->value] ?? 0;

            $this->line(sprintf(
                '  %-12s %6d %5.1f%%   %6d %5.1f%%%s',
                $preset->value,
                $before,
                $before / $total * 100,
                $after,
                $after / $total * 100,
                $preset === MotionPreset::Static ? '   <- awarded, not distributed' : '',
            ));
        }

        $this->line('');
        $this->line('  '.$result['changed'].' scene(s) change preset.');
        $this->info('  Cost: $0.0000 — narration and image prompts are untouched, so nothing is re-billed.');

        if ($this->option('dry-run')) {
            $this->line('  Dry run. Re-run without --dry-run to apply.');
        }

        return self::SUCCESS;
    }
}
