<?php

namespace App\Console\Commands;

use App\Actions\EstimateCharacterSheets;
use App\Actions\GenerateCharacterSheet;
use App\Actions\RecordSceneCast;
use App\Actions\SelectCharacterReference;
use App\Actions\ValidateCharacterSheets;
use App\Models\Character;
use App\Models\CharacterReference;
use App\Models\Story;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Throwable;

/**
 * The character sheet stage from the terminal.
 *
 * The pick itself belongs in the browser — choosing between four faces is not
 * something a terminal does well — but everything around it is better here:
 * pricing a run before committing to it, generating a cast overnight, checking
 * why Gate 2 will not open, and backfilling scene presence for the story that
 * was drafted before the pivot table existed.
 *
 * `--backfill-cast` is the one-off that matters right now. Scene presence used
 * to be computed during drafting and discarded; the reference rule needs it as
 * data. It is recovered from the cast block the prompt builder wrote into each
 * image prompt, which is free — re-running the scene generator to recover an
 * answer it already produced would be paying twice for it.
 */
class CharacterSheets extends Command
{
    protected $signature = 'characters:sheets
        {story : Story slug or id.}
        {--character= : Only this character, by name or id. Regenerates if they already have a face.}
        {--candidates= : Override how many candidates per character.}
        {--pick= : Select an existing candidate by reference id and generate nothing.}
        {--backfill-cast : Recover which characters are in which scene, then stop. Free.}
        {--dry-run : Price the run and stop. Nothing is generated and nothing bills.}
        {--yes : Skip the spend confirmation.}';

    protected $description = 'Generate and inspect character reference sheets for Gate 2.';

    public function handle(
        EstimateCharacterSheets $estimates,
        GenerateCharacterSheet $sheets,
        ValidateCharacterSheets $readiness,
        RecordSceneCast $cast,
        SelectCharacterReference $selector,
    ): int {
        $story = $this->story();

        if ($story === null) {
            return self::FAILURE;
        }

        if ($this->option('backfill-cast')) {
            return $this->backfill($story, $cast);
        }

        if ($this->option('pick') !== null) {
            return $this->pick($story, $selector);
        }

        $only = $this->only($story);

        if ($only === false) {
            return self::FAILURE;
        }

        $estimate = $estimates->handle($story, $only);

        $this->line('');
        $this->info("Story: {$story->title}");
        $this->line("  status     {$story->status->value}");
        $this->line('  provider   '.$estimate->provider.($estimate->model ? ' / '.$estimate->model : ''));
        $this->line('  cast       '.$estimate->castSize.' ('.$estimate->readyCount().' with a face)');
        $this->line('');

        $this->report($readiness->handle($story));

        if (! $estimate->billsAnything()) {
            $this->info('Every character who appears in a scene already has a reference. Nothing to do.');

            return self::SUCCESS;
        }

        $this->line('About to spend:');

        foreach ($estimate->summary() as $line) {
            $this->line('  '.$line);
        }

        if ($estimate->rateIsDeclared) {
            // Stated every run, not just the first. The figure is computed from
            // a rate declared in config because the provider returns no cost on
            // its response, and an operator who forgets that stops reconciling.
            $this->line('');
            $this->warn(
                '  This price is declared in config/providers.php, not returned by the provider. '
                .'Check it against your usage page.'
            );
        }

        $this->line('');

        if ($this->option('dry-run')) {
            $this->info('Dry run — nothing generated, nothing billed.');

            return self::SUCCESS;
        }

        if (! $story->canGenerateReferences()) {
            $this->error(
                "Sheets cannot be generated at '{$story->status->value}'. They are a Gate 2 "
                .'decision, so the scenes they will be used on have to be drafted first.'
            );

            return self::FAILURE;
        }

        if (! $this->option('yes') && ! $this->confirm(
            sprintf('Generate %d images for $%s?', $estimate->imagesTotal(), number_format($estimate->usdTotal(), 4)),
            false
        )) {
            $this->line('Nothing generated.');

            return self::FAILURE;
        }

        $spent = 0.0;
        $failures = 0;

        foreach ($estimate->pending as $character) {
            $this->line("  {$character->name} ...");

            try {
                $produced = $sheets->handle($character, $this->candidates());
            } catch (Throwable $e) {
                // One character's sheet failing outright does not abandon the
                // rest. The ones that worked are on disk and billed either way.
                $this->error('    '.$e->getMessage());
                $failures++;

                continue;
            }

            foreach ($produced as $reference) {
                $spent += (float) $reference->usd_cost;

                $this->line(sprintf(
                    '    b%02d-%02d  %-10s $%s%s',
                    $reference->batch,
                    $reference->sequence,
                    $reference->status->value,
                    number_format((float) $reference->usd_cost, 4),
                    $reference->error ? '  '.mb_substr($reference->error, 0, 80) : '',
                ));

                $failures += $reference->isUsable() ? 0 : 1;
            }
        }

        $this->line('');
        $this->info(sprintf('Spent $%s. %d failure(s).', number_format($spent, 4), $failures));
        $this->line('Pick one per character at '.route('stories.characters', $story));

        return $failures > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param  array{ready: bool, missing: Collection<int, Character>, scenes_blocked: int, unused: Collection<int, Character>}  $state
     */
    private function report(array $state): void
    {
        if ($state['ready']) {
            $this->info('  Gate 2 is clear: every character in a frame has a face.');
        } else {
            $this->warn(sprintf(
                '  Gate 2 is blocked: %s (%d scenes).',
                $state['missing']->pluck('name')->implode(', '),
                $state['scenes_blocked'],
            ));
        }

        if ($state['unused']->isNotEmpty()) {
            $this->line('  In the cast but in no frame (no sheet needed): '
                .$state['unused']->pluck('name')->implode(', '));
        }

        $this->line('');
    }

    private function backfill(Story $story, RecordSceneCast $cast): int
    {
        $result = $cast->backfill($story);

        $this->info(sprintf(
            'Recovered presence for %d scene(s), %d character link(s). Free — nothing billed.',
            $result['scenes'],
            $result['links'],
        ));

        if ($result['unresolved'] !== []) {
            $this->warn(
                '  Names in prompts matching nobody in the cast: '
                .implode(', ', $result['unresolved'])
                .'. Either the cast changed after drafting, or the generator invented a person.'
            );
        }

        return self::SUCCESS;
    }

    private function pick(Story $story, SelectCharacterReference $selector): int
    {
        $reference = CharacterReference::find((int) $this->option('pick'));

        if ($reference === null || $reference->character?->story_id !== $story->id) {
            $this->error('No candidate with that id in this story.');

            return self::FAILURE;
        }

        try {
            $stale = $selector->handle($reference->character, $reference);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            '%s locked to candidate b%02d-%02d.%s',
            $reference->character->name,
            $reference->batch,
            $reference->sequence,
            $stale > 0
                ? sprintf(' %d existing still(s) show the previous face and are now stale. Nothing '
                    .'was deleted — reopen Gate 2 to see what regenerating them costs.', $stale)
                : '',
        ));

        return self::SUCCESS;
    }

    /** @return Character|null|false False means "asked for, not found". */
    private function only(Story $story): Character|null|false
    {
        $key = $this->option('character');

        if ($key === null) {
            return null;
        }

        $character = $story->characters()
            ->where('name', $key)
            ->orWhere('id', ctype_digit((string) $key) ? (int) $key : 0)
            ->first();

        if ($character === null) {
            $this->error("No character matching '{$key}' in this story.");

            return false;
        }

        return $character;
    }

    private function candidates(): ?int
    {
        $value = $this->option('candidates');

        return $value === null ? null : (int) $value;
    }

    private function story(): ?Story
    {
        $key = (string) $this->argument('story');

        $story = Story::query()
            ->where('slug', $key)
            ->orWhere('id', ctype_digit($key) ? (int) $key : 0)
            ->first();

        if ($story === null) {
            $this->error("No story matching '{$key}'.");
        }

        return $story;
    }
}
