<?php

namespace App\Models;

use App\Enums\CostCategory;
use App\Enums\CostUnit;
use Database\Factories\CostEntryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One paid API call. Written once, never updated.
 *
 * Two invariants are enforced here rather than described in a comment:
 *
 *  1. An ASSET cost cannot be recorded against a story that was not allowed to
 *     commit one. Creating an `asset` row asserts the story has passed Gate 2,
 *     which turns "no paid asset generation before scenes_approved" into a
 *     property of the data. Anything that bills writes one of these — so
 *     anything that bills hits the guard, whatever layer it lives in.
 *
 *     `text` rows are NOT gated, and that is deliberate rather than a hole. The
 *     Gate 2 line is about 150-250 stills and per-scene TTS committed before a
 *     human has read a scene; tokens spent writing the outline the operator
 *     reads at Gate 1 are the thing the gates exist to review, not the thing
 *     they exist to prevent. Gating them would have made "every paid API call
 *     writes a row" impossible for every stage above Gate 2. See CostCategory.
 *
 *  2. The story's denormalised total is maintained from these rows, so
 *     stories.total_cost_usd and the sum of its entries cannot disagree.
 *
 * @property CostUnit $unit
 * @property CostCategory $category
 */
class CostEntry extends Model
{
    /** @use HasFactory<CostEntryFactory> */
    use HasFactory;

    /** Immutable: there is no such thing as amending what a provider charged. */
    public const UPDATED_AT = null;

    protected $fillable = [
        'story_id',
        'provider',
        'operation',
        'category',
        'quantity',
        'unit',
        'usd_cost',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'category' => CostCategory::class,
            'unit' => CostUnit::class,
            'usd_cost' => 'decimal:4',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (CostEntry $entry): void {
            // Default to the gated category. A row that arrives without one is
            // a caller that has not thought about which kind of spend it is,
            // and the safe answer to that is the restrictive one.
            $entry->category ??= CostCategory::Asset;

            if ($entry->category->requiresPaidAssetsUnlocked()) {
                $entry->story?->assertPaidAssetsUnlocked($entry->operation);
            }
        });

        static::created(function (CostEntry $entry): void {
            // increment() writes in SQL rather than reading, adding and saving,
            // so concurrent asset workers cannot lose each other's charges.
            $entry->story?->increment('total_cost_usd', (float) $entry->usd_cost);
        });
    }

    /** @return BelongsTo<Story, $this> */
    public function story(): BelongsTo
    {
        return $this->belongsTo(Story::class);
    }
}
