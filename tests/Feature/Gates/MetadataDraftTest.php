<?php

namespace Tests\Feature\Gates;

use App\Actions\GenerateMetadata;
use App\Enums\MetadataStatus;
use App\Enums\RenderJobStatus;
use App\Enums\RenderStage;
use App\Enums\StoryStatus;
use App\Jobs\GenerateMetadataJob;
use App\Livewire\Gates\MetadataGate;
use App\Models\Act;
use App\Models\RenderJob;
use App\Models\Story;
use App\Models\YoutubeMetadata;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The producer behind Gate 4.
 *
 * The page shipped without one: the form, the limits and the checklist were all
 * here, and the only way to fill any of it in was to type it. This is the seam
 * closing, so the tests are about the wiring — that pressing the button reaches
 * a queue somebody is actually running a worker for, that the stage is visible
 * while it runs, and that it refuses before spending rather than after.
 */
class MetadataDraftTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_button_queues_the_job_on_the_text_queue(): void
    {
        // The `text` queue has been in config, in the setup docs and in the
        // NSSM instructions since Phase 1 with nothing ever dispatched to it.
        // An operator following those instructions was running a worker that
        // could never receive anything.
        Queue::fake();

        $story = $this->renderedStory();

        Livewire::test(MetadataGate::class, ['story' => $story])
            ->call('askToDraft')
            ->call('draft');

        Queue::assertPushedOn((string) config('render.queues.text'), GenerateMetadataJob::class);
    }

    public function test_it_says_so_when_nothing_is_listening_on_the_text_queue(): void
    {
        // Absent workers are not a refusal — the job waits and nothing is lost.
        // But "queued" and "queued, and nothing will pick it up" must not read
        // the same, or the operator watches a page that never changes and calls
        // it a bug in the generator.
        config()->set('queue.default', 'redis');

        Queue::fake();

        $story = $this->renderedStory();

        $component = Livewire::test(MetadataGate::class, ['story' => $story])
            ->call('askToDraft')
            ->call('draft');

        $this->assertStringContainsString('Nothing is listening', (string) $component->instance()->problem);
    }

    public function test_the_job_writes_the_render_jobs_row_the_progress_page_reads(): void
    {
        // RenderStage::Metadata has been enumerated since Phase 1 and never
        // written, which made the stage invisible on the only page this
        // platform has instead of Horizon.
        $story = $this->renderedStory();

        (new GenerateMetadataJob($story->id))->handle(app(GenerateMetadata::class));

        $job = RenderJob::query()
            ->where('story_id', $story->id)
            ->where('stage', RenderStage::Metadata)
            ->firstOrFail();

        $this->assertSame(RenderJobStatus::Succeeded, $job->status);
        $this->assertSame(MetadataStatus::Generated, YoutubeMetadata::query()->firstOrFail()->status);
    }

    public function test_a_failed_run_leaves_a_failed_row_rather_than_a_running_one(): void
    {
        // Two acts cannot make a legal chapter list, so the Action refuses.
        $story = Story::factory()->status(StoryStatus::Rendered)->create();
        Act::factory()->for($story)->atSequence(1)->timed(0, 600_000)->create();
        Act::factory()->for($story)->atSequence(2)->timed(600_000, 600_000)->create();

        try {
            (new GenerateMetadataJob($story->id))->handle(app(GenerateMetadata::class));
        } catch (\Throwable) {
            // Rethrown so the failed_jobs table sees it too.
        }

        $job = RenderJob::query()->where('story_id', $story->id)->firstOrFail();

        $this->assertSame(RenderJobStatus::Failed, $job->status);
        $this->assertStringContainsString('chapter list', (string) $job->error);
    }

    public function test_the_button_is_not_offered_before_the_render(): void
    {
        $story = Story::factory()->status(StoryStatus::AssetsReady)->create();
        Act::factory()->for($story)->atSequence(1)->create();

        $blockers = Livewire::test(MetadataGate::class, ['story' => $story])
            ->instance()
            ->draftBlockers();

        $this->assertNotEmpty($blockers);
    }

    public function test_drafting_does_not_pick_a_title_so_the_gate_stays_closed(): void
    {
        $story = $this->renderedStory(StoryStatus::MetadataReady);

        (new GenerateMetadataJob($story->id))->handle(app(GenerateMetadata::class));

        $component = Livewire::test(MetadataGate::class, ['story' => $story->fresh()]);

        // Five titles on the page and nothing selected. Approving Gate 4 is a
        // decision, and a generator that pre-picked would have automated it
        // away while leaving the button labelled "approve".
        $this->assertCount(5, $component->instance()->titleOptions);
        $this->assertSame('', $component->instance()->titleSelected);
        $this->assertFalse($component->instance()->canApprove());
    }

    private function renderedStory(StoryStatus $status = StoryStatus::Rendered): Story
    {
        $story = Story::factory()->status($status)->create(['slug' => 'draft-test']);

        foreach ([['Act one', 0], ['Act two', 600_000], ['Act three', 1_200_000]] as $i => [$title, $start]) {
            Act::factory()->for($story)->atSequence($i + 1)->timed($start, 600_000)->create(['title' => $title]);
        }

        return $story->refresh();
    }
}
