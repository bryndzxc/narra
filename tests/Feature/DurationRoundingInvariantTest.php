<?php

namespace Tests\Feature;

use App\Enums\RenderJobStatus;
use App\Enums\RenderStage;
use App\Models\RenderJob;
use App\Models\Story;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Two invariants that a render depends on and that nothing was checking.
 *
 * Both were found by the same render, one after the other, and both are the
 * project's recurring shape: a value that is very slightly wrong in a direction
 * nobody thought to bound, and a report that is confidently wrong about work
 * that never happened.
 */
class DurationRoundingInvariantTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A scene's frame count must be able to CONTAIN its audio.
     *
     * The spec's rule is `frames = ceil(audio_ms / 1000 * fps)`, and the ceil is
     * there to guarantee video >= audio so that padding only ever adds silence.
     * That guarantee is conditional in a way the spec does not spell out: it
     * holds only if `duration_ms` is itself an UPPER bound on the real audio.
     *
     * The ElevenLabs synthesizer used round(), which understates by up to half a
     * millisecond — and only bites when the true duration lands just past a
     * frame boundary the rounded value falls short of. 3 scenes in 186, by 4 to
     * 6 samples, discovered 186 clips into a render when padding would have had
     * to become a trim.
     */
    #[DataProvider('boundaryCases')]
    public function test_a_duration_derived_from_samples_always_contains_its_audio(int $samples, int $sampleRate): void
    {
        $fps = 30;
        $renderRate = 44100;

        // The rule the synthesizers now follow.
        $durationMs = (int) ceil($samples / $sampleRate * 1000);

        $frames = (int) ceil($durationMs / 1000 * $fps);
        $capacity = $frames * intdiv($renderRate, $fps);
        $actual = (int) round($samples * $renderRate / $sampleRate);

        $this->assertGreaterThanOrEqual(
            $actual,
            $capacity,
            sprintf(
                '%d samples at %d Hz (%d at %d Hz) does not fit in %d frames. Padding would become a trim.',
                $samples, $sampleRate, $actual, $renderRate, $frames,
            ),
        );
    }

    /**
     * The same cases under the OLD rule, to show the test is not vacuous.
     *
     * A guard has to be confirmed against a real instance of the failure it
     * names, and these are the real instances: the exact sample counts of story
     * 9's scenes 67, 105 and 167.
     */
    public function test_round_is_what_broke_it(): void
    {
        $fps = 30;
        $renderRate = 44100;
        $broken = 0;

        foreach ($this->boundaryCases() as [$samples, $sampleRate]) {
            $durationMs = (int) round($samples / $sampleRate * 1000);
            $capacity = ((int) ceil($durationMs / 1000 * $fps)) * intdiv($renderRate, $fps);

            if ((int) round($samples * $renderRate / $sampleRate) > $capacity) {
                $broken++;
            }
        }

        $this->assertGreaterThan(
            0,
            $broken,
            'These fixtures no longer reproduce the rounding failure, so the test above proves nothing.',
        );
    }

    /**
     * Real sample counts from story 9, at the vendor's 24 kHz output.
     *
     * @return array<int, array{0: int, 1: int}>
     */
    public static function boundaryCases(): array
    {
        return [
            'scene 67 — 6 samples over' => [130403, 24000],
            'scene 105 — 4 samples over' => [360002, 24000],
            'scene 167 — 4 samples over' => [360002, 24000],
            'exactly on a frame boundary' => [24000 * 5, 24000],
            'well inside a frame' => [130000, 24000],
        ];
    }

    /**
     * A new dispatch must not inherit a previous run's success.
     *
     * `RenderJob::open()` keeps one row per (story, stage, scene) across every
     * dispatch, so a stage that succeeded in an earlier render and does not run
     * in this one keeps reporting `succeeded`. The progress page counts rows by
     * stage with no notion of which dispatch they belong to.
     *
     * The observed instance: a render whose concat had just failed displayed
     * Subtitles 1/1 and Mux 1/1 complete, with a 506-second mux time, for stages
     * chained BEHIND the concat that failed — rows 21 hours old, from a silent
     * fixture render, presented as the current run.
     */
    public function test_dispatching_a_render_clears_a_previous_runs_stage_results(): void
    {
        $story = Story::factory()->create();

        $stale = RenderJob::factory()->for($story)->create([
            'stage' => RenderStage::Mux,
            'status' => RenderJobStatus::Succeeded,
            'finished_at' => now()->subDay(),
            'output_path' => 'final.mp4',
            'log' => '506s',
        ]);

        $untouched = RenderJob::factory()->for($story)->create([
            'stage' => RenderStage::SceneNarration,
            'status' => RenderJobStatus::Succeeded,
            'finished_at' => now()->subDay(),
        ]);

        RenderJob::queueStages($story->id, [
            RenderStage::SceneClips,
            RenderStage::Concat,
            RenderStage::Subtitles,
            RenderStage::Mux,
        ]);

        $this->assertSame(RenderJobStatus::Queued, $stale->fresh()->status);
        $this->assertNull($stale->fresh()->finished_at);
        $this->assertNull($stale->fresh()->output_path, 'A stale output path would still look like a finished video.');

        // Stages this dispatch does NOT run keep their history. Narration is a
        // paid asset stage; wiping its record would hide what was bought.
        $this->assertSame(RenderJobStatus::Succeeded, $untouched->fresh()->status);
    }
}
