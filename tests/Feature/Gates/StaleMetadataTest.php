<?php

namespace Tests\Feature\Gates;

use App\Actions\ValidateYoutubeMetadata;
use App\Enums\Gate;
use App\Enums\MetadataStatus;
use App\Enums\RenderJobStatus;
use App\Enums\RenderStage;
use App\Enums\StoryStatus;
use App\Livewire\Gates\MetadataGate;
use App\Models\Act;
use App\Models\RenderJob;
use App\Models\Story;
use App\Models\YoutubeMetadata;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A publish sheet that outlived the render it describes.
 *
 * Chapters are derived from act timings and act timings come from the render,
 * so reopening Gate 2 makes every timestamp in a drafted sheet wrong. The sheet
 * does not vanish when that happens — it stays on screen and stays copyable,
 * and Gate 4 is the gate whose consequences land on YouTube rather than in this
 * app. A wrong chapter list pasted into an upload is not something the app can
 * take back, so the sheet is marked and the gate stays shut.
 */
class StaleMetadataTest extends TestCase
{
    use RefreshDatabase;

    public function test_reopening_gate_two_marks_the_publish_sheet_stale(): void
    {
        [$story, $metadata] = $this->renderedStoryWithSheet();

        $this->assertSame(MetadataStatus::Generated, $metadata->status);

        $story->reopenScenesGate();

        $metadata->refresh();

        $this->assertSame(MetadataStatus::Stale, $metadata->status);
        $this->assertNotNull($metadata->stale_at);
    }

    public function test_a_sheet_with_nothing_in_it_yet_is_left_alone(): void
    {
        // Pending means nothing has been generated into the row. There are no
        // wrong timestamps in it, because there are no timestamps in it.
        [$story, $metadata] = $this->renderedStoryWithSheet(MetadataStatus::Pending);

        $story->reopenScenesGate();

        $this->assertSame(MetadataStatus::Pending, $metadata->refresh()->status);
        $this->assertNull($metadata->stale_at);
    }

    public function test_an_approved_sheet_is_marked_too(): void
    {
        // Approval is a record of a decision made against timings that have
        // since been invalidated. The decision does not survive the reopen just
        // because it was already made.
        [$story, $metadata] = $this->renderedStoryWithSheet(MetadataStatus::Approved);

        $story->reopenScenesGate();

        $this->assertSame(MetadataStatus::Stale, $metadata->refresh()->status);
    }

    public function test_gate_four_refuses_approval_while_the_sheet_is_stale(): void
    {
        [$story, $metadata] = $this->renderedStoryWithSheet();
        $story->reopenScenesGate();

        $problems = app(ValidateYoutubeMetadata::class)->handle($metadata->refresh())['blocking'];

        $this->assertNotEmpty($problems);
        $this->assertStringContainsString('no longer exists', implode(' ', $problems));
    }

    public function test_saving_the_sheet_does_not_clear_the_mark(): void
    {
        // The distinction the whole mechanism rests on: typing does not make a
        // chapter timestamp correct. Only a new render does.
        [$story, $metadata] = $this->renderedStoryWithSheet();
        $story->reopenScenesGate();

        // Put the story back somewhere the sheet is editable, without a render.
        $this->walkTo($story->refresh(), StoryStatus::Rendered);

        Livewire::test(MetadataGate::class, ['story' => $story->fresh()])
            ->set('titleSelected', 'A brand new title typed by hand')
            ->call('save');

        $this->assertSame(MetadataStatus::Stale, $metadata->refresh()->status);
    }

    public function test_regenerating_is_refused_until_a_newer_render_has_finished(): void
    {
        [$story, $metadata] = $this->renderedStoryWithSheet();
        $story->reopenScenesGate();
        $this->walkTo($story->refresh(), StoryStatus::Rendered);

        // The old mux finished BEFORE the sheet went stale, so it is not the
        // render this sheet is waiting on.
        $this->assertFalse($metadata->refresh()->hasFreshRender());
        $this->assertFalse($metadata->canClearStale());

        Livewire::test(MetadataGate::class, ['story' => $story->fresh()])
            ->call('regenerate')
            ->assertForbidden();

        $this->assertSame(MetadataStatus::Stale, $metadata->refresh()->status);
    }

    public function test_a_newer_render_lets_the_sheet_be_regenerated_and_reopens_the_gate(): void
    {
        [$story, $metadata] = $this->renderedStoryWithSheet();
        $story->reopenScenesGate();
        $this->walkTo($story->refresh(), StoryStatus::Rendered);

        // The re-render lands.
        $this->recordMux($story, Carbon::now()->addMinutes(5));

        $metadata->refresh();
        $this->assertTrue($metadata->hasFreshRender());
        $this->assertTrue($metadata->canClearStale());

        Livewire::test(MetadataGate::class, ['story' => $story->fresh()])
            ->call('regenerate')
            ->assertHasNoErrors();

        $metadata->refresh();

        $this->assertSame(MetadataStatus::Generated, $metadata->status);
        $this->assertNull($metadata->stale_at);

        // And the staleness problem is gone from Gate 4's blocking list.
        $this->assertStringNotContainsString(
            'no longer exists',
            implode(' ', app(ValidateYoutubeMetadata::class)->handle($metadata)['blocking'])
        );
    }

    public function test_a_story_that_was_never_reopened_is_never_treated_as_stale(): void
    {
        [, $metadata] = $this->renderedStoryWithSheet();

        $this->assertFalse($metadata->isStale());
        $this->assertTrue($metadata->hasFreshRender(), 'A sheet with no stale_at must not be gated on a render.');
    }

    // -- Fixtures ------------------------------------------------------------

    /**
     * A rendered story with three timed acts and a drafted sheet.
     *
     * @return array{0: Story, 1: YoutubeMetadata}
     */
    private function renderedStoryWithSheet(MetadataStatus $status = MetadataStatus::Generated): array
    {
        $story = Story::factory()->status(StoryStatus::Draft)->create(['slug' => 'stale-sheet']);

        $start = 0;

        for ($i = 1; $i <= 3; $i++) {
            Act::factory()->for($story)->atSequence($i)->create([
                'start_ms' => $start,
                'duration_ms' => 600_000,
            ]);

            $start += 600_000;
        }

        $this->walkTo($story, StoryStatus::Rendered);
        $this->recordMux($story, Carbon::now()->subMinutes(10));

        $metadata = YoutubeMetadata::factory()->for($story)->create([
            'status' => $status,
            'title_selected' => 'A title that was true an hour ago',
            'description' => "An opening hook.\n\nChapters:\n00:00 One",
        ]);

        return [$story->refresh(), $metadata];
    }

    private function recordMux(Story $story, Carbon $finishedAt): void
    {
        RenderJob::factory()->for($story)->create([
            'stage' => RenderStage::Mux,
            'status' => RenderJobStatus::Succeeded,
            'started_at' => $finishedAt->copy()->subMinutes(30),
            'finished_at' => $finishedAt,
        ]);
    }

    /**
     * Walk the real gate machine rather than parking the row.
     *
     * The staleness rule reads `reopened_from` and render history, so a story
     * that was assigned its status directly would be testing a lifecycle that
     * never happened.
     */
    private function walkTo(Story $story, StoryStatus $target): void
    {
        $route = [
            StoryStatus::Outlined,
            StoryStatus::Scripted,
            StoryStatus::ScenesDrafted,
            StoryStatus::ScenesApproved,
            StoryStatus::AssetsGenerating,
            StoryStatus::AssetsReady,
            StoryStatus::Rendering,
            StoryStatus::Rendered,
        ];

        foreach ($route as $status) {
            if ($story->status->rank() >= $status->rank()) {
                continue;
            }

            $gate = $story->status->gateFor($status);

            $gate !== null ? $story->approveGate($gate) : $story->transitionTo($status);

            if ($status === $target) {
                break;
            }
        }
    }
}
