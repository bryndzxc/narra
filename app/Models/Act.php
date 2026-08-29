<?php

namespace App\Models;

use Database\Factories\ActFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One act: a unit of script generation and a YouTube chapter, at once.
 *
 * `summary` is not decoration. Acts are generated sequentially, each call fed
 * the outline plus the summaries of the acts before it — that is the mechanism
 * that keeps 7,000 words from drifting, repeating or contradicting themselves.
 */
class Act extends Model
{
    /** @use HasFactory<ActFactory> */
    use HasFactory;

    protected $fillable = [
        'story_id',
        'sequence',
        'title',
        'summary',
        'script',
        'is_rehook_written',
        'start_ms',
        'duration_ms',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'is_rehook_written' => 'boolean',
            'start_ms' => 'integer',
            'duration_ms' => 'integer',
        ];
    }

    /** @return BelongsTo<Story, $this> */
    public function story(): BelongsTo
    {
        return $this->belongsTo(Story::class);
    }

    /** @return HasMany<Scene, $this> */
    public function scenes(): HasMany
    {
        return $this->hasMany(Scene::class)->orderBy('sequence');
    }

    /**
     * This act's chapter start as a YouTube timestamp.
     *
     * Null until the render has filled in start_ms — chapters cannot exist
     * before the video does, which is why metadata generation runs after the
     * render rather than alongside the script.
     *
     * Hours are omitted below an hour, matching how YouTube itself renders
     * them; both forms are accepted in a description.
     */
    public function chapterTimestamp(): ?string
    {
        if ($this->start_ms === null) {
            return null;
        }

        $seconds = intdiv($this->start_ms, 1000);
        $hours = intdiv($seconds, 3600);

        return $hours > 0
            ? sprintf('%d:%02d:%02d', $hours, intdiv($seconds % 3600, 60), $seconds % 60)
            : sprintf('%d:%02d', intdiv($seconds, 60), $seconds % 60);
    }
}
