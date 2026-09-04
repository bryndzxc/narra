<?php

namespace App\Support;

use App\Enums\CostCategory;
use App\Models\Story;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * What the channel has spent, for the dashboard.
 *
 * ---------------------------------------------------------------------------
 * WHY THIS SHARES A PREDICATE WITH THE LEDGER RATHER THAN RESTATING ONE
 * ---------------------------------------------------------------------------
 *
 * `stories.total_cost_usd` is maintained in `CostEntry::booted()`, which adds a
 * row to the total only when `$entry->category->countsTowardVideoCost()`. A
 * roll-up that filtered on its own hand-written list — `where('category', '!=',
 * 'evaluation')`, say — would agree with that on the day it was written and
 * would be wrong the first time a fifth category is added. This project has
 * paid for that exact mistake twice: one narration multiplier applied in three
 * places by three pieces of code that never compared notes, and a retry prompt
 * restating a guard's word list without the word the guard actually refused.
 *
 * So the categories are asked FROM the enum, through the same method the
 * ledger asks. A new category is counted or excluded here by construction, and
 * there is no second opinion to drift.
 *
 * Per-story totals are not recomputed at all. They are read off
 * `stories.total_cost_usd` and `Story::evaluationSpend()` — the same two
 * numbers the stories index, the gate header and the render page already
 * print. A dashboard that computed its own per-story figure would be a second
 * answer to a question that already has one, and this codebase's history says
 * the two would eventually disagree in a way nobody would notice for months.
 *
 * The month is calendar month in the app's timezone, stated rather than
 * implied: `cost_entries.created_at` is UTC and an operator in Manila reading
 * "spend this month" means their month.
 */
final class SpendSummary
{
    private function __construct(
        public readonly CarbonImmutable $since,
        public readonly float $monthVideoSpend,
        public readonly float $monthEvaluationSpend,
        /** @var Collection<int, array{provider: string, usd: float, calls: int, simulated_calls: int}> */
        public readonly Collection $byProvider,
        public readonly float $allTimeVideoSpend,
        public readonly float $allTimeEvaluationSpend,
    ) {}

    public static function forCurrentMonth(): self
    {
        $since = CarbonImmutable::now()->startOfMonth();

        $counted = array_map(
            fn (CostCategory $c): string => $c->value,
            array_values(array_filter(
                CostCategory::cases(),
                fn (CostCategory $c): bool => $c->countsTowardVideoCost(),
            )),
        );

        $excluded = array_map(
            fn (CostCategory $c): string => $c->value,
            array_values(array_filter(
                CostCategory::cases(),
                fn (CostCategory $c): bool => ! $c->countsTowardVideoCost(),
            )),
        );

        $month = DB::table('cost_entries')
            ->where('created_at', '>=', $since->utc())
            ->selectRaw('category, SUM(usd_cost) as usd')
            ->groupBy('category')
            ->pluck('usd', 'category');

        $allTime = DB::table('cost_entries')
            ->selectRaw('category, SUM(usd_cost) as usd')
            ->groupBy('category')
            ->pluck('usd', 'category');

        $sum = static fn (Collection $rows, array $categories): float => (float) collect($categories)
            ->sum(fn (string $category): float => (float) ($rows[$category] ?? 0));

        $byProvider = DB::table('cost_entries')
            ->where('created_at', '>=', $since->utc())
            ->whereIn('category', $counted)
            ->selectRaw('provider')
            ->selectRaw('SUM(usd_cost) as usd')
            ->selectRaw('COUNT(*) as calls')
            ->selectRaw("SUM(CASE WHEN simulated = 1 THEN 1 ELSE 0 END) as simulated_calls")
            ->groupBy('provider')
            ->orderByDesc('usd')
            ->get()
            ->map(fn (object $row): array => [
                'provider' => (string) $row->provider,
                'usd' => (float) $row->usd,
                'calls' => (int) $row->calls,
                'simulated_calls' => (int) $row->simulated_calls,
            ]);

        return new self(
            since: $since,
            monthVideoSpend: $sum(collect($month), $counted),
            monthEvaluationSpend: $sum(collect($month), $excluded),
            byProvider: $byProvider,
            allTimeVideoSpend: $sum(collect($allTime), $counted),
            allTimeEvaluationSpend: $sum(collect($allTime), $excluded),
        );
    }

    /**
     * The costliest stories, straight off the denormalised column.
     *
     * @return Collection<int, Story>
     */
    public static function costliestStories(int $limit = 5): Collection
    {
        return Story::query()
            ->where('total_cost_usd', '>', 0)
            ->orderByDesc('total_cost_usd')
            ->limit($limit)
            ->get();
    }
}
