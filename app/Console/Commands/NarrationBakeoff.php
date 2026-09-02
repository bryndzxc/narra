<?php

namespace App\Console\Commands;

use App\Actions\RecordProviderCost;
use App\Enums\CostCategory;
use App\Models\Scene;
use App\Models\Story;
use App\Services\ElevenLabs\ElevenLabsSpeechSynthesizer;
use App\Support\Providers\ProviderUsage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * The same scene, read at several speeds, so the choice can be heard.
 *
 * This exists because the narrator's reading rate turned out to be a QUALITY
 * question wearing a compliance question's clothes.
 *
 * The measurement was unambiguous: Brian reads at 195 wpm against a pipeline
 * whose whole runtime model is built on a shared 160 wpm constant, which puts a
 * 5,781-word story at 29:41 instead of 36:08 — nineteen seconds under the
 * format's stated floor. The arithmetic says slow the voice to 0.82 and the
 * number complies.
 *
 * What the arithmetic cannot say is whether a voice slowed 18% still sounds
 * like a person telling a story, across thirty minutes, to someone who is
 * deciding whether to keep watching. A narrator that sounds fractionally
 * artificial costs more retention than nineteen seconds of runtime ever gained,
 * and no spec number can settle that. Ears can.
 *
 * So this generates the identical text in the identical voice at each speed and
 * puts the files side by side, exactly as `images:bakeoff` does for a face.
 *
 * On the ledger: these are evaluation spend, not the cost of a video. They are
 * still written, because "every paid API call writes a row" has no exceptions —
 * but they carry a `bakeoff_` operation name so a per-video total can exclude
 * them in one predicate. And they are written to a bakeoff directory, never to
 * the scene's real audio path: a probe must not become the narration.
 */
class NarrationBakeoff extends Command
{
    protected $signature = 'narration:bakeoff
        {story : Story slug or id.}
        {--scene= : Scene sequence to read. Defaults to the longest scene, which is the one that shows pacing.}
        {--speeds=1.0,0.9,0.82 : Comma-separated speeds. ElevenLabs accepts 0.7-1.2.}
        {--force : Skip the confirmation.}';

    protected $description = 'Read one scene at several narration speeds so the pacing can be compared by ear.';

    public function handle(RecordProviderCost $costs): int
    {
        $story = Story::query()
            ->where('slug', $key = (string) $this->argument('story'))
            ->orWhere('id', ctype_digit($key) ? (int) $key : 0)
            ->first();

        if ($story === null) {
            $this->error("No story matching '{$key}'.");

            return self::FAILURE;
        }

        $scene = $this->scene($story);

        if ($scene === null) {
            $this->error('That scene does not exist, or the story has no narration text.');

            return self::FAILURE;
        }

        $speeds = $this->speeds();

        if ($speeds === []) {
            return self::FAILURE;
        }

        $text = trim((string) $scene->narration_text);
        $characters = mb_strlen($text);
        $words = str_word_count($text);

        // Resolved directly rather than through the container, the same way
        // images:bakeoff does. This command is allowed to spend; nothing about
        // running it should depend on, or change, what the pipeline is bound to.
        $speech = app(ElevenLabsSpeechSynthesizer::class);

        $credits = $characters * $speech->creditsPerCharacter() * count($speeds);

        $this->info(sprintf('Narration bake-off — "%s", scene %d', $story->title, $scene->sequence));
        $this->newLine();
        $this->line(sprintf('  voice   : %s', (string) $story->voice_id));
        $this->line(sprintf('  model   : %s', (string) $speech->modelName()));
        $this->line(sprintf('  text    : %d characters, %d words', $characters, $words));
        $this->line(sprintf('  speeds  : %s', implode(', ', array_map(fn (float $s): string => (string) $s, $speeds))));
        $this->line(sprintf('  cost    : %s credits (%d takes)', number_format($credits), count($speeds)));
        $this->newLine();
        $this->line('  These are EVALUATION takes. They are written to a bakeoff directory and never');
        $this->line("  become the scene's narration — the pipeline will not see them.");
        $this->newLine();

        if (! $this->option('force') && ! $this->confirm(sprintf('Spend %s credits?', number_format($credits)), false)) {
            $this->line('Nothing generated.');

            return self::SUCCESS;
        }

        $disk = Storage::disk((string) config('render.assets.disk', 'assets'));
        $directory = sprintf('%d/bakeoff/narration/scene-%d', $story->id, $scene->sequence);
        $rows = [];

        foreach ($speeds as $speed) {
            // Set on config rather than passed as an argument, because the
            // provider reads its voice settings from config — which is the same
            // path a real run takes. A bake-off that exercised a different code
            // path from production would be measuring the wrong thing.
            config()->set('providers.elevenlabs.tts.voice_settings.speed', $speed);

            try {
                $result = $speech->synthesize($scene, $text, (string) $story->voice_id);
            } catch (Throwable $e) {
                $this->error(sprintf('speed %s failed: %s', $speed, $e->getMessage()));

                continue;
            }

            $costs->handle($story, $this->tag($result->usage, $speed));

            $path = sprintf('%s/speed-%s.wav', $directory, str_replace('.', '_', (string) $speed));
            $disk->put($path, $result->bytes);

            $minutes = $result->durationMs / 60000;

            $rows[] = [
                (string) $speed,
                gmdate('i:s', (int) round($result->durationMs / 1000)),
                sprintf('%.0f', $words / max(0.0001, $minutes)),
                // What the whole 186-scene story would run to at this pace,
                // which is the number the decision is actually about.
                $this->projectedRuntime($story, $words, $minutes),
                $disk->path($path),
            ];
        }

        if ($rows === []) {
            $this->error('Every take failed. Nothing was written.');

            return self::FAILURE;
        }

        $this->newLine();
        $this->table(['speed', 'this scene', 'wpm', 'whole story would run', 'file'], $rows);
        $this->newLine();
        $this->line('  Listen to them back to back. The question is not which is closest to a target —');
        $this->line('  it is whether the slower ones sound like a person telling a story or like a');
        $this->line('  recording that has been slowed down.');
        $this->newLine();
        $this->line(sprintf('  %s', $disk->path($directory)));

        return self::SUCCESS;
    }

    /**
     * What the full story runs to if every scene reads at this scene's pace.
     */
    private function projectedRuntime(Story $story, int $words, float $minutes): string
    {
        $total = (int) $story->scenes()->get()
            ->sum(fn (Scene $s): int => str_word_count((string) $s->narration_text));

        $wpm = $words / max(0.0001, $minutes);

        return gmdate('i:s', (int) round($total / max(1.0, $wpm) * 60));
    }

    /**
     * The scene to read.
     *
     * The LONGEST by default, and deliberately: pacing is a property that only
     * shows over a few sentences. A three-second scene read at 0.82 sounds fine
     * and proves nothing about thirty minutes of it.
     */
    private function scene(Story $story): ?Scene
    {
        if ($this->option('scene') !== null) {
            return $story->scenes()->where('sequence', (int) $this->option('scene'))->first();
        }

        return $story->scenes()->get()
            ->sortByDesc(fn (Scene $s): int => mb_strlen((string) $s->narration_text))
            ->first();
    }

    /**
     * @return array<int, float>
     */
    private function speeds(): array
    {
        $speeds = [];

        foreach (explode(',', (string) $this->option('speeds')) as $raw) {
            $raw = trim($raw);

            if ($raw === '') {
                continue;
            }

            if (! is_numeric($raw)) {
                $this->error("\"{$raw}\" is not a speed. Use numbers between 0.7 and 1.2.");

                return [];
            }

            $speed = (float) $raw;

            // Refused rather than clamped. Outside this range ElevenLabs
            // rejects the call, and a silently-clamped 0.5 would produce a take
            // labelled 0.5 that was actually read at 0.7 — a bake-off result
            // that is worse than no bake-off.
            if ($speed < 0.7 || $speed > 1.2) {
                $this->error(sprintf('Speed %s is outside the 0.7-1.2 range ElevenLabs accepts.', $raw));

                return [];
            }

            $speeds[] = $speed;
        }

        return $speeds;
    }

    /** Rename the operation so evaluation spend is filterable in one predicate. */
    private function tag(ProviderUsage $usage, float $speed): ProviderUsage
    {
        return new ProviderUsage(
            provider: $usage->provider,
            operation: 'bakeoff_narration',
            category: CostCategory::Asset,
            quantity: $usage->quantity,
            unit: $usage->unit,
            usdCost: $usage->usdCost,
            detail: $usage->detail + ['speed' => $speed],
            simulated: $usage->simulated,
            model: $usage->model,
        );
    }
}
