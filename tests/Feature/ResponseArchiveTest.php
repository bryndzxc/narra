<?php

namespace Tests\Feature;

use App\Enums\RenderStage;
use App\Models\RenderJob;
use App\Models\Story;
use App\Support\ResponseArchive;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/**
 * Keeping the bytes the model actually sent.
 *
 * The question this answers could not be answered at all before it existed: a
 * doubled escape took four separate measurements to diagnose and the conclusion
 * was still an inference, because the app stored decoded values only.
 */
class ResponseArchiveTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config()->set('providers.archive_responses', true);
    }

    private function story(): Story
    {
        return Story::factory()->create();
    }

    public function test_it_files_a_response_under_the_story_being_recorded(): void
    {
        $story = $this->story();

        RenderJob::record($story->id, RenderStage::Outline, function () {
            return ResponseArchive::store('generate_outline', '{"acts":[]}');
        });

        $files = ResponseArchive::forStory($story->id);

        $this->assertCount(1, $files);
        $this->assertStringContainsString('generate_outline', $files[0]);
        $this->assertStringContainsString('responses/'.$story->id.'/', $files[0]);
    }

    /**
     * The stored bytes are the bytes that arrived, not a re-encoding of them.
     *
     * This is the whole point: a payload that had been through any of our own
     * encoders would be evidence about our encoders, which is the thing that
     * already agrees with itself.
     */
    public function test_the_archived_bytes_round_trip_exactly(): void
    {
        $story = $this->story();
        $raw = '{"t":"a '.chr(92).chr(92).'u2014 b","n":7}';

        RenderJob::record($story->id, RenderStage::Outline, fn () => ResponseArchive::store('outline', $raw));

        $files = ResponseArchive::forStory($story->id);
        $stored = gzdecode(Storage::disk('local')->get($files[0]));

        $this->assertSame($raw, $stored);
        $this->assertStringContainsString(chr(92).chr(92).'u2014', $stored,
            'The doubled escape must survive into the archive — it is the evidence.');
    }

    /**
     * A response with no stage open still lands somewhere findable.
     *
     * Console commands run Actions outside `record()`, and a payload dropped
     * because nobody happened to be recording is the absence-read-as-agreement
     * shape this codebase keeps paying for.
     */
    public function test_a_response_with_no_open_stage_is_still_kept(): void
    {
        $this->assertNotNull(ResponseArchive::store('generate_outline', '{}'));
        $this->assertNotEmpty(Storage::disk('local')->files('responses/_unattached'));
    }

    public function test_it_can_be_turned_off(): void
    {
        config()->set('providers.archive_responses', false);

        $this->assertNull(ResponseArchive::store('generate_outline', '{}'));
    }

    /** A stage that throws must not leave its row on the ambient stack. */
    public function test_a_failed_stage_does_not_leak_the_current_row(): void
    {
        $story = $this->story();

        try {
            RenderJob::record($story->id, RenderStage::Outline, function (): never {
                throw new RuntimeException('act 5 died');
            });
        } catch (RuntimeException) {
            // expected
        }

        $this->assertNull(
            RenderJob::current(),
            'A leaked ambient row would attach one story\'s notes to another story\'s stage.'
        );
    }

    public function test_a_note_with_no_open_stage_is_silent(): void
    {
        RenderJob::noteOnCurrent('nothing is recording');

        $this->assertNull(RenderJob::current());
    }

    public function test_the_note_reaches_the_row_being_recorded(): void
    {
        $story = $this->story();

        $job = RenderJob::record($story->id, RenderStage::Outline, function (RenderJob $job) {
            RenderJob::noteOnCurrent('Undoubled 3 escape(s) the model emitted.');

            return $job;
        });

        $this->assertStringContainsString('Undoubled 3 escape(s)', (string) $job->fresh()->log);
    }
}
