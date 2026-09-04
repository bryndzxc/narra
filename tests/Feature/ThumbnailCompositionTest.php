<?php

namespace Tests\Feature;

use App\Actions\ComposeThumbnails;
use App\Actions\DeliverThumbnail;
use App\Enums\ActPhase;
use App\Enums\MetadataStatus;
use App\Enums\StoryStatus;
use App\Livewire\Gates\MetadataGate;
use App\Models\Act;
use App\Models\Character;
use App\Models\Scene;
use App\Models\Story;
use App\Models\YoutubeMetadata;
use App\Services\Ffmpeg;
use App\Support\ImagePromptBuilder;
use App\Support\ThumbnailFraming;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

/**
 * The thumbnail the app used to describe and not make.
 *
 * Gate 4 handed over overlay text and a recommended still, and composing them
 * into a picture was somebody opening an image editor once per video. What is
 * asserted here is in three parts, and the middle one is the interesting one:
 *
 *  1. **It costs nothing.** Every composition is a crop of a still the story
 *     already owns. There is no provider here and no cost row, and the test
 *     that matters is that a run over a story with paid assets writes no new
 *     `cost_entries`.
 *
 *  2. **It ranks, and the ranking is a proxy that says so.** Nothing in this
 *     stack can find a face in a JPEG, so ThumbnailFraming reasons from who was
 *     recorded in the frame and how the frame was written. That is a real
 *     signal and a fallible one — story 21 has a chair in an empty room flagged
 *     as a thumbnail candidate — so what is asserted is that the wide shot with
 *     nobody in it loses to the close-up, not that the score is correct.
 *
 *  3. **The output is what YouTube accepts.** 1280x720 exactly, under 2 MB, and
 *     delivered beside the video as `<slug>.jpg`. The dimensions are asserted
 *     from the file rather than from the arguments that produced it, because
 *     "the command said 1280" and "the file is 1280" are different claims and
 *     only one of them is the one that matters.
 */
class ThumbnailCompositionTest extends TestCase
{
    use RefreshDatabase;

    private string $outside;

    protected function setUp(): void
    {
        parent::setUp();

        $this->outside = rtrim(sys_get_temp_dir(), '/\\').DIRECTORY_SEPARATOR.'narra-thumb-test';
        $this->cleanup();
    }

    protected function tearDown(): void
    {
        $this->cleanup();

        parent::tearDown();
    }

    // -- The format ----------------------------------------------------------

    public function test_a_composition_is_two_stills_side_by_side_at_youtubes_size(): void
    {
        $story = $this->storyWithStills();

        $result = app(ComposeThumbnails::class)->handle($story, 2);

        $this->assertCount(2, $result['composed']);

        foreach ($result['composed'] as $option) {
            $this->assertFileExists($option['path']);

            // From the file, not from the arguments. A filter graph that says
            // 1280 and a file that is 1278 are different claims.
            $inspected = app(Ffmpeg::class)->inspect($option['path']);

            $this->assertSame(1280, (int) $inspected['stream']['width']);
            $this->assertSame(720, (int) $inspected['stream']['height']);

            // YouTube's cap, not ours.
            $this->assertLessThanOrEqual(2_000_000, (int) $option['bytes']);

            // Two panels. One still cropped to a portrait panel is not a split
            // panel, it is a still with most of it thrown away.
            $this->assertCount(2, $option['panels']);
        }
    }

    public function test_composing_bills_nothing(): void
    {
        // The whole constraint. A 270-scene story has already paid for every
        // frame it could want, and buying one to crop in half would be spending
        // money to avoid making a choice.
        $story = $this->storyWithStills();

        app(ComposeThumbnails::class)->handle($story, 2);

        $this->assertSame(0, $story->costEntries()->count());
    }

    public function test_it_refuses_when_there_are_not_two_stills_to_work_with(): void
    {
        $story = Story::factory()->status(StoryStatus::Rendered)->create(['slug' => 'thumb-test']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('a split panel needs two');

        app(ComposeThumbnails::class)->handle($story);
    }

    // -- The ranking ---------------------------------------------------------

    public function test_a_wide_shot_with_nobody_in_it_loses_to_a_close_up(): void
    {
        // The failure this exists to catch, and it is a live one: story 21 has
        // a chair in an empty room flagged as a thumbnail candidate by the same
        // model that wrote the scene.
        $story = $this->storyWithStills();

        $close = $story->scenes()->where('sequence', 1)->first();
        $wide = $story->scenes()->where('sequence', 4)->first();

        $framing = app(ThumbnailFraming::class);

        $this->assertGreaterThan(
            $framing->score($wide)['score'],
            $framing->score($close)['score'],
        );

        $this->assertSame('close', $framing->score($close)['shot']);
        $this->assertSame('wide', $framing->score($wide)['shot']);
        $this->assertSame(0, $framing->score($wide)['cast']);
    }

    public function test_the_empty_wide_shot_is_not_used_while_better_stills_exist(): void
    {
        $story = $this->storyWithStills();

        $result = app(ComposeThumbnails::class)->handle($story, 2);

        $used = [];

        foreach ($result['composed'] as $option) {
            $used = array_merge($used, array_column($option['panels'], 'sequence'));
        }

        $this->assertNotContains(4, $used, 'The empty wide shot was composed into a thumbnail.');
    }

    public function test_a_pair_spanning_the_reversal_outranks_two_escalation_acts(): void
    {
        // What the reversal phase bought beyond the script. "Before she left"
        // against "after she looked for me" is a thumbnail; two acts of
        // escalation is one picture shown twice.
        $story = $this->storyWithStills();

        $result = app(ComposeThumbnails::class)->handle($story, 1);

        $this->assertStringContainsString(
            'spans the reversal',
            implode(' ', $result['composed'][0]['reasons']),
        );
    }

    public function test_it_says_so_when_it_widens_past_the_flagged_candidates(): void
    {
        // A silent widening would make the Gate 2 flags look respected when
        // they were not.
        $story = $this->storyWithStills();
        $story->scenes()->update(['is_thumbnail_candidate' => false]);

        $result = app(ComposeThumbnails::class)->handle($story, 2);

        $this->assertTrue($result['widened']);
        $this->assertStringContainsString(
            'No scene is flagged as a thumbnail candidate',
            implode(' ', $result['notes']),
        );
    }

    public function test_the_frame_is_read_off_the_prompt_that_produced_the_still(): void
    {
        // The scoring reads the frame sentence only. The cast block below it
        // says "mid-thirties, straight black hair" for every scene of the
        // story, so a search over the whole prompt would score them all alike.
        $prompt = app(ImagePromptBuilder::class)->build(
            'Tight close on her face, eyes fixed on him.',
            [],
            [],
        );

        $this->assertSame('Tight close on her face, eyes fixed on him.', ImagePromptBuilder::frameFrom($prompt));
        $this->assertStringContainsString('art', mb_strtolower($prompt), 'The style block should follow the frame.');
    }

    // -- Picking and delivery ------------------------------------------------

    public function test_gate_four_composes_and_the_pick_is_delivered_beside_the_video(): void
    {
        $story = $this->storyWithStills();

        config()->set('render.delivery.path', $this->outside);

        $component = Livewire::test(MetadataGate::class, ['story' => $story])
            ->call('composeThumbnails')
            ->assertSet('problem', null);

        $options = $component->instance()->thumbnailOptions();

        $this->assertNotEmpty($options);

        $component
            ->set('thumbnailChoice', $options[0]['key'])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(
            $options[0]['key'],
            $story->youtubeMetadata()->first()->thumbnail_selected,
        );

        // Beside the video, named the same way, for the same reason.
        $this->assertFileExists($this->outside.DIRECTORY_SEPARATOR.'thumb-test.jpg');
    }

    public function test_saving_without_a_pick_delivers_nothing(): void
    {
        $story = $this->storyWithStills();

        config()->set('render.delivery.path', $this->outside);

        Livewire::test(MetadataGate::class, ['story' => $story])
            ->call('composeThumbnails')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertFileDoesNotExist($this->outside.DIRECTORY_SEPARATOR.'thumb-test.jpg');
    }

    public function test_a_selection_naming_a_composition_that_is_gone_is_refused(): void
    {
        // Not silent. An operator who thinks they picked a thumbnail should
        // find out here rather than at upload.
        $story = $this->storyWithStills();

        $metadata = YoutubeMetadata::query()->create([
            'story_id' => $story->id,
            'status' => MetadataStatus::Generated,
            'thumbnail_options' => [],
            'thumbnail_selected' => 'thumb-9',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('is not among the composed options');

        app(DeliverThumbnail::class)->handle($story, $metadata, $this->outside);
    }

    public function test_recomposing_keeps_a_selection_that_still_exists(): void
    {
        // Looking at the options again should not silently discard a decision.
        $story = $this->storyWithStills();

        app(ComposeThumbnails::class)->handle($story, 2);

        $metadata = $story->youtubeMetadata()->first();
        $metadata->update(['thumbnail_selected' => 'thumb-2']);

        app(ComposeThumbnails::class)->handle($story, 2);

        $this->assertSame('thumb-2', $metadata->fresh()->thumbnail_selected);
    }

    public function test_a_candidate_is_served_from_the_non_public_disk(): void
    {
        $story = $this->storyWithStills();

        app(ComposeThumbnails::class)->handle($story, 2);

        $this->get(route('stories.thumbnail', ['story' => $story, 'key' => 'thumb-1']))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/jpeg');

        // The key is matched against the stored options, never used as a path.
        $this->get(route('stories.thumbnail', ['story' => $story, 'key' => '../final']))
            ->assertNotFound();
    }

    // -- Fixtures ------------------------------------------------------------

    /**
     * A story whose stills exist on disk, framed the way real ones are.
     *
     * Four scenes: two close-ups on the escalation side, one mid shot and one
     * wide with nobody in it — which is the shape of the real problem rather
     * than a shape invented to make the assertions pass.
     */
    private function storyWithStills(): Story
    {
        $story = Story::factory()->status(StoryStatus::Rendered)->create(['slug' => 'thumb-test']);

        $escalation = Act::factory()->for($story)->atSequence(1)->inPhase(ActPhase::Escalation)->create();
        $refusal = Act::factory()->for($story)->atSequence(2)->inPhase(ActPhase::Refusal)->create();

        $cast = Character::factory()->for($story)->create();

        $frames = [
            1 => ['Tight close on her face, jaw set, eyes fixed on him.', $escalation, true],
            2 => ['Mid shot across the table as he leans back and folds his arms.', $escalation, true],
            3 => ['Close on his face in the doorway, mouth flat, saying nothing.', $refusal, true],
            4 => ['A wide establishing shot of the empty kitchen, the chair unoccupied.', $refusal, false],
        ];

        foreach ($frames as $sequence => [$frame, $act, $peopled]) {
            $path = "thumb-test/stills/scene-{$sequence}.jpg";

            $this->writeStill(Storage::disk('assets')->path($path), $sequence);

            $scene = Scene::factory()->for($story)->create([
                'act_id' => $act->id,
                'sequence' => $sequence,
                'image_path' => $path,
                'image_prompt' => app(ImagePromptBuilder::class)->build(
                    $frame,
                    $peopled ? [$cast] : [],
                    $peopled ? [$cast->name] : [],
                ),
                'is_thumbnail_candidate' => true,
                'is_hook' => false,
            ]);

            if ($peopled) {
                $scene->characters()->attach($cast->id);
            }
        }

        return $story->refresh();
    }

    /**
     * A real 16:9 JPEG, because the crop arithmetic is the thing being tested.
     *
     * lavfi rather than a byte string: the composer scales to cover and crops,
     * and a file FFmpeg cannot decode would prove nothing about either.
     */
    private function writeStill(string $path, int $seed): void
    {
        @mkdir(dirname($path), 0775, true);

        app(Ffmpeg::class)->run([
            '-y',
            '-f', 'lavfi',
            '-i', sprintf('color=c=0x%02x4488:s=1920x1080', 40 * $seed),
            '-frames:v', '1',
            $path,
        ], 30);
    }

    private function cleanup(): void
    {
        foreach (glob($this->outside.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->outside);

        foreach (glob(storage_path('app/renders/thumb-test/thumbnails/*')) ?: [] as $file) {
            @unlink($file);
        }

        @rmdir(storage_path('app/renders/thumb-test/thumbnails'));
        @rmdir(storage_path('app/renders/thumb-test'));

        foreach (glob(storage_path('app/assets/thumb-test/stills/*')) ?: [] as $file) {
            @unlink($file);
        }

        @rmdir(storage_path('app/assets/thumb-test/stills'));
        @rmdir(storage_path('app/assets/thumb-test'));
    }
}
