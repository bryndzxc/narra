<?php

namespace App\Actions;

use App\Enums\AssetStatus;
use App\Enums\Gate;
use App\Enums\MotionPreset;
use App\Enums\SceneStatus;
use App\Enums\StoryFormat;
use App\Enums\StoryStatus;
use App\Models\Act;
use App\Models\AudioTrack;
use App\Models\Scene;
use App\Models\SceneAudio;
use App\Models\Story;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Loads a Phase 0 fixture set into the schema so the queue can drive it.
 *
 * This is the bridge between the two phases. Phase 0 proved the render on files
 * — `timings.json`, `acts.json`, stills and MP3s on disk. Phase 1 runs the same
 * render from database rows. Rather than teaching the jobs to read fixtures,
 * the fixtures are imported once and the jobs only ever know about models,
 * which is exactly the shape Phase 2 needs when a provider fills those rows in
 * instead of a fixture generator.
 *
 * The story is walked through the gate machine rather than dropped at a status:
 * a fixture arrives with its assets already made, so the import stands in for
 * the operator at Gates 1 and 2 and says so out loud. There is no back door
 * into `assets_ready` and there should not be one.
 */
class ImportFixtureStory
{
    /**
     * @return array{story: Story, acts: int, scenes: int, reimported: bool}
     */
    public function handle(string $fixture): array
    {
        $fixtures = Storage::disk('fixtures');
        $fixture = trim($fixture, '/');

        foreach (['timings.json', 'acts.json'] as $file) {
            if (! $fixtures->exists("{$fixture}/{$file}")) {
                throw new RuntimeException(
                    "Missing fixtures/{$fixture}/{$file}. Run `php artisan fixtures:make` first."
                );
            }
        }

        $timings = json_decode($fixtures->get("{$fixture}/timings.json"), true);
        $acts = json_decode($fixtures->get("{$fixture}/acts.json"), true)['acts'] ?? [];
        $scenes = $timings['scenes'] ?? [];

        if ($scenes === [] || $acts === []) {
            throw new RuntimeException("Fixture {$fixture} has no scenes or no acts.");
        }

        return DB::transaction(function () use ($fixture, $timings, $acts, $scenes): array {
            $existing = Story::query()->where('slug', $fixture)->first();

            $story = $existing ?? new Story;

            $story->fill([
                'title' => (string) ($timings['story']['title'] ?? $fixture),
                'slug' => $fixture,
                'premise' => 'Phase 0 fixture set, imported for queue-driven rendering.',
                'format' => StoryFormat::from((string) ($timings['story']['format'] ?? 'single')),
                'locale_profile' => (string) ($timings['story']['locale_profile'] ?? 'en-US'),
                'voice_id' => $timings['story']['voice_id'] ?? null,
            ])->save();

            // The status column has a database default, so a freshly inserted
            // Story does not carry it in memory. Reload before the gate walk
            // below reads it.
            $story->refresh();

            $actIds = $this->syncActs($story, $acts);
            $this->syncScenes($story, $actIds, $fixture, $scenes);
            $this->syncAudio($story, $fixture, $scenes);

            $this->walkToAssetsReady($story);

            return [
                'story' => $story->refresh(),
                'acts' => count($acts),
                'scenes' => count($scenes),
                'reimported' => $existing !== null,
            ];
        });
    }

    /**
     * @param  array<int, array<string, mixed>>  $acts
     * @return array<int, int> Act id, keyed by act sequence.
     */
    private function syncActs(Story $story, array $acts): array
    {
        $ids = [];

        foreach ($acts as $act) {
            $model = Act::query()->updateOrCreate(
                ['story_id' => $story->id, 'sequence' => (int) $act['sequence']],
                [
                    'title' => (string) $act['title'],
                    'summary' => $act['summary'] ?? null,
                    'is_rehook_written' => (bool) ($act['is_rehook_written'] ?? false),
                    // start_ms and duration_ms are deliberately NOT taken from
                    // the fixture. They are render output — the concat stage
                    // fills them from the real scene timeline, and chapters
                    // depend on them being the render's numbers, not a guess.
                ]
            );

            $ids[(int) $act['sequence']] = $model->id;
        }

        return $ids;
    }

    /**
     * @param  array<int, int>  $actIds
     * @param  array<int, array<string, mixed>>  $scenes
     */
    private function syncScenes(Story $story, array $actIds, string $fixture, array $scenes): void
    {
        foreach ($scenes as $scene) {
            $actSequence = (int) $scene['act_sequence'];

            if (! isset($actIds[$actSequence])) {
                throw new RuntimeException("Scene {$scene['sequence']} references act {$actSequence}, which the fixture does not define.");
            }

            Scene::query()->updateOrCreate(
                ['story_id' => $story->id, 'sequence' => (int) $scene['sequence']],
                [
                    'act_id' => $actIds[$actSequence],
                    'is_hook' => (bool) ($scene['is_hook'] ?? false),
                    'is_thumbnail_candidate' => (bool) ($scene['is_thumbnail_candidate'] ?? false),
                    'narration_text' => (string) $scene['narration_text'],
                    'image_prompt' => null,
                    // Relative to the fixtures disk. RenderWorkspace resolves it.
                    'image_path' => "{$fixture}/{$scene['image']}",
                    // The RAW audio duration. Clip length is derived from it.
                    'duration_ms' => (int) $scene['duration_ms'],
                    'motion_preset' => MotionPreset::from((string) $scene['motion_preset']),
                    'status' => SceneStatus::Ready,
                ]
            );
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $scenes
     */
    private function syncAudio(Story $story, string $fixture, array $scenes): void
    {
        $track = AudioTrack::query()->updateOrCreate(
            ['story_id' => $story->id, 'language' => $story->locale_profile],
            ['voice_id' => $story->voice_id, 'status' => AssetStatus::Ready]
        );

        $sceneIds = Scene::query()
            ->where('story_id', $story->id)
            ->pluck('id', 'sequence');

        foreach ($scenes as $scene) {
            SceneAudio::query()->updateOrCreate(
                [
                    'scene_id' => $sceneIds[(int) $scene['sequence']],
                    'audio_track_id' => $track->id,
                ],
                [
                    'audio_path' => "{$fixture}/{$scene['audio']}",
                    // Word-level timings. In Phase 2 these come from Whisper,
                    // per scene, for exactly the reason they are stored per
                    // scene here: a 40-minute transcription drifts at the end.
                    'timings_json' => $scene['words'],
                    'duration_ms' => (int) $scene['duration_ms'],
                    // Everything below is render output, filled by the concat
                    // stage from the real timeline. Left null on import so a
                    // stale offset can never masquerade as a computed one.
                    'padded_duration_ms' => null,
                    'frames' => null,
                    'offset_frames' => null,
                    'offset_samples' => null,
                    'offset_ms' => null,
                    'status' => AssetStatus::Ready,
                ]
            );
        }
    }

    /**
     * Walk a freshly imported story up to `assets_ready`, gate by gate.
     *
     * A fixture arrives with its stills and narration already on disk, so it is
     * legitimately past both free gates — but it gets there the same way a real
     * story would, through approveGate(). Nothing writes `status` directly.
     */
    private function walkToAssetsReady(Story $story): void
    {
        $route = [
            StoryStatus::Outlined,
            StoryStatus::Scripted,
            StoryStatus::ScenesDrafted,
            StoryStatus::ScenesApproved,
            StoryStatus::AssetsGenerating,
            StoryStatus::AssetsReady,
        ];

        foreach ($route as $next) {
            if ($story->status->rank() >= $next->rank()) {
                continue;
            }

            $gate = $story->status->gateFor($next);

            $gate === null
                ? $story->transitionTo($next)
                : $story->approveGate($gate);
        }
    }
}
