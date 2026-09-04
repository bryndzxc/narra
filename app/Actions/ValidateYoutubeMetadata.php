<?php

namespace App\Actions;

use App\Models\YoutubeMetadata;
use App\Support\ChapterRules;
use App\Support\PublishChecklist;

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
    public function __construct(
        // The same four rules GenerateMetadata refuses to spend against. Shared
        // rather than restated: two copies would eventually let the gate pass
        // a chapter list the generator would have rejected.
        private readonly ChapterRules $chapters,
    ) {}

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
        foreach ($this->chapters->problems($metadata->chapters(), $limits) as $problem) {
            $blocking[] = $problem;
        }

        // -- Thumbnail ---------------------------------------------------------
        if ($metadata->thumbnail_scene_id === null) {
            $warnings[] = 'No thumbnail still chosen. Flag one at Gate 2 or pick one here.';
        }

        // A warning rather than a block, and the distinction is the same one
        // the whole sheet runs on: the two blocking items are the ones with
        // consequences outside this app. An upload with no custom thumbnail
        // gets an auto-generated frame, which is bad for the channel and not a
        // policy problem — and an operator who wants to make one by hand should
        // not be stopped by a picker they chose not to use.
        if (trim((string) $metadata->thumbnail_selected) === '') {
            $warnings[] = $metadata->thumbnail_options === null || $metadata->thumbnail_options === []
                ? 'No thumbnail composed. The button above builds candidates from stills this story '
                    .'already owns — it generates nothing and costs nothing. Without one, YouTube '
                    .'picks a frame out of the video for you.'
                : 'Thumbnails are composed but none is picked, so nothing will be copied out beside '
                    .'the video.';
        } elseif ($metadata->selectedThumbnail() === null) {
            // A selection pointing at a composition that no longer exists. This
            // one is worth saying loudly: the sheet looks complete and the file
            // it names is gone.
            $warnings[] = sprintf(
                'The selected thumbnail "%s" is not among the composed options — the thumbnails were '
                .'re-composed and the key went with them. Pick one again.',
                (string) $metadata->thumbnail_selected,
            );
        }

        $overlay = $metadata->thumbnail_text_options ?? [];

        if ($overlay === []) {
            $warnings[] = 'No thumbnail text drafted.';
        }

        // The word cap was in config from the day the sheet was built and read
        // by nothing, which made it a limit in name only. A thumbnail phrase is
        // read at about 200 pixels wide; the constraint is not stylistic.
        $maxWords = (int) $limits['thumbnail_text_words'];

        foreach ($overlay as $phrase) {
            $words = count(preg_split('/\s+/', trim((string) $phrase), -1, PREG_SPLIT_NO_EMPTY) ?: []);

            if ($words > $maxWords) {
                $warnings[] = sprintf(
                    'Thumbnail phrase "%s" is %d words. Past %d it stops being readable at the size a '
                    .'thumbnail is actually seen.',
                    $phrase,
                    $words,
                    $maxWords
                );
            }
        }

        // -- Checklist ----------------------------------------------------------
        $state = $metadata->checklist_state ?? [];

        foreach (config('youtube.checklist') as $key => $item) {
            if (($item['required'] ?? false) && ! ($state[$key] ?? false)) {
                $blocking[] = $item['label'].' — not confirmed.';
            }
        }

        // Checklist items ticked against a value the sheet cannot show.
        //
        // This began as one hand-written check on the scheduled time, and the
        // reasoning behind it was never specific to that item: an item about
        // something that cannot exist is the same defect as a form with no
        // producer, and the fix is to make the thing exist rather than to stop
        // asking. It applies to every per-story item, and it was true of the
        // pinned comment the whole time the check named only one field.
        //
        // PublishChecklist resolves each item to its value, so this asks one
        // question of all of them rather than one question per item written out
        // by hand. A hand-written list of which items are checkable is a second
        // source of truth that only has to agree on the day it is written.
        foreach (PublishChecklist::ticksWithNothingBehindThem($metadata->story, $metadata) as $problem) {
            $warnings[] = $problem;
        }

        return ['blocking' => $blocking, 'warnings' => $warnings];
    }
}
