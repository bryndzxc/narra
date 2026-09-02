<?php

namespace App\Support\Providers;

use App\Enums\CostCategory;
use App\Enums\CostUnit;

/**
 * What one provider call cost, carried back with whatever it produced.
 *
 * Every provider result embeds one of these, and it is not optional. The rule
 * is that every paid call writes a cost_entries row, and the reliable way to
 * make that true is to make it impossible for a call to return a result without
 * saying what it cost — rather than asking each call site to remember.
 *
 * The provider computes the dollar figure, not the caller. Only the provider
 * knows its own rate card, and a caller that priced a response itself would
 * silently drift the moment a rate changed.
 */
final class ProviderUsage
{
    /**
     * @param  string  $provider  Rate-card owner: 'anthropic', 'fake', ...
     * @param  string  $operation  What was asked of it: 'generate_outline', ...
     * @param  float  $quantity  Counted in $unit.
     * @param  float  $usdCost  Already computed by the provider.
     * @param  array<string, int|float|string|null>  $detail
     *                                                        Provider-specific breakdown for the log — input vs output tokens,
     *                                                        cache hits, the model actually served by. Never used for money;
     *                                                        `usdCost` is the money.
     * @param  bool  $simulated
     *                           Whether a stand-in produced this rather than a vendor. Carried
     *                           on the usage rather than inferred from `$provider === 'fake'`
     *                           downstream, because the ledger has to be able to answer "what
     *                           did this video actually cost" without anyone remembering which
     *                           provider names are real. A simulated usage MUST carry
     *                           `usdCost: 0.0`; RecordProviderCost refuses it otherwise.
     * @param  string|null  $model
     *                              The model that actually served the call, from the instance that ran
     *                              — not from config, which describes what is configured now rather
     *                              than what was called then.
     */
    public function __construct(
        public readonly string $provider,
        public readonly string $operation,
        public readonly CostCategory $category,
        public readonly float $quantity,
        public readonly CostUnit $unit,
        public readonly float $usdCost,
        public readonly array $detail = [],
        public readonly bool $simulated = false,
        public readonly ?string $model = null,
    ) {}

    /**
     * A stand-in call: no vendor contacted, no artefact produced, nothing owed.
     *
     * Always zero, by construction rather than by configuration. Fake providers
     * used to price themselves from a `providers.fake.*` rate card so that a
     * fixture run produced a "realistic" breakdown. That made `cost_entries`
     * unable to answer the only question it exists for: a run that never
     * touched the network wrote $8.12 to the ledger, indistinguishable at a
     * glance from a real one.
     */
    public static function simulated(
        string $operation,
        CostCategory $category,
        float $quantity,
        CostUnit $unit,
        array $detail = [],
    ): self {
        return new self(
            provider: 'fake',
            operation: $operation,
            category: $category,
            quantity: $quantity,
            unit: $unit,
            usdCost: 0.0,
            detail: $detail,
            simulated: true,
        );
    }

    /**
     * A call that genuinely cost nothing — a fake, or a cache-only response.
     *
     * Still a usage object rather than null: "this cost zero" and "nobody
     * recorded what this cost" must not look the same downstream.
     */
    public static function free(string $provider, string $operation, CostCategory $category): self
    {
        return new self(
            provider: $provider,
            operation: $operation,
            category: $category,
            quantity: 0.0,
            unit: CostUnit::Requests,
            usdCost: 0.0,
        );
    }

    public function summary(): string
    {
        return sprintf(
            '%s/%s — %s %s, $%s',
            $this->provider,
            $this->operation,
            rtrim(rtrim(number_format($this->quantity, 4, '.', ''), '0'), '.'),
            $this->unit->value,
            number_format($this->usdCost, 4)
        );
    }
}
