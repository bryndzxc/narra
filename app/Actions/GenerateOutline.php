<?php

namespace App\Actions;

use App\Contracts\ScriptWriter;
use App\Enums\StoryFormat;
use App\Enums\StoryStatus;
use App\Models\Act;
use App\Models\Story;
use App\Support\LocaleGuard;
use App\Support\Providers\OutlineDraft;
use App\Support\Providers\ScriptWriterException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Premise -> act outline. The first paid call, and Gate 1's subject.
 *
 * Writes `acts` rows and moves the story to `outlined`, where it waits for an
 * operator. Nothing further runs until they approve, which is the point: the
 * outline is what the entire 7,000-word script is generated against, so an act
 * structure that is wrong here is wrong in every act that follows it.
 *
 * Three things happen in a fixed order, and the order is the design:
 *
 *   1. generate    — one call, priced by the provider
 *   2. cost        — recorded before anything else can fail, because the money
 *                    was spent whether or not the rest succeeds
 *   3. locale check — fails the stage loudly BEFORE the acts are written, so a
 *                    leaked idiom never reaches Gate 1 at all
 *
 * A locale failure after step 2 still leaves the cost row, which is correct.
 * The tokens were burned; a cost table that only records successful calls
 * cannot answer what a video actually cost.
 */
class GenerateOutline
{
    public function __construct(
        private readonly ScriptWriter $writer,
        private readonly LocaleGuard $locale,
        private readonly RecordProviderCost $costs,
    ) {}

    /**
     * @param  int|null  $actCount  Defaults per format. An anthology wants
     *                              3-5 self-contained stories; a single
     *                              narrative carries 5-8 acts comfortably.
     */
    public function handle(Story $story, ?int $actCount = null): OutlineDraft
    {
        $this->assertReady($story);

        $actCount ??= $story->format === StoryFormat::Anthology ? 5 : 6;

        $draft = $this->writer->outline($story, $actCount);

        // Before the locale check, deliberately. The call has already been
        // billed and a cost table that drops the rows for failed stages cannot
        // answer what a video cost.
        $this->costs->handle($story, $draft->usage);

        // After the cost row, for the same reason the locale check is: the
        // call has already been billed, and this is a check on its OUTPUT.
        // Structured outputs cannot express an exact array length — minItems
        // must be 0 or 1 — so the count is verified rather than constrained,
        // and a provider that threw on it would lose the cost row for a call
        // that cost money.
        if (! $draft->actCountMatches()) {
            throw new ScriptWriterException(sprintf(
                'Asked for %d acts and got %d. The outline is one call — re-run it. The act count '
                .'decides the chapter structure of the finished video, so quietly accepting a '
                .'different number changes the product.',
                $draft->requestedActCount,
                $draft->actCount()
            ));
        }

        $this->locale->assert(
            $draft->proseForInspection(),
            (string) $story->locale_profile,
            'outline generation'
        );

        DB::transaction(function () use ($story, $draft): void {
            // Re-running replaces the outline rather than appending to it.
            // Nothing downstream exists yet — the story is at `draft` or
            // `outlined`, so no act has a script and no scene references one.
            $story->acts()->delete();

            foreach ($draft->acts as $act) {
                Act::create([
                    'story_id' => $story->id,
                    'sequence' => $act->sequence,
                    'title' => $act->title,
                    'summary' => $act->summary,
                    // No script yet, and no rehook — GenerateActScripts writes
                    // both. Gate 1 surfaces `is_rehook_written` so an act that
                    // never got one is visible rather than merely weak.
                    'script' => null,
                    'is_rehook_written' => false,
                ]);
            }

            if ($story->title !== $draft->title && trim($draft->title) !== '') {
                $story->update(['title' => $draft->title]);
            }

            if ($story->status === StoryStatus::Draft) {
                $story->transitionTo(StoryStatus::Outlined);
            }
        });

        return $draft;
    }

    /**
     * Re-running is legal but never accidental.
     *
     * Permitted from `draft` (first run) and `outlined` (the operator asked for
     * a different structure at Gate 1). Refused from `scripted` onward, because
     * by then acts carry scripts that were written against this outline and
     * replacing it would orphan every one of them.
     */
    private function assertReady(Story $story): void
    {
        if (in_array($story->status, [StoryStatus::Draft, StoryStatus::Outlined], true)) {
            return;
        }

        throw new RuntimeException(sprintf(
            "Cannot generate an outline for a story at '%s'. The acts already carry scripts written "
            .'against the current outline, and replacing it would orphan all of them. Take the story '
            .'back to Gate 1 first.',
            $story->status->value
        ));
    }
}
