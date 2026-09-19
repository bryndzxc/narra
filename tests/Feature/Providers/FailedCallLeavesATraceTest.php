<?php

namespace Tests\Feature\Providers;

use Anthropic\Client;
use App\Enums\RenderStage;
use App\Models\CostEntry;
use App\Models\RenderJob;
use App\Models\Story;
use App\Services\Claude\ClaudeScriptWriter;
use App\Services\Claude\StreamedMessage;
use App\Support\CharacterTextGuard;
use App\Support\LocaleGuard;
use App\Support\Providers\ScriptWriterException;
use App\Support\ResponseArchive;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use ReflectionMethod;
use Tests\TestCase;

/**
 * A call that costs money and fails still leaves a trace.
 *
 * Two outline calls on story 28 hit the 16,000-token ceiling on 2026-09-12.
 * Each was billed by the vendor at the ceiling; neither wrote a `cost_entries`
 * row, and neither was archived. The ceiling check threw before pricing and
 * before the archive line, so the message said "billed in full, at the ceiling"
 * while the ledger said nothing, and the question "where was the output going
 * when it stopped" had no bytes to answer it from. A third, story 23's, had
 * done the same a week earlier.
 *
 * These cases build the message a stream would have produced and hand it to
 * the step after the stream. Nothing here touches the network.
 */
class FailedCallLeavesATraceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config()->set('providers.archive_responses', true);
    }

    public function test_a_truncated_call_writes_its_cost_row_before_throwing(): void
    {
        $story = Story::factory()->create();
        $message = StreamedMessage::of('{"acts":[{"title":"Cut off mid', 'max_tokens', 2320, 16000, 3282, 0);

        $thrown = null;

        RenderJob::record($story->id, RenderStage::Outline, function () use ($message, &$thrown): void {
            try {
                $this->settle($message, 'generate_outline');
            } catch (ScriptWriterException $e) {
                $thrown = $e;
            }
        });

        $this->assertInstanceOf(ScriptWriterException::class, $thrown, 'A truncated call must still throw.');

        $row = CostEntry::query()->where('story_id', $story->id)->where('operation', 'generate_outline')->first();

        $this->assertNotNull($row, 'The truncated call was billed and wrote no ledger row.');
        $this->assertSame(16000, (int) $row->detail['output_tokens']);
        $this->assertGreaterThan(0, (float) $row->usd_cost);
        $this->assertStringContainsString('billed in full', $thrown->getMessage());
        $this->assertStringContainsString('cost row #'.$row->id, $thrown->getMessage(), 'The message should name the row it made.');
    }

    /**
     * RED/GREEN: a truncated outline is SEEN on Gate 1, and still seen after a
     * successful re-run has reset its job row — because it is read from the
     * cost row, which records why the call stopped. A completed call leaves no
     * such notice. Standing position (CLAUDE.md, 2026-09-19): the outline
     * ceiling is not raised, so a truncation is information and must show.
     */
    public function test_red_green_an_outline_truncation_outlives_the_rerun_that_replaced_it(): void
    {
        $story = Story::factory()->status(\App\Enums\StoryStatus::Draft)->create();
        $truncated = StreamedMessage::of('{"acts":[{"title":"Cut off mid', 'max_tokens', 2320, 16000, 3282, 0);

        RenderJob::record($story->id, RenderStage::Outline, function () use ($truncated): void {
            try {
                $this->settle($truncated, 'generate_outline');
            } catch (ScriptWriterException) {
                // The row is failed by record() only on a rethrow; the test
                // wants the successful re-run below to be the row's last word.
            }
        });

        $row = CostEntry::query()->where('story_id', $story->id)->sole();
        $this->assertSame('max_tokens', $row->detail['stop_reason']);
        $this->assertSame(config('providers.anthropic.operations.generate_outline.effort'), $row->detail['effort']);

        // The successful re-run: the job row now says succeeded.
        RenderJob::record($story->id, RenderStage::Outline, fn () => null);
        $this->assertSame(\App\Enums\RenderJobStatus::Succeeded, RenderJob::query()->where('story_id', $story->id)->sole()->status);

        \Livewire\Livewire::test(\App\Livewire\Gates\OutlineGate::class, ['story' => $story->fresh()])
            ->assertSee('The outline hit its output ceiling 1 time(s) on this story.')
            ->assertSee('16,000 output tokens');

        $clean = Story::factory()->status(\App\Enums\StoryStatus::Draft)->create();
        $done = StreamedMessage::of('{"acts":[]}', 'end_turn', 2320, 5000, 3282, 0);

        RenderJob::record($clean->id, RenderStage::Outline, function () use ($clean, $done): void {
            [, $usage] = $this->settle($done, 'generate_outline');
            app(\App\Actions\RecordProviderCost::class)->handle($clean, $usage);
        });

        $this->assertSame('end_turn', CostEntry::query()->where('story_id', $clean->id)->sole()->detail['stop_reason']);

        \Livewire\Livewire::test(\App\Livewire\Gates\OutlineGate::class, ['story' => $clean->fresh()])
            ->assertDontSee('hit its output ceiling');
    }

    /** A failed outline run is on Gate 1, not only on the progress page. */
    public function test_a_failed_outline_run_is_shown_on_gate_one_with_its_repair(): void
    {
        $story = Story::factory()->status(\App\Enums\StoryStatus::Draft)->create();

        try {
            RenderJob::record($story->id, RenderStage::Outline, function (): void {
                throw new ScriptWriterException(
                    'The outline\'s cast cannot be used: The cast has 2 antagonists.',
                    kind: \App\Enums\FailureKind::OutlineRefused,
                    facts: ['check' => 'cast_structure'],
                );
            });
        } catch (ScriptWriterException) {
        }

        \Livewire\Livewire::test(\App\Livewire\Gates\OutlineGate::class, ['story' => $story->fresh()])
            ->assertSee('Outline failed')
            ->assertSee('The cast has 2 antagonists.')
            ->assertSee('Not measured:');
    }

    public function test_a_refused_call_writes_its_cost_row_before_throwing(): void
    {
        $story = Story::factory()->create();
        $message = StreamedMessage::of('', 'refusal', 2320, 12, 3282, 0, 'some_category');

        $thrown = null;

        RenderJob::record($story->id, RenderStage::Outline, function () use ($message, &$thrown): void {
            try {
                $this->settle($message, 'generate_outline');
            } catch (ScriptWriterException $e) {
                $thrown = $e;
            }
        });

        $this->assertNotNull($thrown);
        $this->assertSame(1, CostEntry::query()->where('story_id', $story->id)->count());
        $this->assertStringContainsString('cost row #', $thrown->getMessage());
    }

    /**
     * The GREEN half: a completed call is priced here and RECORDED by its
     * Action, as it always was. Recording it here as well would bill it twice.
     */
    public function test_a_completed_call_is_not_recorded_by_the_settle_step(): void
    {
        $story = Story::factory()->create();
        $message = StreamedMessage::of('{"acts":[]}', 'end_turn', 2320, 5000, 3282, 0);

        RenderJob::record($story->id, RenderStage::Outline, function () use ($message): void {
            [$text, $usage] = $this->settle($message, 'generate_outline');

            $this->assertSame('{"acts":[]}', $text);
            $this->assertSame(5000, (int) $usage->detail['output_tokens']);
        });

        $this->assertSame(0, CostEntry::query()->where('story_id', $story->id)->count());
    }

    /**
     * Outside a recorded stage there is no story to charge. The message says
     * so — "NOT in the ledger" — rather than claiming a bill that has no row.
     */
    public function test_a_truncation_with_no_recording_stage_says_it_is_not_in_the_ledger(): void
    {
        $message = StreamedMessage::of('{"acts":[', 'max_tokens', 2320, 16000, 3282, 0);

        $this->assertNull(RenderJob::current());

        try {
            $this->settle($message, 'generate_outline');
            $this->fail('A truncated call must throw.');
        } catch (ScriptWriterException $e) {
            $this->assertStringContainsString('NOT in the ledger', $e->getMessage());
        }

        $this->assertSame(0, CostEntry::query()->count());
    }

    /**
     * The configured remedy reaches the PAGE, and not the stored message.
     *
     * Story 28's message said "No truncation_remedy is configured for this
     * operation" while config carried one, because the remedy was dropped on
     * the way to the thrower. It then went the other way: the remedy was
     * copied into the message and stored, so the row kept the advice of the
     * day it failed. This goes through the path a worker does — settle(), the
     * exception, RenderJob::fail(), the progress report — and asserts the
     * remedy is read from config when the report is built, by changing config
     * AFTER the failure is recorded.
     */
    public function test_the_configured_remedy_reaches_the_page_and_not_the_stored_message(): void
    {
        $story = Story::factory()->create();
        config()->set('providers.anthropic.operations.generate_outline.truncation_remedy', 'REMEDY-AT-FAILURE');

        $message = StreamedMessage::of('{"acts":[', 'max_tokens', 2320, 16000, 3282, 0);

        try {
            RenderJob::record($story->id, RenderStage::Outline, fn () => $this->settle($message, 'generate_outline'));
            $this->fail('A truncated call must throw.');
        } catch (ScriptWriterException $e) {
            $this->assertStringNotContainsString('REMEDY-AT-FAILURE', $e->getMessage());
        }

        $row = RenderJob::query()->where('story_id', $story->id)->where('stage', RenderStage::Outline)->firstOrFail();
        $this->assertSame(\App\Enums\FailureKind::Truncated, $row->failure_kind);
        $this->assertSame(['operation' => 'generate_outline'], $row->failure_facts);
        $this->assertStringNotContainsString('REMEDY-AT-FAILURE', (string) $row->error);

        // The advice changes after the failure. The page follows the code.
        config()->set('providers.anthropic.operations.generate_outline.truncation_remedy', 'REMEDY-NOW');

        $failure = \App\Support\RenderProgress::for($story->fresh())['failures'][0];
        $this->assertSame('REMEDY-NOW', $failure['remedy']->text);

        // And an operation with no measured remedy says so rather than guessing.
        config()->set('providers.anthropic.operations.generate_outline.truncation_remedy', null);
        $this->assertFalse(\App\Support\RenderProgress::for($story->fresh())['failures'][0]['remedy']->known);
    }

    public function test_a_truncated_response_is_archived_before_the_ceiling_check(): void
    {
        $story = Story::factory()->create();
        $partial = '{"hook":"The first time my wife did this","acts":[{"title":"Cut off mid';
        $message = StreamedMessage::of($partial, 'max_tokens', 2320, 16000, 3282, 0);

        RenderJob::record($story->id, RenderStage::Outline, function () use ($message): void {
            try {
                $this->settle($message, 'generate_outline');
            } catch (ScriptWriterException) {
                // expected
            }
        });

        $files = ResponseArchive::forStory($story->id);

        $this->assertCount(1, $files, 'The truncated response was not archived.');
        $this->assertStringContainsString('generate_outline', $files[0]);
        $this->assertSame($partial, gzdecode((string) Storage::disk('local')->get($files[0])));
    }

    /**
     * Archived ONCE, in settle(). decodeJson() used to archive as well; with
     * both in place every successful response would be filed twice.
     */
    public function test_a_completed_response_is_archived_exactly_once(): void
    {
        $story = Story::factory()->create();
        $message = StreamedMessage::of('{"acts":[]}', 'end_turn', 2320, 5000, 3282, 0);

        RenderJob::record($story->id, RenderStage::Outline, function () use ($message): void {
            [$text, $usage] = $this->settle($message, 'generate_outline');

            $decode = new ReflectionMethod($this->writer(), 'decodeJson');
            $decode->setAccessible(true);
            $decode->invoke($this->writer(), $text, 'outline', $usage);
        });

        $this->assertCount(1, ResponseArchive::forStory($story->id));
    }

    // -- Helpers --------------------------------------------------------------

    // -- The DISCARDED first attempt of a scene draft ------------------------

    /**
     * A scene draft bills twice when the cheap model is rejected, and the
     * second bill is the one that can throw.
     *
     * `scenes()` bills Haiku, keeps its usage in `$discarded`, calls Sonnet,
     * and hands both back in a SceneDraftSet for DraftScenes to record. When
     * the Sonnet call truncates there is no set to hand back and the Haiku
     * usage dies with the exception. Story 28 act 2: billed ~$0.035, absent
     * from `cost_entries`, while the Sonnet half of the same act WAS recorded
     * by the truncation path — one row where there should be two.
     */
    public function test_a_usage_with_no_action_to_record_it_is_written_against_the_open_stage(): void
    {
        $story = Story::factory()->create();
        $writer = $this->writer();

        $usage = $this->priceOf($writer, StreamedMessage::of('{"scenes":[]}', 'end_turn', 900, 4227, 0, 0));

        $said = RenderJob::record($story->id, RenderStage::DraftScenes, function () use ($writer, $usage): string {
            $record = new ReflectionMethod($writer, 'recordSpendWithNoAction');
            $record->setAccessible(true);

            return $record->invoke($writer, $usage, 'draft_scenes');
        });

        $row = CostEntry::query()->where('story_id', $story->id)->where('operation', 'draft_scenes')->first();

        $this->assertNotNull($row, 'The discarded attempt was billed and wrote no ledger row.');
        $this->assertSame(4227, (int) $row->detail['output_tokens']);
        $this->assertStringContainsString('cost row #'.$row->id, $said);
    }

    /**
     * The GREEN half: outside a recorded stage there is no story to charge, so
     * it says the spend is NOT in the ledger rather than claiming a bill.
     */
    public function test_a_usage_with_no_stage_recording_says_it_is_not_in_the_ledger(): void
    {
        $writer = $this->writer();

        $this->assertNull(RenderJob::current());

        $record = new ReflectionMethod($writer, 'recordSpendWithNoAction');
        $record->setAccessible(true);

        $said = $record->invoke(
            $writer,
            $this->priceOf($writer, StreamedMessage::of('{}', 'end_turn', 900, 4227, 0, 0)),
            'draft_scenes',
        );

        $this->assertStringContainsString('NOT in the ledger', $said);
        $this->assertSame(0, CostEntry::query()->count());
    }

    /**
     * AND THE CALL SITE ACTUALLY REACHES IT.
     *
     * Asserted on source because the failing path needs two live streams — an
     * unusable first response and a truncated second — and `scenes()` builds
     * both from a real client. The builder being correct while nothing calls
     * it is this file's own founding defect (`truncationMessage()` was well
     * tested and unreachable), so the delegation is checked rather than
     * assumed.
     *
     * Comments are stripped first: a docblock quoting the old shape to explain
     * the change would otherwise satisfy a source scan, which is the mistake
     * the clip-stage guard's assertion made.
     */
    public function test_the_scene_fallback_records_the_discarded_attempt_when_it_throws(): void
    {
        $body = $this->methodSource(ClaudeScriptWriter::class, 'scenes');

        $this->assertStringContainsString('try {', $body, 'The fallback call is not guarded at all.');
        $this->assertStringContainsString('catch', $body);
        $this->assertStringContainsString(
            'recordSpendWithNoAction($discarded[0]',
            $body,
            'The fallback path does not record the attempt it discarded.',
        );

        $this->assertLessThan(
            mb_strpos($body, 'recordSpendWithNoAction'),
            mb_strpos($body, '$discarded[] = $usage;'),
            'The attempt must be discarded before the path that records it.',
        );
    }

    // -- Refusals the writer makes after a call that finished ---------------
    //
    // A response that is not JSON, an outline with no acts, an act with no
    // text, a cast of nobody, no scenes. Until 2026-09-19 each threw a bare
    // exception with the usage still in a local variable: billed, and never in
    // the ledger, and "No known repair." on the page. They go through
    // TalksToClaude::refuseOutput() now, which writes the row first.

    public function test_a_response_that_is_not_json_writes_its_cost_row_and_is_a_refused_output(): void
    {
        $story = Story::factory()->create();
        $writer = $this->writer();
        $usage = $this->priceOf($writer, StreamedMessage::of('not json', 'end_turn', 900, 3000, 0, 0));

        $e = $this->refusedInside($story, RenderStage::DraftScenes, function () use ($writer, $usage): void {
            $decode = new ReflectionMethod($writer, 'decodeJson');
            $decode->setAccessible(true);
            $decode->invoke($writer, 'not json', 'scenes for act 1', $usage);
        });

        $this->assertSame(\App\Enums\FailureKind::OutputRefused, $e->failureKind());
        $this->assertSame(['check' => 'malformed_response', 'operation' => 'draft_scenes'], $e->failureFacts());
        $this->assertSame(1, CostEntry::query()->where('story_id', $story->id)->where('operation', 'draft_scenes')->count());
        $this->assertStringContainsString('cost row #', $e->getMessage());
    }

    public function test_an_act_with_no_text_writes_its_cost_row_and_names_its_act(): void
    {
        $story = Story::factory()->create();
        $writer = $this->writer();
        $usage = $this->priceOf($writer, StreamedMessage::of('{"chapters":[]}', 'end_turn', 900, 50, 0, 0));
        $act = new \App\Support\Providers\ActOutline(sequence: 4, title: 'T', summary: 'S');

        $e = $this->refusedInside($story, RenderStage::ActScripts, function () use ($writer, $usage, $act): void {
            $from = new ReflectionMethod($writer, 'actDraftFrom');
            $from->setAccessible(true);
            $from->invoke($writer, $act, ['chapters' => [['title' => 'x', 'text' => '  ']]], $usage);
        });

        $this->assertSame(\App\Enums\FailureKind::OutputRefused, $e->failureKind());
        $this->assertSame('empty_output', $e->failureFacts()['check']);
        $this->assertSame(4, $e->failureFacts()['act']);
        $this->assertSame(1, CostEntry::query()->where('story_id', $story->id)->count());
    }

    /** The outline's own kind, so its remedy is the outline's (Write, --outline-only). */
    public function test_an_outline_with_no_acts_is_an_outline_refusal_with_its_cost_row(): void
    {
        $story = Story::factory()->create();
        $writer = $this->writer();
        $usage = $this->priceOf($writer, StreamedMessage::of('{"acts":[]}', 'end_turn', 900, 50, 0, 0));
        $usage = new \App\Support\Providers\ProviderUsage(
            $usage->provider, 'generate_outline', $usage->category, $usage->quantity, $usage->unit,
            $usage->usdCost, $usage->detail, $usage->simulated, $usage->model,
        );

        $e = $this->refusedInside($story, RenderStage::Outline, function () use ($writer, $usage, $story): void {
            $from = new ReflectionMethod($writer, 'outlineDraftFrom');
            $from->setAccessible(true);
            $from->invoke($writer, $story, 5, ['acts' => []], $usage);
        });

        $this->assertSame(\App\Enums\FailureKind::OutlineRefused, $e->failureKind());
        $this->assertSame('act_count', $e->failureFacts()['check']);
        $this->assertSame(1, CostEntry::query()->where('story_id', $story->id)->where('operation', 'generate_outline')->count());
    }

    /**
     * THE FAMILY, BY CONSTRUCTION: no bare ScriptWriterException is left in
     * the writers. Every throw after a call goes through refuseOutput(), and
     * the two inline refusals that need a live stream to reach (a cast of
     * nobody, no scenes) are asserted at their call sites, the scene one with
     * the discarded attempt it would otherwise lose. Comments stripped, so a
     * docblock quoting the old shape cannot satisfy or fail it.
     */
    public function test_no_writer_refuses_a_billed_response_without_the_ledger_helper(): void
    {
        foreach ([ClaudeScriptWriter::class, \App\Services\Claude\ClaudeMetadataWriter::class] as $class) {
            $source = $this->classSource($class);
            $this->assertStringNotContainsString('throw new ScriptWriterException', $source, "{$class} throws past the ledger.");
        }

        $this->assertStringContainsString("refuseOutput('Character extraction returned nobody.'", $this->methodSource(ClaudeScriptWriter::class, 'characters'));
        $this->assertStringContainsString('...$discarded)', $this->methodSource(ClaudeScriptWriter::class, 'scenes'));
    }

    /** Where no row supplies the stage, the remedy finds it from the operation. */
    public function test_a_writer_refusal_names_its_button_from_the_operation(): void
    {
        $story = Story::factory()->create(['status' => \App\Enums\StoryStatus::Scripted]);

        $remedy = \App\Support\FailureRemedy::for(
            \App\Enums\FailureKind::OutputRefused,
            ['check' => 'empty_output', 'operation' => 'extract_characters'],
            $story,
        );

        $this->assertSame(\App\Enums\OperatorAction::DraftSceneList->label(), $remedy->actionLabel);
        $this->assertNotNull($remedy->unmeasured);
    }

    private function refusedInside(Story $story, RenderStage $stage, \Closure $work): ScriptWriterException
    {
        try {
            RenderJob::record($story->id, $stage, function () use ($work): void {
                $work();
            });
        } catch (ScriptWriterException $e) {
            return $e;
        }

        $this->fail('The writer did not refuse.');
    }

    private function classSource(string $class): string
    {
        $out = '';

        foreach (token_get_all((string) file_get_contents((new \ReflectionClass($class))->getFileName())) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $out .= is_array($token) ? $token[1] : $token;
        }

        return $out;
    }

    /**
     * The source of one method, with comments removed.
     */
    private function methodSource(string $class, string $method): string
    {
        $reflected = new ReflectionMethod($class, $method);
        $lines = file($reflected->getFileName());
        $source = implode('', array_slice(
            $lines,
            $reflected->getStartLine() - 1,
            $reflected->getEndLine() - $reflected->getStartLine() + 1,
        ));

        $out = '';

        foreach (token_get_all('<?php '.$source) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $out .= is_array($token) ? $token[1] : $token;
        }

        return $out;
    }

    private function priceOf(ClaudeScriptWriter $writer, StreamedMessage $message): \App\Support\Providers\ProviderUsage
    {
        $config = new ReflectionMethod($writer, 'operationConfig');
        $config->setAccessible(true);

        $price = new ReflectionMethod($writer, 'priceUsage');
        $price->setAccessible(true);

        return $price->invoke($writer, $message, 'draft_scenes', $config->invoke($writer, 'draft_scenes'));
    }

    /**
     * @return array{0: string, 1: \App\Support\Providers\ProviderUsage}
     */
    private function settle(StreamedMessage $message, string $operation): array
    {
        $writer = $this->writer();

        $config = new ReflectionMethod($writer, 'operationConfig');
        $config->setAccessible(true);

        $settle = new ReflectionMethod($writer, 'settle');
        $settle->setAccessible(true);

        return $settle->invoke($writer, $message, $operation, $config->invoke($writer, $operation));
    }

    private function writer(): ClaudeScriptWriter
    {
        return new ClaudeScriptWriter(
            // Never called: the stream is replaced by StreamedMessage::of().
            client: app(Client::class),
            locale: app(LocaleGuard::class),
            text: app(CharacterTextGuard::class),
        );
    }
}
