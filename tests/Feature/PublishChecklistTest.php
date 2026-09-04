<?php

namespace Tests\Feature;

use App\Models\Story;
use App\Models\YoutubeMetadata;
use App\Support\PublishChecklist;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Gate 4 checklist has to state the value it is asking about.
 *
 * The failure it is named for: "Category set" is a tick box that cannot say
 * WHICH category, so the answer lived in the operator's memory and a video was
 * very nearly published under Gaming. An item the sheet cannot answer is
 * unfalsifiable — you can only agree with it.
 *
 * That is the same defect as the item which asked the operator to confirm a
 * scheduled publish time while the app had no column to hold one, and it got
 * the same fix: make the thing exist rather than stop asking.
 */
class PublishChecklistTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_channel_item_states_the_value_to_enter(): void
    {
        $story = Story::factory()->create();

        $values = collect(PublishChecklist::items($story))->keyBy('key');

        // The one that was nearly shipped wrong, and it comes from config
        // rather than from anybody's memory.
        $this->assertSame('People & Blogs', $values['category_set']['value']);

        // Same on every video, and stated rather than recalled.
        $this->assertStringContainsString('en-US', (string) $values['languages_set']['value']);

        // Phrased as the choice the upload form offers, not as a boolean. A
        // sheet that printed "made_for_kids: false" would be technically
        // correct and useless in front of a radio button.
        $this->assertSame("No, it's not made for kids", $values['not_made_for_kids']['value']);
        $this->assertStringContainsString('disclose', (string) $values['synthetic_content_disclosed']['value']);
        $this->assertSame("Don't allow remixing", $values['shorts_remixing_set']['value']);

        foreach (['category_set', 'languages_set', 'not_made_for_kids', 'synthetic_content_disclosed', 'shorts_remixing_set'] as $key) {
            $this->assertTrue($values[$key]['answerable'], "{$key} must always be answerable");
        }
    }

    /** Changing the channel changes the sheet, in one place. */
    public function test_the_sheet_follows_the_channel_config(): void
    {
        config(['youtube.channel.category' => 'Film & Animation']);

        $items = collect(PublishChecklist::items(Story::factory()->create()))->keyBy('key');

        $this->assertSame('Film & Animation', $items['category_set']['value']);
    }

    /**
     * A per-story item with nothing behind it says so. It must never render
     * blank: a blank beside a tick box reads as "nothing needed here", which is
     * absence being taken for agreement in miniature.
     */
    public function test_a_per_story_item_with_no_value_reports_that_rather_than_blank(): void
    {
        $story = Story::factory()->create(['target_publish_at' => null]);
        $metadata = YoutubeMetadata::factory()->for($story)->create(['pinned_comment' => null]);

        $items = collect(PublishChecklist::items($story, $metadata))->keyBy('key');

        $this->assertFalse($items['scheduled_time_confirmed_et']['answerable']);
        $this->assertNull($items['scheduled_time_confirmed_et']['value']);
        $this->assertNotEmpty($items['scheduled_time_confirmed_et']['detail']);

        $this->assertFalse($items['pinned_comment_drafted']['answerable']);
        $this->assertNotEmpty($items['pinned_comment_drafted']['detail']);
    }

    public function test_a_written_pinned_comment_is_shown_on_the_sheet(): void
    {
        $story = Story::factory()->create();
        $metadata = YoutubeMetadata::factory()->for($story)->create([
            'pinned_comment' => 'Eleven years he cooked, drove, and paid for a house.',
        ]);

        $items = collect(PublishChecklist::items($story, $metadata))->keyBy('key');

        $this->assertTrue($items['pinned_comment_drafted']['answerable']);
        $this->assertStringContainsString('Eleven years', (string) $items['pinned_comment_drafted']['value']);
    }

    /**
     * The generalisation that was the point of the refactor.
     *
     * The warning existed for exactly one item, hand-written, while the same
     * reasoning applied to every per-story one — so a tick certifying a pinned
     * comment that did not exist passed silently. Asking one question of all of
     * them means the next per-story item is covered by construction.
     */
    public function test_a_tick_against_a_missing_value_is_reported_for_every_per_story_item(): void
    {
        $story = Story::factory()->create(['target_publish_at' => null]);
        $metadata = YoutubeMetadata::factory()->for($story)->create([
            'pinned_comment' => null,
            'checklist_state' => [
                'scheduled_time_confirmed_et' => true,
                'pinned_comment_drafted' => true,
            ],
        ]);

        $problems = PublishChecklist::ticksWithNothingBehindThem($story, $metadata);

        $this->assertCount(2, $problems);
        $this->assertStringContainsString('Scheduled publish time', implode(' ', $problems));
        $this->assertStringContainsString('Pinned comment', implode(' ', $problems));
    }

    /** A channel item can never trip that warning: it is always answerable. */
    public function test_ticking_a_channel_item_is_never_a_problem(): void
    {
        $story = Story::factory()->create(['target_publish_at' => now()->addDays(2)]);
        $metadata = YoutubeMetadata::factory()->for($story)->create([
            'pinned_comment' => 'Pinned.',
            'checklist_state' => [
                'category_set' => true,
                'languages_set' => true,
                'shorts_remixing_set' => true,
                'not_made_for_kids' => true,
                'synthetic_content_disclosed' => true,
                'scheduled_time_confirmed_et' => true,
                'pinned_comment_drafted' => true,
            ],
        ]);

        $this->assertSame([], PublishChecklist::ticksWithNothingBehindThem($story, $metadata));
    }
}
