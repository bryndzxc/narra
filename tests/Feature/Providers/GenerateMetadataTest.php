<?php

namespace Tests\Feature\Providers;

use App\Actions\GenerateMetadata;
use App\Enums\MetadataStatus;
use App\Enums\StoryStatus;
use App\Models\Act;
use App\Models\CostEntry;
use App\Models\Story;
use App\Models\YoutubeMetadata;
use App\Services\Fake\FakeMetadataWriter;
use App\Support\Providers\ScriptWriterException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * The publish-sheet generator.
 *
 * Two kinds of rule are tested here and they fail differently. The YouTube
 * limits — title length, tag budget, overlay word count — fail SILENTLY on
 * upload, so the test is that the app refuses to produce them. The sequencing
 * rules — chapters need a render, spending needs a reason — fail expensively,
 * so the test is that the refusal happens BEFORE the calls rather than after.
 */
class GenerateMetadataTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_writes_five_titles_and_leaves_the_pick_to_the_operator(): void
    {
        $story = $this->renderedStory();

        app(GenerateMetadata::class)->handle($story);

        $sheet = YoutubeMetadata::query()->firstOrFail();

        $this->assertCount(5, $sheet->title_options);

        // The whole point of Gate 4. A generator that pre-picked the title
        // while leaving the button labelled "approve" would have automated the
        // gate away in everything but name.
        $this->assertNull($sheet->title_selected);
        $this->assertSame(MetadataStatus::Generated, $sheet->status);
        $this->assertNotEmpty($sheet->tags);
        $this->assertNotEmpty($sheet->thumbnail_text_options);
        $this->assertNotNull($sheet->pinned_comment);
    }

    public function test_the_description_is_a_hook_then_the_derived_chapters_then_the_footer(): void
    {
        $story = $this->renderedStory();

        app(GenerateMetadata::class)->handle($story);

        $description = (string) YoutubeMetadata::query()->firstOrFail()->description;

        $this->assertStringContainsString('Chapters:', $description);
        $this->assertStringContainsString('0:00 Act one', $description);
        $this->assertStringContainsString('10:00 Act two', $description);

        // The hook comes first: it is the search snippet, and everything below
        // the chapter list is boilerplate nobody reads.
        $this->assertLessThan(
            strpos($description, 'Chapters:'),
            strpos($description, 'She told me it was temporary')
        );

        $this->assertStringContainsString('AI-assisted', $description);
    }

    public function test_it_refuses_to_spend_when_the_acts_cannot_make_a_legal_chapter_list(): void
    {
        // Two acts. YouTube ignores a chapter list shorter than three — no
        // error, no chapters — so a sheet written against this would be copy
        // paid for and unusable.
        $story = Story::factory()->status(StoryStatus::Rendered)->create();
        Act::factory()->for($story)->atSequence(1)->timed(0, 600_000)->create(['title' => 'Act one']);
        Act::factory()->for($story)->atSequence(2)->timed(600_000, 600_000)->create(['title' => 'Act two']);

        try {
            app(GenerateMetadata::class)->handle($story);
            $this->fail('Expected a refusal.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('chapter list', $e->getMessage());
        }

        // Upstream of the thing it distrusts: no call was made, so no row.
        $this->assertSame(0, CostEntry::query()->count());
        $this->assertSame([], app(FakeMetadataWriter::class)->calls);
    }

    public function test_it_refuses_before_the_render_because_chapters_do_not_exist_yet(): void
    {
        $story = Story::factory()->status(StoryStatus::AssetsReady)->create();
        Act::factory()->for($story)->atSequence(1)->create(['title' => 'Act one']);

        $this->expectException(RuntimeException::class);

        try {
            app(GenerateMetadata::class)->handle($story);
        } finally {
            $this->assertSame(0, CostEntry::query()->count());
        }
    }

    public function test_a_title_past_the_hard_limit_is_dropped_rather_than_offered(): void
    {
        $story = $this->renderedStory();

        $fake = app(FakeMetadataWriter::class);
        $fake->titleOverride = [
            'A title that fits',
            str_repeat('a', 101),
            'Another title that fits',
        ];

        $notes = app(GenerateMetadata::class)->handle($story);

        $sheet = YoutubeMetadata::query()->firstOrFail();

        $this->assertCount(2, $sheet->title_options);
        $this->assertNotContains(str_repeat('a', 101), $sheet->title_options);
        $this->assertNotEmpty(array_filter($notes, fn (string $n): bool => str_contains($n, 'hard limit')));
    }

    /** Every title over the hard limit refuses the sheet as a refused output of the stage. */
    public function test_a_set_with_every_title_past_the_hard_limit_is_a_refused_output(): void
    {
        $story = $this->renderedStory();

        app(FakeMetadataWriter::class)->titleOverride = [str_repeat('a', 101), str_repeat('b', 102)];

        try {
            app(GenerateMetadata::class)->handle($story);
            $this->fail('A sheet with no usable title must be refused.');
        } catch (\App\Support\Providers\ScriptWriterException $e) {
            $this->assertStringNotContainsString('Re-run', $e->getMessage(), 'The move is built at display time, not stored.');
            $this->assertSame(\App\Enums\FailureKind::OutputRefused, $e->failureKind());
            $this->assertSame(['stage' => 'metadata', 'check' => 'titles_over_limit'], $e->failureFacts());
        }
    }

    public function test_a_set_where_every_title_is_over_the_visible_length_is_reported(): void
    {
        // The failure this guard is for, and it is a real one: the first live
        // run against a real story came back with five variants of 82-94
        // characters. Every one legal, every one past the point the tail stops
        // being visible, and nothing to pick between. Asking the prompt for a
        // spread is not evidence that it produced one.
        $story = $this->renderedStory();

        app(FakeMetadataWriter::class)->titleOverride = array_map(
            fn (int $i): string => str_pad("A long title number {$i} ", 85, 'x'),
            range(1, 5)
        );

        $notes = app(GenerateMetadata::class)->handle($story);

        $this->assertNotEmpty(array_filter(
            $notes,
            fn (string $n): bool => str_contains($n, 'visible length')
        ));

        // A warning, not a block. Long titles are a decision an operator may
        // legitimately make, and the sheet is still written.
        $this->assertCount(5, YoutubeMetadata::query()->firstOrFail()->title_options);
    }

    public function test_a_spread_of_lengths_is_not_reported(): void
    {
        $story = $this->renderedStory();

        app(FakeMetadataWriter::class)->titleOverride = [
            'Short enough to survive truncation',
            str_pad('A long one ', 85, 'x'),
        ];

        $notes = app(GenerateMetadata::class)->handle($story);

        $this->assertEmpty(array_filter(
            $notes,
            fn (string $n): bool => str_contains($n, 'visible length')
        ));
    }

    public function test_the_tag_budget_drops_whole_tags_and_never_cuts_one(): void
    {
        $story = $this->renderedStory();

        // Twelve 60-character tags is 731 characters with separators — well
        // past the 500 budget, and every tag individually legal.
        $proposed = array_map(
            fn (int $i): string => str_pad("tag {$i} ", 60, 'x'),
            range(1, 12)
        );

        app(FakeMetadataWriter::class)->tagOverride = $proposed;

        $notes = app(GenerateMetadata::class)->handle($story);

        $sheet = YoutubeMetadata::query()->firstOrFail();

        $this->assertLessThanOrEqual(500, $sheet->tags_char_count);
        $this->assertNotEmpty($sheet->tags);

        // Enforced, not truncated: every surviving tag is one the model wrote,
        // character for character. A tag cut mid-word is a different tag.
        foreach ($sheet->tags as $tag) {
            $this->assertContains($tag, $proposed, 'A tag was altered rather than dropped.');
        }

        $this->assertNotEmpty(array_filter($notes, fn (string $n): bool => str_contains($n, 'dropped whole')));
    }

    public function test_an_overlay_phrase_too_long_to_read_is_dropped(): void
    {
        $story = $this->renderedStory();

        app(FakeMetadataWriter::class)->thumbnailOverride = [
            'FOUR YEARS OF PAYMENTS',
            'SHE CHANGED THE LOCKS ON THE HOUSE I WAS STILL PAYING FOR',
            'THEN I SAID THE NUMBER',
        ];

        $notes = app(GenerateMetadata::class)->handle($story);

        $overlay = YoutubeMetadata::query()->firstOrFail()->thumbnail_text_options;

        $this->assertCount(2, $overlay);
        $this->assertNotEmpty(array_filter($notes, fn (string $n): bool => str_contains($n, 'thumbnail phrase')));
    }

    public function test_every_call_writes_a_cost_row_even_though_the_fake_is_free(): void
    {
        $story = $this->renderedStory();

        app(GenerateMetadata::class)->handle($story);

        $entries = CostEntry::query()->orderBy('id')->get();

        // Three calls, three rows. "This cost nothing" and "nobody recorded
        // what this cost" must not look the same in the ledger.
        $this->assertSame(
            ['generate_titles', 'generate_copy', 'generate_tags'],
            $entries->pluck('operation')->all()
        );

        $this->assertTrue($entries->every(fn (CostEntry $e): bool => (bool) $e->simulated));
        $this->assertSame(0.0, (float) $entries->sum('usd_cost'));
    }

    public function test_re_running_refuses_unless_forced(): void
    {
        $story = $this->renderedStory();

        app(GenerateMetadata::class)->handle($story);

        try {
            app(GenerateMetadata::class)->handle($story);
            $this->fail('Expected a refusal.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('--force', $e->getMessage());
        }

        // Three rows from the first run, none from the second. Nothing is
        // regenerated silently, because re-running costs money.
        $this->assertSame(3, CostEntry::query()->count());

        app(GenerateMetadata::class)->handle($story, force: true);

        $this->assertSame(6, CostEntry::query()->count());
    }

    public function test_a_stale_sheet_cannot_be_rewritten_until_the_story_is_re_rendered(): void
    {
        $story = $this->renderedStory();
        app(GenerateMetadata::class)->handle($story);

        $sheet = YoutubeMetadata::query()->firstOrFail();
        $sheet->markStale();

        // Writing fresh copy would clear the mark while the chapter timestamps
        // stayed wrong, which is exactly what the mark exists to prevent.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/re-render/i');

        app(GenerateMetadata::class)->handle($story->fresh(), force: true);
    }

    public function test_a_locale_leak_fails_the_stage_after_the_cost_row_is_written(): void
    {
        $story = $this->renderedStory();

        app(FakeMetadataWriter::class)->injectIntoTitles = 'Ay naku, my brother took the house';

        try {
            app(GenerateMetadata::class)->handle($story);
            $this->fail('Expected a locale violation.');
        } catch (\Throwable $e) {
            $this->assertNotInstanceOf(ScriptWriterException::class, $e);
        }

        // The call was billed before the check ran, and a cost table that drops
        // the rows for failed stages cannot answer what a video cost.
        $this->assertSame(1, CostEntry::query()->count());
        $this->assertNull(YoutubeMetadata::query()->first()?->title_options);
    }

    private function renderedStory(): Story
    {
        $story = Story::factory()->status(StoryStatus::Rendered)->create(['slug' => 'sheet-test']);

        foreach ([['Act one', 0], ['Act two', 600_000], ['Act three', 1_200_000]] as $i => [$title, $start]) {
            Act::factory()->for($story)->atSequence($i + 1)->timed($start, 600_000)->create(['title' => $title]);
        }

        return $story->refresh();
    }
}
