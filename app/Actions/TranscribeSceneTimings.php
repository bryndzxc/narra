<?php

namespace App\Actions;

use App\Contracts\SpeechSynthesizer;
use App\Contracts\Transcriber;
use App\Enums\AssetStatus;
use App\Models\Scene;
use App\Models\SceneAudio;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * One scene's word-level timings.
 *
 * Chunked per scene, deliberately. Run against a finished 40-minute file this
 * is slow and its word timestamps drift toward the end — and the drift lands
 * exactly where nobody watches during review. Per scene it is fast, accurate,
 * and re-runnable for the one scene that needs it.
 *
 * The narration text is handed over as `$expectedText` because it is already
 * known: it was written at Gate 1 and read at Gate 2, and a paid TTS call has
 * just spoken it verbatim. Giving the transcriber the text it is aligning
 * against turns an open transcription problem into an alignment one, which is
 * both cheaper and considerably more accurate on the proper nouns this genre is
 * full of.
 *
 * Timings are stored SCENE-LOCAL, starting at zero. Offsetting into whole-video
 * time happens at concat from `scene_audio.offset_samples`, which accumulates
 * as integers across the story. Baking an offset in here would mean every
 * re-transcribed scene silently disagreed with the timeline around it.
 *
 * Idempotent: a scene that already has timings for audio that has not changed
 * is skipped and not re-billed.
 */
class TranscribeSceneTimings
{
    public function __construct(
        private readonly Transcriber $transcriber,
        // Injected rather than resolved inline: the narration provider decides
        // whether the AUDIO under these timings is about to be replaced, and a
        // scene whose audio is being regenerated must be re-timed regardless of
        // who timed it last.
        private readonly SpeechSynthesizer $speech,
        private readonly RecordProviderCost $costs,
    ) {}

    /**
     * @return array{words: int, billed: bool, log: string}
     */
    public function handle(Scene $scene): array
    {
        $story = $scene->story;

        $story->assertPaidAssetsUnlocked('transcribe_scene_timings');

        /** @var SceneAudio|null $audio */
        $audio = $scene->sceneAudio()->first();

        if ($audio === null || $audio->audio_path === null) {
            throw new RuntimeException(
                "Scene {$scene->sequence} has no narration audio to transcribe. Narration is generated "
                ."before timings, and this scene's audio stage has not succeeded."
            );
        }

        // Provenance as well as staleness. Timings produced by a stand-in are
        // synthetic — plausible, contiguous, and describing nothing — so a
        // scene holding them must not be reported as done to a real aligner.
        // The provider is asked of the injected instance, which is the object
        // that would do the work.
        $provenanceStale = $scene->timingsProvenanceStale($this->transcriber->providerName())
            || $scene->narrationProvenanceStale($this->speech->providerName(), $story->voice_id);

        if (! $scene->needsTranscription() && ! $provenanceStale && $audio->timings_json !== null) {
            return [
                'words' => count((array) $audio->timings_json),
                'billed' => false,
                'log' => 'kept existing timings, audio unchanged since approval',
            ];
        }

        // A local, readable path. The contract takes a path rather than bytes
        // because a real transcriber shells out to a local model — WhisperX
        // reads a file, it does not take a stream.
        $path = $this->disk()->path($audio->audio_path);

        if (! is_readable($path)) {
            throw new RuntimeException(sprintf(
                "Scene %d's narration row points at %s, which is not readable. The row claims audio "
                .'that is not on disk — regenerate the narration for this scene.',
                $scene->sequence,
                $audio->audio_path,
            ));
        }

        $transcription = $this->transcriber->transcribe($path, (string) $scene->narration_text);

        // Money first, as everywhere else that bills.
        $this->costs->handle($story, $transcription->usage);

        if ($transcription->words === []) {
            throw new RuntimeException(sprintf(
                'Scene %d transcribed to zero words. The ASS karaoke line for this scene would be '
                .'empty and every word after it in the act would still be timed against it.',
                $scene->sequence,
            ));
        }

        $audio->forceFill([
            'timings_json' => $transcription->words,
            // Recorded alongside the timings, for the same reason the narration
            // provider is: without it, synthetic timings and real ones look
            // identical on the row and a swapped provider never gets to run.
            'timings_provider' => $this->transcriber->providerName(),
            'timings_simulated' => $this->transcriber->isSimulated(),
            'status' => AssetStatus::Ready,
        ])->save();

        return [
            'words' => $transcription->wordCount(),
            'billed' => true,
            'log' => sprintf(
                '%d words over %d ms, $%s',
                $transcription->wordCount(),
                $transcription->durationMs,
                number_format($transcription->usage->usdCost, 4),
            ),
        ];
    }

    private function disk(): Filesystem
    {
        return Storage::disk((string) config('render.assets.disk', 'assets'));
    }
}
