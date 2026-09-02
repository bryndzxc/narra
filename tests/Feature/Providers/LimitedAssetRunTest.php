<?php

namespace Tests\Feature\Providers;

use App\Actions\DispatchAssetGeneration;
use App\Actions\EstimateSceneAssets;
use App\Enums\AssetStatus;
use App\Enums\SceneStatus;
use App\Enums\StoryStatus;
use App\Jobs\GenerateSceneNarrationJob;
use App\Jobs\TranscribeSceneTimingsJob;
use App\Models\Act;
use App\Models\AudioTrack;
use App\Models\Scene;
use App\Models\SceneAudio;
use App\Models\Story;
use App\Support\SceneChangeSet;
use App\Support\SceneSelection;
use Illuminate\Bus\PendingBatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Running five scenes before running a hundred and eighty-six.
 *
 * The reason this exists at all: two newly-built providers running together for
 * the first time is exactly when a small run earns its keep, and until now
 * `assets:generate` had no way to do one. It dispatched everything outstanding
 * or nothing, so the first real exercise of a new vendor was necessarily the
 * whole video.
 *
 * What is pinned here is mostly about the SECOND batch and the reconcile, not
 * the first. Restricting the narration dispatch is the easy half and would have
 * looked finished on its own; the two things that would actually have gone
 * wrong are:
 *
 *  1. The transcription batch is recomputed after the narration batch finishes,
 *     from the whole story. A five-scene run would therefore have queued
 *     alignment for all 186 — 181 of them pointed at placeholder silence, every
 *     one failing for a reason that has nothing to do with what was being
 *     proved.
 *
 *  2. The reconcile decides whether the story reaches `assets_ready`, and it
 *     asked a provenance-blind question. After a five-scene run it would have
 *     called all 186 scenes ready and moved a story that is silent for 97% of
 *     its runtime to "assets ready".
 */
class LimitedAssetRunTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('assets');
    }

    // -- Parsing -------------------------------------------------------------

    public function test_the_operators_shorthand_parses(): void
    {
        $this->assertSame([5], SceneSelection::parse('5')->sequences);
        $this->assertSame([1, 2, 3, 4, 5], SceneSelection::parse('1-5')->sequences);
        $this->assertSame([1, 3, 7], SceneSelection::parse('1,3,7')->sequences);
        $this->assertSame([1, 2, 3, 10, 20, 21], SceneSelection::parse('1-3,10,20-21')->sequences);
        // Overlap collapses rather than dispatching a scene twice.
        $this->assertSame([1, 2, 3], SceneSelection::parse('1-3,2,3')->sequences);
    }

    public function test_a_selection_it_cannot_read_is_refused_rather_than_salvaged(): void
    {
        // Refusing beats salvaging in both directions. A typo that silently
        // NARROWED a run is found later as a story with holes in it; one that
        // silently WIDENED it is found on the bill.
        foreach (['1-', 'five', '1..5', '3-1', ''] as $bad) {
            try {
                SceneSelection::parse($bad);
                $this->fail("Expected \"{$bad}\" to be refused.");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_a_range_reads_back_the_way_it_was_written(): void
    {
        $this->assertSame('1-5', SceneSelection::parse('1-5')->describe());
        $this->assertSame('1-5, 10', SceneSelection::parse('1-5,10')->describe());
        $this->assertSame('3', SceneSelection::parse('3')->describe());
        $this->assertSame('1-2, 7, 20-22', SceneSelection::parse('20-22,1,2,7')->describe());
    }

    // -- The quote matches the run -------------------------------------------

    public function test_a_limited_run_is_quoted_as_limited(): void
    {
        $story = $this->story(10);

        $full = app(EstimateSceneAssets::class)->handle($story);
        $limited = app(EstimateSceneAssets::class)->handle($story, SceneSelection::parse('1-3'));

        $this->assertSame(10, $full->narrationsPending);
        $this->assertSame(3, $limited->narrationsPending);

        // The characters billed are the characters of THOSE scenes, not a
        // tenth of the story averaged out.
        $expected = (int) $story->scenes()->orderBy('sequence')->limit(3)->get()
            ->sum(fn (Scene $s): int => mb_strlen((string) $s->narration_text));
        $this->assertSame($expected, $limited->speechCharacters);

        $this->assertTrue($limited->isLimited());
        $this->assertSame('1-3', $limited->selection);
        // The seven left out are outstanding, NOT preserved. An operator shown
        // "7 preserved" would read the story as nearly finished.
        $this->assertSame(7, $limited->deferred);
    }

    public function test_scenes_the_story_does_not_have_are_reported(): void
    {
        $this->story(5);

        $this->artisan('assets:generate', ['story' => Story::first()->slug, '--scenes' => '1-200'])
            ->expectsOutputToContain('It runs 1-5')
            ->assertExitCode(1);
    }

    // -- The dispatch matches the quote --------------------------------------

    public function test_only_the_selected_scenes_are_queued(): void
    {
        Bus::fake();

        $story = $this->story(10);

        app(DispatchAssetGeneration::class)->handle($story, SceneSelection::parse('1-3'));

        Bus::assertBatched(function (PendingBatch $batch): bool {
            $narration = collect($batch->jobs)->filter(
                fn ($job): bool => $job instanceof GenerateSceneNarrationJob
            );

            return $narration->count() === 3;
        });
    }

    public function test_the_timings_batch_does_not_escape_the_selection(): void
    {
        // THE FAILURE THIS GUARD IS FOR.
        //
        // The transcription batch is deliberately recomputed after the
        // narration batch finishes, because the set that can be transcribed is
        // not knowable until the audio exists. Recomputed from the whole story,
        // a five-scene run queues alignment for every scene that lacks
        // timings — including the ones this run never narrated.
        //
        // Note the axis being tested. Restricting the FIRST batch is the strong,
        // obvious half and passes trivially; the second batch is where the
        // selection had to survive a serialised callback, and it is the half
        // that would have failed silently.
        $story = $this->story(10);

        // Five scenes have audio but no timings — the state the second batch
        // reads. Only 1-3 are in the run.
        foreach ($story->scenes()->orderBy('sequence')->take(5)->get() as $scene) {
            $this->giveAudio($scene);
        }

        Bus::fake();

        DispatchAssetGeneration::dispatchTimings($story->id, [1, 2, 3]);

        Bus::assertBatched(function (PendingBatch $batch): bool {
            $jobs = collect($batch->jobs)->filter(fn ($j): bool => $j instanceof TranscribeSceneTimingsJob);

            return $jobs->count() === 3;
        });
    }

    // -- The story must not look finished ------------------------------------

    public function test_a_partial_run_leaves_the_story_parked_rather_than_ready(): void
    {
        // The second failure this guard is for. reconcile() used to ask
        // Scene::paidAssetsStillValid(), which compares text fingerprints and
        // cannot see that a scene has no assets at all — so a story where three
        // scenes were generated and seven were untouched would have reached
        // assets_ready.
        $story = $this->story(10);

        app(DispatchAssetGeneration::class)->handle($story, SceneSelection::parse('1-3'));

        DispatchAssetGeneration::reconcile($story->id);

        $story->refresh();

        $this->assertNotSame(StoryStatus::AssetsReady, $story->status);
        $this->assertSame(StoryStatus::AssetsGenerating, $story->status);

        // And the scenes that were not generated are findable, which is what
        // makes the retry set exactly the set the button re-dispatches.
        $this->assertSame(7, $story->scenes()->where('status', SceneStatus::Failed)->count());
        $this->assertSame(7, SceneChangeSet::for($story->fresh())->needsNarration->count());
    }

    public function test_running_the_rest_finishes_the_story(): void
    {
        // The whole point of a proving run: it has to be resumable into the
        // full one without re-billing what it already did.
        $story = $this->story(6);

        app(DispatchAssetGeneration::class)->handle($story, SceneSelection::parse('1-3'));
        $this->assertSame(3, SceneChangeSet::for($story->fresh())->needsNarration->count());

        app(DispatchAssetGeneration::class)->handle($story->fresh());

        $story->refresh();

        $this->assertSame(StoryStatus::AssetsReady, $story->status);
        $this->assertSame(0, SceneChangeSet::for($story)->needsNarration->count());
        $this->assertSame(6, $story->scenes()->where('status', SceneStatus::Ready)->count());
    }

    public function test_re_running_the_same_selection_bills_nothing_the_second_time(): void
    {
        $story = $this->story(6);

        app(DispatchAssetGeneration::class)->handle($story, SceneSelection::parse('1-3'));

        $second = app(EstimateSceneAssets::class)->handle($story->fresh(), SceneSelection::parse('1-3'));

        $this->assertSame(0, $second->narrationsPending);
        $this->assertFalse($second->billsAnything());
    }

    // -- Helpers -------------------------------------------------------------

    private function story(int $scenes): Story
    {
        $story = Story::factory()->paidAssetsUnlocked()->create(['voice_id' => 'narrator-us-01']);
        $act = Act::factory()->for($story)->create(['sequence' => 1]);

        foreach (range(1, $scenes) as $sequence) {
            $scene = Scene::factory()->for($story)->for($act)->create([
                'sequence' => $sequence,
                'narration_text' => "Narration for scene {$sequence}, which is this long.",
            ]);

            $scene->forceFill([
                'approved_narration_hash' => $scene->narrationFingerprint(),
                'approved_image_hash' => $scene->imageFingerprint(),
                'approved_motion_preset' => $scene->motion_preset->value,
            ])->save();
        }

        return $story->fresh();
    }

    private function giveAudio(Scene $scene): void
    {
        // One track per story per language — the table has a unique on it,
        // because a second track for one language would silently double the
        // narration.
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
            'timings_json' => null,
        ]);
    }
}
