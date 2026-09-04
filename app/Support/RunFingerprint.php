<?php

namespace App\Support;

use App\Models\Story;
use Illuminate\Support\Facades\File;

/**
 * What the process that dispatched a job believed, in a form a worker can check.
 *
 * **The hole this closes.** A `queue:work` process bootstraps Laravel once and
 * holds its config and its code in memory for its whole life. A worker started
 * before a `.env` edit, a migration or a deploy keeps running the old ones, and
 * nothing about the job it picks up tells it so.
 *
 * That is not hypothetical. A worker booted before the narration speed setting,
 * the `scene_audio.narration_speed` column and the pace guard existed synthesised
 * 117 scenes at speed 1.0 instead of 0.9, recorded no speed provenance for any of
 * them, and cost 15,308 credits. Three separate defences were already in place and
 * all three missed it:
 *
 *   - the provider pin compared only provider NAMES, and the provider was right;
 *   - the pace guard was not in the worker's loaded code, so it could not fire;
 *   - `record()` had no speed column in the code that worker was running, so the
 *     rows came out NULL.
 *
 * Every one of those guards lived DOWNSTREAM of the thing that was stale, which
 * is why staleness is the wrong thing to detect downstream. This detects it at
 * the seam itself: the dispatching process states what it believes, the worker
 * states what it believes, and a difference is a refusal.
 *
 * **Two axes, and both are needed.**
 *
 *   SETTINGS — the values that vary and that change the artefact: narration
 *   speed, voice, expected wpm, the TTS model, the provider bindings. Read live
 *   from config, which inside a worker IS its boot-time config, because a worker
 *   never reloads it.
 *
 *   CODE — a marker over `app/` and `config/` on disk, frozen at process boot.
 *   Settings alone would not have caught the incident above: the speed config
 *   did not merely differ between the two processes, it did not EXIST in the
 *   worker's. A missing guard and a missing column are code facts, not config
 *   facts, and the settings axis is blind to both.
 *
 * The code marker must be sealed at boot and never recomputed, which is the
 * whole subtlety of it. A stale worker reading the file system later would read
 * the NEW files and report itself current — a check that passes precisely
 * because it is being run by the thing it is supposed to catch. `sealCode()` is
 * called from AppServiceProvider::boot() for exactly this reason.
 *
 * @phpstan-type Fingerprint array<string, scalar|null>
 */
final class RunFingerprint
{
    /**
     * The code marker as it was when THIS process started.
     *
     * Static, so it survives the container being rebuilt between tests, and
     * computed exactly once per PHP process — which is the definition of
     * "the code this process booted with".
     */
    private static ?string $code = null;

    /**
     * Freeze the code marker. Called from AppServiceProvider::boot().
     *
     * Idempotent, and deliberately so: the first call wins and every later one
     * is a no-op, because the first call is the one that happened at boot.
     */
    public static function sealCode(): void
    {
        self::$code ??= self::computeCodeMarker();
    }

    /** The frozen code marker, sealing it now if boot somehow did not. */
    public static function code(): string
    {
        self::sealCode();

        return (string) self::$code;
    }

    /**
     * The half of the fingerprint that belongs to the machine, not to a story.
     *
     * This is what a queue worker can announce about itself. A worker serves
     * every story on its queue and cannot know which one it is about to be asked
     * for, so the per-story fields below would be meaningless coming from it —
     * and a fingerprint a worker cannot compute is a fingerprint the preflight
     * cannot compare.
     *
     * Everything here is a property of the process: the code it loaded and the
     * config it booted with. Every one of them was capable of being stale in the
     * incident this exists for.
     *
     * @return array<string, scalar|null>
     */
    public static function shared(): array
    {
        return [
            // The code axis. First, because it is the one that subsumes the
            // others: if this differs, the settings below may not even mean the
            // same thing in both processes.
            'code' => self::code(),

            // Who does the work.
            'speech_provider' => (string) config('providers.speech_synthesizer'),
            'image_provider' => (string) config('providers.image_generator'),
            'transcriber' => (string) config('providers.transcriber'),
            'tts_model' => (string) config('providers.elevenlabs.tts.model'),

            // How it is read. The setting the incident turned on.
            'narration_speed' => number_format(NarrationPace::configuredSpeed(), 2, '.', ''),

            // The fallback rate, and the bounds the pace guard judges against. A
            // worker running a looser tolerance than the operator was quoted is
            // the guard silently weakening, which is the same failure one level
            // up from the one that already happened.
            'fallback_wpm' => (int) config('render.narration.words_per_minute'),
            'pace_tolerance' => (string) config('render.narration.pace_tolerance'),
            'pace_min_words' => (int) config('render.narration.pace_min_words'),

            // Every measured voice profile, not just this story's. A profile
            // edited in config changes what `expected_wpm` means for any story
            // using that voice, and a worker holding the old table would judge
            // pace against a number the operator has already replaced.
            'voice_profiles' => substr(hash(
                'sha256',
                (string) json_encode(config('render.narration.voices', [])),
            ), 0, 12),
        ];
    }

    /**
     * Everything a paid asset job's outcome depends on that is not the scene.
     *
     * `shared()` plus the per-story half — the voice, and the pace expected of
     * that voice — because a fingerprint that only described global config would
     * agree across two stories narrated by different people.
     *
     * This is what travels in the job payload. The worker recomputes it from the
     * story it loaded and compares, so a job queued for one voice cannot be
     * executed by a process that resolves another.
     *
     * @return array<string, scalar|null>
     */
    public static function for(Story $story): array
    {
        $voiceId = $story->voice_id;

        return self::shared() + [
            // WHO is reading, and what this app expects of them. Both are
            // per-story: the voice is a column, and the expected rate is looked
            // up from it.
            'voice_id' => $voiceId,

            // The locale profile, because it is half of the key the expected
            // rate is looked up by. Brian reading en-US and Brian reading en-CN
            // are two different measurements, so two stories that differ only
            // here must not produce the same fingerprint.
            'locale_profile' => $story->locale_profile,
            'expected_wpm' => NarrationPace::expectedWpm($voiceId, $story->locale_profile),

            // Whether that expectation is a measurement or a fallback, and at
            // what speed it was taken. A profile that moved out from under the
            // configured speed makes `expected_wpm` a number about different
            // audio — the stale-profile case NarrationPace::violation() refuses
            // — and it must not be able to change without this changing.
            'wpm_measured' => NarrationPace::isMeasured($voiceId, $story->locale_profile),
            'measured_at_speed' => NarrationPace::measuredAtSpeed($voiceId, $story->locale_profile) === null
                ? null
                : number_format((float) NarrationPace::measuredAtSpeed($voiceId, $story->locale_profile), 2, '.', ''),
        ];
    }

    /** A short, comparable digest of a fingerprint, for logs and messages. */
    public static function digest(array $fingerprint): string
    {
        return substr(hash('sha256', (string) json_encode($fingerprint)), 0, 12);
    }

    /**
     * The keys on which two fingerprints disagree.
     *
     * @param  array<string, scalar|null>  $expected
     * @param  array<string, scalar|null>  $actual
     * @return array<string, array{expected: scalar|null, actual: scalar|null}>
     */
    public static function diff(array $expected, array $actual): array
    {
        $keys = array_unique([...array_keys($expected), ...array_keys($actual)]);
        $diff = [];

        foreach ($keys as $key) {
            $a = $expected[$key] ?? null;
            $b = $actual[$key] ?? null;

            if ($a !== $b) {
                $diff[$key] = ['expected' => $a, 'actual' => $b];
            }
        }

        return $diff;
    }

    /**
     * A refusal an operator can act on, naming the fields that differ.
     *
     * Written as prose rather than a dump because the reader is deciding what to
     * do at 3am with a half-generated story, and "fingerprint mismatch" tells
     * them nothing about which knob moved.
     *
     * @param  array<string, array{expected: scalar|null, actual: scalar|null}>  $diff
     */
    public static function explain(array $diff): string
    {
        $lines = [];

        foreach ($diff as $key => $pair) {
            $lines[] = sprintf(
                '  %-18s dispatched with %-22s worker has %s',
                $key,
                self::render($pair['expected']),
                self::render($pair['actual']),
            );
        }

        $codeDiffers = array_key_exists('code', $diff);

        return sprintf(
            'This job was dispatched by a process that does not agree with this worker about how the '
            ."narration should be made.\n\n%s\n\n%s",
            implode("\n", $lines),
            $codeDiffers
                ? 'The CODE marker differs, which means this worker is running a different version of '
                  .'app/ or config/ than the process that queued the job — it booted before a change '
                  ."and has been holding the old code in memory ever since.\nThat is the failure that "
                  .'synthesised 117 scenes at the wrong speed with no speed provenance: the guards that '
                  ."would have caught it were not in the worker's loaded code.\n\nStop the workers, "
                  .'CONFIRM the processes have actually exited, start them again, and re-press Generate '
                  .'assets. Nothing was generated and nothing was billed.'
                : "Nothing was generated and nothing was billed.\nRun `php artisan queue:restart`, "
                  .'confirm the worker has actually exited, start it again, and re-press Generate assets.',
        );
    }

    private static function render(mixed $value): string
    {
        return match (true) {
            $value === null => '(unset)',
            is_bool($value) => $value ? 'true' : 'false',
            default => '"'.$value.'"',
        };
    }

    /**
     * A hash over the source that decides how an asset is made.
     *
     * `app/` and `config/` only. Not `vendor/` — it is enormous, it changes on a
     * schedule nobody here controls, and a composer update that does not touch
     * this app's behaviour should not invalidate every queued job. Not
     * `resources/` or `routes/` either: a Blade tweak is not a reason to refuse
     * a paid batch, and a fingerprint that fires on harmless edits is one an
     * operator learns to bypass.
     *
     * Size and mtime rather than contents. Reading every file would be exact and
     * is not worth it: this runs once per process, and an edit that changes
     * neither the size nor the modification time of a file is not a thing that
     * happens outside a deliberate attempt to defeat the check.
     */
    private static function computeCodeMarker(): string
    {
        $parts = [];

        foreach ([app_path(), config_path()] as $directory) {
            if (! is_dir($directory)) {
                continue;
            }

            foreach (File::allFiles($directory) as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                // Relative, so the marker is comparable between two checkouts at
                // different paths — a dev box and the Linux production box.
                $parts[] = str_replace('\\', '/', $file->getRelativePathname())
                    .':'.$file->getSize()
                    .':'.$file->getMTime();
            }
        }

        // Sorted, because directory iteration order is a property of the file
        // system rather than of the code, and two identical checkouts must not
        // produce two different markers.
        sort($parts);

        return substr(hash('sha256', implode('|', $parts)), 0, 16);
    }
}
