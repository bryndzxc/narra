<?php

namespace Tests\Feature\Providers;

use App\Actions\DispatchAssetGeneration;
use App\Actions\GenerateCharacterSheet;
use App\Actions\PreflightAssetDispatch;
use App\Enums\StoryStatus;
use App\Exceptions\DispatchRefusedException;
use App\Models\Character;
use App\Models\CharacterReference;
use App\Models\Scene;
use App\Models\Story;
use App\Support\StyleFingerprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The guard `ImagePromptBuilder` said existed for two phases.
 *
 * Its docblock has promised since Phase 2 that "if the channel's look is
 * retuned, the sheets are stale and the operator is told so rather than the
 * mismatch being absorbed silently". Nothing implemented that — no column, no
 * comparison, no surface — and it had a live instance the day it was found:
 * story 9's reference sheets are painted realism and the configured style is
 * now anime.
 *
 * The cost of the gap is not one wrong image. Every still a character appears
 * in is generated through the `edit` endpoint conditioned on their reference
 * face, so a stale sheet drags all 30-90 of that character's stills toward a
 * look the rest of the video is not in — one video in two styles, billed in
 * full, and invisible until the render.
 *
 * These assert the WIRING, in the project's usual style for a closed seam: that
 * the fingerprint is written, that a retune is detected, and that the detection
 * reaches the process holding the money button.
 */
class ReferenceStyleFingerprintTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('characters');
    }

    public function test_a_generated_sheet_records_the_style_it_was_drawn_in(): void
    {
        $character = $this->characterWithScenes();

        $produced = app(GenerateCharacterSheet::class)->handle($character, 1);

        $this->assertSame(
            StyleFingerprint::current(),
            $produced->first()->style_fingerprint,
        );
    }

    public function test_retuning_the_art_style_marks_an_existing_sheet_stale(): void
    {
        $character = $this->characterWithApprovedFace();

        $this->assertSame(Character::STYLE_CURRENT, $character->referenceStyleState());

        // The move this whole mechanism exists for: painted realism to anime.
        config()->set('scenes.art_style', 'Anime-style illustration, flat cel shading.');

        $this->assertSame(Character::STYLE_STALE, $character->fresh()->referenceStyleState());
    }

    /**
     * A reflow is not a retune.
     *
     * A style block is edited as prose and wraps differently every time it is
     * touched. Firing on whitespace would train the operator to ignore the
     * alarm, which costs more than the alarm is worth.
     */
    public function test_reflowing_the_style_does_not_mark_anything_stale(): void
    {
        $character = $this->characterWithApprovedFace();

        $style = (string) config('scenes.art_style');
        config()->set('scenes.art_style', "  \n ".strtoupper(preg_replace('/\s+/', "\n", $style)).'  ');

        $this->assertSame(Character::STYLE_CURRENT, $character->fresh()->referenceStyleState());
    }

    /**
     * NULL is unknown, and unknown is never reported as fine.
     *
     * Every row predating the column is genuinely unknown — the style string of
     * the day was not recorded and cannot be recovered. Stamping today's
     * fingerprint on it to silence the warning would be fabricating provenance,
     * which is the move that let 117 scenes read as narrated at the right speed
     * when nobody knew what speed they were read at.
     */
    public function test_a_sheet_from_before_the_column_reports_unknown_not_current(): void
    {
        $character = $this->characterWithApprovedFace(fingerprint: null);

        $this->assertSame(Character::STYLE_UNKNOWN, $character->referenceStyleState());
    }

    public function test_a_character_with_no_sheet_is_neither_stale_nor_unknown(): void
    {
        $character = $this->characterWithScenes();

        $this->assertSame(Character::STYLE_NONE, $character->referenceStyleState());
    }

    // -- The half that matters: it reaches the money button -------------------

    /**
     * Upstream of what it distrusts, in the process holding the button.
     *
     * A per-job check would be evaluated by the worker, which is the thing that
     * would generate the wrong images — and it would catch the mismatch 186
     * times, one scene at a time, after each had billed.
     */
    public function test_asset_dispatch_refuses_when_a_face_was_drawn_in_another_style(): void
    {
        Bus::fake();

        $story = $this->storyReadyToGenerate();

        config()->set('scenes.art_style', 'Anime-style illustration, flat cel shading.');

        $this->expectException(DispatchRefusedException::class);
        $this->expectExceptionMessageMatches('/different art style/');

        app(DispatchAssetGeneration::class)->handle($story, checkWorkers: false, checkAligner: false);
    }

    public function test_the_refusal_names_the_characters_and_the_way_out(): void
    {
        $story = $this->storyReadyToGenerate();

        config()->set('scenes.art_style', 'Anime-style illustration, flat cel shading.');

        try {
            app(PreflightAssetDispatch::class)->handle($story, checkWorkers: false, checkAligner: false);
            $this->fail('The preflight allowed a dispatch against a sheet in another style.');
        } catch (DispatchRefusedException $e) {
            $this->assertStringContainsString('Dana Whitfield', $e->getMessage());
            $this->assertStringContainsString('--no-style-check', $e->getMessage());
        }
    }

    /**
     * Stale refuses; unknown warns. The asymmetry is the design.
     *
     * A stale row is a positive reading — a fingerprint was recorded, compared,
     * and differs, so the waste is proven. An unknown row has nothing to
     * compare and no honest way to be cleared, so refusing on it would fire
     * forever on every legacy story and become a check somebody turns off.
     */
    public function test_an_unknown_sheet_warns_and_does_not_stop_the_dispatch(): void
    {
        $story = $this->storyReadyToGenerate(fingerprint: null);

        $notes = app(PreflightAssetDispatch::class)->handle($story, checkWorkers: false, checkAligner: false);

        $warnings = array_filter($notes, fn (array $n): bool => $n['level'] === 'warn');

        $this->assertNotEmpty($warnings, 'An unknown sheet passed without a word.');
        $this->assertStringContainsString(
            'predates style tracking',
            implode(' ', array_column($warnings, 'message')),
        );
    }

    /**
     * The escape hatch is named for what it gives up.
     */
    public function test_the_style_check_can_be_given_up_explicitly(): void
    {
        $story = $this->storyReadyToGenerate();

        config()->set('scenes.art_style', 'Anime-style illustration, flat cel shading.');

        $notes = app(PreflightAssetDispatch::class)->handle(
            $story,
            checkWorkers: false,
            checkAligner: false,
            checkStyle: false,
        );

        $this->assertSame([], array_filter($notes, fn (array $n): bool => str_contains($n['message'], 'art style')));
    }

    // -- fixtures -------------------------------------------------------------

    private function characterWithScenes(): Character
    {
        $story = Story::factory()->status(StoryStatus::ScenesApproved)->create();

        return Character::factory()->for($story)->create(['name' => 'Dana Whitfield']);
    }

    private function characterWithApprovedFace(?string $fingerprint = 'current'): Character
    {
        $character = $this->characterWithScenes();

        $reference = CharacterReference::factory()->for($character)->ready()->selected()->create([
            'style_fingerprint' => $fingerprint === 'current' ? StyleFingerprint::current() : $fingerprint,
        ]);

        $character->forceFill(['reference_image_path' => $reference->image_path])->save();

        return $character->fresh();
    }

    private function storyReadyToGenerate(?string $fingerprint = 'current'): Story
    {
        $character = $this->characterWithApprovedFace($fingerprint);
        $story = $character->story;

        $scene = Scene::factory()->for($story)->create(['sequence' => 1, 'image_path' => null]);
        $scene->characters()->attach($character);

        return $story->fresh();
    }
}
