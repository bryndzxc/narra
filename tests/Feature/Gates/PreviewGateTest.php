<?php

namespace Tests\Feature\Gates;

use App\Enums\Gate;
use App\Enums\OperatorAction;
use App\Enums\RenderStage;
use App\Enums\StoryStatus;
use App\Livewire\Gates\PreviewGate;
use App\Models\Act;
use App\Models\RenderJob;
use App\Models\Scene;
use App\Models\Story;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Gate 3 — the operator watches the render.
 *
 * Nothing automated stands in for this, so the only things worth asserting are
 * that it cannot be approved without a render to watch, and that rejecting is
 * an explicit round trip rather than a silent re-queue.
 */
class PreviewGateTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        $this->deleteRender();

        parent::tearDown();
    }

    public function test_the_gate_cannot_be_approved_without_a_finished_render(): void
    {
        $story = $this->renderedStory(withFile: false);

        $component = Livewire::test(PreviewGate::class, ['story' => $story]);

        $this->assertFalse($component->instance()->canApprove());

        $component->call('approve')->assertForbidden();

        $this->assertSame(StoryStatus::Rendered, $story->fresh()->status);
    }

    public function test_approving_moves_the_story_to_the_publish_sheet(): void
    {
        $story = $this->renderedStory();

        Livewire::test(PreviewGate::class, ['story' => $story])->call('approve');

        $this->assertSame(StoryStatus::MetadataReady, $story->fresh()->status);
        $this->assertTrue($story->fresh()->hasPassedGate(Gate::Preview));
    }

    /**
     * Rejecting queues the re-render itself.
     *
     * It used to move the story to `rendering` and then print
     * `php artisan render:dispatch <slug>` — leaving it at a status that means
     * "a clip batch is in flight" with no batch in flight, until somebody
     * opened a terminal. A status describing work nobody started is the same
     * defect as a form with no producer.
     */
    public function test_rejecting_queues_the_re_render_rather_than_naming_a_command(): void
    {
        Bus::fake();

        $story = $this->renderedStory();
        Scene::factory()->for($story)->create(['sequence' => 1]);

        $component = Livewire::test(PreviewGate::class, ['story' => $story])->call('reject');

        $this->assertSame(StoryStatus::Rendering, $story->fresh()->status);
        Bus::assertBatchCount(1);

        $notice = (string) $component->get('notice');

        $this->assertStringContainsString('Sent back for a re-render', $notice);
        $this->assertStringNotContainsString('artisan', $notice);
    }

    /**
     * The half that matters more: when the dispatch cannot happen, the status
     * must not move.
     *
     * The old implementation transitioned first and dispatched never, so every
     * failure of this kind was invisible — the story read `rendering` and the
     * operator had been told to go and type something. Now the transition is
     * the dispatch's, so a refused dispatch leaves the story exactly where it
     * was and says why.
     */
    public function test_a_rejection_that_cannot_dispatch_leaves_the_status_alone(): void
    {
        Bus::fake();

        // No scenes: there is nothing to encode, so the pipeline refuses.
        $story = $this->renderedStory();

        $component = Livewire::test(PreviewGate::class, ['story' => $story])->call('reject');

        $this->assertSame(StoryStatus::Rendered, $story->fresh()->status);
        Bus::assertNothingBatched();

        $this->assertNotNull($component->get('problem'));
        $this->assertNull($component->get('notice'));
    }

    /**
     * The drift the console audit found.
     *
     * OperatorAction::DispatchRender->callers() named this method as one of its
     * consumers while the method checked the status by hand. Same answer that
     * day, which is exactly why it could rot silently — so the assertion is on
     * the JOIN between what the page offers and what the capability permits,
     * not on either one alone.
     */
    public function test_the_gate_and_the_capability_agree_about_dispatching(): void
    {
        $story = $this->renderedStory();

        $component = Livewire::test(PreviewGate::class, ['story' => $story]);

        $this->assertSame(
            OperatorAction::DispatchRender->permittedAt($story->status),
            $component->instance()->canDispatchRender(),
        );

        $this->assertSame(
            OperatorAction::DispatchRender->refusalReason($story->status),
            $component->instance()->dispatchRefusal(),
        );
    }

    /**
     * The command this page used to print is now a button on it.
     */
    public function test_the_render_can_be_dispatched_from_the_gate(): void
    {
        Bus::fake();

        $story = Story::factory()->status(StoryStatus::AssetsReady)->create(['slug' => 'gate-three']);
        Act::factory()->for($story)->atSequence(1)->create();
        Scene::factory()->for($story)->create(['sequence' => 1]);

        Livewire::test(PreviewGate::class, ['story' => $story->refresh()])
            ->call('dispatchRender')
            ->assertHasNoErrors();

        $this->assertSame(StoryStatus::Rendering, $story->fresh()->status);
        Bus::assertBatchCount(1);
    }

    /**
     * The cancel this app's own refusal text recommends, without a terminal.
     */
    public function test_an_in_flight_batch_can_be_cancelled_from_the_gate(): void
    {
        $story = Story::factory()->status(StoryStatus::Rendering)->create(['slug' => 'gate-three']);
        Act::factory()->for($story)->atSequence(1)->create();

        Livewire::test(PreviewGate::class, ['story' => $story])->call('cancelRender');

        // The promise StoryStatus::reopenRefusalReason() makes to the operator:
        // cancelling "lands on assets_ready, which can reopen".
        $this->assertSame(StoryStatus::AssetsReady, $story->fresh()->status);
    }

    public function test_the_gate_reports_the_render_facts_the_operator_is_checking_against(): void
    {
        $story = $this->renderedStory();

        $facts = Livewire::test(PreviewGate::class, ['story' => $story])->instance()->renderFacts();

        $this->assertTrue($facts['exists']);
        $this->assertSame(3, $facts['acts']);
        $this->assertSame('0:30:00', $facts['duration_human']);
        $this->assertStringContainsString('4863 frames', (string) $facts['mux_log']);

        // 30 minutes is inside the 30-40 window this format is built around.
        $this->assertTrue($facts['in_target_window']);
    }

    public function test_a_fixture_length_render_is_reported_as_outside_the_target_window(): void
    {
        // Honest rather than green: 12 fixture scenes are nowhere near 30
        // minutes, and a tick that ignored that would be worth nothing.
        $story = $this->renderedStory();
        $story->acts()->update(['duration_ms' => 54_000]);

        $facts = Livewire::test(PreviewGate::class, ['story' => $story])->instance()->renderFacts();

        $this->assertFalse($facts['in_target_window']);
    }

    public function test_chapters_come_from_the_act_timings_the_render_produced(): void
    {
        $story = $this->renderedStory();

        $chapters = Livewire::test(PreviewGate::class, ['story' => $story])->instance()->chapters();

        $this->assertCount(3, $chapters);
        $this->assertSame('0:00', $chapters[0]['timestamp']);
        $this->assertSame('10:00', $chapters[1]['timestamp']);
    }

    public function test_the_video_route_serves_the_render_and_404s_without_one(): void
    {
        $story = $this->renderedStory();

        $this->get(route('stories.video', $story))
            ->assertOk()
            ->assertHeader('Content-Type', 'video/mp4')
            // Gate 3 is 30-40 minutes and the operator scrubs. Without range
            // support the browser re-downloads from zero on every seek.
            ->assertHeader('Accept-Ranges', 'bytes');

        $this->deleteRender();

        $this->get(route('stories.video', $story))->assertNotFound();
    }

    private function renderedStory(bool $withFile = true): Story
    {
        $story = Story::factory()->status(StoryStatus::Rendered)->create(['slug' => 'gate-three']);

        foreach ([[1, 0], [2, 600_000], [3, 1_200_000]] as [$sequence, $start]) {
            Act::factory()->for($story)->atSequence($sequence)->timed($start, 600_000)->create();
        }

        RenderJob::factory()->for($story)->succeeded()->create([
            'stage' => RenderStage::Mux,
            'log' => '4863 frames, aac master, audio +0 samples (+0.0000 ms), encoded, 22.5s',
        ]);

        if ($withFile) {
            $directory = storage_path('app/renders/gate-three');

            if (! is_dir($directory)) {
                mkdir($directory, 0775, true);
            }

            file_put_contents($directory.'/final.mp4', 'stand-in for a 275 MB render');
        }

        return $story;
    }

    private function deleteRender(): void
    {
        $directory = storage_path('app/renders/gate-three');

        foreach (glob($directory.'/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($directory);
    }
}
