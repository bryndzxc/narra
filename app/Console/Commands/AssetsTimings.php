<?php

namespace App\Console\Commands;

use App\Actions\DispatchAssetGeneration;
use App\Actions\PreflightAssetDispatch;
use App\Contracts\SpeechSynthesizer;
use App\Exceptions\DispatchRefusedException;
use App\Models\Scene;
use App\Models\Story;
use App\Support\SceneChangeSet;
use App\Support\SceneSelection;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Throwable;

/**
 * Re-run word timings, and ONLY word timings.
 *
 * **Why this is a separate command rather than a flag on `assets:generate`.**
 * The two commands want opposite things from the same computation.
 * `assets:generate` asks SceneChangeSet what is outstanding and dispatches all
 * of it, which is right — but `needsTranscription` and `needsNarration` overlap
 * heavily, because narration provenance moving stales both. So on the story this
 * command was written for, "retry the 181 failed alignments" through
 * `assets:generate` would also have re-billed 69 narrations, and there is no
 * flag arrangement that makes that safe to get wrong.
 *
 * Here it cannot happen. The only job class this file can dispatch is
 * TranscribeSceneTimingsJob. That is a structural guarantee rather than a
 * careful one, which is the distinction this project keeps learning: a check
 * that something will not bill is worth less than an arrangement in which
 * billing is unreachable.
 *
 * Alignment is free and local, so there is no confirmation prompt and no
 * estimate — but there IS a preflight, because the free stage is exactly the one
 * that failed 181 times in a row, one job at a time, with nothing asking first
 * whether the interpreter could import the module.
 */
class AssetsTimings extends Command
{
    protected $signature = 'assets:timings
        {story : Story slug or id.}
        {--scenes= : Restrict to these scene sequences: 5, 1-5, or 1-5,10,20-22.}
        {--dry-run : Report what would be queued and exit.}
        {--no-worker-check : Queue even if the workers disagree with this process. }
        {--no-aligner-check : Queue even if whisperx cannot be imported here.}';

    protected $description = 'Re-run word-level alignment for scenes that have audio but no timings. Free; never bills TTS.';

    public function handle(PreflightAssetDispatch $preflight): int
    {
        $story = $this->story();

        if ($story === null) {
            $this->error(sprintf('No story matching "%s".', (string) $this->argument('story')));

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

        $changes = SceneChangeSet::for($story, $only);

        // The set this command will actually queue — timings only, and only for
        // scenes that have audio to align. Recomputed the same way
        // DispatchAssetGeneration::dispatchTimings() does, so the number printed
        // here is the number dispatched.
        $pending = $changes->needsTranscription
            ->filter(fn (Scene $s): bool => $s->sceneAudio->contains(
                fn ($audio): bool => $audio->audio_path !== null
            ))
            ->values();

        $this->info(sprintf('Word timings for "%s" — %s', $story->title, $story->status->value));
        $this->newLine();

        $this->line(sprintf(
            '   %d of %d scene(s) need timings and have audio to align.',
            $pending->count(),
            $changes->sceneCount,
        ));

        // The whole reason this command exists, stated every time rather than
        // only when it bites. An operator reaching for "retry the timings" is
        // entitled to know that the obvious other route would have spent money.
        $this->reportWhatIsNotBeingDone($changes);

        if ($pending->isEmpty()) {
            $this->newLine();
            $this->info('Nothing to align.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->newLine();
            $this->line('Dry run — nothing queued.');

            return self::SUCCESS;
        }

        try {
            // The same preflight the paid path runs, minus the parts that only
            // matter to a spend. A stale worker matters here too: alignment
            // writes timings_json and the provenance that goes with it.
            foreach ($preflight->handle(
                story: $story,
                changes: $changes,
                checkWorkers: ! $this->option('no-worker-check'),
                checkAligner: ! $this->option('no-aligner-check'),
            ) as $note) {
                $note['level'] === 'warn'
                    ? $this->warn('   '.$note['message'])
                    : $this->line('   <info>OK</info> — '.$note['message']);
            }
        } catch (DispatchRefusedException $e) {
            $this->newLine();
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        try {
            $batchId = DispatchAssetGeneration::dispatchTimings(
                $story->id,
                $only?->sequences,
            );
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->info(sprintf('Queued %d alignment job(s). No TTS call is possible from this command.', $pending->count()));
        $this->line('  batch : '.($batchId ?? '(none)'));
        $this->line('  queue : '.config('render.queues.assets'));
        $this->newLine();
        $this->line('Watch it at /renders/'.$story->slug);

        return self::SUCCESS;
    }

    /**
     * Say plainly what `assets:generate` would have done instead.
     *
     * Not a footnote. On the story this was written for, the two routes differ
     * by 69 paid narrations — and, worse, the narration set and the timing set
     * overlap in a way that makes the expensive route look like the obvious one.
     */
    private function reportWhatIsNotBeingDone(SceneChangeSet $changes): void
    {
        $narrations = $changes->needsNarration->count();

        if ($narrations === 0) {
            return;
        }

        $characters = (int) $changes->needsNarration->sum(
            fn (Scene $s): int => mb_strlen(trim((string) $s->narration_text))
        );

        $speech = app(SpeechSynthesizer::class);
        $credits = method_exists($speech, 'creditsPerCharacter')
            ? $characters * $speech->creditsPerCharacter()
            : null;

        $this->newLine();
        $this->warn(sprintf(
            '   NOT doing: %d scene(s) whose narration is also stale%s.',
            $narrations,
            $credits === null ? '' : sprintf(' — %s credits if regenerated', number_format($credits)),
        ));
        $this->line('   `assets:generate` would queue those as well, because a scene whose narration');
        $this->line('   provenance moved needs both. This command will not. If the audio is genuinely');
        $this->line('   wrong, re-narrate deliberately with `assets:generate` — do not reach it by way');
        $this->line('   of a timings retry.');
    }

    private function story(): ?Story
    {
        $key = (string) $this->argument('story');

        return Story::query()
            ->where('slug', $key)
            ->orWhere('id', ctype_digit($key) ? (int) $key : 0)
            ->first();
    }
}
