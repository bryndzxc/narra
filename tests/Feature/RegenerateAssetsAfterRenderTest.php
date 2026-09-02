<?php

namespace Tests\Feature;

use App\Actions\DiscardRenderArtifacts;
use App\Actions\DispatchAssetGeneration;
use App\Enums\AssetStatus;
use App\Enums\MetadataStatus;
use App\Enums\StoryStatus;
use App\Exceptions\GateViolationException;
use App\Jobs\GenerateSceneImageJob;
use App\Models\Act;
use App\Models\AudioTrack;
use App\Models\Scene;
use App\Models\SceneAudio;
use App\Models\Story;
use App\Support\RenderWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/**
 * Replacing assets on a story that has already rendered.
 *
 * The move the state machine did not define, found the way the previous three
 * were: an operator pressed the thing and got "Cannot move a story from
 * 'rendered' to 'assets_generating'".
 *
 * It is a SPEND, not a gate crossing, which is why it is a direct edge rather
 * than a walk back through Gate 2. Reopening Gate 2 to replace narration would
 * put all 186 paid stills back in play to fix the audio — the exact outcome the
 * separate spend button exists to prevent, and a non-negotiable in the spec.
 */
class RegenerateAssetsAfterRenderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('assets');
        Bus::fake();
    }

    public function test_assets_can_be_regenerated_on_a_rendered_story(): void
    {
        $story = $this->renderedStory();

        $result = app(DispatchAssetGeneration::class)->handle($story);

        $this->assertSame(StoryStatus::AssetsGenerating->value, $result['status']);
        $this->assertSame(StoryStatus::AssetsGenerating, $story->fresh()->status);
    }

    public function test_it_is_still_refused_while_a_clip_batch_is_in_flight(): void
    {
        // The one post-approval status that must refuse: regenerating a still
        // out from under a running render would have the encoder read one file
        // while the row names another.
        $story = $this->renderedStory();
        $story->forceFill(['status' => StoryStatus::Rendering])->save();

        $this->expectException(GateViolationException::class);
        $this->expectExceptionMessage('render:cancel');

        app(DispatchAssetGeneration::class)->handle($story->fresh());
    }

    public function test_the_paid_stills_are_not_touched(): void
    {
        // Structural, not careful: stills live on the `assets` disk and every
        // discarded artifact lives on `renders`. At ~$0.035 x 186 this is the
        // single most expensive thing in the story to get wrong.
        $story = $this->renderedStory();

        foreach ($story->scenes as $scene) {
            Storage::disk('assets')->put((string) $scene->image_path, 'still bytes');
        }

        app(DispatchAssetGeneration::class)->handle($story);

        foreach ($story->scenes as $scene) {
            $this->assertTrue(
                Storage::disk('assets')->exists((string) $scene->image_path),
                "still for scene {$scene->sequence} was destroyed"
            );
        }

        // And no image job was queued, so nothing re-bills either. The stills
        // are current: only the narration provenance is stale.
        $this->assertSame(0, $this->queuedImageJobs());
    }

    public function test_the_finished_video_is_discarded(): void
    {
        // The failure this guard is for: a final.mp4 left in place is a
        // finished video an operator can sit down and approve at Gate 3, for a
        // story whose audio is about to be replaced underneath it.
        $story = $this->renderedStory();
        $workspace = RenderWorkspace::for($story);

        foreach (DiscardRenderArtifacts::WHOLE_VIDEO_ARTIFACTS as $artifact) {
            $this->writeWorkspaceFile($workspace->path($artifact));
        }

        $clips = [];

        foreach ($story->scenes as $scene) {
            $this->writeWorkspaceFile($clips[] = $workspace->clipPath($scene));
        }

        app(DispatchAssetGeneration::class)->handle($story);

        foreach (DiscardRenderArtifacts::WHOLE_VIDEO_ARTIFACTS as $artifact) {
            $this->assertFileDoesNotExist($workspace->path($artifact));
        }

        // Clips too: frame count derives from audio duration, so every clip for
        // a re-narrated scene is wrong by construction.
        foreach ($clips as $clip) {
            $this->assertFileDoesNotExist($clip);
        }
    }

    public function test_the_publish_sheet_is_marked_stale(): void
    {
        // Chapters are derived from act timings, act timings come from the
        // render, and the render is about to be rebuilt — so every timestamp on
        // a drafted sheet is wrong. Marked rather than deleted: it stays
        // copyable, and Gate 4's consequences land on YouTube.
        $story = $this->renderedStory();
        $story->forceFill(['status' => StoryStatus::MetadataReady])->save();

        $metadata = $story->youtubeMetadata()->create([
            'title_selected' => 'A title',
            'description' => 'A description',
            'status' => MetadataStatus::Generated,
        ]);

        app(DispatchAssetGeneration::class)->handle($story->fresh());

        $this->assertSame(MetadataStatus::Stale, $metadata->fresh()->status);
    }

    /**
     * Gate 3 cannot survive it, and there is no flag to clear.
     */
    public function test_gate_three_must_be_crossed_again(): void
    {
        $story = $this->renderedStory();
        $story->forceFill(['status' => StoryStatus::MetadataReady])->save();

        app(DispatchAssetGeneration::class)->handle($story->fresh());

        $story->refresh();

        $this->assertSame(StoryStatus::AssetsGenerating, $story->status);
        // Below `rendered`, so the only route back to metadata_ready is
        // approveGate(Preview) — the approval IS that transition.
        $this->assertLessThan(StoryStatus::Rendered->rank(), $story->status->rank());
        $this->assertFalse($story->canTransitionTo(StoryStatus::MetadataReady));
    }

    /**
     * The swallowed exception, now raised.
     */
    public function test_reconcile_refuses_to_hide_a_state_it_cannot_land(): void
    {
        // `catch (Throwable) {}` was absorbing two different things that look
        // identical from inside it: a genuine race, and a transition the machine
        // does not define. It was doing the second. A story with every asset
        // complete that never advances, and no error anywhere, is exactly the
        // silent wrong state this project has already had twice.
        $story = $this->renderedStory();

        // Every scene complete, but parked somewhere assets_ready is unreachable
        // from and that no concurrent actor could legitimately have caused.
        foreach ($story->scenes as $scene) {
            $this->completeScene($scene);
        }

        $story->forceFill(['status' => StoryStatus::Rendered])->save();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('cannot move to assets_ready');

        DispatchAssetGeneration::reconcile($story->id);
    }

    public function test_reconcile_stays_quiet_when_an_operator_reopened_the_gate(): void
    {
        // The benign concurrent case the old catch was nominally for, and the
        // only kind that should still pass silently: the operator's decision to
        // reopen Gate 2 mid-batch outranks this callback.
        $story = $this->renderedStory();

        foreach ($story->scenes as $scene) {
            $this->completeScene($scene);
        }

        $story->forceFill(['status' => StoryStatus::ScenesDrafted])->save();

        DispatchAssetGeneration::reconcile($story->id);

        $this->assertSame(StoryStatus::ScenesDrafted, $story->fresh()->status);
    }

    // -- Helpers -------------------------------------------------------------

    private function renderedStory(): Story
    {
        $story = Story::factory()->create(['voice_id' => 'narrator-us-01']);
        $act = Act::factory()->for($story)->create(['sequence' => 1]);

        foreach (range(1, 3) as $sequence) {
            $scene = Scene::factory()->for($story)->for($act)->create([
                'sequence' => $sequence,
                'narration_text' => "Narration for scene {$sequence}.",
                'image_path' => "{$story->id}/stills/scene-{$sequence}.png",
            ]);

            $scene->forceFill([
                'approved_narration_hash' => $scene->narrationFingerprint(),
                'approved_image_hash' => $scene->imageFingerprint(),
                'approved_motion_preset' => $scene->motion_preset->value,
            ])->save();
        }

        $story->forceFill(['status' => StoryStatus::Rendered])->save();

        return $story->fresh();
    }

    private function completeScene(Scene $scene): void
    {
        $track = AudioTrack::query()->firstOrCreate(
            ['story_id' => $scene->story_id, 'language' => 'en-US'],
            ['voice_id' => 'narrator-us-01', 'status' => AssetStatus::Ready],
        );

        Storage::disk('assets')->put("narration/scene-{$scene->id}.wav", 'x');

        SceneAudio::factory()->create([
            'scene_id' => $scene->id,
            'audio_track_id' => $track->id,
            'audio_path' => "narration/scene-{$scene->id}.wav",
            'narration_provider' => 'fake',
            'narration_voice_id' => 'narrator-us-01',
            'narration_simulated' => true,
            'timings_json' => [['word' => 'Narration', 'start_ms' => 0, 'end_ms' => 500]],
            'timings_provider' => 'fake',
            'timings_simulated' => true,
        ]);
    }

    private function writeWorkspaceFile(string $path): void
    {
        if (! is_dir($directory = dirname($path))) {
            mkdir($directory, 0777, true);
        }

        file_put_contents($path, 'x');
    }

    private function queuedImageJobs(): int
    {
        $count = 0;

        Bus::assertBatched(function ($batch) use (&$count): bool {
            $count += collect($batch->jobs)
                ->filter(fn ($j): bool => $j instanceof GenerateSceneImageJob)
                ->count();

            return true;
        });

        return $count;
    }
}
