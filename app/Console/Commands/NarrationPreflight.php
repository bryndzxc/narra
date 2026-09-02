<?php

namespace App\Console\Commands;

use App\Contracts\SpeechSynthesizer;
use App\Contracts\Transcriber;
use App\Models\Scene;
use App\Models\Story;
use App\Services\ElevenLabs\ElevenLabsSpeechSynthesizer;
use App\Services\WhisperX\WhisperXTranscriber;
use App\Support\SceneChangeSet;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Everything that can be checked about a narration run without paying for it.
 *
 * Five questions, in the order that failing one makes the next irrelevant:
 *
 *   1. What is ACTUALLY bound? Resolved from the container, not read from
 *      config. This is the check that would have caught the run where a missing
 *      `PROVIDER_IMAGE_GENERATOR` sent 186 stills to a stand-in while every
 *      screen named a vendor and $8.12 went into the ledger for calls nobody
 *      made.
 *
 *   2. Is the voice real? `voice_id` has been `narrator-us-01` on every story in
 *      the database since Phase 1 — a string FakeSpeechSynthesizer invented. It
 *      is not a voice on any vendor, and unchecked it fails a paid batch one
 *      scene at a time.
 *
 *   3. Does it FIT? The distinctive risk of this vendor, and the one a cost
 *      estimate structurally cannot see. On a plan without overage, ElevenLabs
 *      does not bill past the allowance — it refuses. So the failure is not an
 *      unexpected charge, it is a half-narrated story: scenes 1-170 paid for and
 *      done, 171-186 refused, and the allowance gone either way.
 *
 *   4. Does the aligner run? WhisperX is a local Python process, and "not
 *      installed" is indistinguishable from "silently exited" until something
 *      asks it to do real work.
 *
 *   5. Do the workers agree? A queue worker holds the container it booted with,
 *      so a `.env` edit does not reach a running one. `assets:generate` pins the
 *      provider into each job for exactly this reason; this says out loud that
 *      the workers must be restarted.
 *
 * Nothing here spends money except `--align`, which is free, and neither
 * generates a paid asset.
 */
class NarrationPreflight extends Command
{
    protected $signature = 'narration:preflight
        {story : Story slug or id.}
        {--align : Also run one real WhisperX alignment against a scene that already has audio.}';

    protected $description = 'Check providers, voice, quota and the aligner before spending on narration.';

    private bool $blocked = false;

    public function handle(): int
    {
        $story = $this->story();

        if ($story === null) {
            $this->error(sprintf('No story matching "%s".', (string) $this->argument('story')));

            return self::FAILURE;
        }

        $this->info(sprintf('Narration pre-flight — "%s" (%s)', $story->title, $story->status->value));
        $this->newLine();

        $speech = app(SpeechSynthesizer::class);
        $transcriber = app(Transcriber::class);

        $this->reportBindings($speech, $transcriber);
        $this->reportVoice($story, $speech);
        $outstanding = $this->reportWorkload($story, $speech);
        $this->reportQuota($speech, $outstanding);
        $this->reportAligner($story, $transcriber);
        $this->reportWorkers();

        $this->newLine();

        if ($this->blocked) {
            $this->error('BLOCKED — fix the items marked above before running assets:generate.');

            return self::FAILURE;
        }

        $this->info('Clear. `php artisan assets:generate '.$story->slug.'` will do real work.');

        return self::SUCCESS;
    }

    /**
     * What the container hands back, which is the only authority on this.
     */
    private function reportBindings(SpeechSynthesizer $speech, Transcriber $transcriber): void
    {
        $this->line('<comment>1. Bindings (resolved from the container, not config)</comment>');

        $this->table(
            ['contract', 'class', 'provider', 'model', 'simulated'],
            [
                [
                    'SpeechSynthesizer',
                    class_basename($speech),
                    $speech->providerName(),
                    (string) $speech->modelName(),
                    $speech->isSimulated() ? 'YES' : 'no',
                ],
                [
                    'Transcriber',
                    class_basename($transcriber),
                    $transcriber->providerName(),
                    (string) $transcriber->modelName(),
                    $transcriber->isSimulated() ? 'YES' : 'no',
                ],
            ],
        );

        foreach (['speech_synthesizer' => $speech, 'transcriber' => $transcriber] as $key => $provider) {
            $configured = (string) config("providers.{$key}");

            if ($configured !== $provider->providerName()) {
                // Not fatal — the testing environment binds fakes over config
                // by design — but always worth saying out loud, because a
                // silent disagreement between these two is the specific failure
                // that cost this project a full render.
                $this->warn(sprintf(
                    '   config providers.%s = "%s" but the container resolved "%s".',
                    $key,
                    $configured,
                    $provider->providerName(),
                ));
            }
        }

        if ($speech->isSimulated()) {
            $this->block('Narration is SIMULATED. Set PROVIDER_SPEECH_SYNTHESIZER=elevenlabs in .env.');
        }

        if ($transcriber->isSimulated()) {
            $this->block('Word timings are SIMULATED. Set PROVIDER_TRANSCRIBER=whisperx in .env.');
        }

        $this->newLine();
    }

    private function reportVoice(Story $story, SpeechSynthesizer $speech): void
    {
        $this->line('<comment>2. Voice</comment>');

        $voiceId = trim((string) $story->voice_id);

        if ($voiceId === '') {
            $this->block('The story has no voice_id. Run `php artisan voices:list --set='.$story->slug.' --voice=<id>`.');
            $this->newLine();

            return;
        }

        $this->line(sprintf('   stories.voice_id = %s', $voiceId));

        if ($speech->isSimulated()) {
            $this->line('   (not validated — a stand-in accepts any voice id.)');
            $this->newLine();

            return;
        }

        try {
            $voices = collect($speech->voices());
        } catch (Throwable $e) {
            $this->warn('   Could not list voices to validate against: '.$e->getMessage());
            $this->newLine();

            return;
        }

        $match = $voices->firstWhere('id', $voiceId);

        if ($match === null) {
            $this->block(sprintf(
                'Voice "%s" is not on this account. This is the fake\'s placeholder id if it reads '
                .'"narrator-us-01" — every story carries it, because nothing ever called voices(). '
                ."Pick a real one:\n     php artisan voices:list --set=%s --voice=<id>",
                $voiceId,
                $story->slug,
            ));
        } else {
            $this->line(sprintf('   <info>OK</info> — %s', $match['name']));
        }

        $this->newLine();
    }

    /**
     * How much narration is outstanding, in the unit the vendor bills.
     *
     * @return array{scenes: int, characters: int, credits: float}
     */
    private function reportWorkload(Story $story, SpeechSynthesizer $speech): array
    {
        $this->line('<comment>3. Outstanding narration</comment>');

        // The same computation the estimate and the dispatcher read, so this
        // cannot quote one set of scenes and queue another.
        $pending = SceneChangeSet::for($story)->needsNarration;

        $characters = (int) $pending->sum(fn (Scene $s): int => mb_strlen(trim((string) $s->narration_text)));

        $credits = $speech instanceof ElevenLabsSpeechSynthesizer
            ? $characters * $speech->creditsPerCharacter()
            : (float) $characters;

        $this->line(sprintf(
            '   %d of %d scene(s) need narration — %s characters, %s credits on %s.',
            $pending->count(),
            $story->scenes()->count(),
            number_format($characters),
            number_format($credits),
            (string) $speech->modelName(),
        ));

        if ($speech instanceof ElevenLabsSpeechSynthesizer && $speech->creditsPerCharacter() > 0.5) {
            $this->line(sprintf(
                '   A flash/turbo model would halve that to %s credits, at a real cost in long-form',
                number_format($credits / 2),
            ));
            $this->line('   stability. ELEVENLABS_TTS_MODEL, and it is your call, not this file\'s.');
        }

        $this->newLine();

        return ['scenes' => $pending->count(), 'characters' => $characters, 'credits' => $credits];
    }

    /**
     * Does it fit? The question a cost estimate cannot ask.
     *
     * @param  array{scenes: int, characters: int, credits: float}  $outstanding
     */
    private function reportQuota(SpeechSynthesizer $speech, array $outstanding): void
    {
        $this->line('<comment>4. Allowance</comment>');

        if (! $speech instanceof ElevenLabsSpeechSynthesizer) {
            $this->line('   n/a for this provider.');
            $this->newLine();

            return;
        }

        $quota = $speech->quota();

        if (! $quota->readable) {
            // Explicitly NOT a pass. An unreadable quota rendered as "fine" is
            // the same class of bug as a config lookup standing in for the
            // container — a check that reports success because it could not run.
            $this->warn('   Could not read the balance: '.(string) $quota->unreadableReason);
            $this->line(sprintf(
                '   This run needs %s credits. Check the usage page by hand before dispatching —',
                number_format($outstanding['credits']),
            ));
            $this->line('   on a plan without overage, running out does not cost extra, it leaves the');
            $this->line('   story half-narrated with the allowance already spent.');
            $this->newLine();

            return;
        }

        $this->line('   '.$quota->summary());

        $fits = $quota->accommodates($outstanding['credits']);

        if ($fits === false) {
            $short = (int) ceil($outstanding['credits'] - (int) $quota->remaining());

            $this->block(sprintf(
                "This run needs %s credits and %s remain — short by %s.\n"
                .'     Free and Starter plans have NO overage: generation STOPS at the limit rather '
                ."than billing past it,\n     so dispatching now spends the remaining allowance and "
                ."still leaves the story unfinished.\n     Upgrade the plan, buy a top-up, or switch "
                .'ELEVENLABS_TTS_MODEL to a flash/turbo model at half the credit cost.',
                number_format($outstanding['credits']),
                number_format((float) $quota->remaining()),
                number_format($short),
            ));
        } elseif ($fits === true) {
            $this->line(sprintf(
                '   <info>OK</info> — %s credits needed, %s remaining, %s left over.',
                number_format($outstanding['credits']),
                number_format((float) $quota->remaining()),
                number_format($quota->remaining() - $outstanding['credits']),
            ));
        }

        $this->newLine();
    }

    /**
     * Prove the aligner runs, rather than assuming it does.
     *
     * `--align` runs a real alignment against a scene that already has audio.
     * It is free and it is the only thing that distinguishes "installed" from
     * "importable but broken" — a distinction that otherwise surfaces 186 jobs
     * into a batch.
     */
    private function reportAligner(Story $story, Transcriber $transcriber): void
    {
        $this->line('<comment>5. Aligner</comment>');

        if (! $transcriber instanceof WhisperXTranscriber) {
            $this->line('   n/a for this provider.');
            $this->newLine();

            return;
        }

        $this->line(sprintf(
            '   python: %s   device: %s   script: %s',
            (string) config('providers.whisperx.python'),
            (string) config('providers.whisperx.device'),
            is_file((string) config('providers.whisperx.script')) ? 'found' : 'MISSING',
        ));

        if (! $this->option('align')) {
            $this->line('   Not exercised. Re-run with --align to prove it against real audio (free).');
            $this->newLine();

            return;
        }

        // Real audio only, and this is not fussiness.
        //
        // A stand-in's narration is a SILENT WAV. Alignment against silence
        // cannot place a single word, so it fails — and it fails through the
        // unaligned-words guard, which reports it as a bad alignment. On a
        // story that is still entirely faked, that reads as a broken WhisperX
        // install and is nothing of the kind. Better to say there is nothing
        // real to align than to hand back a failure about the wrong thing.
        $scene = $story->scenes()
            ->whereHas('sceneAudio', fn ($q) => $q->whereNotNull('audio_path')
                ->where('narration_simulated', false))
            ->with('sceneAudio')
            ->orderBy('sequence')
            ->first();

        if ($scene === null) {
            $simulated = $story->sceneAudio()
                ->whereNotNull('audio_path')
                ->where('narration_simulated', true)
                ->count();

            $this->warn($simulated > 0
                ? sprintf(
                    '   %d scene(s) have audio, but all of it is placeholder silence from a stand-in. '
                    .'Alignment
   against silence cannot place a word and would fail for a reason '
                    .'that has nothing to do with WhisperX.',
                    $simulated,
                )
                : '   No scene has narration audio yet, so there is nothing to align against.');
            $this->line('   Narrate a scene for real first, then re-run with --align.');
            $this->newLine();

            return;
        }

        $path = Storage::disk((string) config('render.assets.disk', 'assets'))
            ->path((string) $scene->sceneAudio->first()?->audio_path);

        $this->line(sprintf('   Aligning scene %d (this loads the model — expect tens of seconds)...', $scene->sequence));

        try {
            $started = microtime(true);
            $result = $transcriber->transcribe($path, (string) $scene->narration_text);

            $this->line(sprintf(
                '   <info>OK</info> — %d words over %d ms in %.1fs.',
                $result->wordCount(),
                $result->durationMs,
                microtime(true) - $started,
            ));
        } catch (Throwable $e) {
            $this->block("WhisperX failed:\n     ".str_replace("\n", "\n     ", $e->getMessage()));
        }

        $this->newLine();
    }

    private function reportWorkers(): void
    {
        $this->line('<comment>6. Queue workers</comment>');
        $this->line('   A worker holds the container it booted with, so a .env edit does NOT reach a');
        $this->line('   running one — that is how a batch quoted against a vendor was served by a');
        $this->line('   stand-in. If .env changed since the workers started:');
        $this->line('     php artisan queue:restart   (then CONFIRM the processes actually exited)');
        $this->line('   assets:generate pins the provider into every job, so a stale worker fails the');
        $this->line('   scene loudly instead of substituting — but it fails it, and that is a wasted run.');
    }

    private function block(string $message): void
    {
        $this->blocked = true;
        $this->line('   <fg=red>BLOCKED</> — '.$message);
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
