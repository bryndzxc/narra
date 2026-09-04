<?php

namespace Tests\Feature;

use App\Actions\DeliverFinalVideo;
use App\Enums\RenderJobStatus;
use App\Enums\RenderStage;
use App\Enums\StoryStatus;
use App\Jobs\DeliverFinalVideoJob;
use App\Models\RenderJob;
use App\Models\Story;
use App\Services\Ffmpeg;
use App\Support\RenderWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * The one artifact the operator actually opens, put where they can find it.
 *
 * Most of what is asserted here is refusal rather than delivery, and that is
 * the point: this is the only stage in the pipeline that touches a path the app
 * does not control. An unmounted share, a wrong drive letter and a full disk
 * all produce a "successful" copy of some kind if nobody looks, and a render
 * that reports delivery it did not perform is the false-success shape this
 * project keeps paying for.
 */
class DeliverFinalVideoTest extends TestCase
{
    use RefreshDatabase;

    private string $outside;

    protected function setUp(): void
    {
        parent::setUp();

        // Outside the project, because the Action refuses anything inside it.
        $this->outside = rtrim(sys_get_temp_dir(), '/\\').DIRECTORY_SEPARATOR.'narra-delivery-test';
        $this->cleanup();
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        $this->deleteWorkspace();

        parent::tearDown();
    }

    public function test_a_finished_render_is_copied_out_under_the_story_slug(): void
    {
        $story = $this->renderedStory();

        $result = app(DeliverFinalVideo::class)->handle($story, $this->outside);

        $this->assertTrue($result['delivered']);
        $this->assertSame(
            $this->outside.DIRECTORY_SEPARATOR.'delivery-test.mp4',
            $result['destination'],
        );
        $this->assertFileExists($result['destination']);
        $this->assertSame('a finished render', file_get_contents((string) $result['destination']));
    }

    /**
     * Copy, never move.
     *
     * Three things read the workspace copy — Gate 3's video route, the purge
     * guard, and the re-render idempotency check — and all three break on a
     * moved file. This is the assertion that stops somebody "tidying up" the
     * duplicate later.
     */
    public function test_the_workspace_keeps_its_own_copy(): void
    {
        $story = $this->renderedStory();

        app(DeliverFinalVideo::class)->handle($story, $this->outside);

        $this->assertFileExists(RenderWorkspace::for($story)->path('final.mp4'));
    }

    public function test_delivery_is_off_when_no_path_is_configured(): void
    {
        $story = $this->renderedStory();

        // After the fixture, which configures a path — the order matters and
        // getting it wrong made this test pass against the wrong branch.
        config()->set('render.delivery.path', null);

        $result = app(DeliverFinalVideo::class)->handle($story);

        // A success with an explanation, never a silent one. A render on a
        // machine that has not configured delivery is a complete render.
        $this->assertFalse($result['delivered']);
        $this->assertStringContainsString('No delivery path configured', (string) $result['reason']);
    }

    /**
     * The stage says which of the two it did.
     *
     * A blank row beside "Deliver" on the progress page reads as "worked", and
     * the operator cannot tell delivery-off from delivery-done. That is exactly
     * the reporting failure the false-success table is made of.
     */
    public function test_the_stage_row_distinguishes_off_from_done(): void
    {
        $story = $this->renderedStory();

        config()->set('render.delivery.path', null);

        (new DeliverFinalVideoJob($story->id))->handle(app(Ffmpeg::class));

        $row = RenderJob::query()
            ->where('story_id', $story->id)
            ->where('stage', RenderStage::Deliver)
            ->firstOrFail();

        $this->assertSame(RenderJobStatus::Succeeded, $row->status);
        $this->assertStringContainsString('No delivery path configured', (string) $row->log);
        $this->assertNull($row->output_path);
    }

    /**
     * A wrong drive letter is the typo auto-create would otherwise hide.
     *
     * Without this, `E:\deliver` on a box with no E: drive would be "created"
     * as a path that goes nowhere useful and 275 MB would go into it on every
     * render, silently, forever.
     */
    public function test_it_refuses_to_create_a_directory_whose_parent_does_not_exist(): void
    {
        $story = $this->renderedStory();

        $orphan = $this->outside.DIRECTORY_SEPARATOR.'no-such-parent'.DIRECTORY_SEPARATOR.'deliver';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/parent does not exist/');

        app(DeliverFinalVideo::class)->handle($story, $orphan);
    }

    public function test_it_refuses_a_relative_path(): void
    {
        $story = $this->renderedStory();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/must be an absolute path/');

        app(DeliverFinalVideo::class)->handle($story, 'deliveries');
    }

    /**
     * The whole point of the feature is a folder outside the project, and a
     * path under storage/app/renders could collide with a workspace directory
     * named for a slug.
     */
    public function test_it_refuses_a_path_inside_the_project(): void
    {
        $story = $this->renderedStory();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/inside the project/');

        app(DeliverFinalVideo::class)->handle($story, storage_path('app/deliveries'));
    }

    public function test_it_creates_the_delivery_directory_when_the_parent_exists(): void
    {
        $story = $this->renderedStory();

        $this->assertDirectoryDoesNotExist($this->outside);

        $result = app(DeliverFinalVideo::class)->handle($story, $this->outside);

        $this->assertDirectoryExists($this->outside);
        $this->assertTrue($result['delivered']);
    }

    /**
     * A re-render lands on the same name deliberately — one file per story, the
     * current cut — but it is reported, because the operator may already have
     * uploaded what was there.
     */
    public function test_a_re_render_replaces_the_delivered_file_and_says_so(): void
    {
        $story = $this->renderedStory();

        $first = app(DeliverFinalVideo::class)->handle($story, $this->outside);
        $this->assertFalse($first['replaced']);

        $second = app(DeliverFinalVideo::class)->handle($story, $this->outside);
        $this->assertTrue($second['replaced']);
    }

    public function test_it_refuses_when_there_is_no_finished_render(): void
    {
        $story = $this->renderedStory(withFile: false);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Nothing to deliver/');

        app(DeliverFinalVideo::class)->handle($story, $this->outside);
    }

    /**
     * Long paths, named as long paths.
     *
     * Windows refuses past 260 characters unless long paths are enabled, and
     * the error it gives is not one anybody reads as a path-length problem.
     * CLAUDE.md calls this out specifically for 200-scene renders.
     */
    public function test_it_names_the_path_length_limit_rather_than_failing_cryptically(): void
    {
        $story = $this->renderedStory();

        // A deep delivery folder rather than a long slug, because that is the
        // shape the operator actually produces: a synced folder several levels
        // down. The refusal has to come BEFORE the directory is created, or the
        // only evidence of the stage is an empty folder nobody asked for.
        $deep = $this->outside.DIRECTORY_SEPARATOR.str_repeat('nested-output-folder', 12);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/past the 255 Windows accepts/');

        try {
            app(DeliverFinalVideo::class)->handle($story, $deep);
        } finally {
            $this->assertDirectoryDoesNotExist($deep);
        }
    }

    private function renderedStory(bool $withFile = true): Story
    {
        $story = Story::factory()->status(StoryStatus::Rendered)->create(['slug' => 'delivery-test']);

        config()->set('render.delivery.path', $this->outside);

        if ($withFile) {
            $workspace = RenderWorkspace::for($story);
            $workspace->ensureExists();
            file_put_contents($workspace->path('final.mp4'), 'a finished render');
        }

        return $story;
    }

    private function cleanup(): void
    {
        foreach (glob($this->outside.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }

        if (is_dir($this->outside)) {
            @rmdir($this->outside);
        }
    }

    private function deleteWorkspace(): void
    {
        $directory = storage_path('app/renders/delivery-test');

        foreach (glob($directory.'/*') ?: [] as $file) {
            @unlink($file);
        }

        if (is_dir($directory)) {
            @rmdir($directory);
        }
    }
}
