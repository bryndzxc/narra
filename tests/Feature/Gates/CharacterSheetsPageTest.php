<?php

namespace Tests\Feature\Gates;

use App\Enums\StoryStatus;
use App\Livewire\Gates\CharacterSheets;
use App\Livewire\Gates\ScenesGate;
use App\Models\Act;
use App\Models\Character;
use App\Models\CharacterReference;
use App\Models\CostEntry;
use App\Models\Scene;
use App\Models\Story;
use App\Support\ReferenceRateCard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The screen where money starts moving.
 *
 * What is pinned here is the promise the page makes rather than its markup: the
 * cost is on screen before the button is, the operator supplies nothing
 * themselves, and Gate 2 says out loud that it will not open until the cast has
 * faces — rather than letting the operator find that out by pressing approve.
 */
class CharacterSheetsPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('characters');
    }

    public function test_the_page_quotes_the_cost_before_anything_is_generated(): void
    {
        $story = $this->storyWithCast(2);

        Livewire::test(CharacterSheets::class, ['story' => $story])
            // The counts are wrapped in their own elements, so this asserts on
            // the numbers and the money rather than on a contiguous sentence a
            // markup change would break.
            ->assertSee('characters still need a face')
            // Per sheet and projected total are different questions and both
            // are shown: a sheet is generated one character at a time, so the
            // unit of the decision is one character.
            // Priced from the bound generator, not from a config rate card the
            // fakes no longer have. A stand-in quotes $0.00 because it will
            // bill $0.00 — the two must agree by construction.
            ->assertSee(number_format(4 * app(ReferenceRateCard::class)->usdPerImage(), 4))
            ->assertSee(number_format(8 * app(ReferenceRateCard::class)->usdPerImage(), 4))
            ->assertSee('fake');

        $this->assertSame(0, CostEntry::count(), 'Opening the page must not bill.');
    }

    public function test_nothing_bills_until_the_operator_confirms(): void
    {
        $story = $this->storyWithCast(1);
        $character = $story->characters()->first();

        Livewire::test(CharacterSheets::class, ['story' => $story])
            ->call('askToGenerate', $character->id)
            // The em dash is an entity in the markup; asserting on the escaped
            // literal would be testing Blade rather than the confirmation.
            ->assertSee('spend it')
            ->assertSee('Every candidate is billed, including ones you discard');

        $this->assertSame(0, CostEntry::count());
    }

    public function test_generating_produces_candidates_and_reports_what_it_cost(): void
    {
        $story = $this->storyWithCast(1);
        $character = $story->characters()->first();

        Livewire::test(CharacterSheets::class, ['story' => $story])
            ->call('generate', $character->id)
            ->assertSee('4 candidate(s) generated');

        $this->assertSame(4, $character->references()->count());
        $this->assertSame(4, CostEntry::where('story_id', $story->id)->count());
    }

    public function test_picking_a_candidate_is_free(): void
    {
        $story = $this->storyWithCast(1);
        $character = $story->characters()->first();
        $reference = CharacterReference::factory()->for($character)->ready()->create();

        Livewire::test(CharacterSheets::class, ['story' => $story])
            ->call('select', $reference->id)
            ->assertSee('Every still they appear in will be generated with this face');

        $this->assertSame($reference->fresh()->image_path, $character->fresh()->reference_image_path);
        $this->assertSame(0, CostEntry::count());
    }

    public function test_gate_two_says_up_front_that_it_will_not_open(): void
    {
        // The refusal an operator meets only on pressing approve is a refusal
        // they were ambushed by. Same Action behind the warning and the block.
        $story = $this->storyWithCast(1);
        $character = $story->characters()->first();
        $this->sceneFeaturing($story, $character);

        Livewire::test(ScenesGate::class, ['story' => $story])
            ->assertSee('Gate 2 will not open until they have one')
            ->assertSee($character->name);
    }

    public function test_approving_gate_two_without_faces_shows_the_reason_not_a_stack_trace(): void
    {
        $story = $this->storyWithCast(1);
        $character = $story->characters()->first();
        $this->sceneFeaturing($story, $character);

        Livewire::test(ScenesGate::class, ['story' => $story])
            ->call('approve')
            ->assertHasErrors('approval');

        $this->assertSame(StoryStatus::ScenesDrafted, $story->fresh()->status);
    }

    public function test_the_page_offers_no_way_to_supply_an_image_by_hand(): void
    {
        // Deliberate. A face uploaded here would be pinned into 150-250 prompts
        // built from a DESCRIPTION it never matched, so the fix for a wrong
        // reference is fixing the description, not feeding this one screen
        // something the scene prompts will never see.
        $story = $this->storyWithCast(1);

        Livewire::test(CharacterSheets::class, ['story' => $story])
            ->assertDontSee('type="file"', escape: false)
            ->assertSee('Generated from the description already stored');
    }

    public function test_the_sub_step_has_its_own_page_and_does_not_become_a_fifth_gate(): void
    {
        $story = $this->storyWithCast(1);

        $this->get(route('stories.characters', $story))
            ->assertOk()
            ->assertSee('Character reference sheets')
            // The stepper is still four gates. Their count is the product's
            // central promise, not a layout detail.
            ->assertSee('Gate 2 &mdash; character sheets', escape: false);
    }

    public function test_a_candidate_is_served_from_the_non_public_disk(): void
    {
        $story = $this->storyWithCast(1);
        $reference = CharacterReference::factory()
            ->for($story->characters()->first())
            ->ready()
            ->create();

        $this->get(route('stories.characters.candidate', ['story' => $story, 'reference' => $reference]))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png');
    }

    public function test_a_candidate_from_another_story_is_not_served(): void
    {
        $mine = $this->storyWithCast(1);
        $theirs = Story::factory()->status(StoryStatus::ScenesDrafted)->create(['slug' => 'somebody-else']);
        $reference = CharacterReference::factory()
            ->for(Character::factory()->for($theirs)->create())
            ->ready()
            ->create();

        $this->get(route('stories.characters.candidate', ['story' => $mine, 'reference' => $reference]))
            ->assertNotFound();
    }

    private function storyWithCast(int $characters): Story
    {
        $story = Story::factory()->status(StoryStatus::ScenesDrafted)->create(['slug' => 'sheet-page']);

        for ($i = 1; $i <= $characters; $i++) {
            Character::factory()->for($story)->create(['name' => "Character {$i} Lastname"]);
        }

        return $story;
    }

    private function sceneFeaturing(Story $story, Character $character): Scene
    {
        $act = $story->acts()->first() ?? Act::factory()->for($story)->atSequence(1)->create();

        $scene = Scene::factory()->forAct($act)->atSequence(1)->create();
        $scene->characters()->attach($character);

        return $scene;
    }
}
