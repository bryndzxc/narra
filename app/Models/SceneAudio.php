<?php

namespace App\Models;

use App\Enums\AssetStatus;
use Database\Factories\SceneAudioFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One scene's narration, its word timings, and its place on the timeline.
 *
 * `offset_frames` and `offset_samples` are authoritative and accumulate as
 * integers. `offset_ms` is derived from `offset_frames` for display and must
 * never be used for timing — summing rounded milliseconds compounds error scene
 * by scene, and by minute 35 that is visible desync. The subtitle shift reads
 * offset_samples.
 *
 * @property AssetStatus $status
 */
class SceneAudio extends Model
{
    /** @use HasFactory<SceneAudioFactory> */
    use HasFactory;

    /** Laravel would guess `scene_audios`. */
    protected $table = 'scene_audio';

    protected $fillable = [
        'scene_id',
        'audio_track_id',
        'audio_path',
        'narration_provider',
        'narration_voice_id',
        'narration_speed',
        'narration_simulated',
        'timings_json',
        'timings_provider',
        'timings_simulated',
        'duration_ms',
        // The true length, in the only unit that loses nothing. duration_ms is
        // derived from these and must never be an input to frame arithmetic.
        'samples',
        'sample_rate',
        'padded_duration_ms',
        'frames',
        'offset_frames',
        'offset_samples',
        'offset_ms',
        'status',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'timings_json' => 'array',
            // Nullable booleans, and the null matters: "provenance unknown" is
            // a third state, not a synonym for false. The staleness rule reads
            // it as "leave the asset alone".
            'narration_speed' => 'float',
            'narration_simulated' => 'boolean',
            'timings_simulated' => 'boolean',
            'duration_ms' => 'integer',
            'padded_duration_ms' => 'integer',
            'frames' => 'integer',
            'offset_frames' => 'integer',
            'offset_samples' => 'integer',
            'offset_ms' => 'integer',
            'status' => AssetStatus::class,
        ];
    }

    /** @return BelongsTo<Scene, $this> */
    public function scene(): BelongsTo
    {
        return $this->belongsTo(Scene::class);
    }

    /** @return BelongsTo<AudioTrack, $this> */
    public function audioTrack(): BelongsTo
    {
        return $this->belongsTo(AudioTrack::class);
    }

    /**
     * Silence appended to reach the frame boundary. At most one frame — ~33 ms
     * at 30fps, average ~16 ms, inaudible and spread across scenes rather than
     * pooled at the end.
     */
    public function paddingMs(): ?int
    {
        if ($this->duration_ms === null || $this->padded_duration_ms === null) {
            return null;
        }

        return $this->padded_duration_ms - $this->duration_ms;
    }
}
