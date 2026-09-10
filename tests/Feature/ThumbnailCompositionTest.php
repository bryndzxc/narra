<?php

namespace Tests\Feature;

use Anthropic\Client;
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
use App\Services\Claude\ClaudeScriptWriter;
use App\Services\Ffmpeg;
use App\Support\CharacterTextGuard;
use App\Support\ImagePromptBuilder;
use App\Support\LocaleGuard;
use App\Support\ThumbnailFraming;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use ReflectionMethod;
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

    /**
     * The nomination rule and the ranker hold ONE opinion about a frame with
     * nobody in it.
     *
     * They did not: the prompt asked for "a face mid-reaction, or an object that
     * raises a question", the model complied, and the ranker scored the envelope
     * it had been asked for at -40. Three of story 25's six flags were documents
     * on desks and one composed pair of two empty desks scored -90 and was still
     * offered. Both texts are held side by side here so a rewording of either
     * that reintroduces the disagreement goes red.
     */
    public function test_the_nomination_rule_and_the_ranker_agree_a_frame_with_nobody_in_it_is_not_a_candidate(): void
    {
        $prompt = $this->scenePrompt();

        $this->assertStringContainsString('thumbnail_candidate', $prompt);
        $this->assertStringNotContainsString('object that raises', $prompt, 'The prompt asks for object thumbnails again.');
        $this->assertStringContainsString('Never a frame with nobody in it', $prompt);
        $this->assertStringContainsString('never a wide establishing shot', $prompt);

        $story = $this->storyWithStills();
        $empty = app(ThumbnailFraming::class)->score($story->scenes()->where('sequence', 4)->first());

        $this->assertSame(0, $empty['cast']);
        $this->assertLessThan(0, $empty['score'], 'The ranker no longer treats an empty frame as near-disqualifying.');
    }

    /**
     * Two different leads outrank the same face twice.
     *
     * Four equally framed single-character close-ups in one phase, so every
     * pair ties on framing and on phase and the ONLY thing separating them is
     * who is in them. Without the term the first candidate in insertion order
     * wins, which is scenes 1 and 2 — the same person — and that is what story
     * 25 shipped: Kevin beside Kevin in the same shirt.
     */
    public function test_the_same_face_on_both_panels_loses_to_two_different_leads(): void
    {
        $story = $this->framedStory(
            acts: [ActPhase::Escalation],
            scenes: [
                [1, 'Tight close on her face, jaw set, eyes fixed on him.', 0, 0],
                [2, 'Close on her face in the doorway, mouth flat.', 0, 0],
                [3, 'Tight close on his face, brows drawn together.', 0, 1],
                [4, 'Close on her face at the table, eyes down.', 0, 0],
            ],
        );

        $result = app(ComposeThumbnails::class)->handle($story, 3);

        $first = $result['composed'][0];
        $casts = array_map(
            fn (array $panel): array => Scene::find($panel['scene_id'])->characters()->pluck('characters.id')->all(),
            $first['panels'],
        );

        $this->assertNotSame($casts[0], $casts[1], 'The top composition shows the same face twice.');

        $sameFace = array_filter(
            $result['composed'],
            fn (array $option): bool => str_contains(implode(' ', $option['reasons']), 'same face on both panels'),
        );

        $this->assertNotEmpty($sameFace, 'A same-face pair was composed without saying so.');
    }

    /**
     * The no-phase fallback measures the gap against the STORY, not the pool.
     *
     * Story 21's flags all sat inside its first 107 scenes of 270. Measured
     * against the pool's own last scene, 20 -> 107 was 0.81 and reported as
     * "opposite ends of the story"; against the story it is 0.32 and is not.
     * The green half puts a flag genuinely late and expects the sentence back.
     */
    public function test_the_fallback_gap_is_measured_against_the_whole_story(): void
    {
        $story = $this->framedStory(
            acts: [null, null],
            scenes: [
                [20, 'Tight close on her face, jaw set, eyes fixed on him.', 0, 0],
                [107, 'Close on his face in the doorway, mouth flat.', 0, 1],
                [270, 'A wide establishing shot of the empty kitchen.', 1, null, false, false],
            ],
        );

        $result = app(ComposeThumbnails::class)->handle($story, 1);

        $this->assertStringNotContainsString(
            'opposite ends of the story',
            implode(' ', $result['composed'][0]['reasons']),
            'A gap of 87 scenes in a 270-scene story was called opposite ends.',
        );

        $story = $this->framedStory(
            acts: [null, null],
            scenes: [
                [20, 'Tight close on her face, jaw set, eyes fixed on him.', 0, 0],
                [200, 'Close on his face in the doorway, mouth flat.', 1, 1],
                [270, 'A wide establishing shot of the empty kitchen.', 1, null, false, false],
            ],
        );

        $result = app(ComposeThumbnails::class)->handle($story, 1);

        $this->assertStringContainsString(
            'opposite ends of the story',
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

        $first = app(ComposeThumbnails::class)->handle($story, 2);
        $key = (string) $first['composed'][1]['key'];

        $metadata = $story->youtubeMetadata()->first();
        $metadata->update(['thumbnail_selected' => $key]);

        app(ComposeThumbnails::class)->handle($story, 2);

        $this->assertSame($key, $metadata->fresh()->thumbnail_selected);
    }

    // -- The pool reaches the pair score whole ---------------------------------

    /**
     * A late-act flag outside the framing top six still reaches a composition.
     *
     * pairs() used to slice the ranked pool to six BEFORE scoring pairs, so the
     * +30 for spanning the reversal was only applied to stills that had already
     * out-framed everything else. Six escalation close-ups at 60 and one refusal
     * still at 30: under the slice the refusal still is seventh and gone; whole,
     * 60 + 30 + 30 beats 60 + 60 - 10 and the top composition spans the arc.
     */
    public function test_a_late_act_flag_outside_the_framing_top_six_reaches_a_composition(): void
    {
        $story = $this->framedStory(
            acts: [ActPhase::Escalation, ActPhase::Refusal],
            scenes: [
                [1, 'Tight close on her face, jaw set.', 0, 0],
                [2, 'Tight close on his face, brows drawn.', 0, 1],
                [3, 'Close on her face in the doorway.', 0, 0],
                [4, 'Close on his face at the window.', 0, 1],
                [5, 'Tight close on her face, eyes down.', 0, 0],
                [6, 'Tight close on his face, mouth flat.', 0, 1],
                [7, 'Lead A alone at the kitchen table, saying nothing.', 1, 0],
            ],
        );

        $result = app(ComposeThumbnails::class)->handle($story, 1);

        $this->assertFalse($result['widened']);
        $this->assertStringContainsString('spans the reversal', implode(' ', $result['composed'][0]['reasons']));
        $this->assertContains(7, array_column($result['composed'][0]['panels'], 'sequence'));
    }

    /**
     * The same, one step earlier: the widened path used to keep the top eight.
     */
    public function test_the_widened_pool_is_the_whole_story_not_its_top_eight(): void
    {
        $story = $this->framedStory(
            acts: [ActPhase::Escalation, ActPhase::Refusal],
            scenes: [
                [1, 'Tight close on her face, jaw set.', 0, 0, false],
                [2, 'Tight close on his face, brows drawn.', 0, 1, false],
                [3, 'Close on her face in the doorway.', 0, 0, false],
                [4, 'Close on his face at the window.', 0, 1, false],
                [5, 'Tight close on her face, eyes down.', 0, 0, false],
                [6, 'Tight close on his face, mouth flat.', 0, 1, false],
                [7, 'Close on her face by the sink.', 0, 0, false],
                [8, 'Close on his face on the stairs.', 0, 1, false],
                [9, 'Lead A alone at the kitchen table, saying nothing.', 1, 0, false],
            ],
        );

        $result = app(ComposeThumbnails::class)->handle($story, 1);

        $this->assertTrue($result['widened']);
        $this->assertStringContainsString('spans the reversal', implode(' ', $result['composed'][0]['reasons']));
        $this->assertContains(9, array_column($result['composed'][0]['panels'], 'sequence'));
    }

    // -- The pick describes the picture, not its slot ---------------------------

    /**
     * A pick survives a re-compose by what it shows.
     *
     * Story 23's pick was `thumb-4`; a re-compose put a different pair in slot
     * four and the record still said `thumb-4`. Here the pool is changed so the
     * positions shift, and the pick must still name the same two scenes.
     */
    public function test_a_pick_survives_a_recompose_by_what_it_shows_not_by_its_slot(): void
    {
        $story = $this->framedStory(
            acts: [ActPhase::Escalation],
            scenes: [
                [1, 'Tight close on her face, jaw set, eyes fixed on him.', 0, 0],
                [2, 'Close on her face in the doorway, mouth flat.', 0, 0],
                [3, 'Tight close on his face, brows drawn together.', 0, 1],
                [4, 'Close on her face at the table, eyes down.', 0, 0],
            ],
        );

        $first = app(ComposeThumbnails::class)->handle($story, 3);
        $picked = $this->optionShowing($first['composed'], [2, 3]);

        $metadata = $story->youtubeMetadata()->first();
        $metadata->update(['thumbnail_selected' => $picked['key']]);

        // Shift every position: the top still leaves the pool.
        $story->scenes()->where('sequence', 1)->update(['is_thumbnail_candidate' => false]);

        $second = app(ComposeThumbnails::class)->handle($story, 3);
        $selected = $this->optionWithKey($second['composed'], (string) $metadata->fresh()->thumbnail_selected);

        $this->assertSame([2, 3], array_column($selected['panels'], 'sequence'));
    }

    public function test_a_pick_whose_pair_is_gone_is_cleared_and_said(): void
    {
        $story = $this->framedStory(
            acts: [ActPhase::Escalation],
            scenes: [
                [1, 'Tight close on her face, jaw set, eyes fixed on him.', 0, 0],
                [2, 'Close on her face in the doorway, mouth flat.', 0, 0],
                [3, 'Tight close on his face, brows drawn together.', 0, 1],
                [4, 'Close on her face at the table, eyes down.', 0, 0],
            ],
        );

        $first = app(ComposeThumbnails::class)->handle($story, 3);
        $picked = $this->optionShowing($first['composed'], [1, 3]);

        $metadata = $story->youtubeMetadata()->first();
        $metadata->update(['thumbnail_selected' => $picked['key']]);

        // Scene 3 loses its still, so no composition can show it.
        $story->scenes()->where('sequence', 3)->update(['image_path' => null, 'is_thumbnail_candidate' => false]);

        $second = app(ComposeThumbnails::class)->handle($story, 3);

        $this->assertNull($metadata->fresh()->thumbnail_selected);
        $this->assertStringContainsString('scenes 1 + 3', implode(' ', $second['notes']));
        $this->assertStringContainsString('no longer among the compositions', implode(' ', $second['notes']));
    }

    /**
     * The same scene pair, a different picture: a still regenerated under its
     * own scene id. The scene ids alone would keep the pick; the fingerprint of
     * the two source files is what says the operator has not seen this one.
     */
    public function test_a_pick_whose_still_was_regenerated_is_cleared_and_said(): void
    {
        $story = $this->framedStory(
            acts: [ActPhase::Escalation],
            scenes: [
                [1, 'Tight close on her face, jaw set, eyes fixed on him.', 0, 0],
                [3, 'Tight close on his face, brows drawn together.', 0, 1],
            ],
        );

        $first = app(ComposeThumbnails::class)->handle($story, 1);
        $metadata = $story->youtubeMetadata()->first();
        $metadata->update(['thumbnail_selected' => $first['composed'][0]['key']]);

        // Same path, different bytes.
        $this->writeStill(Storage::disk('assets')->path('thumb-test/stills/scene-1.jpg'), 5);

        $second = app(ComposeThumbnails::class)->handle($story, 1);

        $this->assertSame($first['composed'][0]['key'], $second['composed'][0]['key'], 'The key should still name the pair.');
        $this->assertNull($metadata->fresh()->thumbnail_selected);
        $this->assertStringContainsString('regenerated since you chose it', implode(' ', $second['notes']));
    }

    /**
     * A pick recorded under the old positional key resolves through what that
     * slot HELD, never through what the slot holds now.
     */
    public function test_a_legacy_slot_pick_is_carried_by_the_scenes_the_slot_held(): void
    {
        $story = $this->framedStory(
            acts: [ActPhase::Escalation],
            scenes: [
                [1, 'Tight close on her face, jaw set, eyes fixed on him.', 0, 0],
                [2, 'Close on her face in the doorway, mouth flat.', 0, 0],
                [3, 'Tight close on his face, brows drawn together.', 0, 1],
                [4, 'Close on her face at the table, eyes down.', 0, 0],
            ],
        );

        $first = app(ComposeThumbnails::class)->handle($story, 3);

        // Rewrite the record the way the old code left it: positional keys,
        // no scene ids, no fingerprint — only the panels.
        $legacy = [];

        foreach ($first['composed'] as $index => $option) {
            $legacy[] = [
                'key' => sprintf('thumb-%d', $index + 1),
                'path' => $option['path'],
                'bytes' => $option['bytes'],
                'score' => $option['score'],
                'reasons' => $option['reasons'],
                'panels' => $option['panels'],
            ];
        }

        $heldByTwo = array_column($legacy[1]['panels'], 'sequence');

        $metadata = $story->youtubeMetadata()->first();
        $metadata->update(['thumbnail_options' => $legacy, 'thumbnail_selected' => 'thumb-2']);

        // Shift the positions, then re-compose.
        $story->scenes()->where('sequence', 1)->update(['is_thumbnail_candidate' => false]);

        $second = app(ComposeThumbnails::class)->handle($story, 3);
        $key = (string) $metadata->fresh()->thumbnail_selected;

        $this->assertStringStartsWith('s', $key);
        $this->assertSame($heldByTwo, array_column($this->optionWithKey($second['composed'], $key)['panels'], 'sequence'));
        $this->assertStringContainsString('recorded as slot "thumb-2"', implode(' ', $second['notes']));
    }

    public function test_a_candidate_is_served_from_the_non_public_disk(): void
    {
        $story = $this->storyWithStills();

        $result = app(ComposeThumbnails::class)->handle($story, 2);

        $this->get(route('stories.thumbnail', ['story' => $story, 'key' => $result['composed'][0]['key']]))
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
     * A story framed to order.
     *
     * @param  array<int, ActPhase|null>  $acts  one phase (or null) per act, in sequence
     * @param  array<int, array{0: int, 1: string, 2: int, 3: int|null, 4?: bool, 5?: bool}>  $scenes
     *         [sequence, frame, act index, cast index or null for nobody, flagged = true, has a still = true]
     */
    private function framedStory(array $acts, array $scenes): Story
    {
        // One workspace, so cleanup() knows where the files are; a test that
        // builds two of these in a row replaces the first.
        Story::query()->where('slug', 'thumb-test')->delete();

        $story = Story::factory()->status(StoryStatus::Rendered)->create(['slug' => 'thumb-test']);

        $actModels = [];

        foreach ($acts as $index => $phase) {
            $factory = Act::factory()->for($story)->atSequence($index + 1);
            $actModels[] = ($phase === null ? $factory : $factory->inPhase($phase))->create();
        }

        $cast = [
            Character::factory()->for($story)->create(['name' => 'Lead A']),
            Character::factory()->for($story)->create(['name' => 'Lead B']),
        ];

        foreach ($scenes as $spec) {
            [$sequence, $frame, $actIndex, $castIndex] = $spec;
            $flagged = $spec[4] ?? true;
            $hasStill = $spec[5] ?? true;

            $path = "thumb-test/stills/scene-{$sequence}.jpg";

            if ($hasStill) {
                $this->writeStill(Storage::disk('assets')->path($path), $sequence % 6);
            }

            $who = $castIndex === null ? null : $cast[$castIndex];

            $scene = Scene::factory()->for($story)->create([
                'act_id' => $actModels[$actIndex]->id,
                'sequence' => $sequence,
                'image_path' => $hasStill ? $path : null,
                'image_prompt' => app(ImagePromptBuilder::class)->build(
                    $frame,
                    $who === null ? [] : [$who],
                    $who === null ? [] : [$who->name],
                ),
                'is_thumbnail_candidate' => $flagged,
                'is_hook' => false,
            ]);

            if ($who !== null) {
                $scene->characters()->attach($who->id);
            }
        }

        return $story->refresh();
    }

    /**
     * @param  array<int, array<string, mixed>>  $composed
     * @param  array<int, int>  $sequences
     * @return array<string, mixed>
     */
    private function optionShowing(array $composed, array $sequences): array
    {
        foreach ($composed as $option) {
            if (array_column($option['panels'], 'sequence') === $sequences) {
                return $option;
            }
        }

        $this->fail('No composition shows scenes '.implode(' + ', $sequences).'; the fixture does not hold the state this test needs.');
    }

    /**
     * @param  array<int, array<string, mixed>>  $composed
     * @return array<string, mixed>
     */
    private function optionWithKey(array $composed, string $key): array
    {
        foreach ($composed as $option) {
            if ($option['key'] === $key) {
                return $option;
            }
        }

        $this->fail("No composition carries the key \"{$key}\".");
    }

    /**
     * The per-act scene prompt, off the real builder and with no call made.
     */
    private function scenePrompt(): string
    {
        $story = Story::factory()->create();
        $act = Act::factory()->for($story)->atSequence(1)->inPhase(ActPhase::Escalation)->create();

        $writer = new ClaudeScriptWriter(
            // Never called: only the private prompt builder is invoked.
            client: app(Client::class),
            locale: app(LocaleGuard::class),
            text: app(CharacterTextGuard::class),
        );

        $method = new ReflectionMethod($writer, 'scenePrompt');
        $method->setAccessible(true);

        return (string) $method->invoke($writer, $story, $act, ['One sentence.', 'Another sentence.'], [], 1);
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
