<?php

namespace Tests\Feature;

use App\Actions\ApproveScenesGate;
use App\Enums\AssetStatus;
use App\Enums\StoryStatus;
use App\Models\AudioTrack;
use App\Models\Scene;
use App\Models\SceneAudio;
use App\Models\Story;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Gate 2 discards audio because it disagrees with the text, not because the
 * approval record is out of date.
 *
 * The instance: story 25's narration text was repaired, the four affected scenes
 * were re-narrated from the repaired text for $0.1274, and Gate 2 was
 * re-approved afterwards. At that moment `approved_narration_hash` still
 * described the PRE-repair text, so `narrationChanged()` was true and four files
 * that were exactly right were destroyed for being stale.
 *
 * The audio was current. The approval was not. `narration_text_hash` is what
 * lets the gate tell those apart, and its whole value is that the ORDER of
 * repair, narration and approval stops mattering.
 */
class NarrationProvenanceTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: Story, 1: Scene, 2: SceneAudio} */
    private function scene(
        string $text,
        ?string $audioHash,
        ?string $approvedHash,
        bool $reachesDiscardBranch = true,
    ): array {
        $story = Story::factory()->create(['status' => StoryStatus::ScenesDrafted]);
        $act = $story->acts()->create([
            'sequence' => 1, 'title' => 'Act', 'summary' => 's',
            'escalation_beat' => 'b', 'script' => 'x', 'is_rehook_written' => true,
        ]);

        $scene = Scene::factory()->create([
            'story_id' => $story->id,
            'act_id' => $act->id,
            'sequence' => 1,
            'narration_text' => $text,
            'image_path' => 'x.png',
        ]);

        $scene->forceFill([
            'approved_narration_hash' => $approvedHash,
            'approved_image_hash' => $scene->imageFingerprint(),
            'approved_motion_preset' => $scene->motion_preset->value,
        ])->save();

        $track = AudioTrack::query()->create([
            'story_id' => $story->id,
            'language' => 'en-US',
            'voice_id' => 'v1',
            'status' => AssetStatus::Ready,
        ]);

        $audio = SceneAudio::query()->create([
            'scene_id' => $scene->id,
            'audio_track_id' => $track->id,
            'audio_path' => 'a.wav',
            'timings_json' => [['word' => 'x', 'start_ms' => 0, 'end_ms' => 1]],
            'duration_ms' => 1000,
            'narration_text_hash' => $audioHash,
        ]);

        $scene = $scene->refresh();

        /*
         * The fixture must produce the state it claims, or every assertion
         * built on it is about a case it cannot express.
         *
         * Gate 2 only considers scenes it already thinks need narration, so a
         * case meaning to test the DISCARD decision has to reach that branch —
         * and a case meaning to test that untouched audio is left alone has to
         * NOT reach it. Stating which is the point: the first draft asserted
         * "reaches it" unconditionally and one case failed, correctly, because
         * a scene whose text never changed is kept for a different reason.
         */
        $this->assertSame(
            $reachesDiscardBranch,
            $scene->needsNarration(),
            'The fixture is not in the state this case is about.'
        );

        return [$story, $scene, $audio];
    }

    private function approve(Story $story): void
    {
        app(ApproveScenesGate::class)->handle($story->refresh());
    }

    /**
     * THE BUG, as a test. Audio made from the CURRENT text survives a
     * re-approval whose record still describes the old text.
     */
    public function test_audio_matching_the_current_text_survives_a_stale_approval(): void
    {
        $text = 'Not refused — stopped.';
        [$story, $scene] = $this->scene($text, Scene::fingerprint($text), 'a-hash-from-the-old-text');

        $this->approve($story);

        $this->assertSame(
            'a.wav',
            $scene->refresh()->sceneAudio->first()->audio_path,
            'The audio was made from this exact text; the approval record was the stale thing.'
        );
    }

    /** The other direction: audio made from DIFFERENT text is still discarded. */
    public function test_audio_made_from_other_text_is_still_discarded(): void
    {
        [$story, $scene] = $this->scene('The current text.', Scene::fingerprint('Something else entirely.'), null);

        $this->approve($story);

        $this->assertNull(
            $scene->refresh()->sceneAudio->first()->audio_path,
            'A file made from words we can name, that are not these words, is stale.'
        );
    }

    /**
     * UNKNOWN falls back to the old predicate, so adding the column changes
     * nothing for the 971 rows that predate it.
     *
     * Both halves, because "unknown must not mean discard" and "unknown must
     * not mean keep" are both wrong on their own.
     */
    public function test_unknown_provenance_behaves_exactly_as_before(): void
    {
        // unchanged text -> kept, as before
        $same = 'The text as approved.';
        [$storyA, $sceneA] = $this->scene($same, null, Scene::fingerprint($same), reachesDiscardBranch: false);
        $this->approve($storyA);
        $this->assertSame('a.wav', $sceneA->refresh()->sceneAudio->first()->audio_path);

        // changed text -> discarded, as before
        [$storyB, $sceneB] = $this->scene('Edited since approval.', null, 'the-hash-before-the-edit');
        $this->approve($storyB);
        $this->assertNull($sceneB->refresh()->sceneAudio->first()->audio_path);
    }

    /**
     * The half-clear. A row whose pointer is gone must not still describe the
     * file, because `samples` is what AudioFrames treats as authoritative for
     * frame arithmetic — over duration_ms, deliberately.
     */
    public function test_a_discarded_row_is_cleared_completely(): void
    {
        [$story, $scene] = $this->scene('Now.', Scene::fingerprint('Then.'), null);

        $scene->sceneAudio->first()->forceFill([
            'samples' => 235172,
            'sample_rate' => 24000,
            'narration_provider' => 'elevenlabs',
            'narration_voice_id' => 'nPczCjzI2devNBz1zQrb',
            'narration_simulated' => false,
        ])->save();

        $this->approve($story);

        $audio = $scene->refresh()->sceneAudio->first();

        foreach ([
            'audio_path', 'timings_json', 'duration_ms', 'samples', 'sample_rate',
            'narration_provider', 'narration_voice_id', 'narration_speed',
            'narration_text_hash', 'padded_duration_ms', 'frames',
            'offset_frames', 'offset_samples', 'offset_ms',
        ] as $column) {
            $this->assertNull($audio->{$column}, "{$column} must not survive a discard.");
        }
    }
}
