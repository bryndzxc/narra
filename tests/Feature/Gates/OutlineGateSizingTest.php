<?php

namespace Tests\Feature\Gates;

use App\Enums\StoryStatus;
use App\Livewire\Gates\OutlineGate;
use App\Models\Act;
use App\Models\Story;
use App\Support\GateVoice;
use Faker\Generator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\PageProbe;
use Tests\TestCase;

/**
 * The rate a script was sized to, on the page where the target is decided.
 *
 * ---------------------------------------------------------------------------
 * THE GAP, WHICH THE FIX FOR THE PREVIOUS ONE CREATED
 * ---------------------------------------------------------------------------
 *
 * `targetWordsPerAct()` moved from the fallback 160 to the measured 197 and
 * `sized_against_wpm` freezes whatever each story was written to. Correct, and
 * it left story 9 with a 5,600-word target and a story written today with
 * 6,895, with nothing on any page explaining the difference. **A figure that is
 * right and unexplained reads as a figure that is wrong** — and two of them side
 * by side read as a bug somebody should go and find.
 *
 * Closed in the same session it was created in, which is the point: this file's
 * whole list of inherited defects is things that were true at the end of one
 * pass and forgotten by the start of the next.
 */
class OutlineGateSizingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Two stories sized differently each say what they were sized to.
     *
     * THE RED HALF, and it is a pair of pages rather than one: the defect is not
     * that a number is missing, it is that two DIFFERENT numbers are unexplained.
     * A page showing only the target would satisfy any single-story assertion
     * and leave the difference exactly as mysterious as it was.
     */
    public function test_two_stories_sized_differently_each_explain_their_own_target(): void
    {
        $old = $this->sizedStory(160, 'sizing-old');
        $new = $this->sizedStory(197, 'sizing-new');

        $oldHtml = Livewire::test(OutlineGate::class, ['story' => $old])->html();
        $newHtml = Livewire::test(OutlineGate::class, ['story' => $new])->html();

        $this->assertMatchesRegularExpression(
            '/sized at\s*(<[^>]+>\s*)?160/',
            $oldHtml,
            'A story written to 160 wpm must say so. Its 5,600-word target is otherwise a number '
            .'that differs from the next story\'s for no visible reason.',
        );
        $this->assertMatchesRegularExpression('/sized at\s*(<[^>]+>\s*)?197/', $newHtml);

        // And the two targets really do differ, or the explanation explains
        // nothing and this test is about a distinction the page does not have.
        $this->assertSame(
            5600,
            Livewire::test(OutlineGate::class, ['story' => $old])->instance()->sizing()['target'],
        );
        $this->assertSame(
            6895,
            Livewire::test(OutlineGate::class, ['story' => $new])->instance()->sizing()['target'],
        );
    }

    /**
     * A script with no recorded rate reads as unknown, and gets no target.
     *
     * NULL MEANS UNKNOWN. The column is nullable precisely so that "nobody
     * recorded this" and "this was 160" cannot be the same value, and there are
     * two ways a page can undo that: resolve the null to today's rate, or drop
     * the row so nothing is said at all. The first version of this panel did the
     * second — it elided — which is how absence comes to read as agreement.
     *
     * **No target is printed, and that is the assertion that matters.** A target
     * computed from today's rate, set beside the words actually written, would
     * compare a script against a budget it was never written to. That is the
     * exact false comparison the column exists to prevent, so the page prints
     * the words, prints no target, and says why.
     */
    public function test_a_script_with_no_recorded_rate_reads_as_unknown(): void
    {
        $story = $this->sizedStory(null, 'sizing-unknown', StoryStatus::Scripted);

        $component = Livewire::test(OutlineGate::class, ['story' => $story]);
        $html = $component->html();

        $this->assertSame('unknown', $component->instance()->sizing()['state']);

        $this->assertMatchesRegularExpression(
            '/not on record|Unknown, not assumed/',
            $html,
            'A script written before the rate was recorded has an unknown target, and unknown that '
            .'is not said is unknown that reads as fine.',
        );

        $this->assertNull(
            $component->instance()->sizing()['target'],
            'No target may be computed here. One derived from today\'s rate, printed beside the '
            .'words actually written, is a comparison against a budget this script never had.',
        );

        $this->assertStringNotContainsString(
            'sized at',
            $html,
            'The page must not claim a rate for a script whose rate is not on record.',
        );
    }

    /**
     * With nothing sized and nothing writable, the group renders no element.
     *
     * THE GREEN HALF OF THE ELISION, and a real occurring case rather than a
     * hypothetical: `sample-story` is parked at `rendered` for ever, with acts
     * imported from a Phase 0 fixture and no scripts in them. "Nothing was
     * sized, and you cannot size it" is noise dressed as information.
     *
     * The pairing is what makes it safe. A panel that elided whenever the rate
     * was null would satisfy this on its own — and that is precisely the
     * first version's defect, which hid the unknown case above.
     */
    public function test_the_group_elides_when_there_is_nothing_to_say(): void
    {
        $story = Story::factory()->status(StoryStatus::Rendered)->create(['slug' => 'sizing-fixture']);

        app(Generator::class)->unique(reset: true);

        // Acts with no scripts: the Phase 0 importer's shape.
        for ($i = 1; $i <= 3; $i++) {
            Act::factory()->for($story)->atSequence($i)->create(['script' => null]);
        }

        $component = Livewire::test(OutlineGate::class, ['story' => $story->refresh()]);

        $this->assertFalse($component->instance()->hasSizingToShow());

        $html = $component->html();

        $this->assertStringNotContainsString('Script length', $html);
        $this->assertStringNotContainsString('not on record', $html);

        // And no wrapper around the void. `x-gate-group` renders no element at
        // all when its slot is empty; a page carrying an empty div is the
        // defect that component exists to make unreachable.
        $this->assertSame([], PageProbe::emptyRowGroups($html));
    }

    /**
     * The sentence about fixing the rate offers a run only where one exists.
     *
     * `sizingFixed()` is an ACTION clause: "the next run fixes this" points at a
     * button, and on a story past `outlined` there is no such button on this
     * page. GateLayoutContractTest already greps every gate at every status for
     * fragments a voice is not entitled to; this asserts the other direction on
     * the one page that emits this clause — that where the run DOES exist, the
     * sentence is actually there to be found.
     */
    public function test_the_fixing_clause_appears_only_where_a_run_exists(): void
    {
        $claim = GateVoice::claimsOf('sizingFixed')[GateVoice::EDIT];

        $writable = $this->sizedStory(null, 'sizing-writable', StoryStatus::Outlined);
        $settled = $this->sizedStory(197, 'sizing-settled', StoryStatus::Scripted);

        $this->assertStringContainsString(
            $claim,
            Livewire::test(OutlineGate::class, ['story' => $writable])->html(),
            'A story that can still be written must say the next run fixes the rate.',
        );

        $this->assertStringNotContainsString(
            $claim,
            Livewire::test(OutlineGate::class, ['story' => $settled])->html(),
            'A story past Gate 1 has no next run on this page, and must not offer one.',
        );
    }

    // -- Fixtures ------------------------------------------------------------

    private function sizedStory(
        ?int $wpm,
        string $slug,
        StoryStatus $status = StoryStatus::Outlined,
    ): Story {
        $story = Story::factory()->status($status)->create([
            'slug' => $slug,
            'voice_id' => 'nPczCjzI2devNBz1zQrb',
            'locale_profile' => 'en-US',
            'target_duration_min' => 30,
            'target_duration_max' => 40,
        ]);

        app(Generator::class)->unique(reset: true);

        for ($i = 1; $i <= 7; $i++) {
            Act::factory()->for($story)->atSequence($i)->create([
                'script' => 'A written act. '.str_repeat('word ', 20),
            ]);
        }

        if ($wpm !== null) {
            $story->forceFill(['sized_against_wpm' => $wpm])->save();
        }

        return $story->refresh();
    }
}
