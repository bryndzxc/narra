<?php

namespace App\Models;

use Database\Factories\CharacterFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A recurring character, and the two things that keep them looking like
 * themselves across 150-250 stills: a locked seed and a reference image.
 */
class Character extends Model
{
    /** @use HasFactory<CharacterFactory> */
    use HasFactory;

    protected $fillable = [
        'story_id',
        'name',
        'description',
        'seed',
        'reference_image_path',
        'style_notes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'seed' => 'integer',
        ];
    }

    /** @return BelongsTo<Story, $this> */
    public function story(): BelongsTo
    {
        return $this->belongsTo(Story::class);
    }

    /**
     * Whether this character is pinned well enough to survive a long video.
     *
     * A seed alone drifts as prompts change around it; a reference image alone
     * leaves the generator free to reinterpret. Consistency needs both.
     */
    public function isLocked(): bool
    {
        return $this->seed !== null && $this->reference_image_path !== null;
    }
}
