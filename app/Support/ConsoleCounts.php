<?php

namespace App\Support;

use App\Enums\StoryStatus;
use App\Models\Story;
use Illuminate\Support\Collection;

/**
 * The two numbers the navigation rail carries.
 *
 * ---------------------------------------------------------------------------
 * WHY THESE TWO
 * ---------------------------------------------------------------------------
 *
 * They are the questions the console is opened to answer: is anything waiting
 * on me, and has anything stopped. Carrying them in the rail means the answer
 * is on screen from Gate 2 and from a render page, not only from the dashboard
 * — which matters because the failure this console exists to prevent is a page
 * that looks calm while a queue holds 152 jobs nobody is listening to, and an
 * operator deep in a 270-scene scene list is exactly the person who will not
 * think to go and look.
 *
 * ---------------------------------------------------------------------------
 * WHY IT RUNS NextAction RATHER THAN COUNTING STATUSES
 * ---------------------------------------------------------------------------
 *
 * A `whereIn('status', [...])` count would be one query instead of a sweep, and
 * it would be a SECOND definition of "waiting on you". It would also be wrong
 * in a way nobody would notice: a story at `outlined` is waiting on the
 * operator only when every act already has a script, and on the `text` queue
 * otherwise. NextAction knows that; a status list does not.
 *
 * This project has paid twice for a parallel computation of a figure that
 * already existed — a narration multiplier applied at three prices in three
 * places, and a cost projection asking config while the container had resolved
 * something else. A badge disagreeing with the page it links to is the same
 * defect at lower stakes, and lower stakes is exactly how it survives.
 *
 * So it is the same sweep the dashboard runs, memoised for the request. Twelve
 * to twenty live stories is a handful of queries per page on a single-operator
 * tool. If this ever holds hundreds the fix is a cached column, not a cleverer
 * count — the same call `Stories\Index` makes about its own per-row NextAction.
 */
final class ConsoleCounts
{
    /**
     * Memoised per request, not per process.
     *
     * The layout renders once per request, so this is computed once. A static
     * that outlived the request would be a stale reading presented as current
     * inside a `queue:work` daemon, which is the exact thing WorkerHealth's
     * depth memo is scoped against.
     *
     * @var array{waiting: int, queued: int, stranded: bool}|null
     */
    private static ?array $memo = null;

    /**
     * @return array{waiting: int, queued: int, stranded: bool}
     */
    public static function all(): array
    {
        if (self::$memo !== null) {
            return self::$memo;
        }

        $live = Story::query()
            ->whereNot('status', StoryStatus::Published)
            // Fixtures are not work, so they are not a badge. A count that
            // never goes to zero is a count nobody reads. See Story::isFixture.
            ->realWork()
            ->get();

        $waiting = $live
            ->map(fn (Story $story): NextAction => NextAction::for($story))
            ->filter(fn (NextAction $next): bool => $next->waitingOn === NextAction::OPERATOR)
            ->count();

        $workers = WorkerHealth::all();

        return self::$memo = [
            'waiting' => $waiting,

            /*
             * Read from the queue, never from `render_jobs`. A row is opened
             * inside the running job, so a scene still sitting in Redis has no
             * row at all — this is the number that tells "nothing left to do"
             * from "nobody doing it". An unreadable depth counts as nothing
             * here and is reported as unreadable by the health panel, which is
             * the surface that can say so properly.
             */
            'queued' => collect($workers)->sum(fn (array $w): int => (int) ($w['pending'] ?? 0)),

            /*
             * Whether that queued work is going anywhere. Depth alone is not an
             * alarm — a live worker with 416 jobs behind it is a worker
             * working. Depth with nobody listening is, and it is what turns the
             * badge red.
             */
            'stranded' => collect($workers)->contains(
                fn (array $w): bool => in_array(
                    $w['state'],
                    // NOT_CONSUMING belongs here for the same reason STRANDED
                    // does: work is queued and nothing is moving it. The only
                    // difference is that somebody is on the queue, which makes
                    // it harder to see rather than less serious.
                    [WorkerHealth::STRANDED, WorkerHealth::NOT_CONSUMING, WorkerHealth::STALE],
                    true,
                ),
            ),
        ];
    }

    /** Drop the memo — for tests that change the world mid-request. */
    public static function forget(): void
    {
        self::$memo = null;
    }

    /**
     * Every unfinished story with the action it is waiting on, for the
     * dashboard.
     *
     * Returned together with the counts above so the rail and the page below it
     * are reading one sweep rather than two.
     *
     * @return Collection<int, NextAction>
     */
    public static function nextActions(Collection $stories): Collection
    {
        return $stories->mapWithKeys(
            fn (Story $story): array => [$story->id => NextAction::for($story)]
        );
    }
}
