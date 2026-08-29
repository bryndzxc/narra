<?php

namespace App\Models;

use App\Enums\MotionPreset;
use App\Enums\SceneStatus;
use Database\Factories\SceneFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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

    protected $fillable = [
        'story_id',
        'act_id',
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
     */
    public function framesAt(?int $fps = null): ?int
    {
        if ($this->duration_ms === null) {
            return null;
        }

        $fps ??= (int) config('render.video.fps');

        return (int) ceil($this->duration_ms / 1000 * $fps);
    }
}
