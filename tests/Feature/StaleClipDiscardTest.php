<?php

namespace Tests\Feature;

use App\Actions\DispatchAssetGeneration;
use App\Contracts\SpeechSynthesizer;
use App\Contracts\Transcriber;
use App\Enums\MotionPreset;
use App\Enums\StoryStatus;
use App\Models\Act;
use App\Models\Scene;
use App\Models\SceneAudio;
use App\Models\Story;
use App\Support\NarrationPace;
use App\Support\RenderWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * Stale clips are discarded whatever status the story is at.
 *
 * **The bug this pins.** The discard used to be guarded by
 * `status->rank() >= Rendering`, on the reasoning that a story which had not
 * rendered could not have render artifacts. That is false, and routinely so: the
 * render stages are individually runnable from the CLI, and `render:clips` is
 * the free one, so running it early on a story parked at `assets_generating` is
 * a normal thing to do. The story sits below `rendering` with a full set of
 * clips and padded WAVs on disk.
 *
 * Regenerating narration then left every one of them in place — 181 clips and
 * 181 padded WAVs built against audio that no longer existed. Nothing would have
 * overwritten them, because clips are filed per scene, and nothing would have
 * noticed until the concat assertion failed tens of minutes into a re-render.
 *
 * The guard is named for that failure, per the spec's rule, and it fires against
 * a real instance of it: a story at `assets_generating`, which is precisely the
 * status the old condition excluded.
 */
class StaleClipDiscardTest extends TestCase
{
    use RefreshDatabase;

    public function test_clips_are_discarded_for_a_story_that_has_not_reached_rendering(): void
    {
        Bus::fake();

        $story = $this->storyWithStaleRenderArtifacts(StoryStatus::AssetsGenerating);

        $workspace = RenderWorkspace::for($story);
        $scene = $story->scenes()->first();

        // Exactly the state the real story was found in.
        $this->assertFileExists($workspace->clipPath($scene));
        $this->assertFileExists($workspace->paddedAudioPath($scene));
        $this->assertFileExists($workspace->path('silent.mp4'));

        app(DispatchAssetGeneration::class)->handle($story);

        $this->assertFileDoesNotExist(
            $workspace->clipPath($scene),
            'A clip built against replaced audio must not survive a regeneration, whatever the status.',
        );
        $this->assertFileDoesNotExist($workspace->paddedAudioPath($scene));
        $this->assertFileDoesNotExist($workspace->path('silent.mp4'));
    }

    /**
     * The behaviour that already worked keeps working — this widened the
     * condition rather than moving it.
     */
    public function test_clips_are_still_discarded_for_a_rendered_story(): void
    {
        Bus::fake();

        $story = $this->storyWithStaleRenderArtifacts(StoryStatus::Rendered);
        $workspace = RenderWorkspace::for($story);
        $scene = $story->scenes()->first();

        app(DispatchAssetGeneration::class)->handle($story);

        $this->assertFileDoesNotExist($workspace->clipPath($scene));
        $this->assertFileDoesNotExist($workspace->path('final.mp4'));
    }

    /**
     * A story with nothing outstanding never reaches the discard.
     *
     * Being liberal about deleting free artifacts is safe, but it must still be
     * consequential: pressing the button on a finished story is a look, and a
     * look must not throw away a render.
     */
    public function test_a_story_with_nothing_outstanding_keeps_its_render(): void
    {
        Bus::fake();

        // `assets_ready` rather than `rendered`, because a rendered story with
        // nothing outstanding trips a different, pre-existing guard: reconcile()
        // refuses the illegal `rendered -> assets_ready` move rather than
        // swallowing it. That guard is correct and is not what this test is
        // about.
        $story = $this->storyWithStaleRenderArtifacts(StoryStatus::AssetsReady, outstanding: false);
        $workspace = RenderWorkspace::for($story);
        $scene = $story->scenes()->first();

        app(DispatchAssetGeneration::class)->handle($story);

        $this->assertFileExists(
            $workspace->clipPath($scene),
            'Nothing was outstanding, so nothing should have been discarded.',
        );
        $this->assertFileExists($workspace->path('final.mp4'));
    }

    private function storyWithStaleRenderArtifacts(StoryStatus $status, bool $outstanding = true): Story
    {
        $story = Story::factory()->status($status)->create([
            'slug' => 'stale-clips',
            'voice_id' => 'narrator-us-01',
        ]);

        $act = Act::factory()->for($story)->atSequence(1)->create();

        $scene = Scene::factory()->for($story)->for($act)->create([
            'sequence' => 1,
            'narration_text' => 'The house had been empty for a year.',
            'image_prompt' => 'An empty house at dusk.',
            'motion_preset' => MotionPreset::ZoomIn,
            'image_path' => 'stills/stale-clips/scene-001.png',
            'duration_ms' => 4000,
        ]);

        // Provenance has to name whatever is actually BOUND, not a vendor
        // string. SceneChangeSet is provenance-aware, so audio attributed to a
        // provider the container does not resolve is outstanding by definition —
        // which would make the "nothing outstanding" case impossible to set up
        // and would quietly turn that test into a copy of the other two.
        $speech = app(SpeechSynthesizer::class);
        $transcriber = app(Transcriber::class);

        SceneAudio::factory()->for($scene)->create([
            'audio_path' => 'narration/stale-clips/scene-001.wav',
            'duration_ms' => 4000,
            'timings_json' => $outstanding ? null : json_encode([['word' => 'The', 'start_ms' => 0, 'end_ms' => 200]]),
            'narration_provider' => $speech->providerName(),
            'narration_voice_id' => $story->voice_id,
            'narration_speed' => NarrationPace::configuredSpeed(),
            'narration_simulated' => $speech->isSimulated(),
            'timings_provider' => $outstanding ? null : $transcriber->providerName(),
        ]);

        // A finished story is one whose approved hashes match what is on the
        // row. An outstanding one has never had them recorded.
        if (! $outstanding) {
            $scene->refresh()->recordGateTwoApproval();
        }

        $workspace = RenderWorkspace::for($story);
        $workspace->ensureExists();

        foreach (['clips', 'padded'] as $directory) {
            @mkdir($workspace->path($directory), 0777, true);
        }

        file_put_contents($workspace->clipPath($scene->refresh()), 'not really an mp4');
        file_put_contents($workspace->paddedAudioPath($scene), 'not really a wav');

        foreach (['silent.mp4', 'final.mp4', 'subs.ass', 'narration.wav'] as $artifact) {
            file_put_contents($workspace->path($artifact), 'stale');
        }

        return $story->refresh();
    }
}
