<?php

namespace App\Support;

use App\Enums\ActPhase;
use App\Enums\PartnerEndState;
use App\Enums\StoryEnding;
use App\Enums\StoryFormat;
use App\Models\Story;

/**
 * How the last few videos ended, shown beside the ending picker.
 *
 * The operator's ask, 2026-09-19: "if three in a row were her voice I want to
 * see that before I pick a fourth." The same shape as the reused-names list in
 * the cast prompt — a choice made one story at a time cannot vary the channel
 * unless the person making it can see the channel.
 *
 * A story that CHOSE is reported as chosen. A story outlined before the choice
 * existed has no ending column, and what it actually produced is read from its
 * refusal act: a closing chapter in the antagonist's voice means it ended in
 * their voice; a written refusal act without one ended on the narrator. Those
 * rows say they were read from the chapters, because a reading is not a
 * choice and should not look like one. Stories with neither — a draft with no
 * ending, an anthology, a refusal act not yet written — are skipped rather
 * than guessed at. Fixtures are skipped: they are not videos.
 */
final class RecentEndings
{
    /** How many videos the picker shows. */
    public const SHOWN = 5;

    /** How long a run of one ending has to be before the picker says so. */
    public const STREAK = 3;

    /**
     * Newest first.
     *
     * @return array<int, array{title: string, slug: string, ending: StoryEnding, chosen: bool, written: bool}>
     */
    public static function last(int $count = self::SHOWN, ?int $exceptStoryId = null): array
    {
        $rows = [];

        $stories = Story::query()
            ->where('is_fixture', false)
            ->when($exceptStoryId !== null, fn ($q) => $q->whereKeyNot($exceptStoryId))
            ->orderByDesc('id')
            ->limit(60)
            ->get();

        foreach ($stories as $story) {
            if ($story->format === StoryFormat::Anthology) {
                continue;
            }

            $refusal = $story->acts()
                ->where('phase', ActPhase::Refusal)
                ->whereNotNull('script')
                ->where('script', '!=', '')
                ->first();

            if ($story->ending !== null) {
                $rows[] = [
                    'title' => (string) $story->title,
                    'slug' => (string) $story->slug,
                    'ending' => $story->ending,
                    'chosen' => true,
                    'written' => $refusal !== null,
                ];
            } elseif ($refusal !== null) {
                $rows[] = [
                    'title' => (string) $story->title,
                    'slug' => (string) $story->slug,
                    'ending' => $refusal->chapters()->whereNotNull('point_of_view')->exists()
                        ? StoryEnding::AntagonistVoice
                        : StoryEnding::NewLife,
                    'chosen' => false,
                    'written' => true,
                ];
            }

            if (count($rows) >= $count) {
                break;
            }
        }

        return $rows;
    }

    /**
     * How the last few videos with a partner ended, for the end-state picker.
     *
     * The same argument as `last()`, one field over and the operator's own
     * words: "a model left to choose converges, and I see one story where the
     * channel sees a run of them." Three weddings in a row is a channel that
     * ends every video with a wedding, and the only person who can see that is
     * the one holding the picker.
     *
     * Only stories that CHOSE. A story outlined before the column existed
     * cannot be read back the way an ending can — `RecentEndings::last()` can
     * look for a point-of-view chapter, and there is no equivalent structural
     * tell for "they married"; the words are in prose. Reading it would be
     * inventing a reading, so those stories are absent rather than guessed at.
     *
     * @return array<int, array{title: string, state: PartnerEndState}>
     */
    public static function lastPartnerEndStates(int $count = self::SHOWN, ?int $exceptStoryId = null): array
    {
        $rows = [];

        $stories = Story::query()
            ->where('is_fixture', false)
            ->whereNotNull('partner_end_state')
            ->when($exceptStoryId !== null, fn ($q) => $q->whereKeyNot($exceptStoryId))
            ->orderByDesc('id')
            ->limit($count)
            ->get();

        foreach ($stories as $story) {
            if ($story->partner_end_state === null) {
                continue;
            }

            $rows[] = ['title' => (string) $story->title, 'state' => $story->partner_end_state];
        }

        return $rows;
    }

    /**
     * The ending the newest stories share, when STREAK or more in a row do.
     *
     * @param  array<int, array{ending: StoryEnding}>  $rows  newest first, from last()
     * @return array{ending: StoryEnding, count: int}|null
     */
    public static function streak(array $rows): ?array
    {
        if ($rows === []) {
            return null;
        }

        $first = $rows[0]['ending'];
        $count = 0;

        foreach ($rows as $row) {
            if ($row['ending'] !== $first) {
                break;
            }

            $count++;
        }

        return $count >= self::STREAK ? ['ending' => $first, 'count' => $count] : null;
    }
}
