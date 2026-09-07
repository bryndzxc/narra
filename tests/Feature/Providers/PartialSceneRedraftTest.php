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

    /**
     * THE FIXTURE ABOVE CANNOT EXPRESS THE FAILURE, AND FOR A PHASE IT HID IT.
     *
     * `storyWithScenes()` gives each of its three acts a ~30-word script, which
     * is one scene apiece. That is what made every case in this file green about
     * a method that failed on every real story it was ever pointed at.
     *
     * The collision: persist() parks the NEW rows at PARK_BASE+1..PARK_BASE+N,
     * then renumberByAct() parked EVERYTHING at PARK_BASE+$index — so the
     * untouched earlier acts' scenes were assigned indices 1..N, which is the
     * band the new rows were still occupying. At one scene per act the two
     * bands are one number wide and the only row assigned PARK_BASE+1 is the
     * row already sitting there, so the update is a no-op and nothing collides.
     *
     * Give the acts more than one scene each and it fails immediately. Story 12
     * died on `Duplicate entry '12-30001'` after billing two model calls for the
     * act it then rolled back — the act had 27 scenes and the two acts before it
     * had 57.
     *
     * This is the fifth time in this codebase that a check was correct and the
     * input it was handed could not contain the defect. Written as its own case
     * rather than by widening the shared fixture, so that what it needs — MANY
     * SCENES PER ACT — is stated where it is relied on.
     */
    public function test_a_partial_redraft_survives_acts_with_more_than_one_scene(): void
    {
        $story = $this->storyWithScenes(scenesPerAct: 6);

        $before = Scene::where('story_id', $story->id)->count();

        $this->assertGreaterThan(
            3,
            $before,
            'This case is vacuous unless the acts carry several scenes each — that is the '
            .'whole difference between it and the cases above.',
        );

        app(DraftScenes::class)->handle($story, onlyActs: [2]);

        $sequences = Scene::where('scenes.story_id', $story->id)
            ->join('acts', 'acts.id', '=', 'scenes.act_id')
            ->orderBy('acts.sequence')
            ->orderBy('scenes.sequence')
            ->pluck('scenes.sequence')
            ->all();

        $this->assertSame(
            range(1, count($sequences)),
            $sequences,
            'After a partial re-draft the story must be numbered 1..n in act order.',
        );
    }

    private function storyWithScenes(int $scenesPerAct = 1): Story
    {
        $story = Story::factory()->status(StoryStatus::Scripted)->create(['slug' => 'partial-redraft']);

        // `scenes.words_per_scene` is 30, and the target is words/30 — so the
        // script is grown in whole 30-word blocks to ask for a known number of
        // scenes per act. The default of 1 preserves every case written against
        // this fixture before the multi-scene one existed.
        $block = 'Erin sat at the table and said the number out loud to Kyle across the '
            .'spreadsheet while nobody in the room answered her for a long while afterwards. ';

        foreach ([1, 2, 3] as $sequence) {
            Act::factory()->for($story)->atSequence($sequence)->create([
                'script' => "Erin sat at the table in act {$sequence}. Kyle would not look at her. "
                    .'The spreadsheet lay between them. She said the number out loud. '
                    .'Nobody answered her for a while. '
                    .str_repeat($block, max(0, $scenesPerAct - 1)),
            ]);
        }

        Character::factory()->for($story)->create(['name' => 'Erin Vasquez']);
        Character::factory()->for($story)->create(['name' => 'Kyle Vasquez']);

        app(DraftScenes::class)->handle($story);

        return $story->refresh();
    }
}
