<?php

namespace Tests\Feature\Gates;

use App\Enums\StoryStatus;
use App\Livewire\Gates\CharacterSheets;
use App\Models\Character;
use App\Models\CharacterReference;
use App\Models\Scene;
use App\Models\Story;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The spend button on the cast panel, and what it does between the click and
 * the images.
 *
 * ---------------------------------------------------------------------------
 * WHY THIS SCREEN AND NOT ANOTHER
 * ---------------------------------------------------------------------------
 *
 * Every press of "Yes — spend it" buys four images. There is no queue behind
 * it — generation is synchronous on purpose, because the operator is sitting in
 * front of it waiting to choose — so the request runs for tens of seconds with
 * the button still live and the page unchanged.
 *
 * **A live button and a silent page is indistinguishable from a click that
 * never landed**, and the natural response to that is to press again. That is
 * the whole defect: not that a double submit is likely, but that the interface
 * asks for one.
 *
 * Two halves, and they are not alternatives:
 *
 *   the ACKNOWLEDGEMENT  `wire:loading` disables the control and says what is
 *                        happening. It makes a second press unlikely.
 *   the CLAIM            an atomic lock in the Action makes a second press
 *                        impossible, including from a second tab, a second
 *                        operator, or the console command running at the same
 *                        time — none of which any amount of UI state can reach.
 *
 * "Prefer arrangements where the bad outcome is unreachable over checks that it
 * did not happen" is the rule, and component state cannot provide one here:
 * Livewire sends a snapshot with every request, so two clicks fired before the
 * first response both carry `confirming` still armed. Whatever the component
 * believes, it believes twice.
 */
class CharacterSheetSpendTest extends TestCase
{
    use RefreshDatabase;

    // -- The claim -----------------------------------------------------------

    /**
     * RED: two requests for the same character, the second one refused.
     *
     * The lock is held for the life of the first call, so this simulates the
     * real shape — a second click arriving while the first is still generating —
     * by taking the claim and then calling.
     */
    public function test_a_second_generation_of_the_same_character_is_refused(): void
    {
        [$story, $character] = $this->castedStory();

        $lock = app(\App\Support\SheetClaim::class)->lockFor($character);
        $this->assertTrue($lock->get(), 'The first claim must succeed.');

        try {
            $component = Livewire::test(CharacterSheets::class, ['story' => $story])
                ->call('askToGenerate', $character->id)
                ->call('generate', $character->id);

            $this->assertSame(
                0,
                $character->references()->count(),
                'A second press bought images while the first was still running. Every press is '
                .'four billed images and there is no queue to de-duplicate them.',
            );

            // NOTICE, not problem, and deliberately: the refusal means the
            // first press landed and nothing extra was bought. Reporting it in
            // the red box would tell an operator something went wrong when the
            // opposite is true — and pressing twice is what the old screen
            // invited rather than something they did wrong.
            $this->assertStringContainsString(
                'already being generated',
                (string) $component->get('notice'),
                'The refusal must reach the operator. A silently ignored second press is the same '
                .'unacknowledged click that caused the double press to begin with.',
            );
            $this->assertNull(
                $component->get('problem'),
                'A refused duplicate is not a fault and must not render as one.',
            );
        } finally {
            $lock->release();
        }
    }

    /**
     * GREEN, and as close to RED as it can be: the same call with no claim held
     * must generate normally.
     *
     * A lock that refused everything would satisfy the case above.
     */
    public function test_generation_proceeds_when_nothing_holds_the_claim(): void
    {
        [$story, $character] = $this->castedStory();

        Livewire::test(CharacterSheets::class, ['story' => $story])
            ->call('askToGenerate', $character->id)
            ->call('generate', $character->id)
            ->assertSet('problem', null);

        $this->assertSame(4, $character->references()->count());
    }

    /** And the claim is released, so a deliberate regenerate still works. */
    public function test_the_claim_is_released_so_a_regenerate_is_possible(): void
    {
        [$story, $character] = $this->castedStory();

        $component = Livewire::test(CharacterSheets::class, ['story' => $story]);

        $component->call('askToGenerate', $character->id)->call('generate', $character->id);
        $component->call('askToGenerate', $character->id)->call('generate', $character->id);

        $this->assertSame(8, $character->references()->count());
        $this->assertSame(
            [1, 2],
            $character->references()->pluck('batch')->unique()->sort()->values()->all(),
            'A regenerate is a new batch, never an overwrite.',
        );
    }

    /**
     * A claim left behind by a crashed request expires on its own.
     *
     * The failure this avoids is worse than the one it fixes: a lock with no
     * expiry turns a killed worker or a closed tab into a character that can
     * never be generated again, with nothing on screen explaining why.
     */
    public function test_the_claim_expires_rather_than_stranding_a_character(): void
    {
        [, $character] = $this->castedStory();

        $this->assertGreaterThan(
            0,
            app(\App\Support\SheetClaim::class)->secondsHeld(),
            'The claim must have a finite lifetime.',
        );
    }

    /**
     * The console command takes the same claim.
     *
     * A lock the UI holds and the command ignores would leave the one
     * arrangement it cannot see — an operator on the page while a command runs
     * — exactly as exposed as before. It lives in the Action for that reason.
     */
    public function test_the_claim_lives_in_the_action_not_in_the_component(): void
    {
        [, $character] = $this->castedStory();

        $lock = app(\App\Support\SheetClaim::class)->lockFor($character);
        $this->assertTrue($lock->get());

        try {
            $this->expectException(\App\Exceptions\SheetInFlightException::class);

            app(\App\Actions\GenerateCharacterSheet::class)->handle($character);
        } finally {
            $lock->release();
        }
    }

    // -- The acknowledgement -------------------------------------------------

    /**
     * Both spend controls disable themselves and say what is happening.
     *
     * Asserted on the rendered markup because that is where it lives — there is
     * no PHP state behind `wire:loading`.
     *
     * **SCOPED TO THE ELEMENT, and the first version was not.** It grepped the
     * whole page for `wire:loading.attr="disabled"`, which appears three times,
     * and for `wire:target="generate(N)"`, which appears five. Deleting the
     * attribute from the spend button itself left both assertions green,
     * satisfied by the Cancel button beside it — a drill caught that, and it is
     * the same page-wide-grep defect that made the first probe for this file
     * report a stale badge that was not stale.
     *
     * So the button's own tag is extracted and the attributes are asserted on
     * it. The target being NAMED is part of the contract rather than decoration:
     * a bare `wire:loading` fires on any request the component makes, so picking
     * a candidate elsewhere would grey out a spend button that is not running.
     */
    public function test_both_spend_controls_disable_and_announce(): void
    {
        [$story, $character] = $this->castedStory();

        $component = Livewire::test(CharacterSheets::class, ['story' => $story]);

        $armed = $component->call('askToGenerate', $character->id)->html();

        $spend = $this->tagWith($armed, 'wire:click="generate('.$character->id.')"');

        foreach ([
            'wire:target="generate('.$character->id.')"' => 'names its own target',
            'wire:loading.attr="disabled"' => 'disables itself while it runs',
        ] as $needle => $what) {
            $this->assertStringContainsString(
                $needle,
                $spend,
                "The spend button itself does not {$what}. A live button and an unchanged page is "
                .'indistinguishable from a click that never landed, and every press is four billed '
                .'images.',
            );
        }

        // And the page says what is happening, louder than a greyed-out button.
        $this->assertStringContainsString('Generating', $armed);
        $this->assertMatchesRegularExpression(
            '/class="alert run[^"]*"[^>]*wire:loading/',
            $armed,
            'The in-flight notice must be an alert. Nothing on this screen gets quieter, and a '
            .'greyed button on its own says "not now" rather than "this is billing".',
        );

        $idle = $component->call('cancel')->html();
        $arm = $this->tagWith($idle, 'wire:click="askToGenerate('.$character->id.')"');

        $this->assertStringContainsString(
            'wire:target="askToGenerate('.$character->id.')"',
            $arm,
            'The first button arms a spend confirmation and must acknowledge its own click too.',
        );
        $this->assertStringContainsString('wire:loading.attr="disabled"', $arm);
    }

    // -- The row updates -----------------------------------------------------

    /**
     * The badge follows the sheet, in the same request that generated it.
     *
     * Measured rather than assumed: the first probe written for this reported
     * the defect as live, because it grepped the page for "no sheet" and the
     * unused-cast advisory contains the words "so no sheet is required". **A
     * detector that matches prose elsewhere on the page is a detector that
     * reports everything** — so this asserts on the badge element, and the
     * fixture puts the character in a scene so the advisory that produced the
     * false positive is not on the page at all.
     *
     * **WHAT THIS DOES NOT COVER, because a drill showed it cannot.** Deleting
     * `forget()`'s body leaves this green. Livewire computed properties are
     * cached per REQUEST, and each `call()` is its own request: `cast()` is
     * first evaluated during the render that follows `generate()`, so it
     * re-queries by construction and there is no stale cache for `forget()` to
     * clear. That makes `forget()` defensive rather than load-bearing here, and
     * it makes this a regression test on user-visible behaviour rather than a
     * test of the mechanism — which is worth saying, because a passing test
     * that cannot fail reads as coverage of something it never touches.
     */
    public function test_the_badge_stops_saying_no_sheet_once_a_sheet_exists(): void
    {
        [$story, $character] = $this->castedStory();

        $component = Livewire::test(CharacterSheets::class, ['story' => $story]);

        $this->assertStringContainsString('<span class="badge">no sheet</span>', $component->html());

        $component->call('askToGenerate', $character->id)->call('generate', $character->id);

        $after = $component->html();

        $this->assertStringNotContainsString(
            '<span class="badge">no sheet</span>',
            $after,
            'The sheet exists and the row still advertises that it does not.',
        );
        $this->assertStringContainsString('<span class="badge warn">pick one</span>', $after);
    }

    /** And the money block re-reads, so the outstanding count comes down. */
    public function test_the_estimate_follows_the_sheet_in_the_same_request(): void
    {
        [$story, $character] = $this->castedStory();

        $component = Livewire::test(CharacterSheets::class, ['story' => $story]);

        $this->assertSame(1, $component->instance()->estimate()->pendingCount());

        $component->call('askToGenerate', $character->id)->call('generate', $character->id);

        // Still pending: a sheet with no PICK does not unblock a scene, and the
        // estimate counts what still needs a face rather than what has candidates.
        $this->assertSame(1, $component->instance()->estimate()->pendingCount());

        $chosen = CharacterReference::where('character_id', $character->id)
            ->where('status', \App\Enums\AssetStatus::Ready)
            ->firstOrFail();

        $component->call('select', $chosen->id);

        $this->assertSame(
            0,
            $component->instance()->estimate()->pendingCount(),
            'The estimate did not re-read after a pick, so the money block would keep quoting for '
            .'a character that already has a face.',
        );
    }

    /**
     * The single HTML tag carrying a given attribute.
     *
     * Exists because asserting an attribute is "on the page" is not the same as
     * asserting it is on the CONTROL, and this page renders three disabled-on-
     * loading controls and five generate targets. Asserts the tag was found, so
     * a renamed control fails here rather than passing vacuously.
     */
    private function tagWith(string $html, string $attribute): string
    {
        $offset = strpos($html, $attribute);

        $this->assertNotFalse($offset, "No element carries {$attribute}.");

        $open = strrpos(substr($html, 0, $offset), '<');
        $this->assertNotFalse($open);

        $close = strpos($html, '>', $offset);
        $this->assertNotFalse($close);

        return substr($html, $open, $close - $open + 1);
    }

    /** @return array{0: Story, 1: Character} */
    private function castedStory(): array
    {
        $story = Story::factory()->status(StoryStatus::ScenesDrafted)->create([
            'slug' => 'cast-spend-'.uniqid(),
        ]);

        $character = Character::factory()->for($story)->create([
            'name' => 'Dana Whitfield',
            'description' => 'Shoulder-length dark hair pinned back, square jaw, narrow shoulders.',
        ]);

        // IN A SCENE, deliberately. An unused character triggers the "no sheet
        // is required" advisory, which is the prose that made the first probe
        // for this file report a defect that was not there.
        $scene = Scene::factory()->for($story)->create(['sequence' => 1]);
        $scene->characters()->attach($character);

        return [$story, $character->refresh()];
    }
}
