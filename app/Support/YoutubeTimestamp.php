<?php

namespace App\Support;

/**
 * A millisecond offset as the timestamp YouTube reads in a description.
 *
 * One place, because two things now print chapter timestamps — an act, for
 * a story outlined before chapters existed, and a chapter — and two copies
 * of "omit the hours below an hour" is two chances for one of them to be
 * corrected alone.
 *
 * Hours are omitted below an hour, matching how YouTube itself renders them;
 * both forms are accepted in a description.
 */
final class YoutubeTimestamp
{
    public static function format(int $ms): string
    {
        $seconds = intdiv($ms, 1000);
        $hours = intdiv($seconds, 3600);

        return $hours > 0
            ? sprintf('%d:%02d:%02d', $hours, intdiv($seconds % 3600, 60), $seconds % 60)
            : sprintf('%d:%02d', intdiv($seconds, 60), $seconds % 60);
    }
}
