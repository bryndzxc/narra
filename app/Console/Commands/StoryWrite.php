<?php

namespace App\Console\Commands;

use App\Actions\CreateStory;
use App\Actions\DispatchTextStage;
use App\Actions\GenerateActScripts;
use App\Actions\GenerateOutline;
use App\Enums\CostCategory;
use App\Enums\OperatorAction;
use App\Enums\StoryEnding;
use App\Enums\StoryFormat;
use App\Exceptions\LocaleViolationException;
use App\Models\Act;
use App\Models\Story;
use App\Support\FailureRemedy;
use App\Support\ModelRoster;
use App\Support\NarrationPace;
use App\Support\NarratorVoice;
use App\Support\Providers\ActScriptDraft;
use App\Support\ScriptSizing;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Throwable;

/**
 * Write a story: outline, then every act, in order.
 *
 * The operator-facing entry point for Phase 2a. It spends real money, so it
 * says what it is about to spend it on and asks first — and it reports what it
 * actually cost against the story's own cost_entries rows rather than against
 * an estimate, because the estimate is the thing being checked.
 *
 * Deliberately stops at `outlined` or `scripted`. There is no flag that carries
 * on into scenes or assets: Gate 1 and Gate 2 are operator decisions and this
 * command's job ends where theirs begins.
 */
class StoryWrite extends Command
{
    protected $signature = 'story:write
        {story? : Story slug or id. Omit to create a new one from --premise.}
        {--premise= : Premise for a new story.}
        {--narrator= : male or female — who narrates a new story, which picks the voice. Required with --premise.}
        {--ending= : new_life or antagonist_voice — how a new single-narrative story ends. Required with --premise.}
        {--title= : Working title for a new story.}
        {--format=single : single or anthology. Single is the default: this genre needs one continuous narrative to escalate.}
        {--acts= : Number of acts. Defaults to 5 for anthology, 6 for single.}
        {--min=30 : Target minimum runtime, minutes.}
        {--max=40 : Target maximum runtime, minutes.}
        {--outline-only : Stop after the outline, before any act is written.}
        {--acts-only= : Comma-separated act sequences to rewrite. Implies the outline exists.}
        {--queue : Dispatch to the text queue instead of writing here. What the Gate 1 button does.}
        {--yes : Skip the spend confirmation.}';

    protected $description = 'Generate a story outline and its act scripts, chunked and sequential.';

    public function handle(GenerateOutline $outline, GenerateActScripts $scripts): int
    {
        try {
            $story = $this->resolveStory();
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->line('');
        $this->info("Story: {$story->title}");
        $this->line("  slug            {$story->slug}");
        $this->line("  format          {$story->format->value}");
        $this->line("  locale          {$story->locale_profile}");
        $this->line("  target runtime  {$story->target_duration_min}-{$story->target_duration_max} min");
        $this->line("  status          {$story->status->value}");
        $this->line('  provider        '.config('providers.script_writer'));

        foreach (app(ModelRoster::class)->lines(ModelRoster::SCRIPT_OPERATIONS) as $line) {
            $this->line('  '.$line);
        }
        $this->line('');

        // The same predicate the Gate 1 button consults. This command had its
        // own opinion about status — GenerateOutline::assertReady() refuses
        // past `outlined`, the acts stage refuses separately, and neither of
        // them is the sentence the page shows. `assets:generate` and its button
        // disagreed this way for a whole phase.
        $refusal = OperatorAction::WriteScript->refusal($story->status);

        if ($refusal !== null && $this->parseActsOnly() === []) {
            $this->error($refusal);

            return self::FAILURE;
        }

        if (! $this->confirmSpend($story)) {
            return self::FAILURE;
        }

        if ($this->option('queue')) {
            return $this->queueInstead($story);
        }

        $startedAt = microtime(true);
        $actsOnly = $this->parseActsOnly();

        try {
            if ($actsOnly === [] && $story->acts()->count() === 0) {
                $this->outlineStage($story, $outline);
            } elseif ($actsOnly === []) {
                $this->line('Outline already exists — keeping it. Use a fresh story to regenerate.');
            }

            if ($this->option('outline-only')) {
                $this->line('');
                $this->info('Stopped after the outline, as asked. Gate 1 is where it gets reviewed.');
                $this->report($story->refresh(), $startedAt);

                return self::SUCCESS;
            }

            $this->actStage($story->refresh(), $scripts, $actsOnly);
        } catch (LocaleViolationException $e) {
            // Distinct from a generic failure: the tokens were spent and the
            // cost rows are already written, so the report below still runs.
            $this->line('');
            $this->error($e->getMessage());
            $this->printRemedy($e, $story);
            $this->report($story->refresh(), $startedAt);

            return self::FAILURE;
        } catch (Throwable $e) {
            $this->line('');
            $this->error($e->getMessage());
            $this->printRemedy($e, $story);
            $this->report($story->refresh(), $startedAt);

            return self::FAILURE;
        }

        $this->report($story->refresh(), $startedAt);

        return self::SUCCESS;
    }

    private function outlineStage(Story $story, GenerateOutline $outline): void
    {
        $this->line('Outline...');

        $actCount = $this->option('acts') !== null ? (int) $this->option('acts') : null;
        $draft = $outline->handle($story, $actCount);

        $this->line('');
        $this->info("  \"{$draft->title}\"");

        foreach ($draft->acts as $act) {
            $this->line(sprintf('  %d. %s', $act->sequence, $act->title));
            $this->line(sprintf('     %s', wordwrap($act->summary, 86, "\n     ")));
        }

        $this->line('');
        $this->line('  '.$draft->usage->summary());
        $this->line('');
    }

    /**
     * @param  array<int, int>  $only
     */
    private function actStage(Story $story, GenerateActScripts $scripts, array $only): void
    {
        $total = $story->acts()->count();
        $this->line("Act scripts ({$total}, sequential — each one is written knowing the ones before it)...");
        $this->line('');

        $scripts->handle($story, $only, function (Act $act, ?ActScriptDraft $draft, string $note): void {
            $this->line(sprintf(
                '  %d. %-38s %s',
                $act->sequence,
                Str::limit($act->title, 36),
                $draft === null ? $note : $note.'   '.$draft->usage->summary()
            ));
        });

        $this->line('');

        // Kept, not refused, since 2026-09-17: printed first and as errors,
        // because at Gate 1 they are the louder of the two.
        foreach ($scripts->localeDenied($story) as $hit) {
            $this->error(sprintf(
                '  locale DENIED term kept for Gate 1, %s %s: "%s" — ...%s...%s',
                $hit['act'] === null ? 'outline' : 'act '.$hit['act'],
                $hit['where'],
                $hit['term'],
                $hit['context'],
                // Not a judgement in a script: scene drafting refuses it.
                $hit['editable'] ? '' : ' Scene drafting refuses this; it has to come out of the script first.',
            ));
        }

        foreach ($scripts->localeWarnings($story) as $warning) {
            $this->warn(sprintf(
                '  locale warning, act %d: "%s" — ...%s...',
                $warning['act'],
                $warning['term'],
                $warning['context']
            ));
        }
    }

    /**
     * What this is about to spend, before it spends it.
     *
     * Not ceremony. Every act is a billed call and a five-act story is six of
     * them; a command that starts spending on being typed is one typo from a
     * duplicate run.
     */
    private function confirmSpend(Story $story): bool
    {
        if ($this->option('yes') || ! $this->input->isInteractive()) {
            return true;
        }

        // The Action's answer, not a literal. This said 5 whatever the format
        // was, so the confirmation before eight billed calls announced six.
        $acts = $story->acts()->count()
            ?: (int) ($this->option('acts') ?: GenerateOutline::defaultActCountFor($story));
        $calls = $story->acts()->count() === 0 ? $acts + 1 : $acts;

        $this->warn(sprintf(
            'This makes %d billed API calls against %s and writes a cost row for each.',
            $calls,
            app(ModelRoster::class)->summary(ModelRoster::SCRIPT_OPERATIONS)
        ));

        return $this->confirm('Continue?', true);
    }

    /**
     * What it actually cost and how long the script actually is.
     *
     * Read from cost_entries rather than accumulated in memory: the table is
     * the thing that has to be able to answer this, so the command proves it
     * can rather than reporting its own running total.
     */
    private function report(Story $story, float $startedAt): void
    {
        $words = $story->acts()->get()->sum(fn (Act $act): int => str_word_count((string) $act->script));

        // What this script will RUN to, which is the narrator's measured rate
        // and not the rate the script was sized against. Those were the same
        // number while both came from the constant, and reporting the sizing
        // rate here would have said story 9's 5,781 words run 36.1 minutes when
        // the render came back at 29:39.
        $minutes = ScriptSizing::minutesFor($story, $words);
        $wpm = NarrationPace::bestKnownWpm($story->voice_id, $story->locale_profile);

        $entries = $story->costEntries()->get();
        $text = $entries->where('category', CostCategory::Text);

        $this->line('');
        $this->line(str_repeat('-', 72));
        $this->info('Result');
        $this->line(str_repeat('-', 72));

        $this->table(
            ['', ''],
            [
                ['status', $story->status->value],
                ['acts written', $story->acts()->whereNotNull('script')->count().' of '.$story->acts()->count()],
                ['rehooks written', $story->acts()->where('is_rehook_written', true)->count()],
                // The unit the viewer gets a re-hook in. Six acts of the
                // writer's natural length should read as about fifteen here;
                // a count near six means the writer returned one chapter per
                // act, which the Action refuses, so a low figure is a bug.
                ['chapters', $story->chapters()->count().' ('.$story->chapters()->whereNotNull('rehook_line')->count().' with a re-hook)'],
                ['words', number_format($words)],
                ['target', '5,500-8,000 words'],
                ['in target', $words >= 5500 && $words <= 8000 ? 'yes' : 'NO'],
                ['est. runtime', sprintf('%.1f min @ %d wpm', $minutes, $wpm)],
                ['runtime target', "{$story->target_duration_min}-{$story->target_duration_max} min"],
                ['in runtime window', $minutes >= $story->target_duration_min && $minutes <= $story->target_duration_max ? 'yes' : 'NO'],
                ['', ''],
                ['api calls', (string) $entries->count()],
                ['tokens', number_format((float) $entries->sum('quantity'))],
                ['text spend', '$'.number_format((float) $text->sum('usd_cost'), 4)],
                ['asset spend', '$'.number_format((float) $entries->where('category', CostCategory::Asset)->sum('usd_cost'), 4)],
                ['TOTAL (cost_entries)', '$'.number_format((float) $entries->sum('usd_cost'), 4)],
                ['TOTAL (stories row)', '$'.number_format((float) $story->total_cost_usd, 4)],
                ['', ''],
                ['wall clock', sprintf('%.1f s', microtime(true) - $startedAt)],
            ]
        );

        $this->line('');
        $this->line('Per-call breakdown:');

        foreach ($entries as $entry) {
            $this->line(sprintf(
                '  %-22s %-8s %10s tok   $%s',
                $entry->operation,
                $entry->category->value,
                number_format((float) $entry->quantity),
                number_format((float) $entry->usd_cost, 4)
            ));
        }

        $this->line('');
        $this->line("Review it at Gate 1: /stories/{$story->slug}/gate/1");
    }

    /**
     * @return array<int, int>
     */
    private function parseActsOnly(): array
    {
        $raw = (string) $this->option('acts-only');

        if (trim($raw) === '') {
            return [];
        }

        return array_values(array_filter(array_map(
            fn (string $part): int => (int) trim($part),
            explode(',', $raw)
        )));
    }

    private function resolveStory(): Story
    {
        $key = (string) $this->argument('story');

        if ($key !== '') {
            $story = Story::query()
                ->where('slug', $key)
                ->orWhere('id', ctype_digit($key) ? (int) $key : 0)
                ->first();

            if ($story === null) {
                throw new \RuntimeException("No story matching '{$key}'.");
            }

            return $story;
        }

        $premise = trim((string) $this->option('premise'));

        if ($premise === '') {
            throw new \RuntimeException(
                'Give a story slug, or --premise to start a new one. The premise is the operator\'s '
                .'editorial input and there is no default for it.'
            );
        }

        // Required, and deliberately without a default: a default here would be
        // the per-story narrator pick in disguise, and it is the trap that left
        // a woman's story on the male voice until somebody noticed (story 33).
        $narrator = trim((string) $this->option('narrator'));

        if (! in_array($narrator, NarratorVoice::GENDERS, true)) {
            throw new \RuntimeException(
                'Give --narrator=male or --narrator=female with --premise. It picks the voice from the '
                .'channel\'s one-voice-per-narrator-gender table, and it has no default.'
            );
        }

        // Required on a single narrative and without a default, for the
        // narrator's reason. See App\Enums\StoryEnding.
        $format = StoryFormat::from((string) $this->option('format'));
        $ending = StoryEnding::tryFrom(trim((string) $this->option('ending')));

        if ($format === StoryFormat::Single && $ending === null) {
            throw new \RuntimeException(sprintf(
                'Give --ending with --premise: %s. The outline writes what the chosen ending needs, and '
                .'it has no default.',
                implode(' or ', array_map(
                    static fn (StoryEnding $e): string => '--ending='.$e->value.' ('.$e->label().')',
                    StoryEnding::cases(),
                )),
            ));
        }

        // One implementation, two front doors. This was the only copy of story
        // creation in the app for the whole of Phase 2, which is why the tool
        // built so an operator would not need a terminal required one to begin.
        return app(CreateStory::class)->handle(
            premise: $premise,
            title: (string) $this->option('title'),
            format: $format,
            targetMin: (int) $this->option('min'),
            targetMax: (int) $this->option('max'),
            narrator: $narrator,
            ending: $ending,
        );
    }

    /**
     * Hand the same work to the `text` queue instead of doing it here.
     *
     * The command keeps its synchronous default — somebody typed it, somebody
     * is watching, and six sequential calls is a reasonable thing to sit
     * through when you chose to. This flag exists so the command can exercise
     * the path the button takes, which is the only way a seam between the two
     * gets crossed by anything other than an operator.
     */
    private function queueInstead(Story $story): int
    {
        try {
            $result = app(DispatchTextStage::class)->writeScript(
                story: $story,
                actCount: $this->option('acts') !== null ? (int) $this->option('acts') : null,
                actsOnly: $this->parseActsOnly(),
                outlineOnly: (bool) $this->option('outline-only'),
            );
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        foreach ($result['notes'] as $note) {
            $note['level'] === 'warn'
                ? $this->warn($note['message'])
                : $this->line('<info>OK</info> — '.$note['message']);
        }

        $this->newLine();
        $this->info(sprintf('Queued on the "%s" queue. Watch it at /renders/%s.', $result['queue'], $story->slug));

        return self::SUCCESS;
    }

    /**
     * The repair for a failure caught here, from the same builder the progress
     * page uses. The exception message holds facts only since 2026-09-17, so
     * without this the terminal would have lost the advice it used to carry.
     */
    private function printRemedy(Throwable $e, Story $story): void
    {
        foreach (FailureRemedy::consoleLines($e, $story->refresh()) as $line) {
            $this->line($line);
        }
    }
}
