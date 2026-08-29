<?php

namespace App\Actions;

use App\Models\YoutubeMetadata;

/**
 * YouTube's rules, checked before the operator can approve Gate 4.
 *
 * These are enforced here rather than described in the UI because the failure
 * mode is silent: an over-long title truncates in search, a tag list past 500
 * characters is cut at upload, and a chapter list that breaks any one of
 * YouTube's four rules is simply ignored — no error, no chapters, and a
 * 30-40 minute video with no chapters is leaving retention on the table.
 *
 * Blocking problems stop approval. Warnings are things an operator may
 * legitimately decide to live with.
 */
class ValidateYoutubeMetadata
{
    /**
     * @return array{blocking: array<int, string>, warnings: array<int, string>}
     */
    public function handle(YoutubeMetadata $metadata): array
    {
        $limits = config('youtube.limits');

        $blocking = [];
        $warnings = [];

        // -- Staleness --------------------------------------------------------
        // First, because it invalidates everything below it. A sheet generated
        // against a render that has since been thrown away has chapter
        // timestamps for a video that no longer exists, and every other check
        // here would pass on it happily.
        if ($metadata->isStale()) {
            $blocking[] = $metadata->hasFreshRender()
                ? 'The publish sheet was generated against an older render and has not been regenerated since '
                    .'the new one finished. Regenerate it — the chapter timestamps are the whole reason this '
                    .'stage runs after the render.'
                : 'The publish sheet describes a render that was invalidated when Gate 2 was reopened. '
                    .'Its chapter timestamps are for a video that no longer exists. Re-render, then '
                    .'regenerate the sheet.';
        }

        // -- Title ----------------------------------------------------------
        $title = trim((string) $metadata->title_selected);

        if ($title === '') {
            $blocking[] = 'No title selected.';
        } elseif (mb_strlen($title) > $limits['title_hard']) {
            $blocking[] = sprintf(
                'Title is %d characters; YouTube truncates at %d.',
                mb_strlen($title),
                $limits['title_hard']
            );
        } elseif (mb_strlen($title) > $limits['title_target']) {
            $warnings[] = sprintf(
                'Title is %d characters. Past %d the tail stops being visible on mobile and in search, '
                .'so make sure the hook is on the left.',
                mb_strlen($title),
                $limits['title_target']
            );
        }

        // -- Description ------------------------------------------------------
        $description = (string) $metadata->description;

        if (trim($description) === '') {
            $blocking[] = 'Description is empty. The first two or three sentences are what shows in search.';
        } elseif (mb_strlen($description) > $limits['description']) {
            $blocking[] = sprintf(
                'Description is %s characters; the limit is %s.',
                number_format(mb_strlen($description)),
                number_format($limits['description'])
            );
        }

        // -- Tags -------------------------------------------------------------
        if ($metadata->tags_char_count > $limits['tags_chars']) {
            $blocking[] = sprintf(
                'Tags total %d characters; the budget is %d. Remove some rather than letting YouTube cut them.',
                $metadata->tags_char_count,
                $limits['tags_chars']
            );
        }

        // -- Chapters ----------------------------------------------------------
        foreach ($this->chapterProblems($metadata, $limits) as $problem) {
            $blocking[] = $problem;
        }

        // -- Thumbnail ---------------------------------------------------------
        if ($metadata->thumbnail_scene_id === null) {
            $warnings[] = 'No thumbnail still chosen. Flag one at Gate 2 or pick one here.';
        }

        if ($metadata->thumbnail_text_options === null || $metadata->thumbnail_text_options === []) {
            $warnings[] = 'No thumbnail text drafted.';
        }

        // -- Checklist ----------------------------------------------------------
        $state = $metadata->checklist_state ?? [];

        foreach (config('youtube.checklist') as $key => $item) {
            if (($item['required'] ?? false) && ! ($state[$key] ?? false)) {
                $blocking[] = $item['label'].' — not confirmed.';
            }
        }

        return ['blocking' => $blocking, 'warnings' => $warnings];
    }

    /**
     * YouTube's four chapter rules, in the order it applies them.
     *
     * @param  array<string, mixed>  $limits
     * @return array<int, string>
     */
    private function chapterProblems(YoutubeMetadata $metadata, array $limits): array
    {
        $chapters = $metadata->chapters();

        if ($chapters === []) {
            return ['No chapters. Act timings are filled in by the render, so this means the render has not run.'];
        }

        $problems = [];

        if ($chapters[0]['start_ms'] !== 0) {
            $problems[] = 'First chapter must start at 00:00.';
        }

        if (count($chapters) < $limits['min_chapters']) {
            $problems[] = sprintf(
                'Only %d chapters; YouTube ignores a list shorter than %d.',
                count($chapters),
                $limits['min_chapters']
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
                } elseif ($limits['min_chapter_ms'] > $chapter['start_ms'] - $previous['start_ms']) {
                    $problems[] = sprintf(
                        'Chapter "%s" is shorter than %d seconds.',
                        $previous['title'],
                        (int) ($limits['min_chapter_ms'] / 1000)
                    );
                }
            }

            $previous = $chapter;
        }

        return $problems;
    }
}
