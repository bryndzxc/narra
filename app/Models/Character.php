<?php

namespace App\Models;

use Database\Factories\CharacterFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

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
     * Every candidate face ever generated for this character, rejects included.
     *
     * @return HasMany<CharacterReference, $this>
     */
    public function references(): HasMany
    {
        return $this->hasMany(CharacterReference::class)->orderBy('batch')->orderBy('sequence');
    }

    /**
     * The chosen one, as a relation so it can be eager-loaded across a cast.
     *
     * A HasMany rather than a HasOne even though at most one row qualifies:
     * "at most one" is enforced in SelectCharacterReference, not by the schema,
     * and a HasOne would quietly hide a second selected row rather than making
     * it visible if the invariant ever broke.
     *
     * @return HasMany<CharacterReference, $this>
     */
    public function selectedReference(): HasMany
    {
        return $this->hasMany(CharacterReference::class)->whereNotNull('selected_at');
    }

    /**
     * The scenes this character actually appears in.
     *
     * Written when scenes are drafted rather than inferred from the prompt
     * text. The reference resolver and the Gate 2 completeness check both hang
     * off this, and neither should depend on substring-matching a name against
     * a field the operator is free to edit.
     *
     * @return BelongsToMany<Scene, $this>
     */
    public function scenes(): BelongsToMany
    {
        return $this->belongsToMany(Scene::class)->withTimestamps();
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

    /**
     * Whether there is an approved face on disk for this character.
     *
     * The path being set is not the question — a path pointing at a file that
     * is gone is worse than a null, because it reads as done. Both are checked
     * here so that every caller asking "is this character ready" asks the same
     * question, including the one that refuses to approve Gate 2 without it.
     */
    public function hasUsableReference(): bool
    {
        return $this->reference_image_path !== null
            && Storage::disk((string) config('characters.disk', 'characters'))
                ->exists($this->reference_image_path);
    }

    public function referenceBytes(): string
    {
        return Storage::disk((string) config('characters.disk', 'characters'))
            ->get((string) $this->reference_image_path);
    }
}
