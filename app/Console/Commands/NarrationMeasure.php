<?php

namespace App\Console\Commands;

use App\Models\SceneAudio;
use App\Models\Story;
use App\Support\NarrationPace;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Measure a narrator's real reading pace on a finished story, and print the
 * config block that records it.
 *
 * **The instrument the pace guard was missing.** Brian's 197 wpm came from
 * dividing story 9's finished narration by hand, sixty-nine scenes into an
 * incident; the figure before that came from ONE scene and was 5% low. A number
 * that important should not depend on somebody remembering to do arithmetic.
 *
 * Cumulative across every scene the story actually has, scoped to one voice at
 * one speed, because that is the only combination the figure is true for. A
 * scene's rate swings 23% between neighbours on sentence length alone — story
 * 9's real audio has four consecutive scenes at 189, 201, 233 and 206 — so this
 * refuses to report on a sample too small to mean anything, and says how small.
 *
 * **It never writes the config.** "Nothing is regenerated silently" applies to
 * a measurement as much as to an asset: a figure that appeared in config on its
 * own is a figure nobody checked, and every runtime number in the app is
 * derived from it. It prints the block; a human pastes it.
 */
class NarrationMeasure extends Command
{
    protected $signature = 'narration:measure
        {story : Story slug or id.}
        {--min-scenes=20 : Refuse to report below this many narrated scenes.}';

    protected $description = 'Measure the real words-per-minute of a story\'s narration, per voice and locale.';

    public function handle(): int
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

        $rows = $this->narration($story);

        if ($rows->isEmpty()) {
            $this->error("Story {$story->id} has no real narration to measure. A simulated run cannot be measured — the fake derives its durations from the very constant this would be setting.");

            return self::FAILURE;
        }

        $this->line("<info>Narration pace for \"{$story->title}\"</info>");
        $this->line("  locale profile : {$story->locale_profile}");
        $this->newLine();

        $exit = self::SUCCESS;

        // Grouped by the full key the figure belongs to. Two voices, or one
        // voice at two speeds, are two measurements and must never be averaged
        // into one — that is precisely the mixture that hid story 9's incident.
        foreach ($rows->groupBy(fn (object $r): string => $r->narration_voice_id.'@'.number_format((float) $r->narration_speed, 2)) as $group) {
            $exit = max($exit, $this->report($story, $group));
        }

        return $exit;
    }

    /**
     * @param  Collection<int, object>  $group
     */
    private function report(Story $story, Collection $group): int
    {
        $first = $group->first();
        $voiceId = (string) $first->narration_voice_id;
        $speed = (float) $first->narration_speed;

        $words = $group->sum(fn (object $r): int => str_word_count(trim((string) $r->narration_text)));
        $durationMs = (int) $group->sum('duration_ms');
        $scenes = $group->count();
        $wpm = NarrationPace::measure($words, $durationMs);

        $name = NarrationPace::voiceName($voiceId) ?? $voiceId;
        $minScenes = (int) $this->option('min-scenes');

        $this->line(sprintf('<comment>%s at speed %.2f</comment>', $name, $speed));
        $this->line(sprintf(
            '  %s scenes, %s words, %s of audio  ->  <info>%.1f wpm</info>',
            number_format($scenes),
            number_format($words),
            gmdate('H:i:s', (int) round($durationMs / 1000)),
            $wpm,
        ));

        $existing = NarrationPace::isMeasured($voiceId, $story->locale_profile)
            ? NarrationPace::expectedWpm($voiceId, $story->locale_profile)
            : null;

        if ($existing !== null) {
            $drift = ($wpm - $existing) / $existing;
            $this->line(sprintf(
                '  currently configured: %d wpm  (%+.1f%%)',
                $existing,
                $drift * 100,
            ));
        }

        // A sample too small to act on is reported as too small, not rounded up
        // into a number. This is the whole lesson of story 21: two scenes read
        // 219 and 230 wpm and the guard treated 224.6 as fact, when the same
        // codebase already documents healthy neighbours 23% apart.
        if ($scenes < $minScenes) {
            $this->newLine();
            $this->warn(sprintf(
                "  Not reporting a figure: %d scene(s) is below the --min-scenes floor of %d.\n"
                ."  Neighbouring scenes in real audio differ by up to 23%% on sentence length alone,\n"
                .'  so a sample this size cannot separate a systematic shift from that spread.',
                $scenes,
                $minScenes,
            ));

            return self::FAILURE;
        }

        $this->newLine();
        $this->line('  Paste into <comment>config/render.php</comment> under narration.voices:');
        $this->newLine();
        $this->line(sprintf("      '%s' => [", $voiceId));
        $this->line(sprintf("          'name' => '%s',", $name));
        $this->line("          'locales' => [");
        $this->line(sprintf("              '%s' => [", $story->locale_profile));
        $this->line(sprintf("                  'words_per_minute' => %d,", (int) round($wpm)));
        $this->line(sprintf("                  'measured_at_speed' => %.1f,", $speed));
        $this->line(sprintf(
            "                  'measured_on' => 'story %d - %s words across %s scenes',",
            $story->id,
            number_format($words),
            number_format($scenes),
        ));
        $this->line('              ],');
        $this->line('          ],');
        $this->line('      ],');
        $this->newLine();
        $this->line('  <comment>Nothing was written.</comment> Every runtime number in this app is derived from');
        $this->line('  that figure, so it goes in by hand or not at all.');
        $this->newLine();

        return self::SUCCESS;
    }

    /**
     * Real, non-simulated narration for this story, with its scene text.
     *
     * `narration_simulated` is excluded and the exclusion is load-bearing: the
     * fake synthesizer DERIVES its duration from `render.narration.words_per_minute`,
     * so measuring it would return that constant and call it a measurement. The
     * two would agree by construction and the agreement would prove nothing —
     * which is exactly how a 160 wpm figure survived an entire real run.
     *
     * @return Collection<int, object>
     */
    private function narration(Story $story): Collection
    {
        return SceneAudio::query()
            ->join('scenes', 'scenes.id', '=', 'scene_audio.scene_id')
            ->where('scenes.story_id', $story->id)
            ->where('scene_audio.narration_simulated', false)
            ->whereNotNull('scene_audio.duration_ms')
            ->where('scene_audio.duration_ms', '>', 0)
            ->whereNotNull('scene_audio.narration_voice_id')
            ->orderBy('scenes.sequence')
            ->get([
                'scenes.narration_text',
                'scene_audio.duration_ms',
                'scene_audio.narration_voice_id',
                'scene_audio.narration_speed',
            ]);
    }
}
