<?php

namespace App\Support;

/**
 * YouTube's four chapter rules, in one place.
 *
 * They are worth enforcing because the failure is silent: a chapter list that
 * breaks any one of them is not rejected at upload, it is simply ignored. No
 * error, no chapters, and a 30-40 minute video with no chapters is leaving
 * retention on the table.
 *
 * One class rather than a method on the Gate 4 validator, because there are now
 * two callers and they need the answer at different times:
 *
 *   GenerateMetadata        BEFORE spending. If the acts cannot make a legal
 *                           chapter list, three model calls buy a sheet that
 *                           cannot be used, and the money is gone either way.
 *                           A guard has to be upstream of the thing it
 *                           distrusts.
 *
 *   ValidateYoutubeMetadata AT the gate, over whatever is in the row now —
 *                           including a sheet whose acts were re-timed by a
 *                           later render.
 *
 * Two copies of four rules is two answers to one question, and the first time
 * they disagree the gate passes something the generator would have refused.
 */
final class ChapterRules
{
    /**
     * What is wrong with this chapter list, phrased for an operator.
     *
     * Empty means YouTube will render it. Order matches the order the rules
     * are applied in, so the first line is the first thing to fix.
     *
     * @param  array<int, array{timestamp: string, title: string, start_ms: int}>  $chapters
     * @param  array<string, mixed>|null  $limits  Defaults to config.
     * @return array<int, string>
     */
    public function problems(array $chapters, ?array $limits = null): array
    {
        $limits ??= (array) config('youtube.limits');

        $minChapters = (int) ($limits['min_chapters'] ?? 3);
        $minChapterMs = (int) ($limits['min_chapter_ms'] ?? 10_000);

        if ($chapters === []) {
            return ['No chapters. Act timings are filled in by the render, so this means the render has not run.'];
        }

        $problems = [];

        if ($chapters[0]['start_ms'] !== 0) {
            $problems[] = 'First chapter must start at 00:00.';
        }

        if (count($chapters) < $minChapters) {
            $problems[] = sprintf(
                'Only %d chapters; YouTube ignores a list shorter than %d.',
                count($chapters),
                $minChapters
            );
        }

        $previous = null;

        foreach ($chapters as $chapter) {
            if ($previous !== null) {
                if ($chapter['start_ms'] <= $previous['start_ms']) {
                    $problems[] = sprintf(
                        'Chapters are not in ascending order: "%s" starts at or before "%s".',
                        $chapter['title'],
                        $previous['title']
                    );
                } elseif ($minChapterMs > $chapter['start_ms'] - $previous['start_ms']) {
                    $problems[] = sprintf(
                        'Chapter "%s" is shorter than %d seconds.',
                        $previous['title'],
                        (int) ($minChapterMs / 1000)
                    );
                }
            }

            $previous = $chapter;
        }

        return $problems;
    }
}
