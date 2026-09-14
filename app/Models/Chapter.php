<?php

namespace App\Models;

use App\Support\YoutubeTimestamp;
use Database\Factories\ChapterFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One chapter: a YouTube chapter and a re-hook point, inside an act.
 *
 * The act is the unit the script is WRITTEN in — its running summary is
 * what keeps 7,000 words coherent, its phase is what the writer branches
 * on. The chapter is the unit the video is WATCHED in: a title in the
 * progress bar, and an opening line every two and a half minutes engineered
 * to carry someone about to close the tab. The two were one unit for a
 * phase, and the measurement that split them is in config/chapters.php.
 *
 * A chapter holds no prose. `first_sentence` is an offset into the act's
 * script in the unit DraftScenes cuts scenes in, so the boundary is
 * addressable and the text is stored once.
 */
class Chapter extends Model
{
    /** @use HasFactory<ChapterFactory> */
    use HasFactory;

    /** YouTube's chapter title limit, the same bound an act title carries. */
    public const TITLE_MAX_CHARS = Act::TITLE_MAX_CHARS;

    protected $fillable = [
        'story_id',
        'act_id',
        'sequence',
        'title',
        'rehook_line',
        'first_sentence',
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
            'first_sentence' => 'integer',
            'start_ms' => 'integer',
            'duration_ms' => 'integer',
        ];
    }

    /** @return BelongsTo<Act, $this> */
    public function act(): BelongsTo
    {
        return $this->belongsTo(Act::class);
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
     * This chapter's start as a YouTube timestamp, or null before the render
     * has timed it.
     */
    public function chapterTimestamp(): ?string
    {
        return $this->start_ms === null ? null : YoutubeTimestamp::format((int) $this->start_ms);
    }

    public function hasRehook(): bool
    {
        return trim((string) $this->rehook_line) !== '';
    }
}
