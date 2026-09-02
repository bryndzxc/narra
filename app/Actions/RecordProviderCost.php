<?php

namespace App\Actions;

use App\Models\CostEntry;
use App\Models\Story;
use App\Support\Providers\ProviderUsage;
use RuntimeException;

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
        // The invariant that keeps this table worth having. A stand-in
        // contacted nobody and owes nothing, so a non-zero cost from one is not
        // an estimate or a placeholder — it is money in the ledger that was
        // never spent, and it is indistinguishable from the real thing once
        // written. This is the guard that was missing when 558 simulated calls
        // wrote $8.12 against a story.
        if ($usage->simulated && abs($usage->usdCost) > 0.0) {
            throw new RuntimeException(sprintf(
                'Provider "%s" reported a simulated %s call costing $%s. A simulated call must cost '
                .'exactly $0.00 — it contacted no vendor and produced no real artefact, and a ledger '
                .'that cannot tell the two apart cannot answer what a video cost. Use '
                .'ProviderUsage::simulated(), which is zero by construction.',
                $usage->provider,
                $usage->operation,
                number_format($usage->usdCost, 4),
            ));
        }

        return CostEntry::create([
            'story_id' => $story->id,
            'provider' => $usage->provider,
            // Read from the provider's own detail rather than from config. The
            // question this answers is "what did this call bill against", and a
            // config lookup here would answer "what is configured now" — which
            // is a different question the moment a model is switched.
            // From the instance that ran, falling back to whatever it reported
            // in its own detail. Never from config: config describes what is
            // configured now, which is a different question from what was
            // called then — and answering the wrong one put 36 reference rows
            // on record as `gemini-3.1-flash-image` when Seedream drew them.
            'model' => $usage->model
                ?? (is_string($usage->detail['model'] ?? null) ? $usage->detail['model'] : null),
            // Carried, not inferred from the provider name. A future stand-in
            // called something other than 'fake' must still be excluded from
            // "what did this video actually cost".
            'simulated' => $usage->simulated,
            'operation' => $usage->operation,
            // Carried from the provider rather than inferred here. Whether a
            // call is asset spend is a property of what was called, and
            // CostEntry gates on it — deriving it from an operation string at
            // the call site would put the money line one typo away from off.
            'category' => $usage->category,
            'quantity' => $usage->quantity,
            'unit' => $usage->unit,
            'usd_cost' => $usage->usdCost,
            // The provider's own breakdown, which every provider fills in and
            // this insert used to drop. It is the audit trail behind usd_cost:
            // for TTS it carries the vendor's `character-cost` response header
            // alongside our own count of what we sent, and without both there
            // is no way to tell from the ledger whether a quantity came from
            // the vendor or from us — which is the whole of reconciling a bill.
            'detail' => $usage->detail,
        ]);
    }
}
