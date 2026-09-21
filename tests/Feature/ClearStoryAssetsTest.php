<?php

namespace Tests\Feature;

use App\Actions\ClearStoryAssets;
use App\Enums\StoryStatus;
use App\Livewire\Stories\Index;
use App\Models\Act;
use App\Models\Character;
use App\Models\CharacterReference;
use App\Models\CostEntry;
use App\Models\RenderJob;
use App\Models\Scene;
use App\Models\SceneAudio;
use App\Models\Story;
use App\Support\RenderWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

/**
 * Reclaiming the disk a finished story holds.
 *
 * Every case here runs against FAKED asset disks. That is not hygiene: the
 * Action deletes files, and a test that resolved `storage_path()` for real
 * would be a test suite that can delete 1.24 GB of paid stills on a green run.
 */
class ClearStoryAssetsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('assets');
        Storage::fake('characters');
        Storage::fake('renders');
    }

    public function test_it_refuses_a_story_that_is_not_past_gate_four(): void
    {
        foreach (StoryStatus::cases() as $status) {
            if ($status === StoryStatus::Published) {
                continue;
            }

            $story = Story::factory()->create(['status' => $status]);

            try {
                app(ClearStoryAssets::class)->handle($story);
                $this->fail("A story at {$status->value} was cleared.");
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('only past Gate 4', $e->getMessage());
                $this->assertStringContainsString($status->value, $e->getMessage());
            }
        }
    }

    /**
     * `rendered` is the one in that list worth naming: it is a story sitting AT
     * Gate 3 waiting to be watched, and deleting its stills would strand it
     * with no way forward but paying for them again.
     */
    public function test_a_rendered_story_waiting_at_gate_three_is_refused(): void
    {
        $story = $this->storyWithAssets(StoryStatus::Rendered);

        $this->expectException(RuntimeException::class);

        app(ClearStoryAssets::class)->handle($story);
    }

    public function test_a_dry_run_deletes_nothing_and_reports_the_size(): void
    {
        $story = $this->storyWithAssets();

        $result = app(ClearStoryAssets::class)->handle($story);

        $this->assertTrue($result['dry_run']);
        $this->assertSame(3 + 3 + 2 + 1, $result['files']);
        $this->assertGreaterThan(0, $result['bytes']);

        // Everything still there.
        $this->assertTrue(Storage::disk('assets')->exists($story->id.'/stills/scene-1.jpg'));
        $this->assertTrue(Storage::disk('assets')->exists($story->id.'/narration/scene-1.wav'));
        $this->assertTrue(Storage::disk('characters')->exists($story->id.'/char-1/b01-01.jpg'));
        $this->assertNotNull($story->fresh()->scenes()->first()->image_path);
        $this->assertNull($story->fresh()->assets_cleared_at);
    }

    public function test_apply_deletes_the_working_assets_and_empties_the_paths(): void
    {
        $story = $this->storyWithAssets();

        $result = app(ClearStoryAssets::class)->handle($story, dryRun: false);

        $this->assertFalse($result['dry_run']);
        $this->assertFalse(Storage::disk('assets')->exists($story->id.'/stills/scene-1.jpg'));
        $this->assertFalse(Storage::disk('assets')->exists($story->id.'/narration/scene-1.wav'));
        $this->assertFalse(Storage::disk('characters')->exists($story->id.'/char-1/b01-01.jpg'));

        // THE PATHS GO WITH THE FILES. `Scene::needsImage()` reads the ROW, so
        // a populated path with no file behind it is a story the pipeline
        // believes is complete — `assets:generate --estimate` would report
        // nothing pending and $0.00.
        $this->assertSame(0, Scene::where('story_id', $story->id)->whereNotNull('image_path')->count());
        $this->assertSame(0, SceneAudio::whereIn('scene_id', $story->scenes()->select('id'))
            ->whereNotNull('audio_path')->count());
        $this->assertSame(0, Character::where('story_id', $story->id)->whereNotNull('reference_image_path')->count());
        $this->assertSame(0, CharacterReference::whereIn('character_id', Character::where('story_id', $story->id)->select('id'))
            ->whereNotNull('image_path')->count());

        foreach ($story->fresh()->scenes as $scene) {
            $this->assertTrue($scene->needsImage(), 'A cleared scene must read as needing its image.');
        }
    }

    public function test_no_row_is_deleted_and_the_ledger_is_untouched(): void
    {
        $story = $this->storyWithAssets();

        $scenes = $story->scenes()->count();
        $characters = $story->characters()->count();
        $costs = CostEntry::where('story_id', $story->id)->count();
        $jobs = RenderJob::where('story_id', $story->id)->count();
        $spend = (string) $story->total_cost_usd;

        app(ClearStoryAssets::class)->handle($story, dryRun: false);

        $this->assertSame($scenes, $story->scenes()->count());
        $this->assertSame($characters, $story->characters()->count());
        $this->assertSame($costs, CostEntry::where('story_id', $story->id)->count());
        $this->assertSame($jobs, RenderJob::where('story_id', $story->id)->count());
        $this->assertSame($spend, (string) $story->fresh()->total_cost_usd);
    }

    /**
     * The word timings are in MySQL, not on disk, and `narration:measure`
     * reads `duration_ms` — so the measured reading rates this whole pipeline
     * sizes scripts against survive a clear.
     */
    public function test_the_timings_and_the_durations_survive(): void
    {
        $story = $this->storyWithAssets();

        app(ClearStoryAssets::class)->handle($story, dryRun: false);

        foreach (SceneAudio::whereIn('scene_id', $story->scenes()->select('id'))->get() as $audio) {
            $this->assertNotNull($audio->timings_json);
            $this->assertNotNull($audio->duration_ms);
            $this->assertNotNull($audio->samples);
            $this->assertNull($audio->audio_path);
        }
    }

    public function test_the_final_video_is_kept_unless_it_is_asked_for(): void
    {
        $story = $this->storyWithAssets();

        $result = app(ClearStoryAssets::class)->handle($story, dryRun: false);

        $this->assertTrue(is_file(RenderWorkspace::for($story)->path('final.mp4')));
        $this->assertStringContainsString('not asked for', (string) $result['final_kept_because']);
        $this->assertContains('final.mp4', $result['kept']);
    }

    /**
     * THE CASE THIS GUARD EXISTS FOR. `deliver` ran and succeeded for thirteen
     * of fourteen published stories and `render_jobs.output_path` still names
     * those files; not one of them is on disk, because they were uploaded and
     * removed. A status column and a job row are not evidence that a file is
     * there now.
     */
    public function test_the_final_video_is_refused_when_no_delivered_copy_exists(): void
    {
        $story = $this->storyWithAssets();

        config(['render.delivery.path' => $this->emptyDeliveryFolder()]);

        $result = app(ClearStoryAssets::class)->handle($story, dryRun: false, includeFinal: true);

        $this->assertTrue(is_file(RenderWorkspace::for($story)->path('final.mp4')));
        $this->assertStringContainsString('REFUSED', (string) $result['final_kept_because']);
        $this->assertStringContainsString('last copy', (string) $result['final_kept_because']);
    }

    public function test_the_final_video_is_refused_when_the_delivered_copy_is_a_different_size(): void
    {
        $story = $this->storyWithAssets();

        $folder = $this->emptyDeliveryFolder();
        file_put_contents($folder.'/'.$story->slug.'.mp4', 'truncated');
        config(['render.delivery.path' => $folder]);

        $result = app(ClearStoryAssets::class)->handle($story, dryRun: false, includeFinal: true);

        $this->assertTrue(is_file(RenderWorkspace::for($story)->path('final.mp4')));
        $this->assertStringContainsString('is not a copy', (string) $result['final_kept_because']);
    }

    public function test_the_final_video_goes_when_a_matching_delivered_copy_is_there(): void
    {
        $story = $this->storyWithAssets();
        $workspace = RenderWorkspace::for($story);

        $folder = $this->emptyDeliveryFolder();
        copy($workspace->path('final.mp4'), $folder.'/'.$story->slug.'.mp4');
        config(['render.delivery.path' => $folder]);

        $result = app(ClearStoryAssets::class)->handle($story, dryRun: false, includeFinal: true);

        $this->assertFalse(is_file($workspace->path('final.mp4')));
        $this->assertNull($result['final_kept_because']);

        // The delivered copy is the one thing that must still be there.
        $this->assertTrue(is_file($folder.'/'.$story->slug.'.mp4'));

        // And the artifacts the metadata sheet reads are kept either way.
        $this->assertTrue(is_file($workspace->path('subs.ass')));
        $this->assertTrue(is_file($workspace->path('scene_audio.json')));
    }

    public function test_the_story_records_that_it_was_cleared_and_what_it_freed(): void
    {
        $story = $this->storyWithAssets();

        $this->assertFalse($story->assetsCleared());

        $result = app(ClearStoryAssets::class)->handle($story, dryRun: false);
        $story->refresh();

        $this->assertTrue($story->assetsCleared());
        $this->assertNotNull($story->assets_cleared_at);
        $this->assertSame($result['bytes'], (int) $story->assets_cleared_bytes);
    }

    /**
     * Without this line the page shows a published story whose every scene has
     * no picture, which is TRUE and reads as a story whose assets failed.
     */
    public function test_the_page_says_the_assets_were_cleared_on_purpose(): void
    {
        $story = $this->storyWithAssets();

        $this->get(route('stories.metadata', $story))->assertDontSee('cleared on purpose');

        app(ClearStoryAssets::class)->handle($story, dryRun: false);

        $this->get(route('stories.metadata', $story))
            ->assertSee('cleared on purpose')
            ->assertSee('buying its stills and its narration again');

        // It is the shared gate wrapper, so it reaches every gate rather than
        // the one page somebody happened to test.
        $this->get(route('stories.preview', $story))->assertSee('cleared on purpose');
        $this->get(route('stories.scenes', $story))->assertSee('cleared on purpose');
    }

    public function test_the_command_is_a_dry_run_by_default(): void
    {
        $story = $this->storyWithAssets();

        $this->artisan('story:clear-assets', ['story' => (string) $story->id])
            ->expectsOutputToContain('WOULD FREE')
            ->expectsOutputToContain('Dry run.')
            ->assertSuccessful();

        $this->assertTrue(Storage::disk('assets')->exists($story->id.'/stills/scene-1.jpg'));
        $this->assertNull($story->fresh()->assets_cleared_at);

        $this->artisan('story:clear-assets', ['story' => (string) $story->id, '--apply' => true])
            ->expectsOutputToContain('FREED')
            ->assertSuccessful();

        $this->assertFalse(Storage::disk('assets')->exists($story->id.'/stills/scene-1.jpg'));
    }

    public function test_the_command_takes_a_slug_and_refuses_an_unknown_story(): void
    {
        $story = $this->storyWithAssets();

        $this->artisan('story:clear-assets', ['story' => (string) $story->slug])->assertSuccessful();
        $this->artisan('story:clear-assets', ['story' => 'no-such-story'])->assertFailed();
    }

    // -- the sweep -------------------------------------------------------------

    public function test_the_survey_holds_published_stories_with_assets_and_nothing_else(): void
    {
        $with = $this->storyWithAssets();
        $empty = Story::factory()->create(['status' => StoryStatus::Published]);
        $unfinished = $this->storyWithAssets(StoryStatus::Rendered);

        $survey = app(ClearStoryAssets::class)->survey();

        $this->assertSame([$with->id], array_map(
            static fn (array $e): int => $e['story']->id,
            $survey['take'],
        ));

        // A published story with nothing left is reported separately rather
        // than as a zero-byte row, so a sweep never looks bigger than it is.
        $this->assertSame([$empty->id], array_map(
            static fn (Story $s): int => $s->id,
            $survey['nothing'],
        ));

        // The one at `rendered` is in neither list: the sweep asks the same
        // question `assertReady()` does, not a looser one written for bulk.
        $this->assertNotContains($unfinished->id, array_map(
            static fn (array $e): int => $e['story']->id,
            $survey['take'],
        ));

        $this->assertSame($survey['take'][0]['result']['bytes'], $survey['bytes']);
    }

    public function test_all_is_a_dry_run_by_default_and_names_every_published_story(): void
    {
        $alpha = tap($this->storyWithAssets())->update(['title' => 'Alpha Story']);
        $beta = tap($this->storyWithAssets())->update(['title' => 'Beta Story']);

        $this->artisan('story:clear-assets', ['--all' => true])
            ->expectsOutputToContain('Alpha Story')
            ->expectsOutputToContain('Beta Story')
            ->expectsOutputToContain('WOULD FREE across 2 story(s)')
            ->expectsOutputToContain('Dry run.')
            ->assertSuccessful();

        // Named, not `Story::all()`: the nested factories behind this fixture
        // mint their own draft stories, and looping every row in the table
        // would assert about ones that never had an asset.
        foreach ([$alpha, $beta] as $story) {
            $this->assertTrue(Storage::disk('assets')->exists($story->id.'/stills/scene-1.jpg'));
            $this->assertNull($story->fresh()->assets_cleared_at);
        }
    }

    public function test_all_apply_clears_every_published_story_and_leaves_the_rest_alone(): void
    {
        $first = $this->storyWithAssets();
        $second = $this->storyWithAssets();
        $unfinished = $this->storyWithAssets(StoryStatus::Rendered);

        $this->artisan('story:clear-assets', ['--all' => true, '--apply' => true])
            ->expectsOutputToContain('FREED across 2 story(s)')
            ->assertSuccessful();

        foreach ([$first, $second] as $story) {
            $this->assertFalse(Storage::disk('assets')->exists($story->id.'/stills/scene-1.jpg'));
            $this->assertNotNull($story->fresh()->assets_cleared_at);
            $this->assertNull(Scene::where('story_id', $story->id)->first()->image_path);

            // No row was deleted by any of it.
            $this->assertSame(3, Scene::where('story_id', $story->id)->count());
            $this->assertSame(1, CostEntry::where('story_id', $story->id)->count());
        }

        $this->assertTrue(Storage::disk('assets')->exists($unfinished->id.'/stills/scene-1.jpg'));
        $this->assertNull($unfinished->fresh()->assets_cleared_at);
    }

    /**
     * THE SHARP ONE. The master survives a sweep even in the single situation
     * where `--include-final` would take it: a delivered copy present, on disk,
     * the same size. That is the difference between "nothing has happened to
     * qualify yet" and "this press cannot reach them".
     */
    public function test_a_sweep_never_touches_a_master_even_when_a_delivered_copy_matches(): void
    {
        $story = $this->storyWithAssets();
        $workspace = RenderWorkspace::for($story);
        $folder = $this->emptyDeliveryFolder();

        copy($workspace->path('final.mp4'), $folder.'/'.$story->slug.'.mp4');
        config(['render.delivery.path' => $folder]);

        app(ClearStoryAssets::class)->clearAll();

        $this->assertTrue(is_file($workspace->path('final.mp4')));
        $this->assertFalse(Storage::disk('assets')->exists($story->id.'/stills/scene-1.jpg'));
    }

    public function test_the_sweep_cannot_be_asked_for_the_masters_by_any_argument(): void
    {
        foreach (['survey', 'clearAll'] as $method) {
            $this->assertSame(
                [],
                array_map(
                    static fn (\ReflectionParameter $p): string => $p->getName(),
                    (new \ReflectionMethod(ClearStoryAssets::class, $method))->getParameters(),
                ),
                "ClearStoryAssets::{$method}() takes an argument. A sweep has no parameter to "
                .'refuse with, so a master must not be reachable from one.',
            );
        }
    }

    public function test_all_refuses_include_final_rather_than_ignoring_it(): void
    {
        $story = $this->storyWithAssets();
        $folder = $this->emptyDeliveryFolder();

        copy(RenderWorkspace::for($story)->path('final.mp4'), $folder.'/'.$story->slug.'.mp4');
        config(['render.delivery.path' => $folder]);

        $this->artisan('story:clear-assets', ['--all' => true, '--apply' => true, '--include-final' => true])
            ->expectsOutputToContain('single-story flag')
            ->assertFailed();

        // Refused means nothing ran at all, not "ran without the flag".
        $this->assertTrue(is_file(RenderWorkspace::for($story)->path('final.mp4')));
        $this->assertTrue(Storage::disk('assets')->exists($story->id.'/stills/scene-1.jpg'));
    }

    public function test_the_command_refuses_a_story_and_all_together_and_neither_alone(): void
    {
        $story = $this->storyWithAssets();

        $this->artisan('story:clear-assets', ['story' => (string) $story->id, '--all' => true])
            ->expectsOutputToContain('not both')
            ->assertFailed();

        $this->artisan('story:clear-assets')
            ->expectsOutputToContain('Name a story')
            ->assertFailed();

        $this->assertTrue(Storage::disk('assets')->exists($story->id.'/stills/scene-1.jpg'));
    }

    // -- the button ------------------------------------------------------------

    public function test_the_index_offers_the_sweep_only_when_a_published_story_exists(): void
    {
        Story::factory()->create(['status' => StoryStatus::Rendered]);

        Livewire::test(Index::class)->assertDontSee('Clear assets of published stories');

        $this->storyWithAssets();

        Livewire::test(Index::class)->assertSee('Clear assets of published stories');
    }

    public function test_the_confirm_shows_the_list_and_the_total_and_deletes_nothing(): void
    {
        $alpha = tap($this->storyWithAssets())->update(['title' => 'Alpha Story']);
        $beta = tap($this->storyWithAssets())->update(['title' => 'Beta Story']);

        Livewire::test(Index::class)
            ->call('askToClearAssets')
            ->assertSee('2 published story(s), and this is what goes')
            ->assertSee('Alpha Story')
            ->assertSee('Beta Story')
            ->assertSee('Total')
            // The one sentence that has to be on the confirm every time.
            ->assertSee('No master is touched.');

        foreach ([$alpha, $beta] as $story) {
            $this->assertTrue(Storage::disk('assets')->exists($story->id.'/stills/scene-1.jpg'));
        }
    }

    public function test_the_press_clears_them_keeps_every_row_and_keeps_the_masters(): void
    {
        $first = $this->storyWithAssets();
        $second = $this->storyWithAssets();

        Livewire::test(Index::class)
            ->call('askToClearAssets')
            ->call('clearAssets')
            ->assertSet('confirmingClear', false)
            ->assertSee('Cleared 2 published story(s)')
            ->assertSee('no master was touched');

        foreach ([$first, $second] as $story) {
            $this->assertFalse(Storage::disk('assets')->exists($story->id.'/stills/scene-1.jpg'));
            $this->assertTrue(is_file(RenderWorkspace::for($story)->path('final.mp4')));
            $this->assertNotNull($story->fresh()->assets_cleared_at);
            $this->assertSame(1, RenderJob::where('story_id', $story->id)->count());
        }
    }

    /**
     * Twice is harmless by construction: the second sweep reads a disk the
     * first one emptied. Nothing here is bought, so there is no claim to take.
     */
    public function test_pressing_the_sweep_twice_takes_nothing_the_second_time(): void
    {
        $story = $this->storyWithAssets();

        $component = Livewire::test(Index::class)->call('askToClearAssets')->call('clearAssets');

        $first = $story->fresh()->assets_cleared_at;

        $component->call('askToClearAssets')->call('clearAssets')->assertSee('Nothing to take');

        $this->assertEquals($first, $story->fresh()->assets_cleared_at);
    }

    // -- fixtures --------------------------------------------------------------

    /**
     * A published story with three stills, three narration files, one
     * character with two reference images, one composed thumbnail and a
     * final.mp4 — and a cost row and a render job, which must survive.
     */
    private function storyWithAssets(StoryStatus $status = StoryStatus::Published): Story
    {
        $story = Story::factory()->create(['status' => $status]);

        // ONE act, shared by the three scenes, rather than letting SceneFactory
        // mint one each. `ActFactory` draws its sequence from
        // `unique()->numberBetween(1, 8)` and that generator resets per test
        // method, so three acts a story capped this fixture at two stories in a
        // test — which is exactly the size a sweep needs to exceed.
        $act = Act::factory()->for($story)->create();

        $character = Character::factory()->for($story)->create([
            'reference_image_path' => $story->id.'/char-1/b01-02.jpg',
        ]);

        foreach ([1, 2] as $n) {
            CharacterReference::factory()->for($character)->create([
                // Explicit: the table is unique on (character, batch, sequence).
                'batch' => 1,
                'sequence' => $n,
                'image_path' => $story->id.'/char-1/b01-0'.$n.'.jpg',
            ]);
            Storage::disk('characters')->put($story->id.'/char-1/b01-0'.$n.'.jpg', str_repeat('c', 900));
        }

        foreach ([1, 2, 3] as $n) {
            $scene = Scene::factory()->for($story)->for($act)->create([
                'sequence' => $n,
                'image_path' => $story->id.'/stills/scene-'.$n.'.jpg',
            ]);

            SceneAudio::factory()->for($scene)->create([
                'audio_path' => $story->id.'/narration/scene-'.$n.'.wav',
                'timings_json' => ['words' => [['word' => 'x', 'start_ms' => 0, 'end_ms' => 100]]],
                'duration_ms' => 4000,
                'samples' => 96000,
            ]);

            Storage::disk('assets')->put($story->id.'/stills/scene-'.$n.'.jpg', str_repeat('i', 2048));
            Storage::disk('assets')->put($story->id.'/narration/scene-'.$n.'.wav', str_repeat('a', 4096));
        }

        $workspace = RenderWorkspace::for($story);
        $workspace->ensureExists();

        @mkdir($workspace->path('thumbnails'), 0777, true);
        file_put_contents($workspace->path('thumbnails/thumb-1.jpg'), str_repeat('t', 512));
        file_put_contents($workspace->path('final.mp4'), str_repeat('v', 8192));
        file_put_contents($workspace->path('subs.ass'), 'subs');
        file_put_contents($workspace->path('scene_audio.json'), '{}');

        CostEntry::factory()->for($story)->create();
        RenderJob::factory()->for($story)->create();

        return $story->fresh();
    }

    private function emptyDeliveryFolder(): string
    {
        $folder = storage_path('framework/testing/delivery-'.uniqid());

        @mkdir($folder, 0777, true);

        return $folder;
    }
}
