<?php

namespace Tests\Feature\Providers;

use Anthropic\Client;
use App\Actions\DraftScenes;
use App\Actions\ExtractCharacters;
use App\Actions\GenerateActScripts;
use App\Actions\GenerateOutline;
use App\Contracts\ScriptWriter;
use App\Enums\Gate;
use App\Enums\StoryFormat;
use App\Enums\StoryStatus;
use App\Models\Act;
use App\Models\Scene;
use App\Models\Story;
use App\Services\Claude\ClaudeScriptWriter;
use App\Services\Fake\FakeScriptWriter;
use App\Support\CharacterTextGuard;
use App\Support\LocaleGuard;
use App\Support\Providers\SceneDraft;
use App\Support\Providers\SceneDraftSet;
use App\Support\Providers\ScriptWriterException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

/**
 * The scene writer cannot return a frame or an expression Gate 2's editor
 * would refuse.
 *
 * Gate 2's editor validates the two sections the operator can type — the
 * frame and the expression — against Scene::FRAME_MAX_CHARS and
 * Scene::EXPRESSION_MAX_CHARS. The scene writer writes those same two
 * sections first, so the same three layers apply as for the act summary:
 * the bound is stated in the prompt, cannot be a schema constraint, and is
 * enforced against the decoded response after the cost row in DraftScenes.
 */
class SceneTextBoundsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_frame_one_over_the_bound_refuses_the_act_and_stores_no_scenes(): void
    {
        $story = $this->castStory();

        $this->bindWriterReturningFrameOf(Scene::FRAME_MAX_CHARS + 1);

        try {
            app(DraftScenes::class)->handle($story);
            $this->fail('An over-long frame must be refused.');
        } catch (ScriptWriterException $e) {
            $this->assertStringContainsString('came back with a frame is', $e->getMessage());
            $this->assertStringContainsString(number_format(Scene::FRAME_MAX_CHARS + 1), $e->getMessage());
            $this->assertSame(\App\Enums\FailureKind::OutputRefused, $e->failureKind());
            $this->assertSame(['stage' => 'draft_scenes', 'check' => 'scene_bounds', 'act' => 1], $e->failureFacts());
        }

        $this->assertSame(0, $story->scenes()->count(), 'A refused act stores no scenes.');
    }

    /** Ranges that leave a gap are refused as a refused output of the draft, with its act. */
    public function test_scenes_that_do_not_tile_the_act_are_recorded_as_a_refused_output(): void
    {
        $story = $this->castStory();

        $this->bindWriterReturningFrameOf(null, firstSentence: 2);

        try {
            app(DraftScenes::class)->handle($story);
            $this->fail('A gap in the ranges must be refused.');
        } catch (\App\Exceptions\PipelineFailure $e) {
            $this->assertStringContainsString('starts at sentence 2', $e->getMessage());
            $this->assertSame(\App\Enums\FailureKind::OutputRefused, $e->failureKind());
            $this->assertSame(['stage' => 'draft_scenes', 'check' => 'scene_tiling', 'act' => 1], $e->failureFacts());
        }

        $this->assertSame(0, $story->scenes()->count());
    }

    public function test_a_frame_at_the_bound_is_stored(): void
    {
        $story = $this->castStory();

        $this->bindWriterReturningFrameOf(Scene::FRAME_MAX_CHARS);

        app(DraftScenes::class)->handle($story);

        $this->assertGreaterThan(0, $story->scenes()->count());
    }

    public function test_the_fake_writer_stays_inside_the_bounds(): void
    {
        $story = $this->castStory();

        app(DraftScenes::class)->handle($story);

        foreach ($story->scenes as $scene) {
            $this->assertSame([], Scene::textOverflows([
                'frame' => \App\Support\ImagePromptBuilder::frameFrom((string) $scene->image_prompt),
                'expression' => \App\Support\ImagePromptBuilder::expressionFrom((string) $scene->image_prompt),
            ]));
        }
    }

    public function test_the_scene_prompt_states_both_bounds(): void
    {
        $prompt = strtolower($this->scenePrompt());

        $this->assertStringContainsString('at most '.Scene::FRAME_MAX_CHARS.' characters', $prompt);
        $this->assertStringContainsString('at most '.Scene::EXPRESSION_MAX_CHARS.' characters', $prompt);
    }

    /**
     * No maxLength in the scene schema either — see ActTextBoundsTest for why
     * a bound written there would be a limit that reads as enforced and is not.
     */
    public function test_the_scene_schema_carries_no_string_length_constraint(): void
    {
        $json = json_encode($this->invoke($this->realWriterWithNoClient(), 'sceneSchema'));

        $this->assertStringNotContainsString('maxLength', $json);
        $this->assertStringNotContainsString('minLength', $json);
    }

    // -- Fixtures ------------------------------------------------------------

    private function castStory(): Story
    {
        $story = Story::factory()->status(StoryStatus::Draft)->create([
            'format' => StoryFormat::Single,
            'locale_profile' => 'en-US',
            'premise' => 'My brother lived in our mother house rent free for four years while I paid for it.',
        ]);

        app(GenerateOutline::class)->handle($story, 3);
        $story->refresh()->approveGate(Gate::Outline);
        app(GenerateActScripts::class)->handle($story->refresh());
        app(ExtractCharacters::class)->handle($story->refresh());

        return $story->refresh();
    }

    /**
     * The fake writer with the FIRST scene's frame replaced by one of exactly
     * $length characters. Everything else is the fake's own.
     */
    private function bindWriterReturningFrameOf(?int $length, ?int $firstSentence = null): void
    {
        $stub = new class($length, $firstSentence) extends FakeScriptWriter
        {
            public function __construct(private readonly ?int $length, private readonly ?int $firstSentence) {}

            public function scenes(Story $story, Act $act, array $sentences, array $cast, int $targetScenes): SceneDraftSet
            {
                $set = parent::scenes($story, $act, $sentences, $cast, $targetScenes);
                $scenes = $set->scenes;
                $first = $scenes[0];

                $scenes[0] = new SceneDraft(
                    firstSentence: $this->firstSentence ?? $first->firstSentence,
                    lastSentence: max($first->lastSentence, $this->firstSentence ?? 0),
                    frame: $this->length === null ? $first->frame : str_repeat('f', $this->length),
                    charactersPresent: $first->charactersPresent,
                    motionPreset: $first->motionPreset,
                    isThumbnailCandidate: $first->isThumbnailCandidate,
                    expression: $first->expression,
                );

                return new SceneDraftSet($scenes, $set->usage, $set->discardedAttempts, $set->fallbackReason);
            }
        };

        app()->instance(ScriptWriter::class, $stub);
    }

    private function scenePrompt(): string
    {
        $story = Story::factory()->single()->create();
        $act = Act::factory()->for($story)->atSequence(1)->create([
            'script' => 'One sentence. Another sentence. A third sentence.',
        ]);

        return $this->invoke(
            $this->realWriterWithNoClient(),
            'scenePrompt',
            $story,
            $act,
            ['One sentence.', 'Another sentence.', 'A third sentence.'],
            [],
            3,
        );
    }

    private function realWriterWithNoClient(): ClaudeScriptWriter
    {
        return new ClaudeScriptWriter(
            client: app(Client::class),
            locale: app(LocaleGuard::class),
            text: app(CharacterTextGuard::class),
        );
    }

    private function invoke(object $target, string $method, mixed ...$args): mixed
    {
        $reflected = new ReflectionMethod($target, $method);
        $reflected->setAccessible(true);

        return $reflected->invoke($target, ...$args);
    }
}
