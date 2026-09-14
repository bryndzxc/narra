<?php

namespace Tests\Feature\Gates;

use App\Enums\MetadataStatus;
use App\Enums\StoryStatus;
use App\Livewire\Gates\MetadataGate;
use App\Models\Act;
use App\Models\Story;
use App\Models\YoutubeMetadata;
use Faker\Generator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\PageProbe;
use Tests\TestCase;

/**
 * Gate 4's body, specified BEFORE it is rebuilt.
 *
 * ---------------------------------------------------------------------------
 * THE MOCK DRAWS ONE STATE. NOT TWO — ONE.
 * ---------------------------------------------------------------------------
 *
 * Gate 2's mock declares three states, Gate 3's declares two, and Gate 4's
 * declares no state enum at all: its only prop is the theme. What it draws is a
 * sheet that is fully written and BLOCKED — "2 things block approval", a
 * disabled approve button, a tag list over budget.
 *
 * Every other state Gate 4 has is therefore unspecified, and the two that
 * matter most are the ones this project has already been bitten by:
 *
 *   NO SHEET     `rendered`, nothing generated. This is the original Gate 4
 *                defect — a form with no producer, the sixth instance in the
 *                audit — and the page STILL renders the full form over an empty
 *                row today, because `mount()` firstOrCreate()s one. Two live
 *                stories sit in exactly that state.
 *
 *   APPROVED     `published`. Terminal. The pickers and the checklist are not
 *                decisions any more and must not keep the room a decision needs.
 *
 * The three whole-page cases live in GateLayoutContractTest and travel across
 * every gate. These are Gate 4's own, and each is written from a state the mock
 * does not draw.
 */
class MetadataGateLayoutTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A sheet that was never generated says so, instead of drawing a form.
     *
     * THE ORIGINAL DEFECT, IN ITS SECOND FORM. The first version was a form with
     * no producer at all. The producer exists now — `GenerateMetadata` — and the
     * page still cannot tell "generated" from "never generated", because
     * `mount()` calls `firstOrCreate()` and then renders the full form over the
     * empty row it just made. An empty form beside a "Save sheet" button reads
     * as a sheet somebody deleted the contents of.
     *
     * The row is not the discriminator and cannot be. The CONTENT is: status
     * `pending` with no titles and no description is a sheet that has not been
     * written.
     */
    public function test_a_sheet_that_was_never_generated_says_so(): void
    {
        $story = $this->storyWithSheet(StoryStatus::Rendered, generated: false);

        $html = Livewire::test(MetadataGate::class, ['story' => $story])->html();

        $this->assertMatchesRegularExpression(
            '/no publish sheet|has not been generated|nothing to review/i',
            $html,
            'A story with no generated sheet must say the sheet has not been written. Rendering the '
            .'form over a row mount() created is the Gate 4 defect this project already found once.',
        );

        // And the empty pickers do not keep the room a real sheet needs.
        $this->assertStringNotContainsString(
            'Variants kept',
            $html,
            'An empty title-variant list is not a picker. There is nothing to pick.',
        );
    }

    /**
     * Published is terminal, and the page stops offering decisions.
     *
     * The mock has no approved state. `published` is where both of this
     * project's finished stories are, and the sheet there is a record rather
     * than a form: the operator is looking things up, not choosing them.
     */
    public function test_gate_four_collapses_once_the_sheet_is_approved(): void
    {
        $story = $this->storyWithSheet(StoryStatus::Published, generated: true);

        $html = Livewire::test(MetadataGate::class, ['story' => $story])->html();

        $this->assertStringContainsString(
            'class="strip"',
            $html,
            'A published story has no metadata decision left. The pickers and the checklist must not '
            .'keep the room they need while the decision is live.',
        );

        // The sheet itself is NOT dropped. Looking up what was published is the
        // whole reason to open this page afterwards.
        $this->assertStringContainsString(
            'Copy-paste sheet',
            $html,
            'Collapsing the decision must not take the published sheet with it.',
        );
    }

    /**
     * The drafting panel is gone once the gate is behind the story, and the
     * refusal it carried is not quieter anywhere it can still be acted on.
     *
     * A RED/GREEN PAIR, and the pairing is the whole test. `published` rendered
     * a red "Not yet - the story is published" under a strip already saying the
     * gate is behind this story and the sheet is read-only. Both sentences were
     * true; the second was chrome, on a surface whose only offer is a button it
     * cannot draw. That is the wear the alarm-band rule exists to prevent.
     *
     * The rule is that no refusal gets QUIETER, so the green half is what makes
     * this safe to assert: BEFORE the gate the same refusal is the only thing on
     * the page naming what has to happen first, and it must still be there and
     * still be an `alert fail`. A change that removed the panel everywhere would
     * satisfy the red half on its own — which is exactly how a guard that
     * reports everything passes its own drill.
     */
    public function test_the_drafting_panel_goes_once_the_gate_is_behind_the_story(): void
    {
        // RED — past the gate. The strip above has already explained this state.
        $published = Livewire::test(MetadataGate::class, [
            'story' => $this->storyWithSheet(StoryStatus::Published, generated: true),
        ])->html();

        $this->assertStringContainsString('class="strip"', $published);

        $this->assertStringNotContainsString(
            'Draft the sheet',
            $published,
            'A published story cannot write a publish sheet, so the panel offering it is an empty '
            .'group. Keeping it renders the refusal a second time under a strip that has already '
            .'said the same thing, which is how a refusal becomes chrome.',
        );

        $this->assertStringNotContainsString(
            'the story is published',
            $published,
            'The refusal must not be repeated by a surface offering the action it refuses.',
        );

        // GREEN — before the gate. Same refusal, same volume, and the only place
        // on the page that names what has to happen first.
        $waiting = Livewire::test(MetadataGate::class, [
            'story' => $this->storyWithSheet(StoryStatus::ScenesDrafted, generated: false),
        ])->html();

        $this->assertStringContainsString(
            'Draft the sheet',
            $waiting,
            'Before the gate the panel is the only surface that says the sheet cannot be written yet.',
        );

        $this->assertStringContainsString(
            'class="alert fail wide"',
            $waiting,
            'The refusal stays an alert fail. Nothing here is a downgrade in volume — the panel is '
            .'removed only in the state a line above it has already explained.',
        );
    }

    /**
     * The published banner states no date, because the app has none to state.
     *
     * THE SECOND HALF OF A TWO-DEFECT SENTENCE. The first half was position —
     * "Published on <date>" rendered on eight statuses where the story is not
     * published — and the position axis fixed that. This is the half it cannot
     * express: `updated_at` was never a publication date IN ANY STATE. It moves
     * on every write, and `CostEntry::created` increments
     * `stories.total_cost_usd`, which is a write.
     *
     * Measured on live data rather than argued: story 9's banner resolved to
     * 2026-09-02 02:16:15, which is to the second the timestamp of a `fal`
     * style_preview_establishing cost row — a day after its sheet was approved,
     * and evaluation spend is the one category deliberately kept OUT of a
     * video's cost. The next preview run against that story would have moved
     * its "publication date" forward again.
     *
     * RED is any date at all on this banner. The fixture pushes `updated_at` to
     * a date nothing else could produce, so a banner rendering it is
     * unambiguous.
     *
     * GREEN is the two facts that were never in doubt: the gate is crossed and
     * the sheet is read-only. Dropping the figure must not drop them — a check
     * satisfied by an empty banner would be satisfied by deleting the banner,
     * which is how "no refusal gets quieter" turns into a page that says less.
     */
    public function test_the_published_banner_states_no_date_it_cannot_stand_behind(): void
    {
        $story = $this->storyWithSheet(StoryStatus::Published, generated: true);

        // A write after approval, which is what actually happens: a cost entry
        // increments the story's denormalised total and touches `updated_at`.
        $story->forceFill(['updated_at' => '2031-07-04 13:37:00'])->save();

        $html = Livewire::test(MetadataGate::class, ['story' => $story->refresh()])->html();

        // RED — the figure, in the format the banner used.
        $this->assertStringNotContainsString(
            $story->updated_at->format('D d M Y H:i'),
            $html,
            'This banner rendered `updated_at` as a publication date. It is not one: it moves on '
            .'any write, and on story 9 it resolves to the moment a style preview was billed.',
        );
        $this->assertStringNotContainsString(
            'Published on',
            $html,
            'The app never uploads, so it has no publication event to report. Reporting one means '
            .'reporting a number nothing stands behind.',
        );

        // GREEN — what the app DID do, which it can stand behind entirely.
        $this->assertStringContainsString(
            'has been approved',
            $html,
            'Dropping the date must not drop the fact. Gate 4 was crossed, and this app is the '
            .'thing that crossed it.',
        );
        $this->assertStringContainsString(
            'read-only',
            $html,
            'And the sheet is a record now, not a form. A banner that says neither is a banner '
            .'that should not be there at all.',
        );
    }

    /**
     * The advisory row lays out against the groups that have findings.
     *
     * Blockers and warnings are two groups, and a sheet routinely has one and
     * not the other — a fully valid sheet has neither, a fresh one has both.
     * The same rule the dashboard, Gate 1 and Gate 2 all carry, and the reason
     * `x-gate-group` exists.
     */
    public function test_the_advisory_row_cuts_no_empty_track(): void
    {
        foreach ([true, false] as $generated) {
            foreach (StoryStatus::cases() as $status) {
                $story = $this->storyWithSheet($status, $generated);

                $html = Livewire::test(MetadataGate::class, ['story' => $story])->html();

                $this->assertSame(
                    [],
                    PageProbe::emptyRowGroups($html),
                    sprintf(
                        'Gate 4 at "%s" (%s sheet) renders an advisory group with nothing in it.',
                        $status->value,
                        $generated ? 'generated' : 'empty',
                    ),
                );
            }
        }
    }

    /**
     * The row assertion above is only worth anything on a sheet with findings
     * in ONE group, and the loop over every status never produces one.
     *
     * FOUND BY DRILLING IT, and it is the same defect as Gate 1's fixture: the
     * check was written carefully, it ran over 22 states, and every one of them
     * had both groups filled or neither. Reverting the row to a hand-written
     * `.gatecols` with hand-written wrappers left it GREEN.
     *
     * This is the shape a real published story is actually in — story 21 has
     * nought blocking and two warnings — so the fixture is built to match it
     * and the discriminating property is asserted before the layout is.
     */
    public function test_a_sheet_with_findings_in_one_group_only_cuts_no_empty_track(): void
    {
        // Every required checklist item confirmed on the ROW — the validator
        // reads the stored sheet, not the component's unsaved properties — and
        // a title past the 70-character target, so exactly one warning stands.
        $story = $this->storyWithSheet(
            StoryStatus::MetadataReady,
            generated: true,
            slug: 'g4-warnonly',
            confirmed: true,
            title: str_repeat('long enough title ', 5),
        );

        $component = Livewire::test(MetadataGate::class, ['story' => $story]);

        $validation = $component->instance()->validation();

        $this->assertSame(
            [],
            $validation['blocking'],
            'The fixture must leave the BLOCKING group empty. With findings in both, the row has no '
            .'void to find and the assertion above measures nothing — which is exactly what it did.',
        );
        $this->assertNotSame([], $validation['warnings']);

        $this->assertSame([], PageProbe::emptyRowGroups($component->html()));
    }

    /**
     * The title meter stays on its own scale and names the overage.
     *
     * Same shape as Gate 3's window bar, and the mock has the same gap: it draws
     * a target-70 / hard-100 meter for a title that is inside it. A title over
     * 100 characters is the case the limit exists FOR, and a marker computed as
     * `len / 100` walks straight off the element at 101.
     *
     * The limit is unchanged and still hard: 100 characters, enforced by the
     * validator. This is only about the picture staying honest.
     */
    /**
     * A refused save renders above the Save button, carries its own width and
     * makes no claim the page is not entitled to. The state no other case in
     * this file builds.
     */
    public function test_a_refused_save_lands_above_the_buttons_and_passes_the_page_contracts(): void
    {
        $story = $this->storyWithSheet(StoryStatus::MetadataReady, generated: true);

        $component = Livewire::test(MetadataGate::class, ['story' => $story])
            ->set('titleSelected', str_repeat('a', (int) config('youtube.limits.title_hard') + 1))
            ->call('save');

        $html = $component->html();

        $refusal = strpos($html, 'class="alert err wide refused"');
        $save = strpos($html, 'wire:click="save"');

        $this->assertNotFalse($refusal, 'The refusal must render.');
        $this->assertNotFalse($save);
        $this->assertLessThan($save, $refusal, 'The refusal must read before the Save button it is about.');

        $this->assertSame([], PageProbe::alertsWithoutTheirOwnWidth($html));
        $this->assertSame([], PageProbe::claimsNotEntitledTo($html, $component->instance()->voice()));
    }

    public function test_the_title_meter_is_clamped_and_the_overage_is_named(): void
    {
        $story = $this->storyWithSheet(StoryStatus::MetadataReady, generated: true);

        $long = str_repeat('a very long title indeed ', 8); // 200 characters

        $html = Livewire::test(MetadataGate::class, ['story' => $story])
            ->set('titleSelected', $long)
            ->html();

        foreach ($this->widthPercents($html) as $percent) {
            $this->assertGreaterThanOrEqual(0.0, $percent);
            $this->assertLessThanOrEqual(
                100.0,
                $percent,
                'A title past the hard limit pushes the meter off its own scale.',
            );
        }

        $this->assertMatchesRegularExpression(
            '/\bover\b/i',
            $html,
            'A title past the limit must SAY it is over. A clamped meter reports 101 characters and '
            .'200 characters as the same picture.',
        );
    }

    /**
     * The tag budget names the tags it would drop, one by one.
     *
     * "500-character total budget across all tags — enforce it, do not silently
     * truncate" is the spec's own wording, and a total alone cannot be acted on:
     * the operator needs to know WHICH tags are past the line, because dropping
     * them here is a decision and dropping them at upload is an accident.
     */
    public function test_the_tag_budget_marks_the_tags_that_are_over(): void
    {
        $story = $this->storyWithSheet(StoryStatus::MetadataReady, generated: true);

        $tags = [];
        for ($i = 1; $i <= 30; $i++) {
            $tags[] = 'a family drama tag number '.$i;
        }

        $html = Livewire::test(MetadataGate::class, ['story' => $story])
            ->set('tagsInput', implode(', ', $tags))
            ->html();

        $this->assertGreaterThan(
            0,
            PageProbe::matchCount($html, '.tagrow .over'),
            'The tags past the budget must be marked individually. A total that is over, with no '
            .'indication of which entries are past the line, is a number the operator cannot act on.',
        );
    }

    /**
     * Every scoped class on this page can actually be reached by its rule.
     *
     * The guard that caught `.measure` on Gate 3 in its own first build.
     * class-audit reports these as CONTEXT, which is its BENIGN verdict, and it
     * reads the stylesheet and the markup separately — so it cannot see that
     * the ancestor a rule needs is not there.
     */
    public function test_every_scoped_class_reaches_its_rule(): void
    {
        $html = $this->everyStateOfThePage();

        $required = [
            'over' => '.tagrow .over',
            'cap' => '.meter .cap',
            'used' => '.meter .used',
        ];

        foreach ($required as $class => $selector) {
            $this->assertGreaterThan(
                0,
                PageProbe::matchCount($html, $selector),
                sprintf(
                    'Nothing on Gate 4 matches "%s", so every .%s on the page is unstyled. '
                    .'class-audit still calls this CONTEXT — it cannot see that the ancestor is gone.',
                    $selector,
                    $class,
                ),
            );
        }
    }

    public function test_every_alert_carries_its_own_width(): void
    {
        foreach (StoryStatus::cases() as $status) {
            foreach ([true, false] as $generated) {
                $html = Livewire::test(MetadataGate::class, [
                    'story' => $this->storyWithSheet($status, $generated),
                ])->html();

                $this->assertSame(
                    [],
                    PageProbe::alertsWithoutTheirOwnWidth($html),
                    sprintf('An alert on Gate 4 at "%s" renders at the 96ch cap.', $status->value),
                );
            }
        }
    }

    // -- Fixtures ------------------------------------------------------------

    /** @return array<int, float> */
    private function widthPercents(string $html): array
    {
        preg_match_all('/width:\s*(-?[\d.]+)%/', $html, $matches);

        return array_map('floatval', $matches[1]);
    }

    private function everyStateOfThePage(): string
    {
        $overBudget = $this->storyWithSheet(StoryStatus::MetadataReady, generated: true, slug: 'g4-over');

        $tags = [];
        for ($i = 1; $i <= 30; $i++) {
            $tags[] = 'a family drama tag number '.$i;
        }

        return implode('', [
            Livewire::test(MetadataGate::class, ['story' => $overBudget])
                ->set('tagsInput', implode(', ', $tags))
                ->html(),
            Livewire::test(MetadataGate::class, [
                'story' => $this->storyWithSheet(StoryStatus::Rendered, generated: false),
            ])->html(),
            Livewire::test(MetadataGate::class, [
                'story' => $this->storyWithSheet(StoryStatus::Published, generated: true),
            ])->html(),
        ]);
    }

    private function storyWithSheet(
        StoryStatus $status,
        bool $generated,
        ?string $slug = null,
        bool $confirmed = false,
        ?string $title = null,
    ): Story {
        $story = Story::factory()->status($status)->create([
            'slug' => $slug ?? 'g4-'.strtolower($status->value).'-'.($generated ? 'made' : 'empty'),
        ]);

        app(Generator::class)->unique(reset: true);

        for ($i = 1; $i <= 3; $i++) {
            Act::factory()->for($story)->atSequence($i)->create([
                'start_ms' => ($i - 1) * 700_000,
                'duration_ms' => 700_000,
            ]);
        }

        YoutubeMetadata::query()->create([
            'story_id' => $story->id,
            'status' => $generated
                ? ($status === StoryStatus::Published ? MetadataStatus::Approved : MetadataStatus::Generated)
                : MetadataStatus::Pending,
            'title_options' => $generated ? [
                'The Locks Were Changed on a Tuesday',
                'She Told Them I Was the One Who Left',
                'What the Neighbours Were Told',
                'Her Family Built the Company',
                'I Stopped Coming Home',
            ] : null,
            'title_selected' => $generated ? ($title ?? 'The Locks Were Changed on a Tuesday') : null,
            'description' => $generated ? str_repeat('She changed the locks. ', 20) : null,
            'tags' => $generated ? ['family drama', 'story time', 'revenge'] : null,
            'thumbnail_text_options' => $generated ? ['She Changed The Locks'] : null,
            'pinned_comment' => $generated ? 'Thanks for watching.' : null,
            'checklist_state' => $confirmed
                ? array_map(static fn (): bool => true, (array) config('youtube.checklist'))
                : [],
        ]);

        return $story->refresh();
    }
}
