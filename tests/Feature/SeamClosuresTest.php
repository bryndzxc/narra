<?php

namespace Tests\Feature;

use App\Actions\DispatchRenderPipeline;
use App\Actions\DraftScenes;
use App\Actions\GenerateActScripts;
use App\Actions\GenerateOutline;
use App\Actions\PurgeRenderScratch;
use App\Enums\RenderJobStatus;
use App\Enums\RenderStage;
use App\Enums\StoryStatus;
use App\Jobs\ConcatRenderJob;
use App\Jobs\DeliverFinalVideoJob;
use App\Jobs\GenerateSubtitlesJob;
use App\Jobs\MuxFinalVideoJob;
use App\Jobs\PurgeRenderScratchJob;
use App\Livewire\Gates\MetadataGate;
use App\Livewire\Gates\ScenesGate;
use App\Models\Act;
use App\Models\RenderJob;
use App\Models\Scene;
use App\Models\Story;
use App\Services\Fake\FakeScriptWriter;
use App\Support\Providers\ScriptWriterException;
use Illuminate\Bus\Batch;
use Illuminate\Bus\PendingBatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/**
 * Four dead-code seams, closed.
 *
 * Every one of these is the same shape the spec names: a mechanism built in one
 * phase whose caller was due in the next and never arrived. None of them threw,
 * none of them failed a test, and all four were invisible precisely because
 * absence reads as agreement. So each test here asserts the WIRING — that the
 * thing is reached — rather than that the thing works, which was never in doubt.
 */
class SeamClosuresTest extends TestCase
{
    use RefreshDatabase;

    // -- 1. The refusal the page swallowed ---------------------------------

    public function test_the_scenes_page_says_why_asset_generation_is_unavailable(): void
    {
        // `assetGenerationRefusal()` was computed and rendered nowhere, so the
        // whole panel vanished and the page said nothing at all. Blank space
        // reads as a broken page, not as a state of the story.
        $story = Story::factory()->status(StoryStatus::ScenesDrafted)->create();
        Act::factory()->for($story)->atSequence(1)->create();

        $component = Livewire::test(ScenesGate::class, ['story' => $story]);

        $this->assertFalse($component->instance()->canGenerateAssets());

        $refusal = $component->instance()->assetGenerationRefusal();

        $this->assertNotNull($refusal);

        // The refusal is on the page, not just on the object.
        $component->assertSee('Asset generation is not available here.')
            ->assertSee('scenes_approved');
    }

    // -- 2. The purge nobody dispatched -------------------------------------

    public function test_the_purge_is_chained_behind_the_mux(): void
    {
        // CLAUDE.md: "Scratch is purged on successful render." The chain ended
        // at the mux, so ~700 MB of clips and padded PCM survived every render
        // and only a hand-typed `render:purge` ever removed it.
        Bus::fake();

        $story = $this->renderableStory();

        app(DispatchRenderPipeline::class)->handle($story, checkWorkers: false);

        Bus::assertBatched(function (PendingBatch $batch): bool {
            // Fire the completion callback the clip batch fires when every clip
            // succeeded. The callback ignores its argument, so a double is
            // enough and avoids depending on Batch's constructor signature.
            $batch->thenCallbacks()[0](Mockery::mock(Batch::class));

            return true;
        });

        Bus::assertChained([
            ConcatRenderJob::class,
            GenerateSubtitlesJob::class,
            MuxFinalVideoJob::class,
            // A chain stops at the first failure, so this is reached only when
            // the mux succeeded — which is the whole safety argument.
            PurgeRenderScratchJob::class,
            // After the purge, not before: delivery is the only stage that
            // touches a path this app does not control, and a failure ahead of
            // the purge would strand scratch on a render that succeeded.
            DeliverFinalVideoJob::class,
        ]);
    }

    public function test_the_purge_stage_is_reset_to_queued_so_an_old_row_cannot_read_as_done(): void
    {
        Bus::fake();

        $story = $this->renderableStory();

        // A purge row left succeeded by an earlier render.
        RenderJob::query()->create([
            'story_id' => $story->id,
            'stage' => RenderStage::Purge,
            'status' => RenderJobStatus::Succeeded,
            'finished_at' => now()->subDay(),
        ]);

        app(DispatchRenderPipeline::class)->handle($story, checkWorkers: false);

        $this->assertSame(
            RenderJobStatus::Queued,
            RenderJob::query()->where('story_id', $story->id)->where('stage', RenderStage::Purge)->value('status')
        );
    }

    public function test_the_purge_refuses_on_its_own_account_when_there_is_no_final_mp4(): void
    {
        // Belt and braces, and both are load-bearing: the chain decides whether
        // the purge is REACHED, this decides whether it PROCEEDS. Scratch is
        // what a re-run reuses, so neither is trusted alone.
        $story = $this->renderableStory();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Refusing to purge/');

        app(PurgeRenderScratch::class)->handle(
            storage_path('app/renders/does-not-exist')
        );
    }

    // -- 3. Text stages with no render_jobs row -----------------------------

    public function test_the_outline_stage_writes_a_row_even_though_it_runs_synchronously(): void
    {
        $story = Story::factory()->status(StoryStatus::Draft)->create();

        app(GenerateOutline::class)->handle($story, 6);

        $job = RenderJob::query()
            ->where('story_id', $story->id)
            ->where('stage', RenderStage::Outline)
            ->firstOrFail();

        $this->assertSame(RenderJobStatus::Succeeded, $job->status);
        $this->assertStringContainsString('6 acts', (string) $job->log);
    }

    public function test_act_scripts_dying_halfway_leaves_a_failed_row_naming_the_act(): void
    {
        // The failure this exists for. Six sequential Opus calls; act 4 dies
        // after acts 1-3 have been written and billed, and until now the
        // progress page looked exactly like a story nobody had started.
        $story = Story::factory()->status(StoryStatus::Outlined)->create();

        foreach (range(1, 4) as $sequence) {
            Act::factory()->for($story)->atSequence($sequence)->create();
        }

        $fake = app(FakeScriptWriter::class);
        $fake->failOnAct = 3;

        try {
            app(GenerateActScripts::class)->handle($story);
            $this->fail('Expected the stage to die on act 3.');
        } catch (ScriptWriterException) {
            // Expected.
        }

        $job = RenderJob::query()
            ->where('story_id', $story->id)
            ->where('stage', RenderStage::ActScripts)
            ->firstOrFail();

        $this->assertSame(RenderJobStatus::Failed, $job->status);

        // How far it got, not merely that it stopped. Acts 1 and 2 landed;
        // act 3 was in flight. All three are named on the row.
        $this->assertStringContainsString('Act 1 of 4', (string) $job->log);
        $this->assertStringContainsString('Act 2 of 4', (string) $job->log);
        $this->assertStringContainsString('Act 3 of 4 — writing...', (string) $job->log);
        $this->assertStringNotContainsString('Act 4 of 4', (string) $job->log);
    }

    public function test_a_no_op_scene_draft_does_not_open_a_green_row_for_work_that_did_not_happen(): void
    {
        // The inverse of the seam: a row saying `succeeded` for a stage that
        // returned early having done nothing is a false success, which is worse
        // than no row at all.
        $story = Story::factory()->status(StoryStatus::ScenesDrafted)->create();
        Act::factory()->for($story)->atSequence(1)->create();
        Scene::factory()->for($story)->create(['sequence' => 1]);

        app(DraftScenes::class)->handle($story);

        $this->assertFalse(
            RenderJob::query()
                ->where('story_id', $story->id)
                ->where('stage', RenderStage::DraftScenes)
                ->exists()
        );
    }

    // -- 4. A checklist item about a field that could not exist -------------

    public function test_the_publish_time_can_be_set_and_comes_back_in_both_zones(): void
    {
        $story = $this->storyAtGateFour();

        Livewire::test(MetadataGate::class, ['story' => $story])
            // 19:30 Eastern in July is inside the 6-10 PM peak window.
            ->set('publishAtEastern', '2026-07-15T19:30')
            ->call('save');

        $story->refresh();

        $this->assertNotNull($story->target_publish_at);

        // Stored in UTC. July is EDT, UTC-4, so 19:30 ET is 23:30 UTC.
        $this->assertSame('2026-07-15 23:30', $story->target_publish_at->format('Y-m-d H:i'));
        $this->assertSame('19:30', $story->targetPublishAtEastern()->format('H:i'));

        // And the Manila time an operator would otherwise work out by hand at
        // 1am, which is the whole reason both are shown.
        $this->assertSame('2026-07-16 07:30', $story->targetPublishAtManila()->format('Y-m-d H:i'));
    }

    public function test_confirming_a_publish_time_that_is_not_recorded_is_a_warning(): void
    {
        $story = $this->storyAtGateFour();

        $validation = Livewire::test(MetadataGate::class, ['story' => $story])
            ->set('checklist.scheduled_time_confirmed_et', true)
            ->call('save')
            ->instance()
            ->validation();

        // The message is generated from the checklist item now rather than
        // hand-written for this one field, so it names the item and then says
        // what is missing. Same guarantee, stated for every per-story item
        // instead of only this one — see PublishChecklist.
        $this->assertNotEmpty(array_filter(
            $validation['warnings'],
            fn (string $w): bool => str_contains($w, 'No publish time is recorded on this story')
                && str_contains($w, 'Scheduled publish time')
        ));
    }

    public function test_clearing_the_publish_time_puts_the_column_back_to_null(): void
    {
        $story = $this->storyAtGateFour();

        Livewire::test(MetadataGate::class, ['story' => $story])
            ->set('publishAtEastern', '2026-07-15T19:30')
            ->call('save')
            ->set('publishAtEastern', '')
            ->call('save');

        $this->assertNull($story->fresh()->target_publish_at);
    }

    private function renderableStory(): Story
    {
        $story = Story::factory()->status(StoryStatus::AssetsReady)->create(['slug' => 'seam-test']);
        Act::factory()->for($story)->atSequence(1)->create();
        Scene::factory()->for($story)->create(['sequence' => 1]);

        return $story->refresh();
    }

    private function storyAtGateFour(): Story
    {
        $story = Story::factory()->status(StoryStatus::MetadataReady)->create(['slug' => 'seam-gate-four']);

        foreach ([0, 600_000, 1_200_000] as $i => $start) {
            Act::factory()->for($story)->atSequence($i + 1)->timed($start, 600_000)->create();
        }

        return $story->refresh();
    }
}
