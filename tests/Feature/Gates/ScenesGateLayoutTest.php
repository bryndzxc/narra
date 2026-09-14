<?php

namespace Tests\Feature\Gates;

use App\Enums\MotionPreset;
use App\Enums\SceneStatus;
use App\Enums\StoryStatus;
use App\Livewire\Gates\ScenesGate;
use App\Models\Act;
use App\Models\Character;
use App\Models\Scene;
use App\Models\Story;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\PageProbe;
use Tests\TestCase;

/**
 * Gate 2's layout, asserted structurally.
 *
 * Two things live here, and both exist because the failure they catch is
 * invisible: nothing throws, no page 500s, and a screenshot of the wide
 * viewport looks exactly right in either case.
 */
class ScenesGateLayoutTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The decisions come first in the DOCUMENT, not only in the grid.
     *
     * `.gatecols` is three columns at width and one column at 1180px. With the
     * advisories first in the markup, the collapsed layout re-created the exact
     * fault the row was built to remove: a page that opens on a column of amber
     * with the spend panel below the fold. On a 168-scene story emitting
     * fourteen advisories that is most of a screen before the decision.
     *
     * A test cannot measure a screenful — the same limitation DashboardTest
     * names — so it asserts the property that produces one: the money panel and
     * the character sheets both precede the advisory list in source order.
     * That is what makes them first on a narrow screen rather than first only
     * when the grid happens to be three columns wide.
     */
    public function test_the_decisions_precede_the_advisory_list(): void
    {
        $story = $this->storyNeedingADecision();

        $html = Livewire::test(ScenesGate::class, ['story' => $story])->html();

        $money = $this->positionOf($html, 'This is the last free gate.');
        $cast = $this->positionOf($html, 'Character sheets');
        $advisories = $this->positionOf($html, 'Worth a look before you approve');

        $this->assertLessThan(
            $advisories,
            $money,
            'The spend decision must come before the advisories, so it leads on a narrow screen too.',
        );

        $this->assertLessThan(
            $advisories,
            $cast,
            'The character sheets must come before the advisories for the same reason.',
        );

        // And the advisories are still ABOVE the scene list they are about, so
        // nothing has been buried in the course of un-burying the decision.
        $this->assertLessThan(
            $this->positionOf($html, 'class="scenetable"'),
            $advisories,
            'The advisories belong above the scenes they describe.',
        );
    }

    /**
     * With no decision to make, the failure takes the top of the page.
     *
     * THE TEST THAT WAS MISSING. The ordering test above passes on a story at
     * `scenes_drafted`, where all three decision panels have something in them,
     * and says nothing at all about a story past Gate 2 — where none of them do.
     * On story 21 that rendered three near-empty panels as three islands with
     * voids between them, while the one actionable thing on the page, scene 141
     * failing asset generation, sat in a narrow box two rows below.
     *
     * That is `.dash.quiet`'s defect exactly: a layout that is a constant when
     * it should be a function of state. So this asserts the same properties
     * DashboardTest does, for the state Gate 2 is in most of its life.
     */
    public function test_a_page_with_no_decision_gives_the_top_to_the_failure(): void
    {
        $story = $this->storyNeedingADecision(StoryStatus::Published, 'gate-two-quiet');
        $story->scenes()->orderBy('sequence')->first()->forceFill([
            'status' => SceneStatus::Failed,
        ])->save();

        $html = Livewire::test(ScenesGate::class, ['story' => $story])->html();

        // The decision row is gone, not rendered empty.
        $this->assertStringNotContainsString('class="gatecols"', $html);
        $this->assertStringContainsString('class="strip"', $html);

        $failure = $this->positionOf($html, 'failed asset generation');
        $advisories = $this->positionOf($html, 'class="advisories');
        $strip = $this->positionOf($html, 'class="strip"');
        $scenes = $this->positionOf($html, 'class="scenetable"');

        $this->assertLessThan($advisories, $failure, 'A failure is not a quiet state: it goes first.');
        $this->assertLessThan($strip, $advisories, 'The advisories widen; they do not go into the strip.');
        $this->assertLessThan($scenes, $strip, 'The strip sits above the scene list.');

        // Nothing is dropped. The refusal keeps its own sentence ON the strip
        // rather than behind the disclosure — a refusal that has to be opened
        // to be read has been made quieter, which this stylesheet never allows.
        $refusal = $this->positionOf($html, 'class="why"');
        $this->assertLessThan(
            $refusal,
            $this->positionOf($html, 'Gate 2 is behind this story'),
            'The strip states its case before the disclosure, not inside it.',
        );

        // And the character-sheet step is still reachable.
        $this->assertStringContainsString(route('stories.characters', $story), $html);
    }

    /**
     * The quiet layout uses the WIDTH, not just the order.
     *
     * The ordering assertion passed on a page where the locked banner, the
     * failure and every advisory rendered at about a third of a 1770px viewport
     * while the strip and the scene table beside them were full width. Order was
     * right; the page was ragged. Asserting sequence and calling the layout
     * checked is the same class of miss as asserting the busy case and calling
     * the component checked.
     *
     * The cause was a rule's SHAPE. `.alert` caps its measure at 96ch on
     * purpose, and the only thing lifting it was `.gatecols .alert` — scoped to
     * the decision row, which this layout deletes. So the cap came back exactly
     * when the container went away.
     *
     * A test cannot measure a pixel, so it asserts the two properties that
     * produce the width: every alert the quiet layout renders carries its own
     * `wide` modifier, and the modifier is defined on the element rather than
     * under a parent that a layout is allowed to remove.
     */
    public function test_the_quiet_layout_fills_the_width(): void
    {
        $story = $this->storyNeedingADecision(StoryStatus::Published, 'gate-two-width');
        $story->scenes()->orderBy('sequence')->first()->forceFill([
            'status' => SceneStatus::Failed,
        ])->save();

        $html = Livewire::test(ScenesGate::class, ['story' => $story])->html();

        // Nothing capped. Every alert outside the advisory grid says `wide` for
        // itself; an alert added here without it renders at 96ch beside a
        // full-width strip, which is the defect this catches.
        $this->assertSame(
            [],
            PageProbe::alertsWithoutTheirOwnWidth($html),
            'An alert in the quiet layout renders at the 96ch measure cap while the strip beside it is full width.',
        );

        // The advisory list fills the width as a grid rather than as one long
        // column — which is what keeps the line length the cap was protecting.
        $this->assertGreaterThan(0, PageProbe::matchCount($html, '.advisories.wide'));

        $css = file_get_contents(resource_path('views/partials/base-css.blade.php'));

        $this->assertMatchesRegularExpression(
            '/^\s*\.alert\.wide\s*\{[^}]*max-width:\s*none/m',
            $css,
            'The width modifier must be defined on the alert itself. Scoped under a container, '
            .'it disappears whenever a layout stops rendering that container — which is how this broke.',
        );

        $this->assertMatchesRegularExpression(
            '/\.advisories\.wide\s*\{[^}]*grid-template-columns/s',
            $css,
            'The advisory list must fill the width in columns rather than as one 96ch ribbon.',
        );
    }

    /**
     * Where a string is, asserting that it is there at all.
     *
     * THE REASON THIS EXISTS. `strpos` returns `false` when the needle is
     * absent, PHP coerces `false` to `0` in a numeric comparison, and
     * `assertLessThan($later, $earlier)` therefore PASSES when the earlier
     * string has been deleted from the page. Every ordering assertion in this
     * file was vacuously true for a missing element.
     *
     * Caught by drilling it: removing the failure alert from the quiet layout
     * left `test_the_failure_outranks_the_locked_banner` green. Absence read as
     * agreement, in the test written to stop exactly that.
     *
     * The probe returns null rather than false, so the vacuous comparison
     * cannot be written; GuardsGoRedTest holds the case that proves it.
     */
    private function positionOf(string $html, string $needle): int
    {
        $at = PageProbe::offsetOf($html, $needle);

        $this->assertNotNull($at, sprintf('"%s" is not on the page at all.', $needle));

        return (int) $at;
    }

    /**
     * The heading must not name a decision that does not exist here.
     *
     * "Worth a look before you approve" rendered one panel away from a strip
     * saying nothing can be approved — two sentences on the same screen
     * contradicting each other. The list is unchanged and no quieter; only the
     * heading is state-dependent, the same way the layout around it is.
     */
    public function test_the_advisory_heading_does_not_promise_a_decision_that_is_gone(): void
    {
        $quiet = $this->storyNeedingADecision(StoryStatus::Published, 'gate-two-heading');
        $busy = $this->storyNeedingADecision(StoryStatus::ScenesDrafted, 'gate-two-heading-busy');

        $quietHtml = Livewire::test(ScenesGate::class, ['story' => $quiet])->html();
        $busyHtml = Livewire::test(ScenesGate::class, ['story' => $busy])->html();

        $this->assertStringNotContainsString('before you approve', $quietHtml);
        $this->assertStringContainsString('Flagged on these scenes', $quietHtml);

        // And the decision-shaped heading survives where the decision is real.
        $this->assertStringContainsString('Worth a look before you approve', $busyHtml);
    }

    /**
     * Locked is the expected state; a failed scene is not.
     *
     * On a published story the locked banner says the gate is behind you, which
     * is what going well looks like. Scene 141 failing asset generation is the
     * only thing on the page reporting something wrong, and it sat underneath.
     */
    public function test_the_failure_outranks_the_locked_banner(): void
    {
        $story = $this->storyNeedingADecision(StoryStatus::Published, 'gate-two-rank');
        $story->scenes()->orderBy('sequence')->first()->forceFill([
            'status' => SceneStatus::Failed,
        ])->save();

        $html = Livewire::test(ScenesGate::class, ['story' => $story])->html();

        $this->assertLessThan(
            $this->positionOf($html, 'Scenes are locked'),
            $this->positionOf($html, 'failed asset generation'),
            'The expected state must not be ordered above the broken one.',
        );
    }

    /**
     * No loud marker can be scrolled out of view, by construction.
     *
     * `read-only`, `paid` and `failed` are the row's loud markers. They used to
     * be split: `paid` in the third column and `read-only` in the LAST one,
     * inside a `min-width: 1120px` scroll region. So the single fact saying a
     * row could not be edited was the first thing to disappear off the right
     * edge, and the narration and the frame — the pair being compared — sat at
     * opposite ends of the same scroll.
     *
     * Two things are asserted, and the second is the one that matters: the
     * markers all live in `.meta`, and there is no horizontal scroll region for
     * anything to hide in. The bad outcome is unreachable rather than checked
     * for, which is the stronger of the two claims this codebase prefers.
     */
    public function test_no_loud_marker_can_be_scrolled_out_of_view(): void
    {
        $html = $this->everyStateOfThePage();

        // Every marker renders, and every one of them is in `.meta` — which is
        // column three, left of the controls. Asserted by position rather than
        // by substring: the word "paid" appears in half the prose on this page.
        foreach (['readonly', 'warn', 'fail'] as $marker) {
            $this->assertGreaterThan(
                0,
                PageProbe::matchCount($html, '.scenerow .meta .'.$marker),
                "No .{$marker} marker renders inside .scenerow .meta.",
            );
        }

        // The controls column carries controls, never a marker.
        $this->assertSame(
            0,
            PageProbe::matchCount($html, '.scenerow .acts .readonly'),
            'A marker in the last column is a marker that scrolls away first.',
        );

        // And there is no scroll region. Read from the stylesheet, because that
        // is where the property lives — a DOM assertion cannot see a min-width.
        $css = file_get_contents(resource_path('views/partials/base-css.blade.php'));

        $this->assertDoesNotMatchRegularExpression(
            '/\.scenetable\s*\{[^}]*overflow-x/',
            $css,
            'A horizontal scroll region on the scene table can hide a loud marker.',
        );
        $this->assertStringNotContainsString(
            '.scenetable > .inner',
            $css,
            'The inner wrapper existed only to carry the scroll region min-width.',
        );
    }

    /**
     * Every scoped class on this page can actually be reached by its rule.
     *
     * `tools/class-audit.php` reports these as CONTEXT — a class that paints
     * nothing on its own and is only reachable under an ancestor. CONTEXT is
     * the benign verdict, and it is benign ONLY while the ancestor is really
     * there. If a wrapper is renamed or dropped, the audit keeps saying CONTEXT
     * — it reads the stylesheet and the markup separately and cannot see that
     * the pair no longer meets — and the element silently loses its styling.
     *
     * That is `.warnfill` exactly: defined only inside a progress bar, written
     * on two surfaces that have none, reported as defined by a grep, and
     * rendering nothing for a phase. So the sixteen scoped classes Gate 2 added
     * are checked here against the RENDERED page rather than against the blade
     * source, which is the only place the ancestor relationship is real.
     *
     * @return void
     */
    public function test_every_scoped_class_reaches_its_rule(): void
    {
        $html = $this->everyStateOfThePage();

        // class => the ancestor chain its only rule needs, exactly as
        // class-audit reports it.
        //
        // The Gate 2 work added sixteen CONTEXT requirements. Fourteen of them
        // are below: `.count` was reported twice, once against the rail's plain
        // badge and once against its `.loud` variant, and both resolve to the
        // same ancestor here; and `.inner` is gone entirely, because the
        // wrapper it named existed only to carry the `min-width` of a
        // horizontal scroll region that no longer exists.
        //
        // `.thead` is not one of the sixteen — the audit answers it as a scope,
        // because it also appears in an ancestor position — and is checked
        // anyway, since it is scoped in fact whatever the audit calls it.
        $required = [
            'acts' => '.scenetable .acts',
            'amt' => '.lockedgate .rates .amt',
            'chips' => '.scenetable .chips',
            'cost' => '.lockedgate > .cost',
            'count' => '.advisories > .head .count',
            'dur' => '.scenetable .meta .dur',
            'editing' => '.scenetable .editing',
            'full' => '.styleblock .full',
            'lbl' => '.seg .lbl',
            'narration' => '.scenetable .narration',
            'on' => '.seg button.on',
            'prompt' => '.scenetable .prompt',
            'readonly' => '.scenetable .readonly',
            'seq' => '.scenetable .scenerow > .seq',
            'thead' => '.scenetable .thead',
        ];

        foreach ($required as $class => $selector) {
            $this->assertGreaterThan(
                0,
                PageProbe::matchCount($html, $selector),
                sprintf(
                    'Nothing on Gate 2 matches "%s", so every .%s on the page is unstyled. '
                    .'class-audit still calls this CONTEXT — it cannot see that the ancestor is gone.',
                    $selector,
                    $class,
                ),
            );
        }
    }

    /**
     * The rendered page across the states that between them show every class.
     *
     * One state cannot: `.readonly` needs a locked story, `.editing` needs a
     * row open for editing, `.cost` needs a gate that can be reopened, and the
     * segmented control's `.on` needs both settings of the prompt toggle.
     */
    private function everyStateOfThePage(): string
    {
        $drafted = $this->storyNeedingADecision();
        $approved = $this->storyNeedingADecision(StoryStatus::ScenesApproved, 'gate-two-locked');

        // The locked story carries the markers that only a story past the gate
        // can have: one scene that failed, and one whose assets were bought.
        $scenes = $approved->scenes()->orderBy('sequence')->get();
        $scenes[0]->forceFill(['status' => SceneStatus::Failed])->save();
        $scenes[1]->forceFill(['image_path' => 'renders/gate-two-locked/scene-002.png'])->save();

        $open = Livewire::test(ScenesGate::class, ['story' => $drafted]);
        $editing = Livewire::test(ScenesGate::class, ['story' => $drafted])
            ->call('edit', $drafted->scenes()->orderBy('sequence')->first()->id);
        $full = Livewire::test(ScenesGate::class, ['story' => $drafted])->call('showFullPrompt');
        $locked = Livewire::test(ScenesGate::class, ['story' => $approved]);

        return implode('', [
            $open->html(),
            $editing->html(),
            $full->html(),
            $locked->html(),
        ]);
    }

    /**
     * A refused scene save renders inside the editing row, carries its own
     * width, and makes no claim the page is not entitled to.
     *
     * The state no other case in this file builds — every fixture here saves
     * cleanly — which is how a refusal with no renderer went unrendered by
     * any test while it was live on 1,495 of 1,693 scenes.
     */
    public function test_a_refused_scene_save_lands_in_the_editing_row_and_passes_the_page_contracts(): void
    {
        $story = $this->storyNeedingADecision();
        $scene = $story->scenes()->where('sequence', 2)->firstOrFail();

        $component = Livewire::test(ScenesGate::class, ['story' => $story])
            ->call('edit', $scene->id)
            ->set('frame', str_repeat('x', Scene::FRAME_MAX_CHARS + 1))
            ->call('saveScene');

        $html = $component->html();

        $editor = $this->positionOf($html, 'id="scene-narration"');
        $refusal = $this->positionOf($html, 'class="alert err wide refused"');
        $save = $this->positionOf($html, 'wire:click="saveScene"');

        $this->assertLessThan($refusal, $editor, 'The refusal must be inside the editing row, not at the top of the page.');
        $this->assertLessThan($save, $refusal, 'The refusal must read before the Save button it is about.');

        // The page carries older alerts the whole-page width probe already
        // reports and this change does not touch; the assertion here is that
        // the refusal is not among them.
        foreach (PageProbe::alertsWithoutTheirOwnWidth($html) as $offender) {
            $this->assertStringNotContainsString('refused', $offender, 'The refusal alert must carry `wide`.');
        }

        $this->assertSame([], PageProbe::claimsNotEntitledTo($html, $component->instance()->voice()));
    }

    private function storyNeedingADecision(
        StoryStatus $status = StoryStatus::ScenesDrafted,
        string $slug = 'gate-two-layout',
    ): Story {
        $story = Story::factory()->status($status)->create(['slug' => $slug]);
        $act = Act::factory()->for($story)->atSequence(1)->create();

        // A character with a stored description carrying something the guard
        // advises on, so the advisory list is genuinely populated rather than
        // the test asserting an order between things that are not both there.
        $character = Character::factory()->for($story)->create([
            'name' => 'Marguerite',
            'description' => 'usually wears a wide straw hat pulled low',
            'style_notes' => 'usually carries a wooden cane',
        ]);

        for ($i = 1; $i <= 3; $i++) {
            $scene = Scene::factory()->forAct($act)->atSequence($i)->create([
                'motion_preset' => MotionPreset::Static,
                'image_prompt' => "frame for scene {$i}\n\npainted realism, oil on canvas",
                'duration_ms' => 4200,
            ]);

            $scene->characters()->attach($character);
        }

        return $story->refresh();
    }
}
