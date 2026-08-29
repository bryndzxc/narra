<?php

namespace App\Models;

use App\Enums\AssetStatus;
use Database\Factories\AudioTrackFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A full narration track for a story, in one language.
 *
 * Exactly one of these exists per story today. The relationship is plural from
 * the start so Phase 3 — one render, several dubbed tracks — is a feature
 * rather than a migration.
 *
 * @property AssetStatus $status
 */
class AudioTrack extends Model
{
    /** @use HasFactory<AudioTrackFactory> */
    use HasFactory;

    protected $fillable = [
        'story_id',
        'language',
        'voice_id',
        'audio_path',
        'timings_json',
        'duration_ms',
        'status',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'timings_json' => 'array',
            'duration_ms' => 'integer',
            'status' => AssetStatus::class,
        ];
    }

    /** @return BelongsTo<Story, $this> */
    public function story(): BelongsTo
    {
        return $this->belongsTo(Story::class);
    }

    /** @return HasMany<SceneAudio, $this> */
    public function sceneAudio(): HasMany
    {
        return $this->hasMany(SceneAudio::class);
    }
}
