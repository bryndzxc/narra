<?php

namespace Tests\Feature\Gates;

use App\Enums\Gate;
use App\Enums\MetadataStatus;
use App\Enums\StoryStatus;
use App\Livewire\Gates\MetadataGate;
use App\Models\Act;
use App\Models\Story;
use App\Models\YoutubeMetadata;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Gate 4 — the publish sheet.
 *
 * Every rule here is one that fails silently on YouTube: a title that truncates,
 * a tag list cut at 500 characters, a chapter list ignored because the first one
 * is not at 00:00. The point of enforcing them in code is that the operator
 * finds out here rather than a week later in the analytics.
 */
class MetadataGateTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_sheet_starts_blocked_and_says_exactly_why(): void
    {
        $story = $this->storyReadyForMetadata();

        $validation = Livewire::test(MetadataGate::class, ['story' => $story])->instance()->validation();

        $this->assertContains('No title selected.', $validation['blocking']);
        $this->assertNotEmpty(array_filter(
            $validation['blocking'],
            fn (string $problem): bool => str_contains($problem, 'Description is empty')
        ));
    }

    public function test_the_sheet_can_be_drafted_before_gate_three_but_not_approved(): void
    {
        // Drafting early is useful; approving early would mean publishing a
        // video nobody has watched. `rendered -> metadata_ready` is Gate 3's
        // crossing, and typing a title is not a substitute for watching.
        $story = $this->storyReadyForMetadata(StoryStatus::Rendered);

        $component = Livewire::test(MetadataGate::class, ['story' => $story])
            ->set('titleSelected', 'She Worked There Nineteen Years. Nobody Told Her It Was Closing.')
            ->call('save');

        $this->assertSame(StoryStatus::Rendered, $story->fresh()->status);
        $this->assertSame(MetadataStatus::Generated, YoutubeMetadata::query()->firstOrFail()->status);
        $this->assertFalse($component->instance()->canApprove());
    }

    public function test_a_title_past_the_hard_limit_is_refused_before_it_reaches_the_column(): void
    {
        // title_selected is varchar(100) because 100 is YouTube's limit. Without
        // a check here the operator would get a MySQL truncation error instead
        // of a sentence.
        $story = $this->storyReadyForMetadata();

        Livewire::test(MetadataGate::class, ['story' => $story])
            ->set('titleSelected', str_repeat('a', 101))
            ->call('save')
            ->assertHasErrors('titleSelected');

        $this->assertNull(YoutubeMetadata::query()->firstOrFail()->title_selected);
    }

    public function test_a_title_past_the_visible_length_is_a_warning_not_a_block(): void
    {
        // 100 is the limit; 70 is where the tail stops being visible. The
        // operator may legitimately decide to live with a long one.
        $story = $this->storyReadyForMetadata();

        $validation = Livewire::test(MetadataGate::class, ['story' => $story])
            ->set('titleSelected', str_repeat('a', 85))
            ->call('save')
            ->instance()
            ->validation();

        $this->assertEmpty(array_filter(
            $validation['blocking'],
            fn (string $p): bool => str_contains($p, 'Title')
        ));
        $this->assertNotEmpty(array_filter(
            $validation['warnings'],
            fn (string $p): bool => str_contains($p, 'hook is on the left')
        ));
    }

    public function test_the_tag_budget_is_enforced_rather_than_truncated(): void
    {
        $story = $this->storyReadyForMetadata();

        $component = Livewire::test(MetadataGate::class, ['story' => $story])
            ->set('tagsInput', implode(', ', array_map(
                fn (int $i): string => 'a-fairly-long-tag-phrase-'.$i,
                range(1, 30)
            )))
            ->call('save');

        $budget = $component->instance()->tagBudget();

        $this->assertGreaterThan(500, $budget['used']);
        $this->assertGreaterThan(0, $budget['over']);
        $this->assertNotEmpty(array_filter(
            $component->instance()->validation()['blocking'],
            fn (string $p): bool => str_contains($p, 'the budget is 500')
        ));
    }

    public function test_rebuilding_the_description_is_idempotent(): void
    {
        // Pressing it twice must not stack two chapter lists into a 5,000
        // character budget.
        $story = $this->storyReadyForMetadata();

        $component = Livewire::test(MetadataGate::class, ['story' => $story])
            ->set('description', 'She read the sign twice before she understood it.')
            ->call('insertChapters');

        $once = $component->get('description');

        $component->call('insertChapters');

        $this->assertSame($once, $component->get('description'));
        $this->assertSame(1, substr_count($once, 'Chapters:'));
        $this->assertStringContainsString('0:00 Act one', $once);
        $this->assertStringContainsString('10:00 Act two', $once);
        $this->assertStringContainsString('AI-assisted', $once);
    }

    public function test_chapters_that_break_youtubes_rules_block_approval(): void
    {
        $story = $this->storyReadyForMetadata();

        // A first chapter that does not start at 00:00 makes YouTube ignore the
        // whole list — no error, no chapters, and no way to notice by looking.
        $story->acts()->where('sequence', 1)->update(['start_ms' => 5_000]);

        $validation = Livewire::test(MetadataGate::class, ['story' => $story])->instance()->validation();

        $this->assertContains('First chapter must start at 00:00.', $validation['blocking']);
    }

    public function test_a_chapter_shorter_than_ten_seconds_blocks_approval(): void
    {
        $story = $this->storyReadyForMetadata();
        $story->acts()->where('sequence', 2)->update(['start_ms' => 5_000]);

        $validation = Livewire::test(MetadataGate::class, ['story' => $story])->instance()->validation();

        $this->assertNotEmpty(array_filter(
            $validation['blocking'],
            fn (string $p): bool => str_contains($p, 'shorter than 10 seconds')
        ));
    }

    public function test_the_required_checklist_items_block_approval(): void
    {
        $story = $this->storyReadyForMetadata();

        $component = $this->completeSheet($story);

        // Everything else is filled in; only the two obligations outside this
        // app are missing.
        $component->set('checklist.synthetic_content_disclosed', false)->call('save');

        $blocking = $component->instance()->validation()['blocking'];

        $this->assertNotEmpty(array_filter(
            $blocking,
            fn (string $p): bool => str_contains($p, 'synthetic content')
        ));

        $component->call('approve')->assertStatus(422);
        $this->assertNotSame(StoryStatus::Published, $story->fresh()->status);
    }

    public function test_a_complete_sheet_can_be_approved_and_that_is_the_end_of_it(): void
    {
        $story = $this->storyReadyForMetadata();

        $this->completeSheet($story)->call('approve');

        $story->refresh();

        $this->assertSame(StoryStatus::Published, $story->status);
        $this->assertTrue($story->hasPassedGate(Gate::Metadata));
        $this->assertSame(MetadataStatus::Approved, YoutubeMetadata::query()->firstOrFail()->status);

        // Terminal. The upload is a human's job, and there is nothing after it
        // in this app.
        $this->assertSame([], $story->status->allowedTransitions());
    }

    public function test_a_published_sheet_is_read_only(): void
    {
        $story = $this->storyReadyForMetadata();
        $this->completeSheet($story)->call('approve');

        Livewire::test(MetadataGate::class, ['story' => $story->fresh()])
            ->set('titleSelected', 'Changed after publishing')
            ->call('save')
            ->assertForbidden();
    }

    private function completeSheet(Story $story): Testable
    {
        return Livewire::test(MetadataGate::class, ['story' => $story])
            ->set('titleSelected', 'She Worked There Nineteen Years')
            ->set('description', 'She read the sign twice before she understood it.')
            ->set('tagsInput', 'true story, small town, closing time')
            ->set('thumbnailTextInput', "NOBODY TOLD HER\nNINETEEN YEARS")
            ->set('pinnedComment', 'Part two is written. Tell me if you want it.')
            ->set('checklist.synthetic_content_disclosed', true)
            ->set('checklist.not_made_for_kids', true)
            ->call('insertChapters')
            ->call('save');
    }

    private function storyReadyForMetadata(StoryStatus $status = StoryStatus::MetadataReady): Story
    {
        $story = Story::factory()->status($status)->create(['slug' => 'gate-four']);

        foreach ([['Act one', 0], ['Act two', 600_000], ['Act three', 1_200_000]] as $i => [$title, $start]) {
            Act::factory()->for($story)->atSequence($i + 1)->timed($start, 600_000)->create(['title' => $title]);
        }

        return $story;
    }
}
