<?php

namespace Tests\Feature\Gates;

use App\Actions\ApproveScenesGate;
use App\Actions\ReorderScenes;
use App\Enums\AssetStatus;
use App\Enums\Gate;
use App\Enums\MotionPreset;
use App\Enums\SceneStatus;
use App\Enums\StoryStatus;
use App\Exceptions\GateViolationException;
use App\Livewire\Gates\PreviewGate;
use App\Livewire\Gates\ScenesGate;
use App\Models\Act;
use App\Models\AudioTrack;
use App\Models\Scene;
use App\Models\SceneAudio;
use App\Models\Story;
use App\Support\RenderWorkspace;
use App\Support\SceneChangeSet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Reopening Gate 2 after the money has been spent.
 *
 * The reported failure was narrow — the page offered "Reopen Gate 2" at
 * `rendered` and the state machine had no such move — but the rule underneath
 * it is the expensive one:
 *
 *   Reopening preserves what was already paid for. Images and narration for
 *   untouched scenes stay on disk, are not re-billed, and their clips are not
 *   re-rendered. Only a scene whose narration or image prompt actually changed
 *   is regenerated.
 *
 * At 150-250 stills and ~70% of a video's cost, the failure mode that matters
 * is not a crash. It is a reopen that quietly invalidates everything and hands
 * the operator a bill for a video they had already finished paying for. Most of
 * what follows is aimed at that.
 */
class ReopenScenesGateTest extends TestCase
{
    use RefreshDatabase;

    private const SLUG = 'reopen-test';

    protected function tearDown(): void
    {
        $this->deleteDirectory(storage_path('app/renders/'.self::SLUG));

        parent::tearDown();
    }

    // -- The reported bug ----------------------------------------------------

    public function test_gate_two_reopens_from_a_finished_render(): void
    {
        // The exact report: story at `rendered`, operator presses the button on
        // the scenes page, GateViolationException::illegalTransition.
        $story = $this->renderedStory();

        Livewire::test(ScenesGate::class, ['story' => $story])
            ->assertSet('notice', null)
            ->call('reopen')
            ->assertHasNoErrors();

        $story->refresh();

        $this->assertSame(StoryStatus::ScenesDrafted, $story->status);
        $this->assertSame(StoryStatus::Rendered, $story->reopened_from);

        // And the operator is back at the gate, not merely at its status.
        $this->assertSame(Gate::Scenes, $story->awaitingGate());
    }

    public function test_the_button_is_offered_exactly_where_the_move_is_legal(): void
    {
        // The root cause: the blade asked `! editable()` — true for seven
        // statuses — and the machine had the edge for one. Visibility and
        // legality are now the same predicate, and this is what says so.
        foreach (StoryStatus::cases() as $status) {
            $story = Story::factory()->status($status)->create();

            $component = Livewire::test(ScenesGate::class, ['story' => $story]);

            $this->assertSame(
                $status->canReopenScenesGate(),
                $component->instance()->canReopen(),
                "The page and the state machine disagree about reopening from {$status->value}."
            );
        }
    }

    public function test_a_published_story_is_not_reopened(): void
    {
        // Terminal by design: the file is on YouTube and this app does not
        // reach it. The button is absent, and the action refuses anyway.
        $story = Story::factory()->status(StoryStatus::Published)->create();

        $this->assertFalse(Livewire::test(ScenesGate::class, ['story' => $story])->instance()->canReopen());

        $this->expectException(GateViolationException::class);
        $this->expectExceptionMessage('terminal');

        $story->reopenScenesGate();
    }

    public function test_a_render_in_flight_is_cancelled_before_it_is_reopened(): void
    {
        // Up to 250 clip jobs are on the queue. Reopening here would let the
        // operator delete scenes that running workers are mid-encode on.
        $story = Story::factory()->status(StoryStatus::Rendering)->create();

        $this->assertFalse(Livewire::test(ScenesGate::class, ['story' => $story])->instance()->canReopen());

        $this->expectException(GateViolationException::class);
        $this->expectExceptionMessage('render:cancel');

        $story->reopenScenesGate();
    }

    // -- Preserve what was paid for ------------------------------------------

    public function test_reopening_alone_deletes_nothing(): void
    {
        // Reopening is entered speculatively — "let me look at scene 147". An
        // operator who looks and closes the page must not have paid for the
        // look, so the reopen itself touches no file and no pointer.
        $story = $this->renderedStory();
        $before = $this->snapshotAssets($story);

        $story->reopenScenesGate();

        $this->assertSame($before, $this->snapshotAssets($story));
        $this->assertFileExists($this->workspace($story)->path('final.mp4'));
        $this->assertFileExists($this->workspace($story)->clipPath($story->scenes()->first()));
    }

    public function test_a_reopen_that_changed_nothing_regenerates_nothing_and_bills_nothing(): void
    {
        $story = $this->renderedStory();
        $before = $this->snapshotAssets($story);

        $story->reopenScenesGate();
        $changes = app(ApproveScenesGate::class)->handle($story->refresh());

        $this->assertTrue($changes->isEmpty(), 'A no-op reopen reported work to do.');
        $this->assertFalse($changes->billsAnything());
        $this->assertSame(5, $changes->preserved());

        // Not one paid pointer moved...
        $this->assertSame($before, $this->snapshotAssets($story));

        // ...and not even the free CPU work was thrown away.
        $this->assertFileExists($this->workspace($story)->path('final.mp4'));
        $this->assertFileExists($this->workspace($story)->clipPath($story->scenes()->first()));

        $this->assertSame(StoryStatus::ScenesApproved, $story->fresh()->status);
    }

    public function test_editing_one_narration_re_bills_that_scene_only(): void
    {
        // The rule, in its most direct form. One of five scenes is edited; the
        // other four keep everything they were paid for.
        $story = $this->renderedStory();
        $story->reopenScenesGate();
        $story->refresh();

        $target = $story->scenes()->where('sequence', 3)->firstOrFail();

        Livewire::test(ScenesGate::class, ['story' => $story])
            ->call('edit', $target->id)
            ->set('narration', 'She read the sign twice before she understood it.')
            ->call('saveScene')
            ->assertHasNoErrors();

        $changes = app(ApproveScenesGate::class)->handle($story->refresh());

        $this->assertSame([3], $changes->needsNarration->pluck('sequence')->all());
        $this->assertSame([3], $changes->needsTranscription->pluck('sequence')->all());
        $this->assertSame(4, $changes->preserved());

        // The four untouched scenes still point at everything they own.
        foreach ($story->scenes()->where('sequence', '!=', 3)->get() as $scene) {
            $this->assertNotNull($scene->image_path, "Scene {$scene->sequence} lost a still it paid for.");
            $this->assertNotNull($scene->duration_ms);
            $this->assertNotNull($scene->sceneAudio()->value('audio_path'));
            $this->assertNotNull($scene->sceneAudio()->value('timings_json'));
            $this->assertSame(SceneStatus::Ready, $scene->status);
        }
    }

    public function test_a_changed_narration_does_not_throw_away_the_still_it_did_not_change(): void
    {
        // Narration and image bill separately, so they are invalidated
        // separately. Clearing both on any edit would double the cost of every
        // typo fix, and stills are the expensive half.
        $story = $this->renderedStory();
        $story->reopenScenesGate();

        $target = $story->scenes()->where('sequence', 2)->firstOrFail();
        $still = $target->image_path;
        $target->update(['narration_text' => 'A different line entirely, read aloud.']);

        app(ApproveScenesGate::class)->handle($story->refresh());
        $target->refresh();

        // Audio and timings gone — they have to be paid for again.
        $this->assertNull($target->sceneAudio()->value('audio_path'));
        $this->assertNull($target->sceneAudio()->value('timings_json'));
        $this->assertNull($target->duration_ms, 'duration_ms is the audio length and must go with it.');

        // The still survives untouched. Its prompt never changed.
        $this->assertSame($still, $target->image_path);
    }

    public function test_a_changed_image_prompt_does_not_throw_away_the_narration(): void
    {
        // The mirror of the case above, and the one that would silently cost
        // 250 TTS calls if the two fingerprints were collapsed into one.
        $story = $this->renderedStory();
        $story->reopenScenesGate();

        $target = $story->scenes()->where('sequence', 4)->firstOrFail();
        $audio = $target->sceneAudio()->value('audio_path');
        $durationMs = $target->duration_ms;

        $target->update(['image_prompt' => 'A hand-lettered CLOSED sign taped inside a diner window at dusk']);

        $changes = app(ApproveScenesGate::class)->handle($story->refresh());
        $target->refresh();

        $this->assertSame([4], $changes->needsImage->pluck('sequence')->all());
        $this->assertTrue($changes->needsNarration->isEmpty(), 'An image prompt edit re-billed TTS.');

        $this->assertNull($target->image_path);
        $this->assertSame($audio, $target->sceneAudio()->value('audio_path'));
        $this->assertSame($durationMs, $target->duration_ms);
    }

    public function test_looking_at_a_scene_and_ticking_a_checkbox_bills_nothing(): void
    {
        // The reason the comparison is a fingerprint of the two paid input
        // fields rather than updated_at or isDirty(). Both of those move here,
        // and neither of these edits costs a cent to redo.
        $story = $this->renderedStory();
        $story->reopenScenesGate();
        $story->refresh();

        $target = $story->scenes()->where('sequence', 1)->firstOrFail();

        Livewire::test(ScenesGate::class, ['story' => $story])
            ->call('edit', $target->id)
            ->set('isHook', true)
            ->set('isThumbnailCandidate', true)
            ->call('saveScene')
            ->assertHasNoErrors();

        $changes = SceneChangeSet::for($story->refresh());

        $this->assertFalse($changes->billsAnything(), 'A ticked checkbox was treated as a re-bill.');
        $this->assertTrue($changes->isEmpty(), 'A ticked checkbox invalidated the render.');
    }

    public function test_whitespace_only_edits_are_not_a_re_bill(): void
    {
        // A pasted trailing newline produces identical audio from any provider.
        // Treating it as a change would bill for nothing at all.
        $story = $this->renderedStory();
        $story->reopenScenesGate();

        $target = $story->scenes()->where('sequence', 1)->firstOrFail();
        $target->update([
            'narration_text' => "  \n".preg_replace('/ /', '  ', (string) $target->narration_text)."\n\n",
        ]);

        $changes = SceneChangeSet::for($story->refresh());

        $this->assertFalse($changes->billsAnything());
    }

    public function test_changing_only_the_motion_preset_re_renders_the_clip_and_bills_nothing(): void
    {
        // Free CPU, not money. The clip is invalidated because it encodes the
        // motion; the still and the narration are not, because neither one
        // changed.
        $story = $this->renderedStory();
        $story->reopenScenesGate();

        $target = $story->scenes()->where('sequence', 2)->firstOrFail();
        $clip = $this->workspace($story)->clipPath($target);
        $survivor = $this->workspace($story)->clipPath($story->scenes()->where('sequence', 5)->firstOrFail());

        $target->update([
            'motion_preset' => $target->motion_preset === MotionPreset::ZoomIn
                ? MotionPreset::PanLeft
                : MotionPreset::ZoomIn,
        ]);

        $changes = app(ApproveScenesGate::class)->handle($story->refresh());

        $this->assertFalse($changes->billsAnything(), 'A motion preset change was billed.');
        $this->assertSame([2], $changes->needsClip->pluck('sequence')->all());

        $this->assertFileDoesNotExist($clip);
        $this->assertFileExists($survivor, 'An unrelated scene lost its clip.');
        $this->assertNotNull($target->fresh()->image_path);
    }

    public function test_reordering_scenes_refiles_every_clip_without_billing(): void
    {
        // Clips are named `scene-%03d` from `sequence`, so swapping two scenes
        // leaves each clip's contents under the other's number. Cheap to
        // rebuild, silently wrong to keep — and still not a re-bill.
        $story = $this->renderedStory();
        $story->reopenScenesGate();
        $story->refresh();

        app(ReorderScenes::class)->move($story->scenes()->where('sequence', 2)->firstOrFail(), -1);

        $changes = app(ApproveScenesGate::class)->handle($story->refresh());

        $this->assertTrue($changes->sceneSetChanged);
        $this->assertFalse($changes->billsAnything(), 'A reorder re-billed the stills.');
        $this->assertSame(5, $changes->preserved());

        $this->assertSame([], glob($this->workspace($story)->path('clips').'/*') ?: []);

        foreach ($story->scenes()->get() as $scene) {
            $this->assertNotNull($scene->image_path, 'A reorder discarded a still.');
            $this->assertNotNull($scene->sceneAudio()->value('audio_path'));
        }
    }

    public function test_deleting_the_last_scene_invalidates_the_render_without_billing(): void
    {
        // The case a per-scene check alone would miss: removing the final scene
        // shifts nobody's sequence, so no surviving scene looks changed — but
        // the video is a scene shorter. The story-level digest catches it.
        $story = $this->renderedStory();
        $story->reopenScenesGate();
        $story->refresh();

        app(ReorderScenes::class)->delete($story->scenes()->where('sequence', 5)->firstOrFail());

        $changes = app(ApproveScenesGate::class)->handle($story->refresh());

        $this->assertTrue($changes->sceneSetChanged);
        $this->assertTrue($changes->videoIsStale());
        $this->assertFalse($changes->billsAnything());
        $this->assertFileDoesNotExist($this->workspace($story)->path('final.mp4'));
    }

    // -- The stale render --------------------------------------------------

    public function test_a_changed_scene_discards_the_finished_video_but_not_the_assets(): void
    {
        // final.mp4 encodes the whole scene sequence, so any change makes it a
        // finished video that no longer matches the scene list — and Gate 3
        // exists to be sat through. It is deleted; the assets it was built from
        // are not.
        $story = $this->renderedStory();
        $workspace = $this->workspace($story);

        $story->reopenScenesGate();
        $story->scenes()->where('sequence', 1)->firstOrFail()
            ->update(['narration_text' => 'An entirely new opening line for the video.']);

        app(ApproveScenesGate::class)->handle($story->refresh());

        foreach (['final.mp4', 'silent.mp4', 'narration.wav', 'subs.ass', 'clips.txt'] as $artifact) {
            $this->assertFileDoesNotExist($workspace->path($artifact), "{$artifact} outlived the scene it described.");
        }

        // Every still survives — a narration edit is not an image edit, and all
        // five image prompts are untouched. Only scene 1's audio is discarded.
        $this->assertSame(5, $story->scenes()->whereNotNull('image_path')->count());
        $this->assertSame(4, $story->sceneAudio()->whereNotNull('audio_path')->count());
    }

    public function test_a_stale_render_cannot_be_approved_at_gate_three_while_the_gate_is_reopened(): void
    {
        // Belt and braces on the same hazard: while a story sits reopened, its
        // status is scenes_drafted, so Gate 3 is not the gate it is waiting at
        // and the old video is unreachable even before it is deleted.
        $story = $this->renderedStory();
        $story->reopenScenesGate();

        $this->assertSame(Gate::Scenes, $story->fresh()->awaitingGate());
        $this->assertNotSame(Gate::Preview, $story->fresh()->awaitingGate());
        $this->assertFalse(
            Livewire::test(PreviewGate::class, ['story' => $story->fresh()])
                ->instance()->canApprove(),
            'A reopened story still offered its stale render for approval at Gate 3.'
        );
    }

    // -- Re-approval bookkeeping --------------------------------------------

    public function test_re_approval_lands_on_scenes_approved_and_clears_the_reopen(): void
    {
        // The gate opens to exactly one status, always. No shortcut forward is
        // added to "restore" a story to where it was reopened from — unchanged
        // scenes cost nothing walking the pipeline again, because every stage
        // is idempotent.
        $story = $this->renderedStory();
        $story->reopenScenesGate();

        app(ApproveScenesGate::class)->handle($story->refresh());
        $story->refresh();

        $this->assertSame(StoryStatus::ScenesApproved, $story->status);
        $this->assertNull($story->reopened_from);
        $this->assertTrue($story->canGeneratePaidAssets());
    }

    public function test_an_untouched_scene_stays_ready_rather_than_dropping_back_to_approved(): void
    {
        // SceneStatus::Ready means "image, narration and timings all present".
        // Resetting every scene to Approved on re-approval would tell the
        // progress page that 250 scenes need work when four do.
        $story = $this->renderedStory();
        $story->reopenScenesGate();
        $story->scenes()->where('sequence', 1)->firstOrFail()
            ->update(['image_prompt' => 'Something completely different']);

        app(ApproveScenesGate::class)->handle($story->refresh());

        $this->assertSame(SceneStatus::Approved, $story->scenes()->where('sequence', 1)->value('status'));
        $this->assertSame(4, $story->scenes()->where('status', SceneStatus::Ready)->count());
    }

    public function test_a_first_approval_still_reports_every_scene_as_work_to_do(): void
    {
        // Same computation, both times. A scene needs an asset if it is absent
        // OR stale, and on a first approval nothing exists — so the cost
        // preview reads five out of five, exactly as it always did.
        $story = Story::factory()->status(StoryStatus::ScenesDrafted)->create(['slug' => self::SLUG]);
        $act = Act::factory()->for($story)->atSequence(1)->create();

        for ($i = 1; $i <= 5; $i++) {
            Scene::factory()->forAct($act)->atSequence($i)->create();
        }

        $preview = Livewire::test(ScenesGate::class, ['story' => $story])->instance()->costPreview();

        $this->assertSame(['scenes' => 5, 'images' => 5, 'narrations' => 5, 'transcriptions' => 5, 'preserved' => 0], $preview);
    }

    public function test_the_confirmation_names_what_changed_rather_than_the_whole_story(): void
    {
        // An operator shown "250 images" for a three-scene fix learns to stop
        // reading the confirmation, and the confirmation is the last thing
        // between them and the bill.
        $story = $this->renderedStory();
        $story->reopenScenesGate();
        $story->refresh();

        $story->scenes()->where('sequence', 2)->firstOrFail()
            ->update(['image_prompt' => 'A rain-slick parking lot under sodium lights']);

        $summary = implode("\n", SceneChangeSet::for($story->refresh())->summary());

        $this->assertStringContainsString('1 images will be generated and billed', $summary);
        $this->assertStringNotContainsString('narrations will be generated', $summary);
        $this->assertStringContainsString('4 scene(s) keep their existing image', $summary);
    }

    public function test_the_cost_preview_after_a_reopen_counts_only_the_edited_scenes(): void
    {
        $story = $this->renderedStory();
        $story->reopenScenesGate();
        $story->refresh();

        $story->scenes()->where('sequence', 1)->firstOrFail()->update(['narration_text' => 'One changed line here.']);
        $story->scenes()->where('sequence', 2)->firstOrFail()->update(['image_prompt' => 'One changed prompt here']);

        $preview = Livewire::test(ScenesGate::class, ['story' => $story])->instance()->costPreview();

        $this->assertSame(5, $preview['scenes']);
        $this->assertSame(1, $preview['images']);
        $this->assertSame(1, $preview['narrations']);
        $this->assertSame(3, $preview['preserved']);
    }

    // -- Fixtures ------------------------------------------------------------

    /**
     * A story that has been all the way through: five scenes with stills,
     * narration and word timings, clips on disk, and a finished final.mp4.
     *
     * Built by walking the real gate machine rather than by parking the row at
     * `rendered`, because the fingerprints that make any of this work are
     * written by the approval itself — a fixture that set the status directly
     * would be testing a story that had never been approved.
     */
    private function renderedStory(): Story
    {
        $story = Story::factory()->status(StoryStatus::ScenesDrafted)->create(['slug' => self::SLUG]);
        $act = Act::factory()->for($story)->atSequence(1)->create();
        $track = AudioTrack::factory()->for($story)->create();

        $offsetFrames = 0;

        for ($i = 1; $i <= 5; $i++) {
            $scene = Scene::factory()->forAct($act)->atSequence($i)->create([
                'image_path' => sprintf('%s/scene-%03d.png', self::SLUG, $i),
                'duration_ms' => 9000 + $i * 100,
                'motion_preset' => MotionPreset::ZoomIn,
                'status' => SceneStatus::Drafted,
            ]);

            SceneAudio::factory()->for($scene)->for($track)->create([
                'audio_path' => sprintf('%s/scene-%03d.mp3', self::SLUG, $i),
                'timings_json' => [['word' => 'the', 'start_ms' => 0, 'end_ms' => 180]],
                'duration_ms' => $scene->duration_ms,
                'frames' => $scene->framesAt(),
                'offset_frames' => $offsetFrames,
                'status' => AssetStatus::Ready,
            ]);

            $offsetFrames += (int) $scene->framesAt();
        }

        // The one path production has. This is what writes the approved
        // fingerprints and the scene digest.
        app(ApproveScenesGate::class)->handle($story);

        $story->scenes()->update(['status' => SceneStatus::Ready]);

        foreach ([StoryStatus::AssetsGenerating, StoryStatus::AssetsReady, StoryStatus::Rendering, StoryStatus::Rendered] as $status) {
            $story->refresh()->transitionTo($status);
        }

        $this->writeRenderArtifacts($story->refresh());

        return $story;
    }

    private function writeRenderArtifacts(Story $story): void
    {
        $workspace = $this->workspace($story);

        foreach (['clips', 'padded'] as $directory) {
            if (! is_dir($path = $workspace->path($directory))) {
                mkdir($path, 0775, true);
            }
        }

        foreach ($story->scenes()->get() as $scene) {
            file_put_contents($workspace->clipPath($scene), 'clip');
            file_put_contents($workspace->paddedAudioPath($scene), 'pcm');
        }

        foreach (['final.mp4', 'silent.mp4', 'narration.wav', 'subs.ass', 'clips.txt', 'scene_audio.json'] as $artifact) {
            file_put_contents($workspace->path($artifact), 'x');
        }
    }

    /**
     * Every paid pointer in the story, as one comparable value.
     *
     * The assertion "nothing was re-billed" is really "none of these moved",
     * and spelling it out as a whole-story snapshot catches a stray null that
     * a per-scene assertion in the wrong loop would walk past.
     *
     * @return array<int, array<string, mixed>>
     */
    private function snapshotAssets(Story $story): array
    {
        return $story->fresh()->scenes()->with('sceneAudio')->get()
            ->map(fn (Scene $scene): array => [
                'sequence' => $scene->sequence,
                'image_path' => $scene->image_path,
                'duration_ms' => $scene->duration_ms,
                'audio_path' => $scene->sceneAudio->pluck('audio_path')->all(),
                'timings' => $scene->sceneAudio->pluck('timings_json')->all(),
            ])
            ->all();
    }

    private function workspace(Story $story): RenderWorkspace
    {
        return RenderWorkspace::for($story);
    }

    private function deleteDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        foreach (glob($path.'/*') ?: [] as $entry) {
            is_dir($entry) ? $this->deleteDirectory($entry) : @unlink($entry);
        }

        @rmdir($path);
    }
}
