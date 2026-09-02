<?php

namespace Tests\Feature\Providers;

use App\Actions\DispatchAssetGeneration;
use App\Actions\DispatchRenderPipeline;
use App\Actions\EstimateSceneAssets;
use App\Actions\GenerateSceneImage;
use App\Actions\RecordProviderCost;
use App\Actions\ResolveSceneReferences;
use App\Contracts\ImageGenerator;
use App\Enums\AssetStatus;
use App\Enums\CostCategory;
use App\Enums\CostUnit;
use App\Enums\Gate;
use App\Enums\RenderStage;
use App\Enums\SceneStatus;
use App\Enums\StoryStatus;
use App\Exceptions\GateViolationException;
use App\Jobs\GenerateSceneImageJob;
use App\Models\Act;
use App\Models\Character;
use App\Models\CharacterReference;
use App\Models\CostEntry;
use App\Models\RenderJob;
use App\Models\Scene;
use App\Models\SceneAudio;
use App\Models\Story;
use App\Services\Fake\FakeImageGenerator;
use App\Services\Ffmpeg;
use App\Support\Providers\ProviderUsage;
use Illuminate\Bus\PendingBatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\Support\PricedImageGeneratorStub;
use Tests\TestCase;

/**
 * The asset stage: the layer between Gate 2 and the render that did not exist.
 *
 * Every phase of this project tested itself and nothing tested Gate 2 through
 * to render, which is exactly how three declared stages, three provider
 * contracts and the whole character reference mechanism came to have no
 * production caller while every suite stayed green. The first test here is that
 * walk, end to end, because it is the one that could not have passed before.
 *
 * What the rest pin, in rough order of how expensive they are to get wrong:
 *
 *  1. Stills are generated against the APPROVED REFERENCES, never from the
 *     description. This is the whole reason nine faces were picked, and until
 *     now ResolveSceneReferences was called only by its own test.
 *
 *  2. Re-running does not re-bill. At ~$0.035 a still across 186 scenes, a
 *     resumed batch that repeated itself would cost a second video.
 *
 *  3. A partial failure parks and flags rather than failing the video, and the
 *     retry re-dispatches only the broken scenes — without touching gate state,
 *     which is the entire reason this is a separate button.
 *
 *  4. The price quoted before the press is the price charged.
 */
class SceneAssetGenerationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('assets');
        Storage::fake('characters');
    }

    // -- The gap that made this stage necessary ------------------------------

    public function test_a_story_walks_from_gate_two_to_a_dispatchable_render(): void
    {
        // The end-to-end walk nothing covered. Before the asset stage existed
        // this story sat at `scenes_approved` with no image_path and no
        // scene_audio rows, and render:dispatch correctly refused it forever.
        $story = $this->approvedStory(scenes: 4);

        $this->assertSame(0, $story->scenes()->whereNotNull('image_path')->count());
        $this->assertSame(0, SceneAudio::query()->count());

        app(DispatchAssetGeneration::class)->handle($story);

        $story->refresh();

        $this->assertSame(StoryStatus::AssetsReady, $story->status);
        $this->assertSame(4, $story->scenes()->whereNotNull('image_path')->count());
        $this->assertSame(4, SceneAudio::query()->whereNotNull('audio_path')->count());
        $this->assertSame(4, SceneAudio::query()->whereNotNull('timings_json')->count());
        $this->assertSame(4, $story->scenes()->where('status', SceneStatus::Ready)->count());

        // Every scene has a real audio duration, which is what the clip's frame
        // count is derived from. A null here is what stopped the render before.
        $this->assertSame(0, $story->scenes()->whereNull('duration_ms')->count());

        // And the thing that was impossible: the render now dispatches.
        Bus::fake();
        app(DispatchRenderPipeline::class)->handle($story->fresh());
        $this->assertSame(StoryStatus::Rendering, $story->fresh()->status);
    }

    // -- The reference mechanism finally has a caller ------------------------

    public function test_a_still_is_generated_against_the_approved_reference_not_the_description(): void
    {
        $story = $this->approvedStory(scenes: 1);
        $scene = $story->scenes()->first();

        $erin = $this->characterWithReference($story, 'Erin Kessler');
        $kyle = $this->characterWithReference($story, 'Kyle Ostergaard');
        $scene->characters()->attach([$erin->id, $kyle->id]);

        app(DispatchAssetGeneration::class)->handle($story);

        /** @var FakeImageGenerator $generator */
        $generator = app(FakeImageGenerator::class);

        $this->assertCount(1, $generator->calls);

        // Names, not a count of "had references at all". A two-hander that
        // pinned one face and generated the other from text is precisely the
        // defect the reference exists to prevent, and it is invisible in the
        // finished image.
        $this->assertEqualsCanonicalizing(
            ['Erin Kessler', 'Kyle Ostergaard'],
            $generator->calls[0]['references'],
        );
    }

    public function test_a_scene_whose_character_lost_their_reference_fails_rather_than_falling_back(): void
    {
        $story = $this->approvedStory(scenes: 1);
        $scene = $story->scenes()->first();

        $character = Character::factory()->for($story)->create(['name' => 'Erin Kessler']);
        $scene->characters()->attach($character);

        // Gate 2 refuses to open in this state, so reaching it means a
        // reference was deleted or a provider was swapped after approval. The
        // per-scene resolver is the backstop, and it must throw rather than
        // draw her from her description.
        $this->generateThroughAWorker($story);

        $this->assertSame(SceneStatus::Failed, $scene->fresh()->status);
        $this->assertNull($scene->fresh()->image_path);

        $error = (string) RenderJob::query()
            ->where('scene_id', $scene->id)
            ->where('stage', RenderStage::Images)
            ->value('error');

        $this->assertStringContainsString('Erin Kessler', $error);

        // Nothing was billed for the image that was refused.
        $this->assertSame(
            0,
            CostEntry::query()->where('operation', 'generate_image')->count(),
        );
    }

    public function test_a_frame_with_nobody_in_it_is_generated_without_references(): void
    {
        // Roughly a quarter of a real story: establishing shots, objects, empty
        // rooms. These legitimately resolve to zero references, and a provider
        // that refuses an empty set cannot serve them at all — which is how 44
        // of 186 scenes failed on the first real run.
        $story = $this->approvedStory(scenes: 1);

        app(DispatchAssetGeneration::class)->handle($story);

        $scene = $story->scenes()->first()->fresh();

        $this->assertNotNull($scene->image_path);
        $this->assertSame(SceneStatus::Ready, $scene->status);

        /** @var FakeImageGenerator $generator */
        $generator = app(FakeImageGenerator::class);
        $this->assertSame([], $generator->calls[0]['references']);
    }

    public function test_a_peopled_frame_is_never_generated_without_its_faces(): void
    {
        // The other half of the same decision. An empty reference set is fine
        // for an empty frame and is the defect the feature exists to prevent
        // for a peopled one — and only this layer can tell them apart, because
        // the provider sees an empty array either way.
        $story = $this->approvedStory(scenes: 1);
        $scene = $story->scenes()->first();
        $scene->characters()->attach($this->characterWithReference($story, 'Erin Kessler'));

        // A resolver that hands back nothing for a frame that has a cast.
        app()->bind(ResolveSceneReferences::class, fn (): object => new class extends ResolveSceneReferences
        {
            public function __construct() {}

            public function handle(Scene $scene): array
            {
                return [];
            }
        });

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/characters in frame but resolved no references/');

        app(GenerateSceneImage::class)->handle($scene->fresh());
    }

    public function test_the_resolver_is_reached_through_the_action_and_not_only_its_own_test(): void
    {
        // Named explicitly because the audit that produced this stage found
        // ResolveSceneReferences with a test and no production caller. If this
        // ever regresses, the reference mechanism is decorative again.
        $story = $this->approvedStory(scenes: 1);
        $scene = $story->scenes()->first();
        $scene->characters()->attach($this->characterWithReference($story, 'Diane Kessler'));

        app(DispatchAssetGeneration::class)->handle($story);

        /** @var FakeImageGenerator $generator */
        $generator = app(FakeImageGenerator::class);

        $this->assertSame(['Diane Kessler'], $generator->calls[0]['references']);
    }

    // -- Money -------------------------------------------------------------

    public function test_nothing_may_be_generated_before_gate_two_is_approved(): void
    {
        $story = $this->approvedStory(scenes: 2, status: StoryStatus::ScenesDrafted);

        $this->expectException(GateViolationException::class);

        try {
            app(DispatchAssetGeneration::class)->handle($story);
        } finally {
            $this->assertSame(0, CostEntry::query()->where('category', CostCategory::Asset)->count());
            $this->assertSame(StoryStatus::ScenesDrafted, $story->fresh()->status);
        }
    }

    public function test_re_running_a_finished_stage_bills_nothing(): void
    {
        $story = $this->approvedStory(scenes: 3);

        app(DispatchAssetGeneration::class)->handle($story);

        $firstRun = CostEntry::query()->count();
        $spent = (float) $story->fresh()->total_cost_usd;

        $this->assertGreaterThan(0, $firstRun);

        // The press an operator makes when they are not sure whether it
        // finished. At 186 scenes, a stage that repeated itself here would cost
        // a second video.
        $result = app(DispatchAssetGeneration::class)->handle($story->fresh());

        $this->assertSame(0, $result['dispatched']);
        $this->assertSame($firstRun, CostEntry::query()->count());
        $this->assertSame($spent, (float) $story->fresh()->total_cost_usd);
        $this->assertSame(StoryStatus::AssetsReady, $story->fresh()->status);
    }

    public function test_a_simulated_run_bills_exactly_nothing(): void
    {
        // The regression that cost the most trust. Fakes used to price
        // themselves from a `providers.fake.*` rate card so a fixture run
        // produced a "realistic" breakdown — and a run that never touched the
        // network wrote $8.12 against a real story, indistinguishable at a
        // glance from money actually spent.
        $story = $this->approvedStory(scenes: 3);

        $this->assertTrue(app(ImageGenerator::class)->isSimulated());

        $before = (float) $story->total_cost_usd;

        app(DispatchAssetGeneration::class)->handle($story);

        // Rows are still written — "this cost nothing" and "nobody recorded
        // what this cost" must not look the same — but every one is zero.
        $rows = CostEntry::query()->where('story_id', $story->id)->get();

        $this->assertCount(9, $rows, 'Nine asset calls should still be on record.');
        $this->assertSame(0.0, (float) $rows->sum('usd_cost'));
        $this->assertSame($before, (float) $story->fresh()->total_cost_usd);

        // And each is flagged, so the true spend survives a future stand-in
        // that is not called "fake".
        $this->assertTrue($rows->every(fn (CostEntry $r): bool => $r->simulated === true));
        $this->assertTrue($rows->every(fn (CostEntry $r): bool => $r->provider === 'fake'));

        $this->assertSame(
            0.0,
            (float) CostEntry::query()->where('story_id', $story->id)->where('simulated', false)->sum('usd_cost'),
            'The real spend on a simulated run is zero.',
        );
    }

    public function test_a_simulated_provider_cannot_write_a_non_zero_cost_row(): void
    {
        // The guard, not the convention. A fake that reports a price is a bug
        // in the fake, and it must not be able to reach the ledger — that is
        // the difference between this being fixed and it being fixed until
        // somebody adds a rate card back.
        $story = $this->approvedStory(scenes: 1);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/simulated .* call costing/');

        app(RecordProviderCost::class)->handle($story, new ProviderUsage(
            provider: 'fake',
            operation: 'generate_image',
            category: CostCategory::Asset,
            quantity: 1.0,
            unit: CostUnit::Images,
            usdCost: 0.04,
            simulated: true,
        ));
    }

    public function test_the_quoted_price_is_the_price_charged(): void
    {
        // The pinning test, now run against a provider that reports itself as
        // real and prices at fal's published rate. Deliberately not run through
        // a fake: with every fake at $0.00 this would assert 0 == 0 and would
        // have sailed through the incident it exists to prevent.
        $story = $this->approvedStory(scenes: 5);

        $stub = new PricedImageGeneratorStub(app(FakeImageGenerator::class));
        app()->instance(ImageGenerator::class, $stub);

        $estimate = app(EstimateSceneAssets::class)->handle($story);

        // Priced from the bound instance, so the quote names what will run.
        $this->assertSame('fal', $estimate->imageProvider);
        $this->assertSame(config('providers.fal.edit_model'), $estimate->imageModel);
        $this->assertSame((float) config('providers.fal.usd_per_image'), $estimate->usdPerImage);
        $this->assertEqualsWithDelta(5 * 0.035, $estimate->usdImages(), 0.0001);

        // Stills are real here; narration and timings are still stand-ins, and
        // the estimate has to say so rather than folding them into one number.
        $this->assertTrue($estimate->hasSimulatedStage());
        $this->assertFalse($estimate->isEntirelySimulated());
        $this->assertSame(['narration', 'word timings'], $estimate->simulatedStages);

        app(DispatchAssetGeneration::class)->handle($story);

        $charged = (float) CostEntry::query()
            ->where('story_id', $story->id)
            ->where('simulated', false)
            ->sum('usd_cost');

        // Money is decimal(10,4): the ledger stores each row rounded to four
        // places and sums the rounded rows, while the estimate multiplies once
        // and rounds once at the end. That is at most half a unit of the last
        // place per row, and it is the ledger that is right, because a charge
        // is a row. Tightening this does not find a bug; it re-finds this
        // comment.
        $allowance = $estimate->imagesPending * 0.00005 + 0.0001;

        $this->assertEqualsWithDelta($estimate->usdImages(), $charged, $allowance);
        $this->assertEqualsWithDelta($estimate->usdTotal(), $charged, $allowance);
    }

    public function test_every_generated_asset_writes_a_cost_row(): void
    {
        $story = $this->approvedStory(scenes: 2);

        app(DispatchAssetGeneration::class)->handle($story);

        foreach (['generate_image' => 2, 'synthesize_speech' => 2, 'transcribe' => 2] as $operation => $expected) {
            $this->assertSame(
                $expected,
                CostEntry::query()->where('story_id', $story->id)->where('operation', $operation)->count(),
                "Expected {$expected} {$operation} cost rows.",
            );
        }
    }

    public function test_a_worker_bound_to_a_different_provider_refuses_the_job(): void
    {
        // The stale-worker guard. A queue worker is a long-lived process that
        // booted its own container, so a .env edit or a deploy does not reach
        // one already running. A run quoted against `fal` was executed by a
        // worker started hours earlier and produced 185 placeholder stills and
        // $8.08 of ledger entries for calls nobody made.
        $story = $this->approvedStory(scenes: 1);
        $scene = $story->scenes()->first();

        // Queued against fal; this process resolves the fake. That is exactly
        // the shape of the incident, inverted so it is reproducible.
        $job = new GenerateSceneImageJob($story->id, $scene->id, 'fal');

        $this->assertSame('fake', app(ImageGenerator::class)->providerName());

        try {
            $job->handle(app(Ffmpeg::class));
            $this->fail('The job should have refused to run against a different provider.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('queued against provider "fal"', $e->getMessage());
            $this->assertStringContainsString('queue:restart', $e->getMessage());
        }

        // Refused, not substituted: no still, no cost row, and the scene is
        // flagged so the operator can find it.
        $this->assertNull($scene->fresh()->image_path);
        $this->assertSame(0, CostEntry::query()->where('operation', 'generate_image')->count());
        $this->assertSame(SceneStatus::Failed, $scene->fresh()->status);
    }

    public function test_a_matching_provider_runs_normally(): void
    {
        // The guard must not fire on the ordinary path, or every job fails.
        $story = $this->approvedStory(scenes: 1);
        $scene = $story->scenes()->first();

        (new GenerateSceneImageJob($story->id, $scene->id, 'fake'))->handle(app(Ffmpeg::class));

        $this->assertNotNull($scene->fresh()->image_path);
    }

    public function test_dispatch_pins_the_provider_it_quoted_into_every_job(): void
    {
        Bus::fake();

        $story = $this->approvedStory(scenes: 2);

        app(DispatchAssetGeneration::class)->handle($story);

        Bus::assertBatched(function (PendingBatch $batch): bool {
            foreach ($batch->jobs as $job) {
                // Whatever the dispatching process resolved is what the worker
                // is held to. Null would mean the guard is inert.
                $this->assertNotNull($job->expectedProvider);
                $this->assertSame('fake', $job->expectedProvider);
            }

            return true;
        });
    }

    // -- Partial failure and retry -------------------------------------------

    public function test_a_partial_failure_parks_the_story_and_flags_only_the_broken_scenes(): void
    {
        $story = $this->approvedStory(scenes: 4);

        // One scene the image stage cannot serve: a character in frame with no
        // approved face. The other three are fine.
        $broken = $story->scenes()->orderBy('sequence')->first();
        $broken->characters()->attach(Character::factory()->for($story)->create(['name' => 'Nobody Pictured']));

        $this->generateThroughAWorker($story);

        $story->refresh();

        // Parked, not failed, and specifically NOT assets_ready.
        $this->assertSame(StoryStatus::AssetsGenerating, $story->status);

        $this->assertSame(SceneStatus::Failed, $broken->fresh()->status);
        $this->assertSame(3, $story->scenes()->where('status', SceneStatus::Ready)->count());

        // Three scenes out of four are complete and paid for. The failure did
        // not cost them anything.
        $this->assertSame(3, $story->scenes()->whereNotNull('image_path')->count());
        $this->assertSame(4, SceneAudio::query()->whereNotNull('timings_json')->count());
    }

    public function test_the_retry_re_dispatches_only_the_failed_scene(): void
    {
        $story = $this->approvedStory(scenes: 4);

        $broken = $story->scenes()->orderBy('sequence')->first();
        $character = Character::factory()->for($story)->create(['name' => 'Nobody Pictured']);
        $broken->characters()->attach($character);

        $this->generateThroughAWorker($story);

        $billedBefore = CostEntry::query()->count();

        // The operator fixes what was wrong: the character gets a face.
        CharacterReference::factory()->for($character)->selected()->create();
        $character->refresh();

        $result = $this->generateThroughAWorker($story->fresh());

        // One job, not 4 and not 12. The three healthy scenes are not touched
        // and — the reason this is a button and not a gate crossing — the
        // operator did not have to reopen Gate 2 to get here.
        $this->assertSame(1, $result['dispatched']);
        $this->assertSame(1, $result['images']);
        $this->assertSame(0, $result['narrations']);

        $this->assertSame(
            1,
            CostEntry::query()->count() - $billedBefore,
            'The retry billed for more than the one scene that failed.',
        );

        $story->refresh();
        $this->assertSame(StoryStatus::AssetsReady, $story->status);
        $this->assertSame(4, $story->scenes()->where('status', SceneStatus::Ready)->count());
    }

    public function test_generating_assets_is_not_a_gate_crossing(): void
    {
        // The decisive reason this is a separate action from ApproveScenesGate.
        // If dispatch were a gate crossing, retrying five stills would mean
        // re-crossing Gate 2 — which risks regenerating the other 181.
        $story = $this->approvedStory(scenes: 2);

        $approvedAt = $story->fresh()->scenes()->first()->approved_image_hash;
        $digest = $story->fresh()->approved_scene_digest;

        app(DispatchAssetGeneration::class)->handle($story);
        app(DispatchAssetGeneration::class)->handle($story->fresh());

        $story->refresh();

        $this->assertTrue($story->hasPassedGate(Gate::Scenes));
        $this->assertSame($digest, $story->approved_scene_digest);
        $this->assertSame($approvedAt, $story->scenes()->first()->approved_image_hash);
        $this->assertNull($story->reopened_from);
    }

    // -- Shape on the queue --------------------------------------------------

    public function test_the_paid_stages_run_on_the_assets_queue_not_the_render_queue(): void
    {
        // A 40-minute mux must never sit in the same queue as 186 image calls.
        // Both queues were configured long before anything dispatched to the
        // second one.
        Bus::fake();

        $story = $this->approvedStory(scenes: 3);

        app(DispatchAssetGeneration::class)->handle($story);

        Bus::assertBatched(function (PendingBatch $batch) use ($story): bool {
            $this->assertSame(config('render.queues.assets'), $batch->queue());
            $this->assertNotSame(config('render.queues.render'), $batch->queue());
            $this->assertSame("scene-assets:{$story->slug}", $batch->name);

            // Three failures out of 186 flag for retry rather than failing the
            // video, which needs the batch to finish after one dies.
            $this->assertTrue($batch->allowsFailures());

            // Stills and narration fan out together: nothing about one informs
            // the other, and serialising them would double the wall clock.
            $this->assertCount(6, $batch->jobs);

            return true;
        });
    }

    public function test_word_timings_are_a_second_batch_because_they_read_the_audio(): void
    {
        $story = $this->approvedStory(scenes: 2);

        app(DispatchAssetGeneration::class)->handle($story);

        $stages = RenderJob::query()
            ->where('story_id', $story->id)
            ->pluck('stage')
            ->map(fn (RenderStage $stage): string => $stage->value)
            ->unique()
            ->values()
            ->all();

        $this->assertEqualsCanonicalizing(
            [RenderStage::Images->value, RenderStage::SceneNarration->value, RenderStage::SceneTimings->value],
            $stages,
            'All three declared asset stages must actually open render_jobs rows.',
        );

        // The timings batch is dispatched from the first batch's `finally`, not
        // its `then`: if three narrations fail, the other 183 still have audio
        // worth transcribing.
        $timings = RenderJob::query()
            ->where('story_id', $story->id)
            ->where('stage', RenderStage::SceneTimings)
            ->get();

        $this->assertCount(2, $timings);
        $this->assertNotSame(
            $timings->first()->batch_id,
            RenderJob::query()->where('stage', RenderStage::Images)->value('batch_id'),
        );
    }

    public function test_a_scene_audio_row_records_the_probed_duration_not_the_declared_one(): void
    {
        // scenes.duration_ms is what the clip's frame count is ceil()'d from,
        // and PadSceneAudio decodes the real file and throws if the audio does
        // not fit in those frames. Trusting a provider's declared length would
        // move that failure to concat time, one full bill later.
        $story = $this->approvedStory(scenes: 1);

        app(DispatchAssetGeneration::class)->handle($story);

        $scene = $story->scenes()->first()->fresh();
        $audio = $scene->sceneAudio()->first();

        $this->assertNotNull($scene->duration_ms);
        $this->assertSame($scene->duration_ms, $audio->duration_ms);
        $this->assertSame(AssetStatus::Ready, $audio->status);

        // Derived timeline values stay null: they accumulate across the whole
        // story and are computed wholesale at concat, never patched per scene.
        $this->assertNull($audio->offset_frames);
        $this->assertNull($audio->offset_samples);
        $this->assertNull($audio->padded_duration_ms);
    }

    public function test_assets_survive_on_a_disk_the_render_purge_cannot_reach(): void
    {
        $story = $this->approvedStory(scenes: 1);

        app(DispatchAssetGeneration::class)->handle($story);

        $scene = $story->scenes()->first()->fresh();

        // Not `renders`, which is purged once final.mp4 exists and decodes. A
        // still is ~70% of a video's cost; a re-render must never re-bill.
        Storage::disk('assets')->assertExists((string) $scene->image_path);
        $this->assertStringStartsWith("{$story->id}/stills/", (string) $scene->image_path);
    }

    public function test_a_reorder_does_not_rename_a_paid_still(): void
    {
        // Clips are filed by sequence because they are free to rebuild. Stills
        // are filed by scene id because they are not.
        $story = $this->approvedStory(scenes: 2);

        app(DispatchAssetGeneration::class)->handle($story);

        $scene = $story->scenes()->orderBy('sequence')->first()->fresh();
        $path = (string) $scene->image_path;

        $this->assertStringContainsString("scene-{$scene->id}.", $path);
        $this->assertStringNotContainsString(sprintf('scene-%03d', $scene->sequence), $path);
    }

    // -- Helpers -------------------------------------------------------------

    /**
     * Run the stage the way a real worker does, rather than the way `sync` does.
     *
     * This exists because the two disagree on exactly the behaviour these tests
     * are about. SyncQueue records a failed job and then RETHROWS, so the
     * exception escapes Bus::batch()->dispatch() and the remaining jobs are
     * never even pushed — one bad scene halts the run. That is not what happens
     * in production, where `allowFailures()` means the batch finishes and every
     * failure shows up in one pass instead of one per re-run.
     *
     * Testing the partial-failure path under `sync` would therefore pin the
     * opposite of the spec's batch policy: three failures out of 200 must flag
     * for retry, not fail the video. A real worker on the database driver
     * catches, records and continues, and also runs the batch completion
     * callbacks that dispatch the timings batch and reconcile the story.
     *
     * @return array<string, mixed>
     */
    private function generateThroughAWorker(Story $story): array
    {
        config()->set('queue.default', 'database');

        $result = app(DispatchAssetGeneration::class)->handle($story);

        Artisan::call('queue:work', [
            'connection' => 'database',
            '--queue' => config('render.queues.assets'),
            '--stop-when-empty' => true,
            // Paid work is never retried automatically: an automatic retry of a
            // call that already billed is an automatic second charge.
            '--tries' => 1,
        ]);

        return $result;
    }

    /**
     * A story sitting exactly where the real one is: Gate 2 approved, every
     * scene approved with its hashes recorded, and no assets at all.
     */
    private function approvedStory(int $scenes, StoryStatus $status = StoryStatus::ScenesApproved): Story
    {
        $story = Story::factory()->status($status)->create([
            'slug' => 'asset-stage',
            'voice_id' => 'narrator-us-01',
            'locale_profile' => 'en-US',
        ]);

        $act = Act::factory()->for($story)->atSequence(1)->create();

        for ($i = 1; $i <= $scenes; $i++) {
            $scene = Scene::factory()->forAct($act)->atSequence($i)->create([
                'status' => $status === StoryStatus::ScenesApproved ? SceneStatus::Approved : SceneStatus::Drafted,
                // Nulled deliberately: duration_ms is the audio's length and no
                // audio exists yet. The factory's default would let a test pass
                // against a number the narration stage never wrote.
                'duration_ms' => null,
            ]);

            if ($status === StoryStatus::ScenesApproved) {
                // What ApproveScenesGate records. Without these, needsImage()
                // and needsNarration() are true for a different reason than the
                // one under test.
                $scene->recordGateTwoApproval();
            }
        }

        if ($status === StoryStatus::ScenesApproved) {
            $story->forceFill(['approved_scene_digest' => $story->sceneDigest()])->save();
        }

        return $story->fresh();
    }

    private function characterWithReference(Story $story, string $name): Character
    {
        $character = Character::factory()->for($story)->create(['name' => $name]);

        CharacterReference::factory()->for($character)->selected()->create();

        return $character->fresh();
    }
}
