<?php

namespace Tests\Feature\Providers;

use Anthropic\Client;
use App\Actions\GenerateActScripts;
use App\Actions\GenerateOutline;
use App\Contracts\ScriptWriter;
use App\Enums\StoryStatus;
use App\Models\Act;
use App\Models\Story;
use App\Services\Claude\ClaudeScriptWriter;
use App\Services\Fake\FakeScriptWriter;
use App\Support\CharacterTextGuard;
use App\Support\LocaleGuard;
use App\Support\Providers\ActOutline;
use App\Support\Providers\ActScriptDraft;
use App\Support\Providers\ScriptWriterException;
use Faker\Generator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

/**
 * The act writer cannot return a summary Gate 1's form would refuse.
 *
 * Story 28, 2026-09-12: the act-script stage replaced act 4's outline summary
 * with the writer's own, 2,026 characters in five sentences, and Gate 1's
 * save() rule — a literal 2,000 sized by feel for the OUTLINE writer — refused
 * it. Silently, because the summary field had no `@error`. Three layers were
 * wrong at once and each is drilled here or in OutlineGateTest:
 *
 *   the value    the bound now comes from Act::SUMMARY_MAX_CHARS, derived from
 *                the measured distribution of what the act writer returns;
 *   the silence  OutlineGateTest — every rule's refusal reaches the gate bar;
 *   the source   THIS FILE — the bound is stated in the prompt, cannot be a
 *                schema constraint, and is enforced against the decoded
 *                response after the cost row, so an over-long summary is
 *                unreturnable from the act call rather than merely unlikely.
 */
class ActTextBoundsTest extends TestCase
{
    use RefreshDatabase;

    // -- The act call --------------------------------------------------------

    /**
     * RED: one character over the bound is refused, loudly, and NOT stored.
     */
    public function test_an_act_summary_one_over_the_bound_is_refused_and_not_stored(): void
    {
        $story = $this->outlinedStory();

        $this->bindWriterReturningSummaryOf(Act::SUMMARY_MAX_CHARS + 1);

        try {
            app(GenerateActScripts::class)->handle($story);
            $this->fail('An over-long act summary must be refused.');
        } catch (ScriptWriterException $e) {
            $this->assertStringContainsString('Act 1 came back with a summary is', $e->getMessage());
            $this->assertStringContainsString(number_format(Act::SUMMARY_MAX_CHARS + 1), $e->getMessage());
            $this->assertStringContainsString('NOT stored', $e->getMessage());
        }

        $this->assertNull($story->acts()->where('sequence', 1)->value('script'), 'The refused act must not be stored.');
    }

    /**
     * GREEN: exactly at the bound is accepted and stored — the pair that stops
     * the guard being made green by refusing everything.
     */
    public function test_an_act_summary_at_the_bound_is_stored(): void
    {
        $story = $this->outlinedStory();

        $this->bindWriterReturningSummaryOf(Act::SUMMARY_MAX_CHARS);

        app(GenerateActScripts::class)->handle($story);

        $act = $story->acts()->where('sequence', 1)->first();

        $this->assertNotNull($act->script);
        $this->assertSame(Act::SUMMARY_MAX_CHARS, mb_strlen($act->summary));
    }

    /**
     * The fake's own summaries are well inside the bound, so the rest of the
     * suite is not passing this guard by accident of a short fixture.
     */
    public function test_the_fake_writer_stays_inside_the_bound(): void
    {
        $story = $this->outlinedStory();

        app(GenerateActScripts::class)->handle($story);

        foreach ($story->acts as $act) {
            $this->assertLessThanOrEqual(Act::SUMMARY_MAX_CHARS, mb_strlen((string) $act->summary));
        }
    }

    // -- The outline call ----------------------------------------------------

    public function test_an_outline_act_summary_over_the_bound_is_refused_and_nothing_is_stored(): void
    {
        $story = Story::factory()->status(StoryStatus::Draft)->single()->create();

        /** @var FakeScriptWriter $writer */
        $writer = app(ScriptWriter::class);
        $writer->injectIntoOutline = str_repeat('y', Act::SUMMARY_MAX_CHARS);

        try {
            app(GenerateOutline::class)->handle($story, 6);
            $this->fail('An over-long outline summary must be refused.');
        } catch (ScriptWriterException $e) {
            $this->assertStringContainsString('came back with a summary is', $e->getMessage());
        }

        $this->assertSame(0, $story->acts()->count(), 'A refused outline stores no acts.');
    }

    public function test_an_outline_inside_the_bounds_is_stored(): void
    {
        $story = Story::factory()->status(StoryStatus::Draft)->single()->create();

        app(GenerateOutline::class)->handle($story, 6);

        $this->assertSame(6, $story->acts()->count());
    }

    // -- The prompt and the schema -------------------------------------------

    /**
     * The writer is TOLD the bound, in both calls, from the same constant the
     * guard enforces. A guard the writer is not told about is a re-bill.
     */
    public function test_both_prompts_state_the_summary_bound(): void
    {
        $story = Story::factory()->single()->create();
        $writer = $this->realWriterWithNoClient();

        $act = new ActOutline(sequence: 1, title: 'One', summary: 'One.');
        $outline = [$act, new ActOutline(sequence: 2, title: 'Two', summary: 'Two.')];

        $actPrompt = $this->invoke($writer, 'actPrompt', $story, $act, $outline, [], 985);
        $outlinePrompt = $this->invoke($writer, 'outlinePrompt', $story, 6);

        $stated = 'at most '.number_format(Act::SUMMARY_MAX_CHARS).' characters';

        $this->assertStringContainsString($stated, strtolower($actPrompt));
        $this->assertStringContainsString($stated, strtolower($outlinePrompt));
    }

    /**
     * THE SUMMARY IS ASKED FOR AS FIVE SENTENCES WITH A JOB EACH, NOT AS A
     * LENGTH, AND THAT IS THE LEVER THE BOUND'S OWN DOCBLOCK NAMES.
     *
     * The bound has now fired three times in twelve acts — story 30's act 3
     * at 3,121 characters, story 31's act 1 at 3,111 and act 4 at 3,233 —
     * and each refusal is a billed call that stores nothing. It got worse, not
     * better, as acts got longer: a summary scales with the act it summarises.
     *
     * The old ask was "3-5 sentences on what happened in the act". That is a
     * stated COUNT, and this pass measured that a stated count steers
     * absolutely — so it WAS obeyed. The writer returned three to five
     * sentences every time. What nobody stated is how big a sentence may be,
     * and the thing that overflows is characters, so the instruction counted
     * the one quantity that was never the problem.
     *
     * The fix does not ask for LESS: the summary is the running context that
     * keeps a 7,000-word story coherent, and this file's own rule is that
     * trimming it spends coherence to save a form field. It asks for the same
     * facts with a SHAPE — one sentence per job, five jobs — which bounds the
     * sentence by giving it a single thing to do.
     */
    public function test_the_summary_is_asked_for_as_five_sentences_with_a_job_each(): void
    {
        $story = Story::factory()->single()->create();
        $writer = $this->realWriterWithNoClient();

        $act = new ActOutline(sequence: 1, title: 'One', summary: 'One.');
        $outline = [$act, new ActOutline(sequence: 2, title: 'Two', summary: 'Two.')];

        $prompt = $this->invoke($writer, 'actPrompt', $story, $act, $outline, [], 985);

        $this->assertStringContainsString('FIVE SENTENCES, ONE EACH', $prompt);
        $this->assertStringContainsString('FIVE SENTENCES, NOT FIVE PARAGRAPHS', $prompt);

        // One sentence per job, and the jobs named, so a sentence has a
        // single thing to do rather than a subject to expand on.
        foreach ([
            'what happened in this act',
            'what was said that matters, with the one line quoted',
            'what it cost, and to whom',
            'where things stand at the end',
            'the one thing the next act must not contradict',
        ] as $job) {
            $this->assertStringContainsString($job, $prompt, "The summary ask dropped: {$job}");
        }

        // The open-ended version must be gone, not merely joined by the new
        // one — two asks in one prompt is the act-1 collision, one field over.
        $this->assertStringNotContainsString('3-5 sentences on what happened', $prompt);

        // And the bound still travels with it.
        $this->assertStringContainsString(
            'at most '.number_format(Act::SUMMARY_MAX_CHARS).' characters',
            strtolower($prompt),
        );
    }

    /**
     * Neither schema carries maxLength. Structured outputs do not honour it,
     * so a bound written there is either a 400 on every call or a constraint
     * that reads as enforced and is not — the documented-guard shape in a
     * schema. This is the assertion that stops somebody "fixing" it in.
     */
    public function test_the_schemas_carry_no_string_length_constraint(): void
    {
        $writer = $this->realWriterWithNoClient();

        foreach (['actSchema', 'outlineSchema'] as $schema) {
            $json = json_encode($this->invoke($writer, $schema));

            $this->assertStringNotContainsString('maxLength', $json, "{$schema} must not carry maxLength.");
            $this->assertStringNotContainsString('minLength', $json, "{$schema} must not carry minLength.");
        }
    }

    // -- Fixtures ------------------------------------------------------------

    private function outlinedStory(): Story
    {
        $story = Story::factory()->status(StoryStatus::Outlined)->single()->create();

        app(Generator::class)->unique(reset: true);

        foreach ([1, 2, 3] as $sequence) {
            Act::factory()->for($story)->atSequence($sequence)->create(['script' => null]);
        }

        return $story;
    }

    /**
     * The fake writer with its act summary replaced by one of exactly $length
     * characters. Everything else — the script, the re-hook, the recorded
     * calls, the simulated usage — is the fake's own.
     */
    private function bindWriterReturningSummaryOf(int $length): void
    {
        $stub = new class($length) extends FakeScriptWriter
        {
            public function __construct(private readonly int $length) {}

            public function actScript(
                Story $story,
                ActOutline $act,
                array $fullOutline,
                array $priorSummaries,
                int $targetWords,
            ): ActScriptDraft {
                $draft = parent::actScript($story, $act, $fullOutline, $priorSummaries, $targetWords);

                return new ActScriptDraft(
                    sequence: $draft->sequence,
                    script: $draft->script,
                    summary: str_repeat('s', $this->length),
                    rehookLine: $draft->rehookLine,
                    usage: $draft->usage,
                    // Carried through, so this stub is about the summary and
                    // not refused one check later for having no chapters.
                    chapters: $draft->chapters,
                );
            }
        };

        app()->instance(ScriptWriter::class, $stub);
    }

    private function realWriterWithNoClient(): ClaudeScriptWriter
    {
        // Never called: only private prompt and schema builders are invoked.
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
