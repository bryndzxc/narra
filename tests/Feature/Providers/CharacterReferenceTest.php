<?php

namespace Tests\Feature\Providers;

use App\Actions\ApproveScenesGate;
use App\Actions\EstimateCharacterSheets;
use App\Actions\GenerateCharacterSheet;
use App\Actions\RecordSceneCast;
use App\Actions\ResolveSceneReferences;
use App\Actions\SelectCharacterReference;
use App\Actions\ValidateCharacterSheets;
use App\Enums\AssetStatus;
use App\Enums\CostCategory;
use App\Enums\StoryStatus;
use App\Exceptions\GateViolationException;
use App\Exceptions\MissingCharacterReferenceException;
use App\Models\Act;
use App\Models\Character;
use App\Models\CharacterReference;
use App\Models\CostEntry;
use App\Models\Scene;
use App\Models\Story;
use App\Services\Fake\FakeReferenceImageGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Character reference sheets: the first paid assets in the pipeline.
 *
 * Three things are being pinned here, in rough order of how expensive they are
 * to get wrong:
 *
 *  1. A scene featuring a character with no reference is REFUSED. Never
 *     generated from the description, never warned about and continued. Falling
 *     back to text produces an image that looks correct on its own, so nothing
 *     downstream catches it — the defect surfaces as a face that changes
 *     between scene 40 and scene 90, after 199 stills have been paid for.
 *
 *  2. Every attempt is billed, including discards and failures. Four candidates
 *     are four charges, and a ledger that recorded only the winner would
 *     understate a video by a factor of four.
 *
 *  3. The price quoted before generating is the price charged. A projection
 *     that drifts from the rate the provider bills at is worse than no
 *     projection, because it will be believed.
 */
class CharacterReferenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('characters');
    }

    // -- The money line ------------------------------------------------------

    public function test_a_sheet_cannot_be_generated_before_the_scenes_are_drafted(): void
    {
        // The one status below Gate 2 where the temptation exists: the acts are
        // written but nobody has seen a scene, so there is nothing for a face to
        // be chosen against yet.
        $story = Story::factory()->status(StoryStatus::Scripted)->create();
        $character = Character::factory()->for($story)->create();

        $this->assertFalse($story->canGenerateReferences());

        $this->expectException(GateViolationException::class);

        app(GenerateCharacterSheet::class)->handle($character);
    }

    public function test_a_sheet_is_permitted_at_gate_two_even_though_stills_are_not(): void
    {
        // The whole point of the third cost category. A sheet bills for images
        // and runs BEFORE Gate 2 is crossed, because the operator picks a face
        // while the scenes are still free to change. Stills stay locked.
        $story = $this->story();

        $this->assertTrue($story->canGenerateReferences());
        $this->assertFalse($story->canGeneratePaidAssets());

        $character = Character::factory()->for($story)->create();

        $produced = app(GenerateCharacterSheet::class)->handle($character);

        $this->assertCount(4, $produced);
        $this->assertSame(4, CostEntry::where('category', CostCategory::Reference)->count());

        // And the harder half of the same assertion: unlocking references did
        // not quietly unlock stills.
        $this->expectException(GateViolationException::class);

        CostEntry::factory()->for($story)->image()->create();
    }

    public function test_every_candidate_is_billed_including_the_ones_nobody_picks(): void
    {
        $story = $this->story();
        $character = Character::factory()->for($story)->create();

        app(GenerateCharacterSheet::class)->handle($character, candidates: 4);

        $rows = CostEntry::where('story_id', $story->id)->get();

        $this->assertCount(4, $rows, 'Four candidates must write four cost rows, not one for the winner.');

        // The denormalised total is the number the operator reads on every
        // page, so it has to agree with the ledger rather than with the pick.
        $this->assertEqualsWithDelta(
            (float) $rows->sum('usd_cost'),
            (float) $story->fresh()->total_cost_usd,
            0.00001,
        );
    }

    public function test_a_failed_candidate_is_recorded_rather_than_losing_the_batch(): void
    {
        $story = $this->story();
        $character = Character::factory()->for($story)->create();

        /** @var FakeReferenceImageGenerator $generator */
        $generator = app(FakeReferenceImageGenerator::class);
        $generator->failWith = 'provider refused the request';

        $produced = app(GenerateCharacterSheet::class)->handle($character, candidates: 3);

        // The failure costs one candidate, not three. The two that worked are on
        // disk and choosable.
        $this->assertCount(3, $produced);
        $this->assertSame(1, $produced->where('status', AssetStatus::Failed)->count());
        $this->assertSame(2, $produced->where('status', AssetStatus::Ready)->count());

        // A failed call was never billed by the fake because it threw before
        // returning usage — so exactly the two that succeeded wrote rows.
        $this->assertSame(2, CostEntry::where('story_id', $story->id)->count());
    }

    public function test_the_quoted_price_is_the_price_charged(): void
    {
        // The pinning test for the projection. The estimate reads a rate card
        // that duplicates what the provider prices itself from; if the two ever
        // drift, the operator is shown a number before spending that is not the
        // number they are charged.
        $story = $this->story();
        $character = Character::factory()->for($story)->create();
        Scene::factory()->forAct($this->act($story))->atSequence(1)->create()
            ->characters()->attach($character);

        $estimate = app(EstimateCharacterSheets::class)->handle($story);

        $this->assertSame(1, $estimate->pendingCount());
        $this->assertSame(1, $estimate->scenesUnblocked);

        app(GenerateCharacterSheet::class)->handle($character);

        $this->assertEqualsWithDelta(
            $estimate->usdTotal(),
            (float) CostEntry::where('story_id', $story->id)->sum('usd_cost'),
            0.00001,
            'The sheet cost more or less than the operator was quoted before pressing the button.',
        );
    }

    public function test_a_character_who_already_has_a_face_is_not_quoted_for_again(): void
    {
        $story = $this->story();
        $with = Character::factory()->for($story)->create(['name' => 'Erin Kessler']);
        $without = Character::factory()->for($story)->create(['name' => 'Kyle Ostergaard']);

        CharacterReference::factory()->for($with)->selected()->create();

        $estimate = app(EstimateCharacterSheets::class)->handle($story->fresh());

        $this->assertSame(1, $estimate->pendingCount());
        $this->assertSame([$without->name], $estimate->pending->pluck('name')->all());
    }

    // -- The loud failure ----------------------------------------------------

    public function test_a_scene_whose_character_has_no_reference_is_refused_not_drawn_from_text(): void
    {
        $story = $this->story();
        $character = Character::factory()->for($story)->create(['name' => 'Erin Kessler']);
        $scene = Scene::factory()->forAct($this->act($story))->atSequence(1)->create();
        $scene->characters()->attach($character);

        $this->expectException(MissingCharacterReferenceException::class);
        $this->expectExceptionMessageMatches('/Erin Kessler/');

        app(ResolveSceneReferences::class)->handle($scene->fresh());
    }

    public function test_a_reference_row_whose_file_is_gone_counts_as_no_reference(): void
    {
        // The failure mode that reads as success: a path is set, the row says
        // ready, and the bytes are not there. Discovering that inside the image
        // batch is 199 stills too late.
        $story = $this->story();
        $character = Character::factory()->for($story)->create();
        $reference = CharacterReference::factory()->for($character)->selected()->create();

        Storage::disk('characters')->delete((string) $reference->fresh()->image_path);

        $scene = Scene::factory()->forAct($this->act($story))->atSequence(1)->create();
        $scene->characters()->attach($character);

        $this->expectException(MissingCharacterReferenceException::class);
        $this->expectExceptionMessageMatches('/missing from disk/');

        app(ResolveSceneReferences::class)->handle($scene->fresh());
    }

    public function test_a_frame_with_nobody_in_it_needs_nobody_s_face(): void
    {
        // Establishing shots, objects, empty rooms — 62 of the 199 scenes in the
        // real story. Refusing these would block the gate on nothing.
        $story = $this->story();
        $scene = Scene::factory()->forAct($this->act($story))->atSequence(1)->create();

        $this->assertSame([], app(ResolveSceneReferences::class)->handle($scene));
    }

    public function test_every_character_in_the_frame_gets_their_own_reference(): void
    {
        // The capability that decided the provider. Leonardo's Character
        // Reference has one preprocessor slot per generation, so a two-hander
        // could pin one face and would invent the other.
        $story = $this->story();
        $scene = Scene::factory()->forAct($this->act($story))->atSequence(1)->create();

        foreach (['Erin Kessler', 'Kyle Ostergaard', 'Diane Kessler'] as $name) {
            $character = Character::factory()->for($story)->create(['name' => $name]);
            CharacterReference::factory()->for($character)->selected()->create();
            $scene->characters()->attach($character);
        }

        $references = app(ResolveSceneReferences::class)->handle($scene->fresh());

        $this->assertCount(3, $references);
        $this->assertEqualsCanonicalizing(
            ['Erin Kessler', 'Kyle Ostergaard', 'Diane Kessler'],
            array_map(fn ($r): string => $r->characterName, $references),
        );

        // And each one carries bytes, not just a name — a reference the
        // provider cannot actually be handed is not a reference.
        foreach ($references as $reference) {
            $this->assertNotSame('', $reference->bytes);
        }
    }

    public function test_a_frame_with_more_people_than_the_model_can_carry_is_refused(): void
    {
        config()->set('providers.elevenlabs.max_references', 2);

        $story = $this->story();
        $scene = Scene::factory()->forAct($this->act($story))->atSequence(1)->create();

        foreach (['A Person', 'B Person', 'C Person'] as $name) {
            $character = Character::factory()->for($story)->create(['name' => $name]);
            CharacterReference::factory()->for($character)->selected()->create();
            $scene->characters()->attach($character);
        }

        // Silently dropping the third is the same defect arriving through a
        // different door: the provider accepts the call and draws that person
        // from text.
        $this->expectException(MissingCharacterReferenceException::class);
        $this->expectExceptionMessageMatches('/accepts 2 reference/');

        app(ResolveSceneReferences::class)->handle($scene->fresh());
    }

    // -- Gate 2 ---------------------------------------------------------------

    public function test_gate_two_will_not_open_while_a_character_in_a_frame_has_no_face(): void
    {
        $story = $this->story();
        $character = Character::factory()->for($story)->create(['name' => 'Erin Kessler']);
        $scene = Scene::factory()->forAct($this->act($story))->atSequence(1)->create();
        $scene->characters()->attach($character);

        $state = app(ValidateCharacterSheets::class)->handle($story);
        $this->assertFalse($state['ready']);
        $this->assertSame(1, $state['scenes_blocked']);

        $this->expectException(MissingCharacterReferenceException::class);

        app(ApproveScenesGate::class)->handle($story);

        $this->assertSame(StoryStatus::ScenesDrafted, $story->fresh()->status);
    }

    public function test_gate_two_opens_once_every_appearing_character_has_a_face(): void
    {
        $story = $this->story();
        $character = Character::factory()->for($story)->create();
        CharacterReference::factory()->for($character)->selected()->create();

        $scene = Scene::factory()->forAct($this->act($story))->atSequence(1)->create();
        $scene->characters()->attach($character);

        app(ApproveScenesGate::class)->handle($story->fresh());

        $this->assertSame(StoryStatus::ScenesApproved, $story->fresh()->status);
    }

    public function test_a_character_in_no_frame_does_not_block_the_gate(): void
    {
        // Extracted from the script, never put in a picture. Demanding a sheet
        // would refuse the gate over an image no still will ever cite.
        $story = $this->story();
        Character::factory()->for($story)->create(['name' => 'Uncle Dale']);
        Scene::factory()->forAct($this->act($story))->atSequence(1)->create();

        $state = app(ValidateCharacterSheets::class)->handle($story);

        $this->assertTrue($state['ready']);
        $this->assertSame(['Uncle Dale'], $state['unused']->pluck('name')->all());
    }

    // -- Picking --------------------------------------------------------------

    public function test_picking_a_candidate_locks_it_onto_the_character(): void
    {
        $story = $this->story();
        $character = Character::factory()->for($story)->create();

        $produced = app(GenerateCharacterSheet::class)->handle($character, candidates: 3);
        $chosen = $produced[1];

        app(SelectCharacterReference::class)->handle($character, $chosen);

        $character->refresh();

        $this->assertSame($chosen->image_path, $character->reference_image_path);
        $this->assertTrue($character->hasUsableReference());
        $this->assertSame(1, $character->references()->whereNotNull('selected_at')->count());
    }

    public function test_re_picking_is_free_and_leaves_exactly_one_selection(): void
    {
        $story = $this->story();
        $character = Character::factory()->for($story)->create();

        $produced = app(GenerateCharacterSheet::class)->handle($character, candidates: 3);
        $spent = (float) $story->fresh()->total_cost_usd;

        app(SelectCharacterReference::class)->handle($character, $produced[0]);
        app(SelectCharacterReference::class)->handle($character->fresh(), $produced[2]);

        $character->refresh();

        $this->assertSame($produced[2]->image_path, $character->reference_image_path);
        $this->assertSame(1, $character->references()->whereNotNull('selected_at')->count());

        // Changing your mind must cost nothing. That is the reason the rejects
        // are kept on disk rather than cleaned up after the pick.
        $this->assertEqualsWithDelta($spent, (float) $story->fresh()->total_cost_usd, 0.00001);
    }

    public function test_re_picking_reports_the_stills_that_now_show_the_old_face(): void
    {
        $story = $this->story();
        $character = Character::factory()->for($story)->create();
        $produced = app(GenerateCharacterSheet::class)->handle($character, candidates: 2);

        app(SelectCharacterReference::class)->handle($character, $produced[0]);

        $act = $this->act($story);

        foreach ([1, 2, 3] as $sequence) {
            $scene = Scene::factory()->forAct($act)->atSequence($sequence)
                ->create(['image_path' => "sample/scene-00{$sequence}.png"]);
            $scene->characters()->attach($character);
        }

        $stale = app(SelectCharacterReference::class)->handle($character->fresh(), $produced[1]);

        // Counted, never deleted — same posture as reopening Gate 2. What it
        // costs to fix is shown at the gate, where the operator decides.
        $this->assertSame(3, $stale);
        $this->assertSame(3, $story->scenes()->whereNotNull('image_path')->count());
    }

    public function test_a_candidate_from_another_character_cannot_be_picked(): void
    {
        $story = $this->story();
        $erin = Character::factory()->for($story)->create(['name' => 'Erin Kessler']);
        $kyle = Character::factory()->for($story)->create(['name' => 'Kyle Ostergaard']);

        $kyles = CharacterReference::factory()->for($kyle)->ready()->create();

        $this->expectExceptionMessageMatches('/belongs to another character/');

        app(SelectCharacterReference::class)->handle($erin, $kyles->fresh());
    }

    public function test_a_failed_candidate_cannot_be_picked(): void
    {
        $story = $this->story();
        $character = Character::factory()->for($story)->create();
        $failed = CharacterReference::factory()->for($character)->failed()->create();

        $this->expectExceptionMessageMatches('/cannot be the face/');

        app(SelectCharacterReference::class)->handle($character, $failed);
    }

    public function test_regenerating_starts_a_new_batch_rather_than_overwriting_the_last(): void
    {
        $story = $this->story();
        $character = Character::factory()->for($story)->create();

        app(GenerateCharacterSheet::class)->handle($character, candidates: 2);
        app(GenerateCharacterSheet::class)->handle($character->fresh(), candidates: 2);

        // Four rows, two batches. What the first round cost stays visible next
        // to what the second cost — regenerating is an explicit act with a bill,
        // not an overwrite.
        $this->assertSame(4, $character->references()->count());
        $this->assertEqualsCanonicalizing([1, 2], $character->references()->pluck('batch')->unique()->all());
        $this->assertSame(4, CostEntry::where('story_id', $story->id)->count());
    }

    // -- Presence -------------------------------------------------------------

    public function test_scene_presence_is_recovered_from_the_prompt_cast_block(): void
    {
        $story = $this->story();
        $erin = Character::factory()->for($story)->create([
            'name' => 'Erin Kessler',
            'description' => 'Thirty-four, sharp jaw, dark hair to the shoulder.',
        ]);
        Character::factory()->for($story)->create(['name' => 'Kyle Ostergaard']);

        Scene::factory()->forAct($this->act($story))->atSequence(1)->create([
            'image_prompt' => "A kitchen at dusk.\n\n"
                ."The people in this image, described exactly:\n"
                ."Erin Kessler: Thirty-four, sharp jaw, dark hair to the shoulder.\n\n"
                .'Digital painting in a warm American realist style.',
        ]);

        $result = app(RecordSceneCast::class)->backfill($story);

        $this->assertSame(1, $result['scenes']);
        $this->assertSame([$erin->id], $story->scenes()->first()->characters->pluck('id')->all());
    }

    public function test_a_character_merely_mentioned_in_the_frame_is_not_recorded_as_present(): void
    {
        // "A photograph of Kyle on the shelf" is not Kyle in the picture, and
        // recording him would demand a face for somebody who is not in it.
        $story = $this->story();
        Character::factory()->for($story)->create(['name' => 'Kyle Ostergaard']);

        Scene::factory()->forAct($this->act($story))->atSequence(1)->create([
            'image_prompt' => "A framed photograph of Kyle Ostergaard on an empty shelf.\n\n"
                .'Digital painting in a warm American realist style.',
        ]);

        app(RecordSceneCast::class)->backfill($story);

        $this->assertTrue($story->scenes()->first()->characters->isEmpty());
    }

    public function test_the_backfill_leaves_presence_that_was_already_recorded_alone(): void
    {
        $story = $this->story();
        $character = Character::factory()->for($story)->create(['name' => 'Erin Kessler']);
        $scene = Scene::factory()->forAct($this->act($story))->atSequence(1)
            ->create(['image_prompt' => 'An empty room.']);
        $scene->characters()->attach($character);

        $result = app(RecordSceneCast::class)->backfill($story);

        // The prompt is operator-editable and the pivot is not derived from it
        // after drafting; overwriting would let an edit rewrite who is in a
        // frame.
        $this->assertSame(0, $result['scenes']);
        $this->assertSame(1, $scene->fresh()->characters->count());
    }

    // -- Helpers --------------------------------------------------------------

    private function story(): Story
    {
        return Story::factory()->status(StoryStatus::ScenesDrafted)->create(['slug' => 'sheet-test']);
    }

    private function act(Story $story): Act
    {
        return $story->acts()->first() ?? Act::factory()->for($story)->atSequence(1)->create();
    }
}
