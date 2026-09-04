<?php

namespace App\Models;

use App\Support\StyleFingerprint;
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

    // -- Which look the approved face was drawn in ---------------------------

    /** No sheet at all — nothing to be stale. */
    public const STYLE_NONE = 'none';

    /** Generated under the art style configured right now. */
    public const STYLE_CURRENT = 'current';

    /** Generated under a different one. Every still of this face inherits it. */
    public const STYLE_STALE = 'stale';

    /**
     * Generated before the style was recorded at all.
     *
     * Reported as unknown and never as fine. The style string of the day was
     * not stored and cannot be recovered, so stamping today's fingerprint on
     * the row to silence the warning would be fabricating provenance — the
     * exact move that let 117 scenes read as narrated at the right speed when
     * nobody knew what speed they were read at.
     */
    public const STYLE_UNKNOWN = 'unknown';

    /**
     * Whether the approved reference face matches the configured art style.
     *
     * `ImagePromptBuilder` has claimed this existed since Phase 2 — "if the
     * channel's look is retuned, the sheets are stale and the operator is told
     * so rather than the mismatch being absorbed silently" — and nothing
     * implemented it. This is that.
     *
     * It reads the SELECTED reference rather than the character row, because
     * the fingerprint belongs to the image that was generated, and the
     * character row only holds a path to it.
     */
    public function referenceStyleState(): string
    {
        if (! $this->hasUsableReference()) {
            return self::STYLE_NONE;
        }

        $reference = $this->selectedReference()->latest('selected_at')->first()
            // Fall back to matching the stored path. `images:bakeoff` and any
            // hand-repaired row can set reference_image_path without a
            // selected_at, and reporting those as "no sheet" would be a warning
            // that hides itself on exactly the rows most likely to be odd.
            ?? $this->references()->where('image_path', $this->reference_image_path)->latest('id')->first();

        $fingerprint = $reference?->style_fingerprint;

        if ($fingerprint === null || $fingerprint === '') {
            return self::STYLE_UNKNOWN;
        }

        return $fingerprint === StyleFingerprint::current()
            ? self::STYLE_CURRENT
            : self::STYLE_STALE;
    }
}
