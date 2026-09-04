<?php

namespace App\Console\Commands;

use App\Actions\DispatchAssetGeneration;
use App\Actions\EstimateSceneAssets;
use App\Contracts\ImageGenerator;
use App\Contracts\SpeechSynthesizer;
use App\Contracts\Transcriber;
use App\Enums\OperatorAction;
use App\Exceptions\DispatchRefusedException;
use App\Models\Story;
use App\Support\SceneSelection;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Throwable;

/**
 * Queue a story's paid asset generation. The CLI half of Gate 2's spend button.
 *
 * Shows the itemised bill and asks, every time, because this is the largest
 * single spend in the product and "nothing is generated silently" has to hold
 * on a terminal as well as on a page. `--force` exists for a scripted re-run
 * and is the only way past the prompt.
 */
class AssetsGenerate extends Command
{
    protected $signature = 'assets:generate
        {story : Story slug or id.}
        {--scenes= : Restrict the run to these scene sequences: 5, 1-5, or 1-5,10,20-22.}
        {--estimate : Print the cost breakdown and exit without queueing anything.}
        {--force : Skip the confirmation. For scripted re-runs only.}
        {--no-worker-check : Dispatch even if a worker on the assets queue booted before the current code or config. }
        {--no-aligner-check : Dispatch even if whisperx cannot be imported by the configured interpreter.}
        {--no-style-check : Dispatch even if a character reference was drawn in a different art style.}';

    protected $description = 'Dispatch image, narration and word-timing generation for a story onto the assets queue.';

    public function handle(DispatchAssetGeneration $dispatcher, EstimateSceneAssets $estimator): int
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

        // The same predicate the Gate 2 button consults, checked BEFORE the
        // estimate rather than at the transition. Printing an itemised bill and
        // a confirmation prompt for a run that cannot legally start is how a
        // command comes to offer a move the state machine does not define.
        $refusal = OperatorAction::RegenerateAssets->refusal($story->status);

        if ($refusal !== null) {
            $this->error($refusal);

            return self::FAILURE;
        }

        try {
            $only = $this->option('scenes') === null
                ? null
                : SceneSelection::parse((string) $this->option('scenes'));
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        // Asked for but not present. Surfaced rather than quietly intersected:
        // `--scenes=1-200` on a 186-scene story is more likely a misremembered
        // length than an intention, and running 186 of it would teach the
        // operator that the flag is approximate.
        if ($only !== null) {
            $missing = $only->missingFrom($story->scenes()->pluck('sequence')->map(fn ($s): int => (int) $s)->all());

            if ($missing !== []) {
                $this->error(sprintf(
                    'This story has no scene(s) %s. It runs 1-%d.',
                    implode(', ', $missing),
                    (int) $story->scenes()->max('sequence'),
                ));

                return self::FAILURE;
            }
        }

        $estimate = $estimator->handle($story, $only);

        $this->info(sprintf('Scene assets for "%s" — %s', $story->title, $story->status->value));
        $this->newLine();

        $this->table(
            ['stage', 'pending', 'rate', 'usd', 'provider'],
            [
                ['stills', $estimate->imagesPending, '$'.number_format($estimate->usdPerImage, 4).' ea',
                    '$'.number_format($estimate->usdImages(), 4),
                    $estimate->imageProvider.($estimate->imageModel !== null ? ' / '.$estimate->imageModel : '')],
                ['narration', $estimate->narrationsPending,
                    number_format($estimate->speechCharacters).' chars @ $'.number_format($estimate->usdPerThousandSpeechCharacters, 4).'/1k',
                    '$'.number_format($estimate->usdNarration(), 4), $estimate->speechProvider],
                ['timings', $estimate->transcriptionsPending,
                    '~'.number_format($estimate->projectedAudioMinutes(), 1).' min @ $'.number_format($estimate->usdPerTranscribedMinute, 4),
                    '$'.number_format($estimate->usdTranscription(), 4), $estimate->transcriberProvider],
                ['', '', 'TOTAL', '$'.number_format($estimate->usdTotal(), 4), ''],
            ]
        );

        if ($estimate->preserved() > 0) {
            $this->line(sprintf(
                '  %d of %d scene(s) keep the assets they already have and are not billed again.',
                $estimate->preserved(),
                $estimate->scenesTotal,
            ));
        }

        if ($estimate->hasSimulatedStage()) {
            $this->newLine();
            $this->warn(sprintf(
                'SIMULATED — %s served by a stand-in, not a vendor.',
                implode(' and ', $estimate->simulatedStages),
            ));
            $this->line('  No vendor is contacted, nothing is billed, and the output is a flat-fill');
            $this->line('  PNG / silent WAV. The $0.00 above is for that reason, not because it is cheap.');
            $this->line('  Set PROVIDER_IMAGE_GENERATOR / PROVIDER_SPEECH_SYNTHESIZER /');
            $this->line('  PROVIDER_TRANSCRIBER in .env before spending. The scene-still key is NOT');
            $this->line('  PROVIDER_REFERENCE_IMAGE_GENERATOR, which only governs character sheets.');
        }

        if ($estimate->rateIsDeclared) {
            $this->newLine();
            $this->warn('These rates are DECLARED in config/providers.php, not billed back by the provider.');
            $this->line('  No image API returns a cost field on a generation response. Reconcile once');
            $this->line('  against the real usage page and correct it in that one place.');
        }

        // Resolved from the CONTAINER, immediately before the press, and shown
        // whether or not anything looks wrong.
        //
        // This is the check that was missing when a run quoted against `fal`
        // was served by a stand-in: config said one thing, the container held
        // another, and every screen in the app reported the config. The table
        // above is priced from these same instances, but it shows names —
        // this shows the CLASS, which is the thing that will actually run and
        // the thing a name cannot be wrong about.
        $this->newLine();
        $this->line('<comment>Resolved from the container (not config):</comment>');
        $this->table(
            ['contract', 'class', 'provider', 'model', 'simulated'],
            collect([
                'ImageGenerator' => app(ImageGenerator::class),
                'SpeechSynthesizer' => app(SpeechSynthesizer::class),
                'Transcriber' => app(Transcriber::class),
            ])->map(fn ($p, string $contract): array => [
                $contract,
                class_basename($p),
                $p->providerName(),
                (string) $p->modelName(),
                $p->isSimulated() ? 'YES' : 'no',
            ])->values()->all(),
        );

        if ($estimate->isLimited()) {
            $this->warn(sprintf('LIMITED RUN — scenes %s only.', (string) $estimate->selection));
            $this->line(sprintf(
                '  %d other scene(s) still need work and are NOT in this run. The story stays parked',
                $estimate->deferred,
            ));
            $this->line('  at assets_generating with them flagged, which is correct — it must not read as');
            $this->line('  finished. Re-run without --scenes to do the rest.');
        }

        if ($this->option('estimate')) {
            return self::SUCCESS;
        }

        if (! $estimate->billsAnything()) {
            $this->newLine();
            $this->info('Nothing outstanding. Every scene has its still, narration and word timings.');
        }

        if ($estimate->billsAnything() && ! $this->option('force')) {
            $this->newLine();

            $question = $estimate->hasSimulatedStage()
                ? sprintf('Generate %d placeholder job(s) with a stand-in provider ($0.00)?', $estimate->jobsTotal())
                : sprintf('Spend $%s across %d job(s)?', number_format($estimate->usdTotal(), 4), $estimate->jobsTotal());

            if (! $this->confirm($question, false)) {
                $this->line('Nothing queued.');

                return self::SUCCESS;
            }
        }

        try {
            $result = $dispatcher->handle(
                story: $story,
                only: $only,
                // Refusals, not warnings. `--force` deliberately does NOT reach
                // these: it skips the confirmation prompt, which is a question
                // about whether the operator wants to spend, and these are
                // questions about whether the machine would do what they are
                // paying for. A scripted re-run wants the first waived and the
                // second more than anyone.
                checkWorkers: ! $this->option('no-worker-check'),
                checkAligner: ! $this->option('no-aligner-check'),
                checkStyle: ! $this->option('no-style-check'),
            );
        } catch (DispatchRefusedException $e) {
            $this->newLine();
            $this->error($e->getMessage());

            return self::FAILURE;
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->newLine();

        // Printed AFTER the dispatch rather than before it, because the only
        // note that survives to here is an advisory one — anything that should
        // have stopped the run already threw. The no-worker warning in
        // particular has to be visible: the run is queued and nothing will move.
        foreach ($result['notes'] ?? [] as $note) {
            $note['level'] === 'warn'
                ? $this->warn($note['message'])
                : $this->line('<info>OK</info> — '.$note['message']);
        }

        $this->newLine();
        $this->info(sprintf('Queued %d job(s).', $result['dispatched']));
        $this->line('  batch  : '.($result['batch_id'] ?? '(none — nothing outstanding)'));
        $this->line('  queue  : '.config('render.queues.assets'));
        $this->line('  status : '.$story->refresh()->status->value);
        $this->newLine();
        $this->line('Word timings run as a second batch once the narration batch finishes — they');
        $this->line('transcribe the audio it writes. When every scene is complete the story moves to');
        $this->line('assets_ready and `php artisan render:dispatch` will run.');
        $this->newLine();
        $this->line('Watch it at /renders/'.$story->slug.' — there is no Horizon on this platform.');
        $this->newLine();
        $this->line('If nothing moves, no worker is running:');
        $this->line('  php artisan queue:work redis --queue=assets --tries=3 --max-time=3600');

        return self::SUCCESS;
    }
}
