<?php

namespace App\Actions;

use App\Contracts\SpeechSynthesizer;
use App\Enums\AssetStatus;
use App\Exceptions\NarrationPaceException;
use App\Models\AudioTrack;
use App\Models\Scene;
use App\Models\SceneAudio;
use App\Models\Story;
use App\Services\Ffmpeg;
use App\Support\NarrationPace;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * One scene's narration audio.
 *
 * Per scene, never as one 40-minute file. That is the spec's rule and the
 * reason is billing rather than tidiness: a single TTS call for the whole story
 * means one bad sentence forces a full re-bill, where per-scene audio is
 * re-generatable in isolation for the price of one scene and concatenated at
 * mux time.
 *
 * **The duration is probed from the written file, not taken from the provider's
 * response**, and this is the detail that decides whether the render succeeds
 * two hours later. `scenes.duration_ms` is what the clip's frame count is
 * ceil()'d from, and PadSceneAudio then decodes the real file and throws if the
 * audio does not fit in those frames — because padding that became a trim would
 * clip the tail of the last word. A provider's declared length and its encoded
 * length are not the same number; trusting the declaration would put that
 * failure at concat time, 186 scenes and one full bill later, instead of here.
 *
 * It is the same lesson the image side already learned from fal answering a
 * 1024x1024 PNG request with a 1920x1920 JPEG: trust the bytes, not the
 * response.
 *
 * Idempotent for the same reason as the still: a resumed batch must not be a
 * repeated one. Audio already on disk whose narration text has not changed
 * since Gate 2 approval is kept and not re-billed.
 */
class GenerateSceneNarration
{
    public function __construct(
        private readonly SpeechSynthesizer $speech,
        private readonly RecordProviderCost $costs,
        private readonly Ffmpeg $ffmpeg,
    ) {}

    /**
     * @return array{path: string, duration_ms: int, billed: bool, log: string}
     */
    public function handle(Scene $scene): array
    {
        $story = $scene->story;

        $story->assertPaidAssetsUnlocked('generate_scene_narration');

        $track = $this->track($story);

        if ($this->isAlreadyGenerated($scene)) {
            /** @var SceneAudio $existing */
            $existing = $scene->sceneAudio()->first();

            return [
                'path' => (string) $existing->audio_path,
                'duration_ms' => (int) $existing->duration_ms,
                'billed' => false,
                'log' => 'kept existing narration, text unchanged since approval',
            ];
        }

        $text = trim((string) $scene->narration_text);

        if ($text === '') {
            throw new RuntimeException("Scene {$scene->sequence} has no narration text to synthesize.");
        }

        $voiceId = (string) ($story->voice_id ?? $track->voice_id);

        if (trim($voiceId) === '') {
            throw new RuntimeException(
                "Story {$story->slug} has no voice_id, so its narrator is undefined. A channel keeps one "
                .'consistent narrator across every video — set it on the story before generating audio.'
            );
        }

        $speech = $this->speech->synthesize($scene, $text, $voiceId);

        // Money first: a provider that billed and then failed to have its bytes
        // filed has still billed.
        $this->costs->handle($story, $speech->usage);

        $path = $this->store($scene, $speech->bytes, $speech->mimeType);

        // Probed, not declared. See the class docblock.
        $absolute = $this->disk()->path($path);
        $probed = $this->ffmpeg->durationMs($absolute);

        // The two numbers the frame count is actually derived from. Milliseconds
        // are lossy by an amount that matters — 360002 samples at 24 kHz is
        // 15.0000833 s and stores as 15000, which is exactly 450 frames when
        // the audio needs 451 — so the sample count is recorded and the
        // duration is kept only for pace, estimates and display.
        $samples = $this->ffmpeg->sampleCount($absolute, deep: true);
        $sampleRate = $this->ffmpeg->sampleRate($absolute);

        $this->record($scene, $track, $path, $probed, $voiceId, $samples, $sampleRate);

        // The check that did not exist, and whose absence let a 22% error
        // survive sixty-nine paid scenes.
        //
        // The script writer is handed a word target DERIVED from a
        // words-per-minute figure; the runtime estimate comes from the same
        // figure. Nothing ever asked whether the voice honoured it — and could
        // not, while the only synthesizer was a fake that derives its durations
        // from that very constant and therefore agreed with it by construction.
        //
        // It runs after the row is written on purpose. The audio is paid for
        // and real either way, so it is kept and recorded; what stops here is
        // the rest of the run.
        $this->assertPaceMatchesTheScriptItWasSizedFor($scene, $voiceId, $probed);

        return [
            'path' => $path,
            'duration_ms' => $probed,
            'billed' => true,
            'log' => sprintf(
                '%d ms probed (%d ms declared, %+d), %s, $%s',
                $probed,
                $speech->durationMs,
                $probed - $speech->durationMs,
                $speech->mimeType,
                number_format($speech->usage->usdCost, 4),
            ),
        ];
    }

    /**
     * Stop the run when the narrator is not reading at the rate the script was
     * written for.
     *
     * Throws rather than warns, and that is the point: a warning on scene one of
     * a hundred and eighty-six is a line of text nobody is watching for. The
     * exception fails this scene, and SceneAssetJob cancels the batch on it — so
     * the failure that used to cost sixty-nine scenes now costs one.
     *
     * Only on a sample worth judging. A four-word scene can read 40% off the
     * story average purely on where the sentence breaks fall, and an alarm that
     * fires on noise is an alarm that gets ignored.
     *
     * @throws NarrationPaceException
     */
    private function assertPaceMatchesTheScriptItWasSizedFor(
        Scene $scene,
        string $voiceId,
        int $durationMs,
    ): void {
        // CUMULATIVE across the story, not this one scene.
        //
        // Real neighbouring scenes in story 9 read 189, 201, 233 and 206 wpm —
        // a 23% spread caused by sentence length alone. A per-scene threshold
        // tight enough to catch a 22% systematic drift would cancel a healthy
        // batch at scene four, and an alarm that fires on healthy runs is an
        // alarm that gets switched off.
        //
        // The failure is systematic, so the instrument is the running average.
        // Restricted to audio from the CURRENT voice at the CURRENT speed:
        // mixing in scenes read by a different narrator, or the same one at
        // another tempo, would average away the very thing being detected.
        $narrated = $this->currentNarration($scene, $voiceId);

        $words = 0;

        foreach ($narrated as $row) {
            $words += str_word_count(trim((string) $row->narration_text));
        }

        // The locale profile is half the key. Without it this compares en-CN
        // audio against an en-US measurement, which is what cancelled story
        // 21's 270-scene batch at scene 2.
        $violation = NarrationPace::violation(
            $voiceId,
            $scene->story?->locale_profile,
            $words,
            (int) $narrated->sum('duration_ms'),
        );

        if ($violation === null) {
            return;
        }

        $message = sprintf(
            'Scene %d, measured across the %d scenes narrated so far: %s',
            $scene->sequence,
            $narrated->count(),
            $violation,
        );

        // Detected either way. What the drift PROVES is what differs.
        //
        // On a measured pair it proves something is wrong, and the run stops at
        // a cost of one scene. On an unmeasured pair it proves only that the
        // sizing assumption was a guess — the audio is unaffected and it is the
        // runtime estimate that moves — and cancelling a 270-scene batch over
        // an estimate borrowed from a different script is the trade story 21
        // made and should not have.
        //
        // Logged rather than swallowed. A check that fired and was overruled
        // must leave a record, or afterwards "the guard did not stop it" and
        // "the guard could not stop it" read identically.
        if (! NarrationPace::isEnforceable($voiceId, $scene->story?->locale_profile)) {
            Log::warning('narration pace advisory — detected, not enforced: this voice has no measured pace for this locale', [
                'story_id' => $scene->story_id,
                'scene' => $scene->sequence,
                'voice_id' => $voiceId,
                'locale_profile' => $scene->story?->locale_profile,
                'scenes_measured' => $narrated->count(),
                'detail' => $message,
            ]);

            return;
        }

        throw new NarrationPaceException($message);
    }

    /**
     * Narration this story already has from the CURRENT voice at the CURRENT
     * speed.
     *
     * Scoped that tightly on purpose: scenes read by another narrator, or by
     * this one at a different tempo, answer a different question and would
     * average away the very drift being looked for.
     *
     * @return Collection<int, object>
     */
    private function currentNarration(Scene $scene, string $voiceId): Collection
    {
        return SceneAudio::query()
            ->join('scenes', 'scenes.id', '=', 'scene_audio.scene_id')
            ->where('scenes.story_id', $scene->story_id)
            ->where('scene_audio.narration_simulated', false)
            ->where('scene_audio.narration_provider', $this->speech->providerName())
            ->where('scene_audio.narration_voice_id', $voiceId)
            ->whereNotNull('scene_audio.duration_ms')
            ->whereRaw('abs(coalesce(scene_audio.narration_speed, -1) - ?) < 0.005', [
                NarrationPace::configuredSpeed(),
            ])
            ->get(['scenes.narration_text', 'scene_audio.duration_ms']);
    }

    /**
     * The story's narration track, created on first use.
     *
     * One row per story per language. The table has carried N tracks per story
     * since Phase 0 even though only one is ever written, so that Phase 3's
     * dubbed audio is a new row rather than a migration — which is why this
     * keys on the locale rather than assuming a single track per story.
     */
    private function track(Story $story): AudioTrack
    {
        return AudioTrack::query()->firstOrCreate(
            [
                'story_id' => $story->id,
                'language' => (string) ($story->locale_profile ?? 'en-US'),
            ],
            [
                'voice_id' => $story->voice_id,
                'status' => AssetStatus::Generating,
            ],
        );
    }

    /**
     * Write the audio row, and the scene's duration alongside it.
     *
     * `duration_ms` lands in two places on purpose and they are not duplicates:
     * `scene_audio.duration_ms` is this file's length, and `scenes.duration_ms`
     * is what the clip's frame count is computed from. They are the same number
     * for a single-track story and would diverge the moment a second language
     * exists, where the video is cut once and the tracks differ.
     *
     * Everything derived — padding, offsets, the subtitle timeline — is left
     * null. Those accumulate across the whole story and are recomputed
     * wholesale at concat, never patched per scene.
     */
    private function record(
        Scene $scene,
        AudioTrack $track,
        string $path,
        int $durationMs,
        string $voiceId,
        int $samples,
        int $sampleRate,
    ): void {
        SceneAudio::query()->updateOrCreate(
            ['scene_id' => $scene->id, 'audio_track_id' => $track->id],
            [
                'audio_path' => $path,
                // Who made this, recorded at the time it was made. The ledger
                // already carries it per call; the asset row did not, and that
                // gap is why a story full of placeholder audio could report
                // itself finished to a real provider.
                'narration_provider' => $this->speech->providerName(),
                'narration_voice_id' => $voiceId,
                // The tempo, which is provenance too. Same narrator, same
                // words, two speeds in one video is as audible as two
                // narrators and invisible to every other check.
                'narration_speed' => NarrationPace::configuredSpeed(),
                'narration_simulated' => $this->speech->isSimulated(),
                /*
                 * The WORDS this file was made from, recorded at the moment it
                 * was made — the one thing this row described everything about
                 * except.
                 *
                 * Without it the only evidence about whether audio still
                 * matches its scene was `scenes.approved_narration_hash`, which
                 * records what the OPERATOR approved rather than what the
                 * SYNTHESISER was sent. The two agree until somebody repairs
                 * the text and regenerates before re-approving, at which point
                 * the approval record is the stale one and Gate 2 discards four
                 * freshly paid files for being exactly right. See
                 * ApproveScenesGate::clearStalePaidAssets().
                 */
                'narration_text_hash' => Scene::fingerprint($scene->narration_text),
                'duration_ms' => $durationMs,
                'samples' => $samples,
                'sample_rate' => $sampleRate,
                // A regenerated scene's timings describe audio that no longer
                // exists. Cleared here so TranscribeSceneTimings sees work to
                // do rather than a stale transcript that happens to be present.
                'timings_json' => null,
                'timings_provider' => null,
                'timings_simulated' => null,
                'padded_duration_ms' => null,
                'frames' => null,
                'offset_frames' => null,
                'offset_samples' => null,
                'offset_ms' => null,
                'status' => AssetStatus::Generating,
            ],
        );

        $scene->forceFill(['duration_ms' => $durationMs])->save();
    }

    /**
     * Whether this scene's audio survives untouched.
     *
     * Two questions, and both have to be answered or the skip is wrong in one
     * direction or the other:
     *
     *   Has the TEXT changed? That is what decides whether redoing this costs
     *   money, and it is what stops a resumed batch billing a second time.
     *
     *   Was it made by WHAT IS BOUND NOW? That is what decides whether the file
     *   on disk is the artefact we want. Without it, a story narrated by a
     *   stand-in — 186 silent WAVs whose text has not changed — is reported as
     *   finished, and a real provider bound afterwards never gets to run.
     *
     * The provider is asked of the injected instance, not of config: this is
     * the object that would do the work, and it is the one whose name will land
     * in the ledger.
     */
    private function isAlreadyGenerated(Scene $scene): bool
    {
        if ($scene->needsNarration()) {
            return false;
        }

        if ($scene->narrationProvenanceStale($this->speech->providerName(), $scene->story->voice_id)) {
            return false;
        }

        $audio = $scene->sceneAudio()->first();

        return $audio?->audio_path !== null && $this->disk()->exists($audio->audio_path);
    }

    /** Filed by scene ID, not sequence — a reorder must not rename paid audio. */
    private function store(Scene $scene, string $bytes, string $mimeType): string
    {
        $path = sprintf(
            '%d/narration/scene-%d.%s',
            $scene->story_id,
            $scene->id,
            match ($mimeType) {
                'audio/mpeg', 'audio/mp3' => 'mp3',
                'audio/wav', 'audio/x-wav', 'audio/wave' => 'wav',
                'audio/ogg' => 'ogg',
                default => 'bin',
            },
        );

        $this->disk()->put($path, $bytes);

        return $path;
    }

    private function disk(): Filesystem
    {
        return Storage::disk((string) config('render.assets.disk', 'assets'));
    }
}
