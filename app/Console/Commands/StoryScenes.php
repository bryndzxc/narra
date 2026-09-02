<?php

namespace App\Console\Commands;

use App\Actions\DraftScenes;
use App\Actions\ExtractCharacters;
use App\Actions\ValidateSceneDrafts;
use App\Enums\CostCategory;
use App\Models\Act;
use App\Models\Story;
use App\Support\ModelRoster;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Throwable;

/**
 * Phase 2b: cast, then scenes. The stage that fills Gate 2.
 *
 * Characters first and always, because their descriptions are pasted verbatim
 * into every image prompt and a scene drafted before the cast exists has to
 * invent one. Still text-only — nothing here generates an image or a second of
 * audio, and Gate 2 is where the operator decides whether any of that happens.
 */
class StoryScenes extends Command
{
    protected $signature = 'story:scenes
        {story : Story slug or id.}
        {--rebuild : Discard the existing cast and scenes and draft them again.}
        {--cast-only : Extract characters and stop, before any scene is drafted.}
        {--acts= : Comma-separated act sequences to re-draft, keeping every other act as it is. Implies the cast already exists.}
        {--yes : Skip the spend confirmation.}';

    protected $description = 'Extract the cast, then split the act scripts into scenes for Gate 2.';

    public function handle(ExtractCharacters $characters, DraftScenes $scenes, ValidateSceneDrafts $review): int
    {
        $story = Story::query()
            ->where('slug', $key = (string) $this->argument('story'))
            ->orWhere('id', ctype_digit($key) ? (int) $key : 0)
            ->first();

        if ($story === null) {
            $this->error("No story matching '{$key}'.");

            return self::FAILURE;
        }

        $this->line('');
        $this->info("Story: {$story->title}");
        $this->line("  status   {$story->status->value}");
        $this->line('  acts     '.$story->acts()->count().' ('.number_format($this->words($story)).' words)');
        $this->line('  provider '.config('providers.script_writer'));

        foreach (app(ModelRoster::class)->lines(['extract_characters', 'draft_scenes']) as $line) {
            $this->line('  '.$line);
        }
        $this->line('');

        if (! $this->confirmSpend($story)) {
            return self::FAILURE;
        }

        $startedAt = microtime(true);

        try {
            $this->castStage($story, $characters);

            if ($this->option('cast-only')) {
                $this->line('');
                $this->info('Stopped after the cast, as asked.');
                $this->report($story->refresh(), $review, $startedAt);

                return self::SUCCESS;
            }

            $this->sceneStage($story->refresh(), $scenes, $this->actsOption());
        } catch (Throwable $e) {
            $this->line('');
            $this->error($e->getMessage());
            $this->report($story->refresh(), $review, $startedAt);

            return self::FAILURE;
        }

        $this->report($story->refresh(), $review, $startedAt);

        return self::SUCCESS;
    }

    private function castStage(Story $story, ExtractCharacters $characters): void
    {
        $this->line('Cast...');

        // A partial scene re-draft must NOT re-extract the cast. Descriptions
        // are pasted verbatim into every prompt in the story, so replacing them
        // would leave the acts this run is not touching built from a cast that
        // no longer exists — and would re-bill an extraction to fix one act.
        $result = $characters->handle($story, (bool) $this->option('rebuild') && $this->actsOption() === []);

        if ($result['kept']) {
            $this->line("  kept existing cast of {$result['characters']} — pass --rebuild to replace it.");
            $this->line('');

            return;
        }

        $this->line('');

        foreach ($story->characters()->orderBy('id')->get() as $character) {
            $this->line(sprintf('  %-20s seed %-12s', $character->name, $character->seed));
            $this->line('    '.wordwrap((string) $character->description, 84, "\n    "));
        }

        $this->line('');
        $this->line('  These descriptions are pasted verbatim into every prompt the character appears in.');
        $this->line('');
    }

    /**
     * @param  array<int, int>  $onlyActs
     */
    private function sceneStage(Story $story, DraftScenes $scenes, array $onlyActs = []): void
    {
        $this->line($onlyActs === []
            ? 'Scenes (one call per act)...'
            : 'Scenes — re-drafting act '.implode(', ', $onlyActs).' only. Every other act keeps its scenes.');
        $this->line('');

        $result = $scenes->handle(
            $story,
            (bool) $this->option('rebuild'),
            function (Act $act, int $count, string $note): void {
                $this->line(sprintf('  act %d  %-34s %3d scenes   %s', $act->sequence, Str::limit($act->title, 32), $count, $note));
            },
            $onlyActs,
        );

        if ($result['kept']) {
            $this->line("  kept existing {$result['scenes']} scenes — pass --rebuild to replace them.");
        }

        $this->line('');
    }

    /**
     * @return array<int, int>
     */
    private function actsOption(): array
    {
        $raw = trim((string) $this->option('acts'));

        if ($raw === '') {
            return [];
        }

        return array_values(array_filter(array_map(
            fn (string $part): int => (int) trim($part),
            explode(',', $raw)
        ), fn (int $sequence): bool => $sequence > 0));
    }

    private function confirmSpend(Story $story): bool
    {
        if ($this->option('yes') || ! $this->input->isInteractive()) {
            return true;
        }

        $onlyActs = $this->actsOption();

        // A partial re-draft is one call per named act and no cast call — the
        // confirmation has to say the real number, or the operator learns that
        // the number is decorative.
        $calls = $onlyActs !== []
            ? count($onlyActs)
            : 1 + ($this->option('cast-only') ? 0 : $story->acts()->count());

        $this->warn(sprintf(
            'This makes %d billed API calls against %s. Still text only — no images, no audio.',
            $calls,
            app(ModelRoster::class)->summary(['extract_characters', 'draft_scenes'])
        ));

        return $this->confirm('Continue?', true);
    }

    private function report(Story $story, ValidateSceneDrafts $review, float $startedAt): void
    {
        $result = $review->handle($story);
        $stats = $result['stats'];
        $entries = $story->costEntries()->get();
        $stage = $entries->whereIn('operation', ['extract_characters', 'draft_scenes']);

        $this->line(str_repeat('-', 72));
        $this->info('Result');
        $this->line(str_repeat('-', 72));

        $this->table(['', ''], [
            ['status', $story->status->value],
            ['characters', (string) $story->characters()->count()],
            ['scenes', (string) ($stats['scenes'] ?? 0)],
            ['narration words', number_format((float) ($stats['words'] ?? 0))],
            ['avg words / scene', (string) ($stats['avg_words'] ?? 0)],
            ['shortest / longest', ($stats['min_words'] ?? 0).' / '.($stats['max_words'] ?? 0).' words'],
            ['thumbnail candidates', (string) ($stats['thumbnail_candidates'] ?? 0)],
            ['hook scene', (string) ($story->scenes()->where('is_hook', true)->value('sequence') ?? 'none')],
            ['', ''],
            ['this stage', '$'.number_format((float) $stage->sum('usd_cost'), 4).' over '.$stage->count().' calls'],
            ['story total', '$'.number_format((float) $story->total_cost_usd, 4)],
            ['asset spend', '$'.number_format((float) $entries->where('category', CostCategory::Asset)->sum('usd_cost'), 4)],
            ['', ''],
            ['wall clock', sprintf('%.1f s', microtime(true) - $startedAt)],
        ]);

        if ($stats !== [] && isset($stats['motion'])) {
            $this->line('');
            $this->line('Motion mix:');

            foreach ($stats['motion'] as $preset => $count) {
                $this->line(sprintf('  %-12s %4d  %d%%', $preset, $count, (int) round($count / max(1, $stats['scenes']) * 100)));
            }
        }

        foreach ($result['warnings'] as $warning) {
            $this->line('');
            $this->warn('  '.wordwrap($warning, 86, "\n  "));
        }

        $this->line('');
        $this->line("Review every scene at Gate 2: /stories/{$story->slug}/gate/2");
        $this->line('Nothing has billed for an asset yet. Gate 2 is where that starts.');
    }

    private function words(Story $story): int
    {
        return $story->acts()->get()->sum(fn (Act $act): int => str_word_count((string) $act->script));
    }
}
