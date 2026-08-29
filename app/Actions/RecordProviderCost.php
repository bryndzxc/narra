<?php

namespace App\Actions;

use App\Models\CostEntry;
use App\Models\Story;
use App\Support\Providers\ProviderUsage;

/**
 * Turns a provider's reported usage into the cost row it owes.
 *
 * One place, called by every stage. The rule is that every paid API call writes
 * a row and that "what did this video cost" is answerable in one query — and
 * the way that stays true is by there being exactly one function that writes
 * these, taking an object that a provider cannot return without filling in.
 *
 * Free calls are recorded too. A fake run producing no rows and a real run
 * whose cost recording silently broke look identical from the table, and the
 * whole point of the table is to notice the difference.
 */
class RecordProviderCost
{
    public function handle(Story $story, ProviderUsage $usage): CostEntry
    {
        return CostEntry::create([
            'story_id' => $story->id,
            'provider' => $usage->provider,
            'operation' => $usage->operation,
            // Carried from the provider rather than inferred here. Whether a
            // call is asset spend is a property of what was called, and
            // CostEntry gates on it — deriving it from an operation string at
            // the call site would put the money line one typo away from off.
            'category' => $usage->category,
            'quantity' => $usage->quantity,
            'unit' => $usage->unit,
            'usd_cost' => $usage->usdCost,
        ]);
    }
}
