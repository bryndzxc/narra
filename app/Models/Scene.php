<?php

namespace App\Models;

use App\Enums\MotionPreset;
use App\Enums\SceneStatus;
use App\Support\AudioFrames;
use App\Support\NarrationPace;
use App\Support\TextBounds;
use Database\Factories\SceneFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One still, one line of narration, one clip.
 *
 * `duration_ms` is the RAW AUDIO duration. The clip's duration is derived from
 * it and never stored — see framesAt(). Two stored copies of one duration is
 * how a 35-minute video ends up seconds out of sync.
 *
 * @property MotionPreset $motion_preset
 * @property SceneStatus $status
 */
class Scene extends Model
{
    /** @use HasFactory<SceneFactory> */
    use HasFactory;

    /**
     * How long the two AUTHORED sections of an image prompt may be.
     *
     * A stored `image_prompt` is five sections joined by a blank line: the
     * frame, the expression block, the cast block, the art style and the
     * constraints (ImagePromptBuilder::build). Only the first two are written
     * per scene — by the scene writer, then by the operator at Gate 2. The
     * other three are the frozen cast text and two config constants,
     * identical across every scene of the story, and the operator neither
     * writes them nor can change them from the editor.
     *
     * Gate 2's editor used to load the WHOLE prompt into one field and
     * validate it at a literal 2,000. Measured over 1,681 stored prompts:
     *
     *   whole prompt                   p50 3,008   p90 3,281   max 4,206
     *   everything the operator did    p50 2,838   p90 3,084   max 3,973
     *     not write (cast + style +
     *     constraints)
     *   frame (authored)               p50   140   p90   173   p99 241   max 311
     *   expression (authored)          p50    48   p90    73   p99  92   max 117
     *
     * The art style alone is 2,226 characters and the constraints 306, so
     * 2,532 of a median 3,008 was the app's own text, and 1,495 of 1,693
     * scenes could not be saved — silently, because the field had no error
     * renderer. The operator was being measured against a constant. The
     * editor now loads and validates the frame and the expression only, and
     * ImagePromptBuilder::rewrite() puts them back in front of the untouched
     * tail. These bounds measure what is actually typed.
     *
     * 600 and 250 are derived from the authored distributions: roughly twice
     * the observed maximum of each, and for the frame about 100 words against
     * a prompt that asks for 25-45 — room for the operator to say more than
     * the writer did without a frame becoming a paragraph of scene, which
     * the same prompt forbids. Stated in the scene prompt and enforced after
     * the call in DraftScenes, for the reason Act::SUMMARY_MAX_CHARS is:
     * structured outputs do not honour maxLength.
     */
    public const FRAME_MAX_CHARS = 600;

    public const EXPRESSION_MAX_CHARS = 250;

    /**
     * @return array<string, int>
     */
    public static function textBounds(): array
    {
        return [
            'frame' => self::FRAME_MAX_CHARS,
            'expression' => self::EXPRESSION_MAX_CHARS,
        ];
    }

    /**
     * @param  array<string, string>  $fields
     * @return array<string, string>
     */
    public static function textOverflows(array $fields): array
    {
        return TextBounds::overflows(self::textBounds(), $fields);
    }

    protected $fillable = [
        'story_id',
        'act_id',
        // The chapter this scene's narration falls in. Null on a scene
        // drafted before chapters existed, or whose act was rewritten since.
        'chapter_id',
        'sequence',
        'is_hook',
        'is_thumbnail_candidate',
        'narration_text',
        'image_prompt',
        'image_path',
        'duration_ms',
        'motion_preset',
        'status',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'is_hook' => 'boolean',
            'is_thumbnail_candidate' => 'boolean',
            'duration_ms' => 'integer',
            'motion_preset' => MotionPreset::class,
            'status' => SceneStatus::class,
        ];
    }

    /** @return BelongsTo<Story, $this> */
    public function story(): BelongsTo
    {
        return $this->belongsTo(Story::class);
    }

    /** @return BelongsTo<Chapter, $this> */
    public function chapter(): BelongsTo
    {
        return $this->belongsTo(Chapter::class);
    }

    /**
     * Who is in this frame.
     *
     * Recorded when the scene is drafted, from the names the generator put in
     * the frame resolved against the stored cast — the same resolution that
     * decides which frozen descriptions get pasted into the image prompt. It
     * used to be computed for that and then discarded, which left the prompt
     * prose as the only record of who is in scene 147.
     *
     * It is data now because the reference rule depends on it: a scene
     * featuring a character with no approved face must fail loudly, and that
     * guarantee cannot rest on grepping an operator-editable text field for
     * names.
     *
     * @return BelongsToMany<Character, $this>
     */
    public function characters(): BelongsToMany
    {
        return $this->belongsToMany(Character::class)->withTimestamps();
    }

    /** @return BelongsTo<Act, $this> */
    public function act(): BelongsTo
    {
        return $this->belongsTo(Act::class);
    }

    /** @return HasMany<SceneAudio, $this> */
    public function sceneAudio(): HasMany
    {
        return $this->hasMany(SceneAudio::class);
    }

    // -- Gate 2 reopen: what was approved, and what has actually changed -----

    /**
     * The narration exactly as a paid provider would read it.
     *
     * Whitespace is normalised before hashing, deliberately. A pasted trailing
     * newline or a double space between sentences produces identical audio from
     * any TTS provider, so treating it as a change would re-bill a scene for
     * nothing. The hash exists to answer "will this cost money to redo", and the
     * answer there is no.
     */
    public static function fingerprint(?string $text): string
    {
        return hash('sha256', trim((string) preg_replace('/\s+/u', ' ', (string) $text)));
    }

    /** Input to TTS, and therefore to transcription and to duration_ms. */
    public function narrationFingerprint(): string
    {
        return self::fingerprint($this->narration_text);
    }

    /** Input to image generation. Nothing else. */
    public function imageFingerprint(): string
    {
        return self::fingerprint($this->image_prompt);
    }

    /**
     * Whether this scene's still has to be paid for again.
     *
     * Absent OR stale, in one predicate, so a first approval and a re-approval
     * are the same computation: on a first approval nothing exists, so every
     * scene needs everything, which is exactly right.
     */
    public function needsImage(): bool
    {
        return $this->image_path === null
            || $this->approved_image_hash !== $this->imageFingerprint();
    }

    /**
     * Whether this scene's narration has to be paid for again.
     *
     * Note what is NOT here: image_prompt, motion_preset, is_hook,
     * is_thumbnail_candidate and sequence. Changing any of those leaves the
     * audio on disk untouched and correct. Testing this with updated_at or
     * isDirty() would bill for a ticked checkbox.
     */
    public function needsNarration(): bool
    {
        return $this->sceneAudio->isEmpty()
            || $this->sceneAudio->contains(fn (SceneAudio $audio): bool => $audio->audio_path === null)
            || $this->approved_narration_hash !== $this->narrationFingerprint();
    }

    /**
     * Whether the word timings have to be transcribed again.
     *
     * Follows the narration: Whisper reads the audio, so new audio means new
     * timings. A changed image prompt does not touch this.
     */
    public function needsTranscription(): bool
    {
        return $this->sceneAudio->isEmpty()
            || $this->sceneAudio->contains(fn (SceneAudio $audio): bool => $audio->timings_json === null)
            || $this->approved_narration_hash !== $this->narrationFingerprint();
    }

    /**
     * Whether this scene's audio was made by something other than what is bound
     * now.
     *
     * The gap this closes: idempotency is keyed on the narration TEXT, which is
     * the right key for "will redoing this cost money" and the wrong key for
     * "is this the artefact we want". A story narrated by FakeSpeechSynthesizer
     * — 186 silent WAVs, text unchanged since Gate 2 — reports nothing
     * outstanding, so binding ElevenLabs and pressing Generate assets does
     * nothing at all, silently. Which is the same silent-substitution failure
     * that ran 186 stills through a stand-in, running in the other direction.
     *
     * Three rules, in order:
     *
     *   Unknown provenance is NOT stale. A null provider means the row predates
     *   provenance recording and the ledger could not resolve it. Never destroy
     *   an asset because its provenance is unknown — only because it
     *   demonstrably changed. That is the same rule narrationChanged() follows,
     *   and it is what stops this method re-billing a paid asset on a guess.
     *
     *   A different provider is stale. This is the point of the method.
     *
     *   A different VOICE is stale, on the same provider. A channel keeps one
     *   narrator; audio generated under a voice the story no longer uses would
     *   leave the video narrated by two different people, which no listener
     *   would forgive and no cost check would catch.
     */
    public function narrationProvenanceStale(string $provider, ?string $voiceId, ?float $speed = null): bool
    {
        $speed ??= NarrationPace::configuredSpeed();

        return $this->sceneAudio->contains(function (SceneAudio $audio) use ($provider, $voiceId, $speed): bool {
            if ($audio->audio_path === null || $audio->narration_provider === null) {
                return false;
            }

            if ($audio->narration_provider !== $provider) {
                return true;
            }

            // Only when both sides are known. A row recorded before voices were
            // tracked must not be regenerated because the column is empty.
            if ($audio->narration_voice_id !== null
                && $voiceId !== null
                && $audio->narration_voice_id !== $voiceId) {
                return true;
            }

            // And the SPEED, which is the same argument one level down. The
            // same narrator reading the same words at two different tempos in
            // one video is as audible as two different narrators, and it is
            // invisible to every other check: the provider matches, the voice
            // matches, the text is unchanged, the ledger balances.
            return $audio->narration_speed !== null
                && abs((float) $audio->narration_speed - $speed) >= 0.005;
        });
    }

    /**
     * The same question for the word timings, which are a different provider on
     * the same row.
     *
     * Separate from the narration check because the two swap independently: the
     * timings can move from a stand-in to WhisperX without the audio changing
     * hands, and re-timing existing audio is free where re-narrating it is not.
     */
    public function timingsProvenanceStale(string $provider): bool
    {
        return $this->sceneAudio->contains(
            fn (SceneAudio $audio): bool => $audio->timings_json !== null
                && $audio->timings_provider !== null
                && $audio->timings_provider !== $provider
        );
    }

    /**
     * Whether the narration the operator approved is not the narration on the
     * row any more.
     *
     * Distinct from needsNarration(), and the distinction is the whole safety
     * margin. needsNarration() is "absent OR stale" and answers "does the
     * pipeline have work to do" — it is true on a first approval, when nothing
     * has been recorded yet. This one is "recorded, and different", and it is
     * what authorises DELETING something. Never destroy an asset because its
     * provenance is unknown; destroy it only because it demonstrably changed.
     */
    public function narrationChanged(): bool
    {
        return $this->approved_narration_hash !== null
            && $this->approved_narration_hash !== $this->narrationFingerprint();
    }

    /** The same distinction, for the field that bills for stills. */
    public function imagePromptChanged(): bool
    {
        return $this->approved_image_hash !== null
            && $this->approved_image_hash !== $this->imageFingerprint();
    }

    /**
     * Whether the Ken Burns clip has to be re-encoded.
     *
     * Free — CPU, not money — so this is allowed to be broader than the two
     * above. Motion is in here and in neither of them: switching zoom_in to
     * pan_left re-renders the clip and bills for nothing.
     */
    public function needsClip(): bool
    {
        return $this->needsImage()
            || $this->needsNarration()
            || $this->approved_motion_preset !== $this->motion_preset->value;
    }

    /** Nothing paid has to be regenerated for this scene. */
    public function paidAssetsStillValid(): bool
    {
        return ! $this->needsImage() && ! $this->needsNarration() && ! $this->needsTranscription();
    }

    /**
     * Record this scene's paid inputs as approved.
     *
     * Written at Gate 2 approval and nowhere else — these columns are the
     * definition of "what the operator signed off on", so a caller that could
     * set them independently could also make a stale asset look current.
     * Deliberately not in $fillable for the same reason.
     */
    public function recordGateTwoApproval(): void
    {
        $this->forceFill([
            'approved_narration_hash' => $this->narrationFingerprint(),
            'approved_image_hash' => $this->imageFingerprint(),
            'approved_motion_preset' => $this->motion_preset->value,
        ])->save();
    }

    /**
     * Frames this scene's clip must hold.
     *
     * ceil, not round, and deliberately: the audio is padded up to match, so
     * ceil guarantees video >= audio for every scene and padding only ever adds
     * silence. round would sometimes make the video shorter than the audio,
     * forcing a trim that can clip the tail of the last word.
     *
     * The clip duration is exactly frames / fps by construction, because the
     * render uses -frames:v rather than -t.
     *
     * **Computed from the SAMPLE COUNT, never from duration_ms.** Milliseconds
     * are a lossy intermediate: 360002 samples at 24 kHz is 15.0000833 s, which
     * stores as 15000 and yields exactly 450 frames when the audio needs 451.
     * The ceil above normally absorbs that, and does not when the rounded
     * duration lands precisely on a frame boundary — story 21 scene 201, four
     * samples over. See App\Support\AudioFrames.
     *
     * The millisecond path remains for audio whose sample count is not known:
     * fixture stories, and rows written before the columns existed. It is named
     * rather than implied, and the clip job fills those rows in as it meets
     * them.
     */
    public function framesAt(?int $fps = null): ?int
    {
        $fps ??= (int) config('render.video.fps');
        $audio = $this->sceneAudio->first();

        if ($audio?->samples !== null && $audio?->sample_rate !== null) {
            return AudioFrames::forSamples(
                (int) $audio->samples,
                (int) $audio->sample_rate,
                (int) config('render.audio.sample_rate'),
                $fps,
            );
        }

        if ($this->duration_ms === null) {
            return null;
        }

        return AudioFrames::forMilliseconds((int) $this->duration_ms, $fps);
    }
}
