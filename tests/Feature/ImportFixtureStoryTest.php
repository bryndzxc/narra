<?php

namespace Tests\Feature;

use App\Actions\ImportFixtureStory;
use App\Enums\Gate;
use App\Enums\SceneStatus;
use App\Enums\StoryStatus;
use App\Models\Story;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * The bridge from Phase 0's files to Phase 1's rows.
 *
 * Worth testing for two reasons beyond "it copies data": it must walk the gate
 * machine rather than write a status, and it must NOT import the fixture's act
 * timings — those are render output, and a chapter built from a guess is a
 * chapter that points at the wrong minute.
 */
#[Group('render')]
class ImportFixtureStoryTest extends TestCase
{
    use RefreshDatabase;

    private const FIXTURE = 'sample-story';

    protected function setUp(): void
    {
        parent::setUp();

        if (! Storage::disk('fixtures')->exists(self::FIXTURE.'/timings.json')) {
            $this->markTestSkipped('Fixture set missing. Run `php artisan fixtures:make` first.');
        }
    }

    public function test_a_fixture_becomes_a_story_ready_to_render(): void
    {
        $result = app(ImportFixtureStory::class)->handle(self::FIXTURE);

        $story = $result['story'];

        $this->assertSame(self::FIXTURE, $story->slug);
        $this->assertSame(StoryStatus::AssetsReady, $story->status);
        $this->assertSame(3, $story->acts()->count());
        $this->assertSame(12, $story->scenes()->count());
        $this->assertFalse($result['reimported']);

        $scene = $story->scenes()->where('sequence', 1)->firstOrFail();

        $this->assertTrue($scene->is_hook);
        $this->assertSame(SceneStatus::Ready, $scene->status);
        $this->assertSame(self::FIXTURE.'/scene-001.png', $scene->image_path);
        $this->assertNotNull($scene->duration_ms);

        $audio = $scene->sceneAudio()->firstOrFail();

        $this->assertSame(self::FIXTURE.'/scene-001.mp3', $audio->audio_path);
        $this->assertNotEmpty($audio->timings_json);
        $this->assertArrayHasKey('start_ms', $audio->timings_json[0]);
    }

    public function test_the_import_walks_the_gates_rather_than_writing_a_status(): void
    {
        $story = app(ImportFixtureStory::class)->handle(self::FIXTURE)['story'];

        // Past both free gates, because a fixture arrives with its assets made
        // — and therefore permitted to spend money, which is the whole point of
        // that line.
        $this->assertTrue($story->hasPassedGate(Gate::Outline));
        $this->assertTrue($story->hasPassedGate(Gate::Scenes));
        $this->assertTrue($story->canGeneratePaidAssets());

        // And nowhere near the gates that need a human to look at something.
        $this->assertFalse($story->hasPassedGate(Gate::Preview));
        $this->assertFalse($story->hasPassedGate(Gate::Metadata));
    }

    public function test_act_timings_are_left_for_the_render_to_fill_in(): void
    {
        $story = app(ImportFixtureStory::class)->handle(self::FIXTURE)['story'];

        foreach ($story->acts as $act) {
            $this->assertNull($act->start_ms, 'Act timings must come from the render, not the fixture.');
            $this->assertNull($act->duration_ms);
            $this->assertNull($act->chapterTimestamp());
        }
    }

    public function test_reimporting_updates_in_place(): void
    {
        app(ImportFixtureStory::class)->handle(self::FIXTURE);
        $second = app(ImportFixtureStory::class)->handle(self::FIXTURE);

        $this->assertTrue($second['reimported']);
        $this->assertSame(1, Story::query()->count());
        $this->assertSame(12, Story::query()->firstOrFail()->scenes()->count());
        $this->assertDatabaseCount('scene_audio', 12);
    }
}
