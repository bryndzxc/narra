<?php

namespace Tests\Feature\Gates;

use App\Enums\Gate;
use App\Enums\StoryStatus;
use App\Livewire\Gates\OutlineGate;
use App\Models\Act;
use App\Models\Story;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Gate 1 — the operator approves the act outline.
 *
 * The cheapest gate to get right. Everything downstream is generated against
 * this outline, so the tests here are mostly about it being impossible to move
 * past it by accident.
 */
class OutlineGateTest extends TestCase
{
    use RefreshDatabase;

    public function test_saving_the_outline_moves_a_draft_to_outlined_but_does_not_cross_the_gate(): void
    {
        $story = $this->draftStory();

        Livewire::test(OutlineGate::class, ['story' => $story])
            ->set('premise', 'A diner closes without telling the woman who has worked there for nineteen years.')
            ->call('save')
            ->assertHasNoErrors();

        // Moved, but only to the near side of the gate. Approving is a separate
        // press, by a person.
        $this->assertSame(StoryStatus::Outlined, $story->fresh()->status);
        $this->assertFalse($story->fresh()->hasPassedGate(Gate::Outline));
    }

    /**
     * The cast age range has a producer, and it is this page.
     *
     * `stories.target_publish_at` sat in the schema for two phases with two
     * display helpers and no input anywhere, so the column was null on every
     * story and the block never rendered — while the Gate 4 checklist asked the
     * operator to confirm a scheduled publish time the app had no way to hold.
     * A column read by a prompt and written by nothing is that defect exactly,
     * so the write path is pinned here rather than assumed.
     */
    public function test_the_cast_age_range_is_editable_at_gate_one(): void
    {
        $story = $this->draftStory();

        Livewire::test(OutlineGate::class, ['story' => $story])
            ->set('premise', 'A diner closes without telling the woman who has worked there for nineteen years.')
            ->set('castAgeProfile', 'Spouses in their late twenties and thirties. Nobody over forty.')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(
            'Spouses in their late twenties and thirties. Nobody over forty.',
            $story->fresh()->cast_age_profile
        );
    }

    public function test_a_blank_cast_age_range_is_stored_as_null_rather_than_an_empty_string(): void
    {
        // The extraction prompt tests this field for emptiness to decide
        // whether to state a range at all, so '' and null must not be two
        // different kinds of nothing.
        $story = $this->draftStory();

        Livewire::test(OutlineGate::class, ['story' => $story])
            ->set('premise', 'A diner closes without telling the woman who has worked there for nineteen years.')
            ->set('castAgeProfile', '   ')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertNull($story->fresh()->cast_age_profile);
    }

    public function test_approving_crosses_gate_one(): void
    {
        $story = $this->draftStory(StoryStatus::Outlined);
        // Written, because approving an outline whose acts have no script is
        // refused — see the next case.
        $story->acts()->update(['script' => 'The first invoice came by text at eleven at night.']);

        Livewire::test(OutlineGate::class, ['story' => $story])
            ->set('premise', 'A long enough premise to satisfy the validator.')
            ->call('approve');

        $this->assertSame(StoryStatus::Scripted, $story->fresh()->status);
        $this->assertTrue($story->fresh()->hasPassedGate(Gate::Outline));
    }

    public function test_act_titles_are_capped_at_the_youtube_chapter_limit(): void
    {
        $story = $this->draftStory();

        Livewire::test(OutlineGate::class, ['story' => $story])
            ->set('premise', 'A premise long enough to pass validation on its own.')
            ->set('acts.0.title', str_repeat('a', 101))
            ->call('save')
            ->assertHasErrors('acts.0.title');
    }

    public function test_the_outline_is_locked_once_the_gate_is_approved(): void
    {
        $story = $this->draftStory(StoryStatus::Scripted);

        $component = Livewire::test(OutlineGate::class, ['story' => $story]);

        $this->assertFalse($component->instance()->editable());

        // Not merely hidden in the markup — the write itself is refused, because
        // a Livewire action is reachable by anything that can post to it.
        $component->set('premise', 'Rewritten behind the gate')->call('save')->assertForbidden();

        $this->assertNotSame('Rewritten behind the gate', $story->fresh()->premise);
    }

    public function test_the_gate_can_be_reopened_deliberately(): void
    {
        $story = $this->draftStory(StoryStatus::Scripted);

        Livewire::test(OutlineGate::class, ['story' => $story])->call('reopen');

        $this->assertSame(StoryStatus::Outlined, $story->fresh()->status);
    }

    public function test_acts_without_a_rehook_are_surfaced_rather_than_left_to_be_noticed(): void
    {
        $story = $this->draftStory();

        // Act 1 opens the video and needs no re-hook; act 3 does and has none.
        $story->acts()->where('sequence', 2)->update(['is_rehook_written' => true]);

        $component = Livewire::test(OutlineGate::class, ['story' => $story]);
        $missing = $component->instance()->actsMissingRehooks();

        $this->assertCount(1, $missing);
        $this->assertSame(3, $missing[0]['sequence']);
    }

    // -- A refused save is loud, and every rule's refusal is on the page -----

    /**
     * RED / GREEN on the summary bound, one character apart.
     *
     * The bound is Act::SUMMARY_MAX_CHARS and nothing else: this case reads it
     * rather than retyping a number, so moving the constant moves the test.
     */
    public function test_a_summary_one_over_the_bound_is_refused_and_one_at_the_bound_is_not(): void
    {
        $story = $this->draftStory(StoryStatus::Outlined);

        Livewire::test(OutlineGate::class, ['story' => $story])
            ->set('premise', 'A premise long enough to pass validation on its own.')
            ->set('acts.0.summary', str_repeat('x', Act::SUMMARY_MAX_CHARS + 1))
            ->call('save')
            ->assertHasErrors('acts.0.summary');

        Livewire::test(OutlineGate::class, ['story' => $story])
            ->set('premise', 'A premise long enough to pass validation on its own.')
            ->set('acts.0.summary', str_repeat('x', Act::SUMMARY_MAX_CHARS))
            ->call('save')
            ->assertHasNoErrors();
    }

    /**
     * The form rule and the model constant are ONE number.
     *
     * A literal in the rule is how the summary came to be capped at 2,000 for
     * text a different stage writes. Asserted as an identity so the two cannot
     * drift the way the estimate and the recorder once priced one narration
     * three ways.
     */
    public function test_the_act_bounds_in_the_form_are_the_model_constants(): void
    {
        $rules = OutlineGate::saveRules();

        $this->assertContains('max:'.Act::TITLE_MAX_CHARS, $rules['acts.*.title']);
        $this->assertContains('max:'.Act::SUMMARY_MAX_CHARS, $rules['acts.*.summary']);
        $this->assertContains('max:'.Act::ESCALATION_BEAT_MAX_CHARS, $rules['acts.*.escalation_beat']);
    }

    /**
     * Approve is save first, so a refused save is a refused gate crossing —
     * and it has to SAY so where the press was, not three screens up.
     *
     * The shipped defect: Livewire caught the ValidationException, answered
     * 200, and the only renderer a validation error has is an `@error` beside
     * its field. The summary had none. The status stayed `outlined`, `$saved`
     * and `$problem` stayed null, and the page was byte-identical before and
     * after the press.
     */
    public function test_a_refused_approve_is_said_at_the_gate_bar_and_crosses_nothing(): void
    {
        $story = $this->draftStory(StoryStatus::Outlined);

        $component = Livewire::test(OutlineGate::class, ['story' => $story])
            ->set('premise', 'A premise long enough to pass validation on its own.')
            ->set('acts.1.summary', str_repeat('x', Act::SUMMARY_MAX_CHARS + 1))
            ->call('approve');

        $this->assertSame(StoryStatus::Outlined, $story->fresh()->status, 'A refused save must not cross the gate.');

        $bar = $this->gateBarRefusal($component->html());

        $this->assertNotNull($bar, 'The refusal must render INSIDE the sticky gate bar, where the press happened.');
        $this->assertStringContainsString('Not saved, and Gate 1 not crossed', $bar);
        $this->assertStringContainsString('Act 2 — summary', $bar, 'The line names the act by its sequence, not by an array index.');
        $this->assertStringContainsString('href="#act-summary-1"', $bar, 'The line links to the field it refuses.');
        $this->assertStringContainsString('stays at <span class="mono">outlined</span>', $bar);
    }

    /**
     * EVERY rule in saveRules(), violated one at a time, is named on the bar.
     *
     * The bar renders the error bag whole rather than a list somebody typed,
     * so this is a check that the by-construction claim holds — a new rule
     * cannot be added to save() without its refusal reaching the page, and if
     * this test ever needs a special case for one, that is the defect.
     *
     * The violating value is derived from the rule itself: one over a `max`,
     * empty for `required`, one under a `min`. A rule with none of those is
     * skipped and named, rather than silently counted as covered.
     */
    public function test_every_save_rule_refuses_visibly_at_the_gate_bar(): void
    {
        $story = $this->draftStory(StoryStatus::Outlined);
        $checked = 0;

        foreach (OutlineGate::saveRules() as $pattern => $rules) {
            $value = $this->violatingValueFor($rules);

            if ($value === null) {
                $this->fail("No violating value could be derived for rule '{$pattern}': ".implode('|', $rules));
            }

            // Wildcards resolve to the first act; the fixture has three.
            $key = str_replace('*', '0', $pattern);

            $component = Livewire::test(OutlineGate::class, ['story' => $story])
                ->set('premise', 'A premise long enough to pass validation on its own.')
                ->set($key, $value)
                ->call('save')
                ->assertHasErrors($key);

            $bar = $this->gateBarRefusal($component->html());

            $this->assertNotNull($bar, "The refusal of '{$key}' did not reach the gate bar.");

            $message = $component->errors()->first($key);

            $this->assertStringContainsString(
                e($message),
                $bar,
                "The gate bar does not carry the message for '{$key}'.",
            );

            $checked++;
        }

        $this->assertSame(count(OutlineGate::saveRules()), $checked);
    }

    /**
     * The block inside `.gatebar` that carries a refused save, or null.
     */
    private function gateBarRefusal(string $html): ?string
    {
        if (! preg_match('/<div class="gatebar">(.*?)<button wire:click="save">/s', $html, $m)) {
            return null;
        }

        // Runs of whitespace collapse, for the reason claimsNotEntitledTo()
        // does the same: a template that wraps a sentence across two lines
        // must not hide it from an assertion about the sentence.
        $block = preg_replace('/\s+/', ' ', $m[1]);

        return str_contains($block, 'class="alert err wide refused"') ? $block : null;
    }

    /**
     * @param  array<int, string>  $rules
     */
    private function violatingValueFor(array $rules): ?string
    {
        foreach ($rules as $rule) {
            if (str_starts_with($rule, 'max:')) {
                return str_repeat('x', (int) substr($rule, 4) + 1);
            }
        }

        foreach ($rules as $rule) {
            if (str_starts_with($rule, 'min:')) {
                return str_repeat('x', max(0, (int) substr($rule, 4) - 1));
            }

            if ($rule === 'required') {
                return '';
            }

            // A value outside the list. Built from the list rather than
            // hard-coded, so a rule whose options grow cannot make this
            // accidentally legal.
            if (str_starts_with($rule, 'in:')) {
                return 'not-'.str_replace(',', '-', substr($rule, 3));
            }
        }

        return null;
    }

    private function draftStory(StoryStatus $status = StoryStatus::Draft): Story
    {
        $story = Story::factory()->status($status)->create(['slug' => 'gate-one']);

        foreach ([1, 2, 3] as $sequence) {
            Act::factory()->for($story)->atSequence($sequence)->create();
        }

        return $story;
    }
}
