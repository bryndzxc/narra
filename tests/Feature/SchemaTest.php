<?php

namespace Tests\Feature;

use App\Enums\AssetStatus;
use App\Enums\MotionPreset;
use App\Enums\RenderJobStatus;
use App\Enums\RenderStage;
use App\Models\Act;
use App\Models\AudioTrack;
use App\Models\Character;
use App\Models\RenderJob;
use App\Models\Scene;
use App\Models\SceneAudio;
use App\Models\Story;
use App\Models\YoutubeMetadata;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The schema, exercised through the models and factories that will drive the
 * Phase 1 review UI.
 *
 * The point of these is less "does Eloquent work" and more "do the pieces the
 * spec insists on actually hold" — integer offsets, derived clip lengths,
 * chapters that come from acts rather than a second copy, and a heartbeat that
 * can surface a hung worker on a platform with no pcntl.
 */
class SchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_story_holds_the_whole_graph(): void
    {
        $story = Story::factory()->paidAssetsUnlocked()->create();
        $act = Act::factory()->for($story)->atSequence(1)->create();

        Scene::factory()->forAct($act)->atSequence(1)->hook()->create();
        Scene::factory()->forAct($act)->atSequence(2)->create();
        Character::factory()->for($story)->locked()->create();
        AudioTrack::factory()->for($story)->ready()->create();
        RenderJob::factory()->for($story)->succeeded()->create();
        YoutubeMetadata::factory()->for($story)->generated()->create();

        $story->refresh();

        $this->assertCount(1, $story->acts);
        $this->assertCount(2, $story->scenes);
        $this->assertCount(1, $story->characters);
        $this->assertCount(1, $story->audioTracks);
        $this->assertCount(1, $story->renderJobs);
        $this->assertNotNull($story->youtubeMetadata);
        $this->assertTrue($story->characters->first()->isLocked());
    }

    public function test_deleting_a_story_takes_its_graph_with_it(): void
    {
        $story = Story::factory()->paidAssetsUnlocked()->create();
        $act = Act::factory()->for($story)->create();
        $scene = Scene::factory()->forAct($act)->create();
        $track = AudioTrack::factory()->for($story)->create();
        SceneAudio::factory()->for($scene)->for($track)->create();

        $story->delete();

        $this->assertDatabaseCount('acts', 0);
        $this->assertDatabaseCount('scenes', 0);
        $this->assertDatabaseCount('audio_tracks', 0);
        $this->assertDatabaseCount('scene_audio', 0);
    }

    public function test_a_scene_sequence_is_unique_within_a_story(): void
    {
        $story = Story::factory()->create();
        $act = Act::factory()->for($story)->create();

        Scene::factory()->forAct($act)->atSequence(7)->create();

        $this->expectException(QueryException::class);

        Scene::factory()->forAct($act)->atSequence(7)->create();
    }

    public function test_clip_length_is_derived_from_audio_and_never_stored_twice(): void
    {
        // 12,867 ms at 30fps is 386.01 frames. ceil gives 387, and the clip is
        // exactly 387/30 s by construction — the audio is padded up to meet it,
        // so padding only ever adds silence and never trims a word.
        $scene = Scene::factory()->create(['duration_ms' => 12867]);

        $this->assertSame(387, $scene->framesAt(30));

        // The stored duration is the RAW AUDIO. Nothing stores the clip length.
        $this->assertSame(12867, $scene->duration_ms);
    }

    public function test_scene_offsets_are_stored_as_integers_in_frames_and_samples(): void
    {
        $scene = Scene::factory()->create();
        $track = AudioTrack::factory()->create();

        $audio = SceneAudio::factory()
            ->for($scene)
            ->for($track)
            ->placedAt(durationMs: 12867, offsetFrames: 1200)
            ->create();

        $this->assertSame(387, $audio->frames);
        $this->assertSame(1200, $audio->offset_frames);

        // 1470 samples per frame at 44.1 kHz / 30 fps, exactly. This is the
        // number the subtitle shift reads — never offset_ms.
        $this->assertSame(1200 * 1470, $audio->offset_samples);

        // Derived, and rounded. Display only.
        $this->assertSame(40000, $audio->offset_ms);

        // Padding is at most one frame: inaudible, and spread across scenes
        // rather than pooled at the end.
        $this->assertLessThanOrEqual(34, $audio->paddingMs());
        $this->assertGreaterThanOrEqual(0, $audio->paddingMs());
    }

    public function test_one_scene_gets_one_row_per_audio_track(): void
    {
        // Jobs are idempotent. A second row for the same scene and track would
        // mean the narration is in the timeline twice.
        $scene = Scene::factory()->create();
        $track = AudioTrack::factory()->create();

        SceneAudio::factory()->for($scene)->for($track)->create();

        $this->expectException(QueryException::class);

        SceneAudio::factory()->for($scene)->for($track)->create();
    }

    public function test_a_story_supports_several_audio_tracks(): void
    {
        // Phase 0 writes one. The schema takes N from day one so localisation
        // is a feature rather than a migration.
        $story = Story::factory()->create();

        AudioTrack::factory()->for($story)->ready()->create();
        AudioTrack::factory()->for($story)->language('es-MX')->create();

        $this->assertCount(2, $story->refresh()->audioTracks);
    }

    public function test_chapters_are_derived_from_acts_rather_than_stored(): void
    {
        $story = Story::factory()->rendered()->create();

        Act::factory()->for($story)->atSequence(1)->timed(0, 600_000)->create(['title' => 'The Call']);
        Act::factory()->for($story)->atSequence(2)->timed(600_000, 660_000)->create(['title' => 'The Descent']);
        Act::factory()->for($story)->atSequence(3)->timed(1_260_000, 720_000)->create(['title' => 'The Cost']);

        $metadata = YoutubeMetadata::factory()->for($story)->generated()->create();

        $chapters = $metadata->chapters();

        $this->assertCount(3, $chapters);
        $this->assertSame('0:00', $chapters[0]['timestamp']);
        $this->assertSame('10:00', $chapters[1]['timestamp']);
        $this->assertSame('21:00', $chapters[2]['timestamp']);
        $this->assertSame('The Descent', $chapters[1]['title']);

        // No chapters table, no chapter columns — one answer to the question.
        $this->assertFalse(Schema::hasTable('chapters'));
    }

    public function test_chapters_are_empty_until_the_render_has_timed_the_acts(): void
    {
        // Metadata runs after the render for exactly this reason.
        $story = Story::factory()->rendered()->create();
        Act::factory()->for($story)->atSequence(1)->create();

        $this->assertSame([], YoutubeMetadata::factory()->for($story)->create()->chapters());
    }

    public function test_the_tag_character_count_is_derived_from_the_tags(): void
    {
        $metadata = YoutubeMetadata::factory()->create(['tags' => ['ghost story', 'true horror']]);

        // 11 + 11 characters plus one separator.
        $this->assertSame(23, $metadata->tags_char_count);
        $this->assertFalse($metadata->exceedsTagBudget());

        $over = YoutubeMetadata::factory()->overTagBudget()->create();

        $this->assertTrue($over->exceedsTagBudget());
        $this->assertGreaterThan(YoutubeMetadata::TAGS_CHAR_BUDGET, $over->tags_char_count);
    }

    public function test_losing_the_thumbnail_scene_does_not_take_the_publish_sheet(): void
    {
        $story = Story::factory()->rendered()->create();
        $act = Act::factory()->for($story)->create();
        $scene = Scene::factory()->forAct($act)->thumbnailCandidate()->create();

        $metadata = YoutubeMetadata::factory()->for($story)->generated()
            ->create(['thumbnail_scene_id' => $scene->id]);

        $scene->delete();

        $this->assertNull($metadata->fresh()->thumbnail_scene_id);
        $this->assertDatabaseCount('youtube_metadata', 1);
    }

    public function test_a_running_job_with_a_silent_heartbeat_is_stale(): void
    {
        // The only signal there is. `queue:work --timeout` uses a pcntl alarm,
        // and pcntl does not exist in Windows PHP, so a hung FFmpeg occupies a
        // worker forever with nothing else reporting it.
        $healthy = RenderJob::factory()->running()->create();
        $hung = RenderJob::factory()->stale()->create();

        $this->assertFalse($healthy->isStale());
        $this->assertTrue($hung->isStale());

        $stale = RenderJob::query()->stale()->pluck('id');

        $this->assertTrue($stale->contains($hung->id));
        $this->assertFalse($stale->contains($healthy->id));
    }

    public function test_a_heartbeat_clears_the_stale_flag(): void
    {
        $job = RenderJob::factory()->stale()->create();

        $this->assertTrue($job->isStale());

        Carbon::setTestNow(Carbon::now());
        $job->heartbeat();

        $this->assertFalse($job->fresh()->isStale());

        Carbon::setTestNow();
    }

    public function test_fan_out_jobs_name_the_scene_that_failed(): void
    {
        // At 200 scenes, "3 failed" without "which three" is not an answer.
        $story = Story::factory()->paidAssetsUnlocked()->create();
        $act = Act::factory()->for($story)->create();
        $scene = Scene::factory()->forAct($act)->atSequence(147)->create();

        $job = RenderJob::factory()
            ->for($story)
            ->stage(RenderStage::Images)
            ->inBatch('9f3c1a4e-0000-4000-8000-000000000001')
            ->failed('Provider returned 429.')
            ->create(['scene_id' => $scene->id]);

        $this->assertSame(147, $job->scene->sequence);
        $this->assertSame(RenderJobStatus::Failed, $job->status);
        $this->assertTrue($job->stage->isPaid());
        $this->assertTrue($job->stage->fansOut());
    }

    public function test_enums_survive_the_round_trip(): void
    {
        $story = Story::factory()->create();
        $act = Act::factory()->for($story)->create();

        $scene = Scene::factory()->forAct($act)->create([
            'motion_preset' => MotionPreset::PanLeft,
        ]);

        $track = AudioTrack::factory()->for($story)->create(['status' => AssetStatus::Generating]);

        $this->assertSame(MotionPreset::PanLeft, $scene->fresh()->motion_preset);
        $this->assertTrue($scene->fresh()->motion_preset->isPan());
        $this->assertSame(AssetStatus::Generating, $track->fresh()->status);
    }

    public function test_a_publish_time_is_stored_in_utc_and_readable_in_both_places(): void
    {
        // Peak US viewing is 6-10 PM ET, which is the small hours in Manila.
        // Showing only one of the two is how a schedule gets fumbled.
        $story = Story::factory()->create([
            'target_publish_at' => Carbon::parse('2026-09-15 23:00:00', 'UTC'),
        ]);

        $this->assertSame('19:00', $story->targetPublishAtEastern()->format('H:i'));
        $this->assertSame('07:00', $story->targetPublishAtManila()->format('H:i'));
        $this->assertSame('2026-09-16', $story->targetPublishAtManila()->format('Y-m-d'));
    }
}
