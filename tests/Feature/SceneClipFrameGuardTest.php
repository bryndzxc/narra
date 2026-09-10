<?php

namespace Tests\Feature;

use App\Enums\AssetStatus;
use App\Enums\StoryStatus;
use App\Models\AudioTrack;
use App\Models\Scene;
use App\Models\SceneAudio;
use App\Models\Story;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The clip stage refuses a scene whose frame count is unknowable — and only
 * then.
 *
 * It used to test `scenes.duration_ms`, which is the FALLBACK input.
 * `Scene::framesAt()` prefers `scene_audio.samples` and reaches the millisecond
 * column only when the sample count is absent, because a millisecond cannot
 * represent where audio ends. So the guard rejected on a field the arithmetic
 * would not have read.
 *
 * Story 25 scenes 169, 180, 193 and 201 are the instance: a repair restored
 * `scene_audio.duration_ms` and not the copy on `scenes`, and four rows holding
 * 587,372 / 235,172 / 353,315 / 672,078 samples at 24 kHz — 735, 294, 442 and
 * 841 frames, exactly computable — failed the render.
 *
 * **This guard now rejects LESS than it did**, which is the direction that
 * needs the most evidence. Every case below states which side of the line it is
 * on, and the refusing case is drilled.
 */
class SceneClipFrameGuardTest extends TestCase
{
    use RefreshDatabase;

    /** A file's PHP with every comment removed, so a scan reads code only. */
    private function codeOf(string $path): string
    {
        $out = '';

        foreach (token_get_all((string) file_get_contents($path)) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    continue;
                }

                $out .= $token[1];

                continue;
            }

            $out .= $token;
        }

        return $out;
    }

    private function scene(?int $sceneDurationMs, ?int $samples, ?int $rate, bool $withAudioRow = true): Scene
    {
        $story = Story::factory()->create(['status' => StoryStatus::AssetsReady]);
        $act = $story->acts()->create([
            'sequence' => 1, 'title' => 'A', 'summary' => 's',
            'escalation_beat' => 'b', 'script' => 'x', 'is_rehook_written' => true,
        ]);

        $scene = Scene::factory()->create([
            'story_id' => $story->id,
            'act_id' => $act->id,
            'sequence' => 1,
            'image_path' => 'x.png',
        ]);

        $scene->forceFill(['duration_ms' => $sceneDurationMs])->save();

        if ($withAudioRow) {
            $track = AudioTrack::query()->create([
                'story_id' => $story->id, 'language' => 'en-US',
                'voice_id' => 'v1', 'status' => AssetStatus::Ready,
            ]);

            SceneAudio::query()->create([
                'scene_id' => $scene->id,
                'audio_track_id' => $track->id,
                // No path on purpose: backfillSampleCount() must not be able to
                // probe its way out, so these cases test the GUARD and not the
                // repair that runs before it.
                'audio_path' => null,
                'samples' => $samples,
                'sample_rate' => $rate,
            ]);
        }

        return $scene->refresh();
    }

    /**
     * THE INSTANCE. Samples present, `scenes.duration_ms` null: the count is
     * exactly computable and the old guard threw anyway.
     */
    public function test_a_scene_with_samples_but_no_millisecond_column_is_renderable(): void
    {
        $scene = $this->scene(null, 235172, 24000);

        $this->assertSame(294, $scene->framesAt(), 'This is story 25 scene 180, to the frame.');
        $this->assertNotNull($scene->framesAt(), 'The guard reads exactly this.');
    }

    /** The fallback still works: milliseconds alone are enough. */
    public function test_a_scene_with_only_milliseconds_is_renderable(): void
    {
        $scene = $this->scene(9799, null, null);

        $this->assertSame(294, $scene->framesAt());
    }

    /**
     * Both paths must agree, or the guard would be waving through a scene the
     * encoder then sizes differently from its own audio.
     */
    public function test_the_two_inputs_give_the_same_frame_count(): void
    {
        foreach ([[587372, 24474, 735], [235172, 9799, 294], [353315, 14721, 442], [672078, 28003, 841]] as [$samples, $ms, $frames]) {
            $this->assertSame($frames, $this->scene(null, $samples, 24000)->framesAt(), 'from samples');
            $this->assertSame($frames, $this->scene($ms, null, null)->framesAt(), 'from milliseconds');
        }
    }

    /**
     * THE REFUSING CASE. Neither input, so the count genuinely is unknowable
     * and the guard must still say so.
     *
     * This is the half that must not be lost when a guard is loosened.
     */
    public function test_a_scene_with_neither_input_is_still_refused(): void
    {
        $this->assertNull(
            $this->scene(null, null, null)->framesAt(),
            'No samples and no milliseconds is the state the guard exists for.'
        );

        $this->assertNull(
            $this->scene(null, null, null, withAudioRow: false)->framesAt(),
            'A scene with no audio row at all is the same answer.'
        );
    }

    /** A half-populated sample pair is not a length, and must not be read as one. */
    public function test_samples_without_a_rate_falls_back_rather_than_guessing(): void
    {
        $this->assertNull($this->scene(null, 235172, null)->framesAt());
        $this->assertSame(294, $this->scene(9799, 235172, null)->framesAt(), 'falls back to ms');
    }

    /**
     * THE CALL SITE ACTUALLY USES IT, and this case exists because the drill
     * said otherwise.
     *
     * Every case above reflects into `Scene::framesAt()`. Restoring the old
     * `$scene->duration_ms === null` guard in the job left all of them GREEN —
     * eight tests, ninety-eight assertions, passing over the exact defect they
     * were written for. That is `truncationMessage()` again: a builder that is
     * correct, well covered, and no longer reachable from the thing that is
     * supposed to call it.
     *
     * A behavioural test cannot reach this guard cheaply — the job wants a
     * workspace, a decodable still and ffmpeg — so the precondition is asserted
     * where it is written, which is the same instrument the truncation remedy
     * settled on for the same reason.
     */
    public function test_the_clip_job_guards_on_the_frame_count_not_on_the_fallback_column(): void
    {
        /*
         * CODE ONLY, comments stripped — and this is not tidiness.
         *
         * The first version scanned the raw file and failed on the correct
         * code, because the docblock beside the guard QUOTES the old condition
         * to explain why it was replaced. A source assertion that cannot tell
         * code from a comment reports the explanation as the defect: the same
         * class of mistake as blade-php-scan flagging a component tag inside a
         * block the compiler never compiles.
         */
        $source = $this->codeOf(app_path('Jobs/RenderSceneClipJob.php'));

        $this->assertStringContainsString(
            'if ($expected === null) {',
            $source,
            'The guard must ask whether the frame count is knowable.'
        );

        $this->assertStringNotContainsString(
            '$scene->duration_ms === null',
            $source,
            'That is the FALLBACK input. Guarding on it refuses scenes whose sample '
            .'count makes the frame count exactly computable — story 25, four scenes.'
        );

        // And the backfill must stay AHEAD of the guard: it can answer the
        // guard's question from the file, so running it second would reject
        // rows this stage could repair itself.
        $this->assertLessThan(
            strpos($source, 'if ($expected === null) {'),
            strpos($source, '$this->backfillSampleCount('),
            'The probe that can supply the answer must run before the refusal.'
        );
    }
}
