<?php

namespace Tests\Feature;

use App\Enums\StoryStatus;
use App\Models\Act;
use App\Models\Scene;
use App\Models\Story;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The operator's navigation: four gates, always visible, and the app sending
 * you to the one that is actually waiting on you.
 */
class StoryPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_index_lists_stories_and_which_gate_they_are_waiting_at(): void
    {
        $story = $this->story(StoryStatus::ScenesDrafted);

        $this->get(route('stories.index'))
            ->assertOk()
            ->assertSee($story->title)
            ->assertSee('Gate 2');
    }

    public function test_opening_a_story_lands_on_the_gate_that_needs_a_decision(): void
    {
        // A list rather than a keyed array: PHP arrays cannot be keyed by an
        // enum instance.
        $cases = [
            [StoryStatus::Outlined, 'stories.outline'],
            [StoryStatus::ScenesDrafted, 'stories.scenes'],
            [StoryStatus::Rendered, 'stories.preview'],
            [StoryStatus::MetadataReady, 'stories.metadata'],
        ];

        foreach ($cases as [$status, $route]) {
            $story = $this->story($status, 'waiting-'.$status->value);

            $this->get(route('stories.show', $story))
                ->assertRedirect(route($route, $story));
        }
    }

    public function test_a_story_mid_pipeline_lands_on_the_last_gate_it_passed(): void
    {
        // Nothing is waiting on the operator while assets generate, so the
        // useful place to be is the last decision they made.
        $story = $this->story(StoryStatus::AssetsGenerating);

        $this->get(route('stories.show', $story))->assertRedirect(route('stories.scenes', $story));
    }

    public function test_every_gate_page_shows_the_full_stepper_whichever_one_you_are_on(): void
    {
        $story = $this->story(StoryStatus::ScenesDrafted);

        foreach (['outline', 'scenes', 'preview', 'metadata'] as $gate) {
            $response = $this->get(route("stories.{$gate}", $story));

            $response->assertOk();

            // All four, in order, always. It should never be unclear which
            // decision is outstanding or what comes next.
            $response->assertSeeInOrder(['Gate 1', 'Gate 2', 'Gate 3', 'Gate 4']);
            $response->assertSee('waiting on you');
        }
    }

    public function test_a_scene_still_is_served_from_the_non_public_disk(): void
    {
        $story = $this->story(StoryStatus::ScenesDrafted);
        $scene = $story->scenes()->firstOrFail();

        $path = storage_path('app/fixtures/'.$story->slug.'/scene-001.png');

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }

        file_put_contents($path, 'stand-in still');
        $scene->update(['image_path' => $story->slug.'/scene-001.png']);

        $this->get(route('stories.still', ['story' => $story, 'scene' => $scene]))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png');

        unlink($path);
        @rmdir(dirname($path));
    }

    public function test_a_still_from_another_story_is_not_served(): void
    {
        $mine = $this->story(StoryStatus::ScenesDrafted, 'mine');
        $theirs = $this->story(StoryStatus::ScenesDrafted, 'theirs');

        $this->get(route('stories.still', [
            'story' => $mine,
            'scene' => $theirs->scenes()->firstOrFail(),
        ]))->assertNotFound();
    }

    private function story(StoryStatus $status, string $slug = 'nav-test'): Story
    {
        $story = Story::factory()->status($status)->create(['slug' => $slug]);
        $act = Act::factory()->for($story)->atSequence(1)->create();

        Scene::factory()->forAct($act)->atSequence(1)->create();

        return $story;
    }
}
