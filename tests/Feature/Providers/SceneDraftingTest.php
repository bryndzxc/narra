<?php

namespace Tests\Feature\Providers;

use App\Actions\DraftScenes;
use App\Actions\ExtractCharacters;
use App\Actions\GenerateActScripts;
use App\Actions\GenerateOutline;
use App\Actions\ValidateSceneDrafts;
use App\Contracts\ScriptWriter;
use App\Enums\CostCategory;
use App\Enums\Gate;
use App\Enums\MotionPreset;
use App\Enums\RenderStage;
use App\Enums\SceneStatus;
use App\Enums\StoryFormat;
use App\Enums\StoryStatus;
use App\Exceptions\LocaleViolationException;
use App\Models\Character;
use App\Models\Scene;
use App\Models\Story;
use App\Services\Fake\FakeScriptWriter;
use App\Support\SentenceSplitter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * Phase 2b — cast, then scenes.
 *
 * Two properties carry this stage and neither is visible in the output:
 *
 *  1. The narration is VERBATIM. The script was approved by an operator at
 *     Gate 1 and a paid TTS call will read it aloud, so a paraphrase here
 *     silently changes what was approved and what gets spoken. Scenes are
 *     addressed by sentence range and sliced in PHP; the ranges are then
 *     checked to cover every sentence exactly once.
 *
 *  2. The character description is the SAME BYTES in every prompt. Consistency
 *     across 150-250 stills is the single biggest quality risk in this format,
 *     and it is set here, in text, before a cent is spent on images.
 */
class SceneDraftingTest extends TestCase
{
    use RefreshDatabase;

    private FakeScriptWriter $writer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->writer = app(ScriptWriter::class);
    }

    // -- Characters first ----------------------------------------------------

    public function test_the_cast_is_extracted_with_a_fixed_description_and_a_locked_seed(): void
    {
        $story = $this->scriptedStory();

        $result = app(ExtractCharacters::class)->handle($story);

        $this->assertGreaterThan(0, $result['characters']);

        foreach ($story->characters()->get() as $character) {
            $this->assertNotEmpty($character->description);
            $this->assertNotNull($character->seed, 'A character with no seed is half the consistency mechanism.');
        }
    }

    public function test_seeds_are_deterministic_so_a_rebuild_does_not_reroll_every_face(): void
    {
        // Random seeds would mean editing one description at Gate 2 and
        // re-extracting silently re-rolls everybody else's face, which is the
        // opposite of what the table is for.
        $story = $this->scriptedStory();

        app(ExtractCharacters::class)->handle($story);
        $before = $story->characters()->orderBy('name')->pluck('seed', 'name');

        app(ExtractCharacters::class)->handle($story, rebuild: true);
        $after = $story->characters()->orderBy('name')->pluck('seed', 'name');

        $this->assertSame($before->all(), $after->all());
    }

    public function test_scenes_cannot_be_drafted_before_the_cast_exists(): void
    {
        // The ordering IS the mechanism. Scenes drafted first would each invent
        // a description for the same person.
        $story = $this->scriptedStory();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no characters');

        app(DraftScenes::class)->handle($story);
    }

    public function test_a_second_extraction_keeps_the_existing_cast(): void
    {
        // Every one of these calls bills, and a re-extraction that silently
        // replaced descriptions would invalidate every prompt written from them.
        $story = $this->scriptedStory();

        app(ExtractCharacters::class)->handle($story);
        $this->writer->calls = [];

        $result = app(ExtractCharacters::class)->handle($story);

        $this->assertTrue($result['kept']);
        $this->assertSame([], $this->writer->calls);
    }

    public function test_the_cast_cannot_be_extracted_before_the_scripts_exist(): void
    {
        $story = Story::factory()->status(StoryStatus::Outlined)->create();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('read out of the act scripts');

        app(ExtractCharacters::class)->handle($story);
    }

    // -- Narration is verbatim -----------------------------------------------

    public function test_the_scene_narration_reconstructs_the_act_script_exactly(): void
    {
        // THE test for this stage. If this passes, no model has retyped a word
        // an operator approved.
        $story = $this->draftedStory();
        $splitter = app(SentenceSplitter::class);

        foreach ($story->acts()->orderBy('sequence')->get() as $act) {
            $fromScenes = $splitter->join(
                $act->scenes()->orderBy('sequence')->pluck('narration_text')->all()
            );

            $this->assertSame(
                $splitter->normalise((string) $act->script),
                $fromScenes,
                "Act {$act->sequence}'s scenes do not reconstruct its script. Narration has been "
                .'altered, dropped or duplicated somewhere between Gate 1 and Gate 2.'
            );
        }
    }

    public function test_a_gap_in_the_sentence_ranges_fails_loudly(): void
    {
        // A gap drops narration out of the video and nothing downstream would
        // notice: every scene still has text, the render still succeeds, the
        // video is just missing a line.
        $story = $this->castStory();

        $this->writer->sceneRangeOverride = fn (int $total): array => [[1, 2], [4, $total]];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('previous scene ended');

        app(DraftScenes::class)->handle($story);
    }

    public function test_ranges_that_stop_short_of_the_end_fail_loudly(): void
    {
        $story = $this->castStory();

        $this->writer->sceneRangeOverride = fn (int $total): array => [[1, max(1, $total - 3)]];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('silently dropped');

        app(DraftScenes::class)->handle($story);
    }

    public function test_the_sentence_splitter_round_trips(): void
    {
        // Everything above rests on this. A splitter that loses a clause makes
        // the verbatim guarantee a lie.
        $splitter = app(SentenceSplitter::class);

        $text = 'Mr. Ostrander called on Jan. 4th. He said the trust was signed in 2019. '
            .'I asked him what that meant for the house, and he said "it means it was never his." '
            .'The balance was $71,000. Was it really? I drove to St. Luke\'s that afternoon.';

        $sentences = $splitter->split($text);

        $this->assertSame($splitter->normalise($text), $splitter->join($sentences));
        $this->assertGreaterThan(3, count($sentences));

        // And the abbreviations did not become sentence boundaries.
        $this->assertStringContainsString('Mr. Ostrander called on Jan. 4th.', $sentences[0]);
    }

    // -- Image prompts -------------------------------------------------------

    public function test_every_prompt_carries_the_style_from_config_not_from_the_model(): void
    {
        // Stated once, appended identically 150-250 times. A style a model
        // restates per prompt drifts per prompt.
        config()->set('scenes.art_style', 'STYLE-SENTINEL painterly.');

        $story = $this->draftedStory();

        foreach ($story->scenes()->get() as $scene) {
            $this->assertStringContainsString('STYLE-SENTINEL', (string) $scene->image_prompt);
        }
    }

    public function test_a_character_description_is_the_same_bytes_in_every_prompt(): void
    {
        // The consistency mechanism, asserted rather than assumed.
        $story = $this->draftedStory();

        $lead = $story->characters()->orderBy('id')->first();

        $carrying = $story->scenes()->get()->filter(
            fn (Scene $s): bool => str_contains((string) $s->image_prompt, $lead->name.':')
        );

        $this->assertGreaterThan(1, $carrying->count(), 'The lead appears in only one scene.');

        foreach ($carrying as $scene) {
            $this->assertStringContainsString(
                trim((string) $lead->description),
                (string) $scene->image_prompt,
                "Scene {$scene->sequence} describes {$lead->name} in different words. That is the "
                .'drift the characters table exists to prevent.'
            );
        }
    }

    public function test_only_the_characters_in_the_frame_get_their_description_pasted(): void
    {
        // A prompt carrying six descriptions when two people are in shot
        // invites the generator to put all six in the picture.
        $story = $this->draftedStory();
        $names = $story->characters()->pluck('name');

        $scene = $story->scenes()->get()->first(
            fn (Scene $s): bool => str_contains((string) $s->image_prompt, 'described exactly')
        );

        $mentioned = $names->filter(fn (string $n): bool => str_contains((string) $scene->image_prompt, $n.':'));

        $this->assertGreaterThan(0, $mentioned->count());
        $this->assertLessThan($names->count(), $mentioned->count());
    }

    public function test_a_prompt_that_restates_its_narration_is_flagged_at_gate_two(): void
    {
        // The failure this stage is shaped to avoid: a literal illustration of
        // a sentence, two hundred times, which reads as a slideshow.
        $story = $this->draftedStory();

        $scene = $story->scenes()->orderBy('sequence')->first();
        $scene->update(['image_prompt' => $scene->narration_text."\n\nsome style"]);

        $warnings = app(ValidateSceneDrafts::class)->handle($story->refresh())['warnings'];

        $this->assertStringContainsString('restates their narration', implode(' ', $warnings));
    }

    public function test_composed_frames_are_not_flagged(): void
    {
        // The counterpart. A check that fired on every scene would be turned off.
        $warnings = app(ValidateSceneDrafts::class)->handle($this->draftedStory())['warnings'];

        $this->assertStringNotContainsString('restates their narration', implode(' ', $warnings));
    }

    // -- Per-scene fields ----------------------------------------------------

    public function test_exactly_one_scene_is_the_hook_and_it_is_the_first(): void
    {
        $story = $this->draftedStory();

        $hooks = $story->scenes()->where('is_hook', true)->get();

        $this->assertCount(1, $hooks);
        $this->assertSame(1, $hooks->first()->sequence);
    }

    public function test_scenes_are_numbered_across_the_whole_story_not_per_act(): void
    {
        // scenes.sequence names a clip on disk and is what an operator says out
        // loud when one of 200 fails.
        $story = $this->draftedStory();

        $sequences = $story->scenes()->orderBy('sequence')->pluck('sequence')->all();

        $this->assertSame(range(1, count($sequences)), $sequences);
    }

    public function test_motion_presets_are_set_and_varied(): void
    {
        $story = $this->draftedStory();

        $presets = $story->scenes()->pluck('motion_preset');

        $this->assertTrue($presets->every(fn (MotionPreset $p): bool => true));
        $this->assertGreaterThan(1, $presets->unique()->count(), 'Every scene uses the same camera move.');
    }

    public function test_an_unusable_motion_preset_degrades_into_variety(): void
    {
        // Not into 200 identical zoom-ins, which would look mechanical with
        // nothing in the data to say why.
        $story = $this->castStory();
        $this->writer->motionOverride = 'not-a-preset';

        app(DraftScenes::class)->handle($story);

        $this->assertGreaterThan(1, $story->scenes()->pluck('motion_preset')->unique()->count());
    }

    public function test_at_least_one_thumbnail_candidate_is_always_flagged(): void
    {
        $story = $this->draftedStory();

        $this->assertGreaterThanOrEqual(1, $story->scenes()->where('is_thumbnail_candidate', true)->count());
    }

    public function test_the_hook_becomes_the_thumbnail_when_nothing_else_is_flagged(): void
    {
        $story = $this->castStory();
        $this->writer->suppressThumbnails = true;

        app(DraftScenes::class)->handle($story);

        $this->assertTrue((bool) $story->scenes()->where('sequence', 1)->value('is_thumbnail_candidate'));
    }

    /**
     * The cap is per act, so the LAST act holds candidates too.
     *
     * The story-wide cap this replaced was six, applied in act order, and every
     * story in the database filled it by act 3 — so the departure, the search
     * and the refusal never contributed a thumbnail candidate and the pair
     * score's reversal bonus had never fired on real data. This fixture asks
     * for more than the cap in every act; the old code goes red on the last
     * act having none, the new code keeps the cap in each.
     *
     * The fixture's size is asserted before anything else, because a fixture
     * too small to overflow the cap is exactly what kept the old behaviour
     * green: one nomination per act across three acts cannot exceed six.
     */
    public function test_every_act_keeps_its_own_thumbnail_candidates_and_the_drop_is_said(): void
    {
        config(['scenes.thumbnail_candidates.per_act' => 2]);

        $story = $this->castStory();
        $this->writer->thumbnailsPerAct = 4;

        app(DraftScenes::class)->handle($story);

        $acts = $story->acts()->orderBy('sequence')->get();
        $this->assertGreaterThanOrEqual(3, $acts->count(), 'The fixture needs several acts to show front-loading.');

        foreach ($acts as $act) {
            $inAct = $story->scenes()->where('act_id', $act->id)->count();
            $this->assertGreaterThanOrEqual(5, $inAct, "Act {$act->sequence} is too small to overflow the cap.");

            $this->assertSame(
                2,
                $story->scenes()->where('act_id', $act->id)->where('is_thumbnail_candidate', true)->count(),
                "Act {$act->sequence} should keep exactly the per-act cap.",
            );
        }

        // 4 nominated, 2 kept, 2 dropped — in EVERY act, and the row says so.
        $log = (string) $story->renderJobs()->where('stage', RenderStage::DraftScenes)->latest('id')->value('log');
        $this->assertStringContainsString('over the per-act cap of 2 were dropped', $log);

        foreach ($acts as $act) {
            $this->assertStringContainsString("act {$act->sequence} dropped 2", $log);
        }
    }

    public function test_nominations_within_the_cap_are_all_kept_and_nothing_is_reported_dropped(): void
    {
        config(['scenes.thumbnail_candidates.per_act' => 2]);

        $story = $this->castStory();
        $this->writer->thumbnailsPerAct = 2;

        app(DraftScenes::class)->handle($story);

        $acts = $story->acts()->count();
        $this->assertSame(2 * $acts, $story->scenes()->where('is_thumbnail_candidate', true)->count());

        $log = (string) $story->renderJobs()->where('stage', RenderStage::DraftScenes)->latest('id')->value('log');
        $this->assertStringNotContainsString('were dropped', $log);
    }

    // -- Pipeline position ---------------------------------------------------

    public function test_drafting_parks_the_story_at_gate_two(): void
    {
        $story = $this->draftedStory();

        $this->assertSame(StoryStatus::ScenesDrafted, $story->fresh()->status);
        $this->assertSame(Gate::Scenes, $story->fresh()->awaitingGate());
    }

    public function test_nothing_in_this_stage_bills_for_an_asset(): void
    {
        // Phase 2b is text only. Not one image, not one second of audio.
        $story = $this->draftedStory();

        $this->assertSame(
            0,
            $story->costEntries()->where('category', CostCategory::Asset)->count()
        );
        $this->assertFalse($story->fresh()->canGeneratePaidAssets());
    }

    public function test_every_act_call_writes_a_cost_row(): void
    {
        $story = $this->draftedStory();

        $this->assertSame(1, $story->costEntries()->where('operation', 'extract_characters')->count());
        $this->assertSame(
            $story->acts()->count(),
            $story->costEntries()->where('operation', 'draft_scenes')->count()
        );
    }

    public function test_scenes_land_drafted_and_awaiting_review(): void
    {
        $story = $this->draftedStory();

        $this->assertSame(
            $story->scenes()->count(),
            $story->scenes()->where('status', SceneStatus::Drafted)->count()
        );
    }

    public function test_a_second_draft_keeps_the_existing_scenes(): void
    {
        $story = $this->draftedStory();
        $this->writer->calls = [];

        $result = app(DraftScenes::class)->handle($story->refresh());

        $this->assertTrue($result['kept']);
        $this->assertSame([], $this->writer->calls);
    }

    public function test_a_locale_leak_in_a_frame_fails_the_stage(): void
    {
        $story = $this->castStory();
        $this->writer->injectIntoScenes = 'Outside the sari-sari store on the corner.';

        $this->expectException(LocaleViolationException::class);

        try {
            app(DraftScenes::class)->handle($story);
        } finally {
            $this->assertSame(0, $story->scenes()->count(), 'A leaking frame was still written.');
        }
    }

    public function test_a_locale_leak_in_a_character_description_fails_before_any_scene(): void
    {
        // The cheapest place to catch it: one call, and the description would
        // otherwise be pasted into every prompt in the video.
        $story = $this->scriptedStory();
        $this->writer->injectIntoCharacters = ' Wearing tsinelas.';

        $this->expectException(LocaleViolationException::class);

        try {
            app(ExtractCharacters::class)->handle($story);
        } finally {
            $this->assertSame(0, $story->characters()->count());
        }
    }

    // -- Fixtures ------------------------------------------------------------

    private function scriptedStory(): Story
    {
        $story = Story::factory()->status(StoryStatus::Draft)->create([
            'format' => StoryFormat::Single,
            'locale_profile' => 'en-US',
            'premise' => 'My brother lived in our mother house rent free for four years while I paid for it.',
        ]);

        app(GenerateOutline::class)->handle($story, 3);
        $story->refresh()->approveGate(Gate::Outline);
        app(GenerateActScripts::class)->handle($story->refresh());

        $this->writer->calls = [];

        return $story->refresh();
    }

    private function castStory(): Story
    {
        $story = $this->scriptedStory();

        app(ExtractCharacters::class)->handle($story);

        $this->writer->calls = [];

        return $story->refresh();
    }

    private function draftedStory(): Story
    {
        $story = $this->castStory();

        app(DraftScenes::class)->handle($story);

        return $story->refresh();
    }
}
