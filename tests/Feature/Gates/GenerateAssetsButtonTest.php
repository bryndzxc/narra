<?php

namespace Tests\Feature\Gates;

use App\Contracts\ImageGenerator;
use App\Contracts\SpeechSynthesizer;
use App\Contracts\Transcriber;
use App\Enums\Gate;
use App\Enums\SceneStatus;
use App\Enums\StoryStatus;
use App\Livewire\Gates\ScenesGate;
use App\Models\Act;
use App\Models\Character;
use App\Models\Scene;
use App\Models\Story;
use App\Services\Fake\FakeImageGenerator;
use Illuminate\Bus\PendingBatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\PricedImageGeneratorStub;
use Tests\TestCase;

/**
 * Gate 2's second button: the spend.
 *
 * Two presses on one page that must never collapse into one. Approving scenes
 * is a quality decision and generating assets is a money decision, and the page
 * used to have only the first — while claiming, in the future tense and with no
 * subject, that "186 image(s) and 186 narration(s) will be generated". Nothing
 * dispatched them and no view mentioned `assets_generating` at all.
 *
 * The decisive reason they stay separate is retry, which is what most of this
 * file is about: a spend that is not a gate crossing can be pressed again.
 */
class GenerateAssetsButtonTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('assets');
        Storage::fake('characters');
    }

    public function test_the_button_is_absent_until_gate_two_is_approved(): void
    {
        $story = $this->story(StoryStatus::ScenesDrafted);

        Livewire::test(ScenesGate::class, ['story' => $story])
            ->assertDontSee('Generate assets')
            ->assertSee('Approve Gate 2');
    }

    public function test_approving_does_not_generate_anything_and_says_so(): void
    {
        Bus::fake();

        $story = $this->story(StoryStatus::ScenesDrafted);

        Livewire::test(ScenesGate::class, ['story' => $story])
            ->call('askToApprove')
            ->call('approve')
            // The defect this replaced: future tense, no subject, no dispatch.
            ->assertSee('none of them have been generated')
            ->assertSee('Generate assets');

        Bus::assertNothingBatched();

        $this->assertSame(StoryStatus::ScenesApproved, $story->fresh()->status);
        $this->assertSame(0, $story->scenes()->whereNotNull('image_path')->count());
    }

    public function test_the_cost_is_itemised_before_the_press(): void
    {
        $story = $this->story(StoryStatus::ScenesApproved);

        $component = Livewire::test(ScenesGate::class, ['story' => $story]);
        $estimate = $component->instance()->assetEstimate();

        $this->assertSame(4, $estimate->imagesPending);
        $this->assertSame(4, $estimate->narrationsPending);
        $this->assertSame(4, $estimate->transcriptionsPending);

        // Every stage is a stand-in here, so the honest quote is $0.00 — and
        // the page must say WHY, not just show a small number. A run served
        // entirely by fakes once presented $8.12 of plausible-looking bill.
        $this->assertTrue($estimate->isEntirelySimulated());
        $this->assertSame(0.0, $estimate->usdTotal());

        $component
            ->assertSee('Generate assets')
            ->assertSee('These are not real assets')
            ->assertSee('No vendor is contacted and nothing is billed')
            ->assertSee('Stills')
            ->assertSee('Word timings');
    }

    public function test_the_press_takes_two_clicks_and_the_second_names_the_amount(): void
    {
        Bus::fake();

        $story = $this->story(StoryStatus::ScenesApproved);

        $component = Livewire::test(ScenesGate::class, ['story' => $story]);

        // With stand-ins bound the button must not say "spend": there is
        // nothing to spend, and a button that offers to spend $0.00 reads as a
        // bargain rather than as a warning.
        $component->call('askToGenerate')
            ->assertSet('confirmingGeneration', true)
            ->assertSee('generate placeholders (no vendor, $0.00)')
            ->assertDontSee('Yes — spend');

        Bus::assertNothingBatched();

        $component->call('generateAssets');

        Bus::assertBatched(fn (PendingBatch $batch): bool => $batch->queue() === config('render.queues.assets'));
    }

    public function test_the_press_moves_the_story_into_assets_generating(): void
    {
        Bus::fake();

        $story = $this->story(StoryStatus::ScenesApproved);

        Livewire::test(ScenesGate::class, ['story' => $story])
            ->call('askToGenerate')
            ->call('generateAssets')
            ->assertSee('queued on the assets queue');

        // The status nothing in the app had ever written or displayed.
        $this->assertSame(StoryStatus::AssetsGenerating, $story->fresh()->status);
    }

    public function test_the_button_survives_a_partial_failure_and_offers_a_retry(): void
    {
        $story = $this->story(StoryStatus::AssetsGenerating);

        // What a run that lost three scenes leaves behind.
        $story->scenes()->limit(1)->update(['status' => SceneStatus::Failed]);

        $component = Livewire::test(ScenesGate::class, ['story' => $story]);

        $this->assertTrue($component->instance()->canGenerateAssets());
        $this->assertCount(1, $component->instance()->failedScenes());

        $component
            ->assertSee('scene(s) failed asset generation')
            // The same button, relabelled. It re-runs only the broken scenes,
            // and reaching it never required reopening Gate 2.
            ->assertSee('Retry failed scenes');
    }

    public function test_pressing_it_never_touches_gate_state(): void
    {
        Bus::fake();

        $story = $this->story(StoryStatus::ScenesApproved);
        $digest = $story->approved_scene_digest;

        Livewire::test(ScenesGate::class, ['story' => $story])
            ->call('askToGenerate')
            ->call('generateAssets');

        $story->refresh();

        // If this were a gate crossing, retrying five stills would mean
        // re-crossing Gate 2 — which risks regenerating the other 181.
        $this->assertTrue($story->hasPassedGate(Gate::Scenes));
        $this->assertSame($digest, $story->approved_scene_digest);
        $this->assertNull($story->reopened_from);
    }

    public function test_a_missing_reference_is_shown_on_the_page_not_thrown_at_the_operator(): void
    {
        $story = $this->story(StoryStatus::ScenesApproved);
        $scene = $story->scenes()->first();
        $scene->characters()->attach(Character::factory()->for($story)->create(['name' => 'Erin Kessler']));

        // ResolveSceneReferences refuses rather than drawing her from text. The
        // operator meets that as a sentence on the page that fixes it — which
        // only works if the view renders the error bag, and for a long time it
        // did not.
        Livewire::test(ScenesGate::class, ['story' => $story])
            ->call('askToGenerate')
            ->call('generateAssets')
            ->assertHasErrors('generation')
            ->assertSee('Erin Kessler');
    }

    public function test_the_provider_shown_is_the_one_that_would_actually_run(): void
    {
        // The defect this replaced: the screen read `config(...)` while the
        // container held something else, so it named a vendor that was never
        // called. Asked of the instance now, so the two cannot disagree.
        $story = $this->story(StoryStatus::ScenesApproved);

        $component = Livewire::test(ScenesGate::class, ['story' => $story]);
        $estimate = $component->instance()->assetEstimate();

        $this->assertSame(app(ImageGenerator::class)->providerName(), $estimate->imageProvider);
        $this->assertSame(app(SpeechSynthesizer::class)->providerName(), $estimate->speechProvider);
        $this->assertSame(app(Transcriber::class)->providerName(), $estimate->transcriberProvider);
    }

    public function test_a_real_provider_is_priced_and_labelled_as_declared(): void
    {
        // Swap one stage to something that reports itself real: the quote must
        // follow the instance, not config, and the declared-rate warning must
        // appear only now.
        $story = $this->story(StoryStatus::ScenesApproved);

        app()->instance(ImageGenerator::class, new PricedImageGeneratorStub(app(FakeImageGenerator::class)));

        $component = Livewire::test(ScenesGate::class, ['story' => $story]);
        $estimate = $component->instance()->assetEstimate();

        $this->assertSame('fal', $estimate->imageProvider);
        $this->assertTrue($estimate->rateIsDeclared);
        $this->assertEqualsWithDelta(4 * 0.035, $estimate->usdImages(), 0.0001);

        // Still partly simulated, and the page still has to say which part.
        $component
            ->assertSee('not billed back by the provider')
            ->assertSee('These are not real assets');
    }

    private function story(StoryStatus $status): Story
    {
        $story = Story::factory()->status($status)->create([
            'slug' => 'spend-button',
            'voice_id' => 'narrator-us-01',
        ]);

        $act = Act::factory()->for($story)->atSequence(1)->create();

        for ($i = 1; $i <= 4; $i++) {
            $scene = Scene::factory()->forAct($act)->atSequence($i)->create([
                'status' => $status === StoryStatus::ScenesDrafted ? SceneStatus::Drafted : SceneStatus::Approved,
                'duration_ms' => null,
            ]);

            if ($status !== StoryStatus::ScenesDrafted) {
                $scene->recordGateTwoApproval();
            }
        }

        if ($status !== StoryStatus::ScenesDrafted) {
            $story->forceFill(['approved_scene_digest' => $story->sceneDigest()])->save();
        }

        return $story->fresh();
    }
}
