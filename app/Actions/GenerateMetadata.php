<?php

namespace App\Actions;

use App\Contracts\MetadataWriter;
use App\Enums\MetadataStatus;
use App\Enums\StoryStatus;
use App\Models\Story;
use App\Models\YoutubeMetadata;
use App\Support\ChapterRules;
use App\Support\LocaleGuard;
use App\Support\Providers\ScriptWriterException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The publish sheet: five titles, a description, chapters, tags, thumbnail text
 * and a pinned comment. Gate 4's subject.
 *
 * Runs AFTER the render and cannot run before it. Chapters are derived from act
 * timings and act timings are written by the mux, so a sheet generated earlier
 * would carry timestamps for a video that does not exist. That sequencing is
 * the reason this stage is last rather than a preference about workflow.
 *
 * **Nothing here is selected on the operator's behalf.** Five titles are
 * written and `title_selected` is deliberately left null, so the story cannot
 * reach `canApprove()` until a human has picked one. Gate 4 is a decision, and
 * a generator that pre-picked the title while leaving the button labelled
 * "approve" would have automated the gate away in everything but name.
 *
 * Order of operations, and the order is the design:
 *
 *   1. refuse    — status, and YouTube's chapter rules, checked BEFORE any call
 *   2. generate  — three calls, three models
 *   3. cost      — recorded per call, immediately, before anything downstream
 *                  can fail; the tokens were burned either way
 *   4. enforce   — title limit, tag budget, description ceiling, applied to
 *                  what came back
 *   5. write     — one row, one transaction
 *
 * Step 1 is the one worth defending. Validating the chapter rules at Gate 4
 * instead — where they were already validated — would mean discovering that a
 * story cannot have a legal chapter list AFTER paying to write copy about it.
 * A guard belongs upstream of the thing it distrusts.
 */
class GenerateMetadata
{
    /** Five, so the four that are not picked become data on what performs. */
    public const TITLE_VARIANTS = 5;

    public function __construct(
        private readonly MetadataWriter $writer,
        private readonly LocaleGuard $locale,
        private readonly RecordProviderCost $costs,
        private readonly ChapterRules $chapters,
        private readonly ComposeDescription $description,
    ) {}

    /**
     * @return array<int, string> One line per thing worth telling the operator.
     */
    public function handle(Story $story, bool $force = false): array
    {
        $story->loadMissing('acts');

        $metadata = YoutubeMetadata::query()->firstOrCreate(
            ['story_id' => $story->id],
            ['status' => MetadataStatus::Pending]
        );

        $this->assertReady($story, $metadata, $force);

        $notes = [];
        $limits = (array) config('youtube.limits');

        // -- Titles and the opening hook. Judgement work. --------------------
        $titles = $this->writer->titles($story, self::TITLE_VARIANTS);
        $this->costs->handle($story, $titles->usage);

        $this->locale->assert(
            $titles->proseForInspection(),
            (string) $story->locale_profile,
            'metadata titles'
        );

        // Enforced after the cost row, on what came back. A title past 100
        // characters is not a worse title, it is one YouTube truncates and the
        // column refuses — so it is dropped from the choices rather than
        // offered to the operator as if it were usable.
        $usableTitles = array_values(array_filter(
            $titles->titles,
            fn (string $title): bool => mb_strlen($title) <= $limits['title_hard']
        ));

        $dropped = count($titles->titles) - count($usableTitles);

        if ($dropped > 0) {
            $notes[] = sprintf(
                '%d title variant(s) came back past the %d-character hard limit and were dropped.',
                $dropped,
                $limits['title_hard'],
            );
        }

        if ($usableTitles === []) {
            throw new ScriptWriterException(sprintf(
                'Every title variant came back past the %d-character hard limit. The sheet has not '
                .'been written. Re-run the stage — it is one call and cents.',
                $limits['title_hard'],
            ));
        }

        // The failure this catches, named: five variants that are all legal and
        // all long. Nothing blocks on it — the gate correctly treats past-target
        // as a warning an operator may decide to live with — but a set where
        // EVERY option trips that warning leaves them nothing to decide between,
        // and the prompt asking for a spread is not evidence that it produced
        // one. Asking and checking are different claims.
        $withinTarget = array_filter(
            $usableTitles,
            fn (string $title): bool => mb_strlen($title) <= $limits['title_target']
        );

        if ($withinTarget === []) {
            $notes[] = sprintf(
                'Every one of the %d title variants is longer than the %d-character visible length, so '
                .'whichever is picked will truncate on mobile and in search. Re-run the stage if you '
                .'want a short option to test against — it is one call and cents.',
                count($usableTitles),
                $limits['title_target'],
            );
        }

        // -- Thumbnail text and the pinned comment. --------------------------
        $copy = $this->writer->copy($story, $usableTitles);
        $this->costs->handle($story, $copy->usage);

        $this->locale->assert(
            $copy->proseForInspection(),
            (string) $story->locale_profile,
            'metadata copy'
        );

        $overlay = $this->withinWordLimit($copy->thumbnailText, (int) $limits['thumbnail_text_words'], $notes);

        // -- Tags. Mechanical, and budgeted. ---------------------------------
        $tagDraft = $this->writer->tags($story, $usableTitles, (int) $limits['tags_chars']);
        $this->costs->handle($story, $tagDraft->usage);

        $this->locale->assert(
            $tagDraft->proseForInspection(),
            (string) $story->locale_profile,
            'metadata tags'
        );

        $tags = $tagDraft->withinBudget((int) $limits['tags_chars']);
        $droppedTags = $tagDraft->droppedCount((int) $limits['tags_chars']);

        if ($droppedTags > 0) {
            // Reported, not swallowed. The rule is that the budget is enforced
            // rather than silently truncated — and a list that quietly arrives
            // a third shorter than it was written is the silent version with
            // extra steps.
            $notes[] = sprintf(
                '%d tag(s) dropped whole to stay inside the %d-character budget. No tag was cut '
                .'mid-word — a truncated tag is a different tag.',
                $droppedTags,
                $limits['tags_chars'],
            );
        }

        // -- The description. Opening + derived chapters + channel footer. ----
        //
        // Assembled by the same Action the Gate 4 button uses, so "rebuild the
        // chapter list" and "generate the sheet" cannot produce two different
        // descriptions from the same acts.
        $description = $this->description->handle($metadata, $titles->descriptionOpening);

        if (mb_strlen($description) > $limits['description']) {
            throw new ScriptWriterException(sprintf(
                'The assembled description is %s characters against a %s limit. The chapter list and '
                .'footer are fixed, so this is the opening — re-run the stage.',
                number_format(mb_strlen($description)),
                number_format($limits['description']),
            ));
        }

        DB::transaction(function () use ($story, $metadata, $usableTitles, $description, $tags, $overlay, $copy): void {
            $metadata->fill([
                'title_options' => $usableTitles,
                'description' => $description,
                'tags' => $tags,
                'thumbnail_text_options' => $overlay ?: null,
                'pinned_comment' => $copy->pinnedComment ?: null,
                // Only if the operator has not already chosen one. A scene
                // flagged at Gate 2 is a decision they made in front of the
                // stills; regenerating the copy is not a reason to overwrite it.
                'thumbnail_scene_id' => $metadata->thumbnail_scene_id
                    ?? $story->scenes()->where('is_thumbnail_candidate', true)->value('id'),
            ]);

            // NOT title_selected. Five variants are written; picking one is the
            // gate. Leaving it null is what keeps canApprove() false until a
            // human has actually chosen.
            $metadata->status = MetadataStatus::Generated;
            $metadata->stale_at = null;
            $metadata->save();

            // `rendered -> metadata_ready` is Gate 3's crossing and stays Gate
            // 3's. Drafting the sheet is not a substitute for watching the
            // render, so this deliberately moves nothing.
        });

        return $notes;
    }

    /**
     * Everything that must be true before a single token is spent.
     *
     * All of it is cheap and all of it is checkable from the database. The
     * expensive version of this function is discovering the same facts at Gate
     * 4, after three calls have been billed for a sheet that cannot be used.
     */
    private function assertReady(Story $story, YoutubeMetadata $metadata, bool $force): void
    {
        if ($story->status->rank() < StoryStatus::Rendered->rank()) {
            throw new RuntimeException(sprintf(
                "Cannot write the publish sheet for a story at '%s'. Chapters are derived from act "
                .'timings and act timings are written by the mux, so this stage runs after the render '
                .'— not alongside the script.',
                $story->status->value,
            ));
        }

        if ($story->status === StoryStatus::Published) {
            throw new RuntimeException(
                'The story is published. The sheet that was approved is the record of what was '
                .'uploaded, and regenerating it would rewrite that record.'
            );
        }

        // A sheet marked stale describes a render that was thrown away. Writing
        // fresh copy over it would clear the mark while the chapter timestamps
        // stayed wrong, which is precisely the failure the mark exists for.
        if ($metadata->isStale() && ! $metadata->hasFreshRender()) {
            throw new RuntimeException(
                'This sheet was invalidated when Gate 2 was reopened and the story has not been '
                .'re-rendered since. Its chapters would be timestamps for a video that no longer '
                .'exists. Re-render first.'
            );
        }

        // The chapter rules, before the money. Same rules the gate applies —
        // one implementation, two callers. See App\Support\ChapterRules.
        $problems = $this->chapters->problems($metadata->chapters());

        if ($problems !== []) {
            throw new RuntimeException(
                'The acts cannot make a chapter list YouTube will render, so the sheet would be '
                ."unusable and the calls would be wasted:\n  - ".implode("\n  - ", $problems)
            );
        }

        if ($metadata->status === MetadataStatus::Approved && ! $force) {
            throw new RuntimeException(
                'This sheet has already been approved at Gate 4. Regenerating would overwrite the '
                .'title and description an operator chose. Pass --force if that is what you mean.'
            );
        }

        // Nothing is regenerated silently: a second run is three more billed
        // calls and it replaces copy the operator may have edited by hand.
        if ($metadata->title_options !== null && $metadata->title_options !== [] && ! $force) {
            throw new RuntimeException(
                'A sheet has already been written for this story. Re-running replaces every title '
                .'variant and the description opening, including any edits made at Gate 4, and bills '
                .'three more calls. Pass --force if that is what you mean.'
            );
        }
    }

    /**
     * Overlay phrases short enough to be read at thumbnail size.
     *
     * Dropped rather than trimmed. Cutting "SHE CHANGED THE LOCKS ON THE HOUSE"
     * to five words produces a phrase nobody wrote; dropping it leaves the four
     * that work.
     *
     * @param  array<int, string>  $phrases
     * @param  array<int, string>  $notes
     * @return array<int, string>
     */
    private function withinWordLimit(array $phrases, int $maxWords, array &$notes): array
    {
        $kept = [];

        foreach ($phrases as $phrase) {
            $words = count(preg_split('/\s+/', trim($phrase), -1, PREG_SPLIT_NO_EMPTY) ?: []);

            if ($words > 0 && $words <= $maxWords) {
                $kept[] = $phrase;
            }
        }

        $dropped = count($phrases) - count($kept);

        if ($dropped > 0) {
            $notes[] = sprintf(
                '%d thumbnail phrase(s) came back longer than %d words and were dropped.',
                $dropped,
                $maxWords,
            );
        }

        return $kept;
    }
}
