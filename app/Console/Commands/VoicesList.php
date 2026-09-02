<?php

namespace App\Console\Commands;

use App\Contracts\SpeechSynthesizer;
use App\Models\Story;
use Illuminate\Console\Command;
use Throwable;

/**
 * The narrators this account offers, and the one this story uses.
 *
 * This command exists because `SpeechSynthesizer::voices()` was declared on the
 * contract, implemented by the fake, and **called by nothing** — the exact
 * shape of gap this project keeps finding at a phase seam. A mechanism built in
 * one phase whose production caller was due in the next, which arrived without
 * it.
 *
 * The consequence was not cosmetic. With no picker of any kind, `voice_id` was
 * hard-coded to `narrator-us-01` at story creation — a string invented by
 * FakeSpeechSynthesizer to have something to record. It is not a voice on any
 * vendor. Every story in the database carries it, and the first real TTS call
 * for any of them would have been a 422 on scene 1.
 *
 * `--set` is on this command rather than a separate one on purpose: choosing a
 * narrator and seeing the candidates are one decision, and splitting them is
 * how a voice_id gets typed from memory.
 */
class VoicesList extends Command
{
    protected $signature = 'voices:list
        {--set= : Story slug or id to assign the chosen voice to.}
        {--voice= : The voice id to assign. Required with --set.}
        {--force : Skip the re-voicing confirmation. For scripted use only.}';

    protected $description = 'List the American English narrators the bound speech provider offers.';

    public function handle(): int
    {
        // From the container, never from config. Config says what SHOULD be
        // bound; only this says what IS, and the two disagreeing silently is
        // how 186 stills came back from a stand-in.
        $speech = app(SpeechSynthesizer::class);

        $this->info(sprintf(
            'Speech provider: %s%s%s',
            $speech->providerName(),
            $speech->modelName() !== null ? ' / '.$speech->modelName() : '',
            $speech->isSimulated() ? '  [SIMULATED — these voices are placeholders]' : '',
        ));
        $this->newLine();

        try {
            $voices = $speech->voices();
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($voices === []) {
            $this->warn('The provider returned no American English voices.');
            $this->line('  A US-audience channel wants an American narrator, so this list is filtered by');
            $this->line('  accent. If the account has voices but none are labelled american, they are');
            $this->line('  hidden here deliberately rather than missing.');

            return self::FAILURE;
        }

        $current = $this->story()?->voice_id;

        $this->table(
            ['', 'voice_id', 'name', 'locale'],
            array_map(fn (array $v): array => [
                $v['id'] === $current ? '*' : '',
                $v['id'],
                $v['name'],
                $v['locale'],
            ], $voices),
        );

        if ($current !== null) {
            $this->line(sprintf('  * = the voice currently on the story (%s).', $current));
        }

        if ($this->option('set') === null) {
            $this->newLine();
            $this->line('  Assign one with:  php artisan voices:list --set=<story> --voice=<voice_id>');

            return self::SUCCESS;
        }

        return $this->assign($voices);
    }

    /**
     * @param  array<int, array{id: string, name: string, locale: string}>  $voices
     */
    private function assign(array $voices): int
    {
        $story = $this->story();

        if ($story === null) {
            $this->error(sprintf('No story matching "%s".', (string) $this->option('set')));

            return self::FAILURE;
        }

        $voiceId = trim((string) $this->option('voice'));

        if ($voiceId === '') {
            $this->error('--set needs --voice. Pick a voice_id from the table above.');

            return self::FAILURE;
        }

        // Checked against the account's real list rather than accepted on
        // trust. A typo here is not caught until a paid batch is already
        // running, and it would fail every scene one at a time.
        $match = collect($voices)->firstWhere('id', $voiceId);

        if ($match === null) {
            $this->error(sprintf(
                'No voice "%s" on this account. Copy an id from the table above — a voice that is not '
                .'on the account fails every scene of a batch individually, one paid call at a time.',
                $voiceId,
            ));

            return self::FAILURE;
        }

        // Only audio somebody PAID for is worth stopping over.
        //
        // The warning used to fire on any existing narration, which made it
        // useless on precisely the story it most needed to be usable on: one
        // narrated end to end by a stand-in, where every file is a silent
        // placeholder and changing the voice strands nothing. Now that the
        // provenance is on the row, the question can be the real one — is there
        // real narration here that a new voice would leave orphaned.
        $paid = $story->sceneAudio()
            ->whereNotNull('audio_path')
            ->where(fn ($q) => $q->where('narration_simulated', false)->orWhereNull('narration_simulated'))
            ->exists();

        if ($paid && ! $this->option('force')) {
            $this->warn(sprintf(
                '"%s" already has narration audio that was paid for, generated with voice "%s".',
                $story->title,
                (string) $story->voice_id,
            ));
            $this->line('  Scenes whose audio came from this provider under the OLD voice are now stale,');
            $this->line('  so the next `assets:generate` will re-narrate and re-bill them. That is the');
            $this->line('  right behaviour — a story must not end up narrated by two different people —');
            $this->line('  but it is a spend, so it is being said out loud first.');
            $this->newLine();

            if (! $this->confirm('Set the voice anyway?', false)) {
                $this->line('Unchanged.');

                return self::SUCCESS;
            }
        }

        $simulated = $story->sceneAudio()->whereNotNull('audio_path')->where('narration_simulated', true)->count();

        if ($simulated > 0) {
            $this->line(sprintf(
                '  %d scene(s) hold placeholder audio from a stand-in. Nothing was paid for it and it '
                .'is stale either way.',
                $simulated,
            ));
        }

        $story->forceFill(['voice_id' => $voiceId])->save();

        $this->info(sprintf('%s now narrated by %s (%s).', $story->title, $match['name'], $voiceId));

        return self::SUCCESS;
    }

    private function story(): ?Story
    {
        $key = trim((string) $this->option('set'));

        if ($key === '') {
            return null;
        }

        return Story::query()
            ->where('slug', $key)
            ->orWhere('id', ctype_digit($key) ? (int) $key : 0)
            ->first();
    }
}
