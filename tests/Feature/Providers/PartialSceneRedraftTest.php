<?php

namespace Tests\Feature\Providers;

use App\Actions\DraftScenes;
use App\Enums\StoryStatus;
use App\Models\Act;
use App\Models\Character;
use App\Models\CostEntry;
use App\Models\Scene;
use App\Models\Story;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Re-drafting one act without touching the rest.
 *
 * `--rebuild` throws away every act to fix one, which re-bills the whole story
 * and discards operator edits in five acts that were fine. The narrow version
 * has one hard requirement beyond "don't call the model for the other acts":
 * `(story_id, sequence)` is unique and story-wide, so a re-drafted act whose
 * scene count changed shifts the numbering of every act after it.
 */
class PartialSceneRedraftTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_the_named_act_is_re_drafted(): void
    {
        $story = $this->storyWithScenes();

        $untouched = Scene::where('story_id', $story->id)
            ->whereHas('act', fn ($q) => $q->where('sequence', '!=', 2))
            ->pluck('narration_text', 'id');

        $before = CostEntry::where('story_id', $story->id)->count();

        app(DraftScenes::class)->handle($story, onlyActs: [2]);

        foreach ($untouched as $id => $narration) {
            $this->assertSame(
                $narration,
                Scene::find($id)?->narration_text,
                'A scene outside the named act was re-drafted.'
            );
        }

        // One call for one act. The whole point of the option.
        $this->assertSame(1, CostEntry::where('story_id', $story->id)->count() - $before);
    }

    public function test_the_story_stays_numbered_one_to_n_in_act_order(): void
    {
        $story = $this->storyWithScenes();

        app(DraftScenes::class)->handle($story, onlyActs: [2]);

        $scenes = Scene::where('scenes.story_id', $story->id)
            ->join('acts', 'acts.id', '=', 'scenes.act_id')
            ->orderBy('scenes.sequence')
            ->get(['scenes.sequence', 'acts.sequence as act_sequence']);

        $this->assertSame(
            range(1, $scenes->count()),
            $scenes->pluck('sequence')->map(fn ($s): int => (int) $s)->all(),
            'Numbering is not contiguous from 1.'
        );

        // And act order is preserved: a re-drafted act 2 must not land at the
        // end of the video just because its rows are newest.
        $actOrder = $scenes->pluck('act_sequence')->map(fn ($s): int => (int) $s)->all();
        $sorted = $actOrder;
        sort($sorted);

        $this->assertSame($sorted, $actOrder, 'A re-drafted act moved out of act order.');
    }

    public function test_a_re_draft_of_the_middle_act_renumbers_the_acts_after_it(): void
    {
        $story = $this->storyWithScenes();

        $act3First = Scene::where('story_id', $story->id)
            ->whereHas('act', fn ($q) => $q->where('sequence', 3))
            ->orderBy('sequence')->first();

        app(DraftScenes::class)->handle($story, onlyActs: [2]);

        $act3First->refresh();

        // Act 3's scenes are untouched in content but their positions follow
        // whatever act 2 now contains.
        $act2Count = Scene::where('story_id', $story->id)
            ->whereHas('act', fn ($q) => $q->where('sequence', 2))->count();
        $act1Count = Scene::where('story_id', $story->id)
            ->whereHas('act', fn ($q) => $q->where('sequence', 1))->count();

        $this->assertSame($act1Count + $act2Count + 1, $act3First->sequence);
    }

    public function test_exactly_one_scene_is_the_hook_afterwards(): void
    {
        $story = $this->storyWithScenes();

        app(DraftScenes::class)->handle($story, onlyActs: [2]);

        $hooks = Scene::where('story_id', $story->id)->where('is_hook', true)->get();

        $this->assertCount(1, $hooks);
        $this->assertSame(1, $hooks->first()->sequence);
    }

    public function test_the_cast_is_not_re_extracted(): void
    {
        $story = $this->storyWithScenes();

        $ids = Character::where('story_id', $story->id)->pluck('id')->all();

        app(DraftScenes::class)->handle($story, onlyActs: [2]);

        // Descriptions are pasted verbatim into every prompt in the story.
        // Replacing the cast to fix one act would leave the other acts built
        // from characters that no longer exist.
        $this->assertSame($ids, Character::where('story_id', $story->id)->pluck('id')->all());
    }

    public function test_an_act_the_story_does_not_have_is_refused(): void
    {
        $story = $this->storyWithScenes();

        $this->expectExceptionMessageMatches('/has no act 9/');

        app(DraftScenes::class)->handle($story, onlyActs: [9]);
    }

    private function storyWithScenes(): Story
    {
        $story = Story::factory()->status(StoryStatus::Scripted)->create(['slug' => 'partial-redraft']);

        foreach ([1, 2, 3] as $sequence) {
            Act::factory()->for($story)->atSequence($sequence)->create([
                'script' => "Erin sat at the table in act {$sequence}. Kyle would not look at her. "
                    .'The spreadsheet lay between them. She said the number out loud. '
                    .'Nobody answered her for a while.',
            ]);
        }

        Character::factory()->for($story)->create(['name' => 'Erin Vasquez']);
        Character::factory()->for($story)->create(['name' => 'Kyle Vasquez']);

        app(DraftScenes::class)->handle($story);

        return $story->refresh();
    }
}
