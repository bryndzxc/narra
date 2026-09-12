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
     * The configured remedy reaches the thrown message.
     *
     * Story 28's message said "No truncation_remedy is configured for this
     * operation" while config carried one: operationConfig() returned model,
     * effort and max_tokens and dropped the remedy. TruncationMessageTest reads
     * config directly, so it could not see that — this goes through the same
     * path the worker does.
     */
    public function test_the_thrown_message_carries_the_configured_remedy(): void
    {
        config()->set(
            'providers.anthropic.operations.generate_outline.truncation_remedy',
            'REMEDY-SENTINEL: lower the effort for this stage.',
        );

        $message = StreamedMessage::of('{"acts":[', 'max_tokens', 2320, 16000, 3282, 0);

        try {
            $this->settle($message, 'generate_outline');
            $this->fail('A truncated call must throw.');
        } catch (ScriptWriterException $e) {
            $this->assertStringContainsString('REMEDY-SENTINEL', $e->getMessage());
            $this->assertStringNotContainsString('No truncation_remedy is configured', $e->getMessage());
        }
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
            [$text] = $this->settle($message, 'generate_outline');

            $decode = new ReflectionMethod($this->writer(), 'decodeJson');
            $decode->setAccessible(true);
            $decode->invoke($this->writer(), $text, 'outline');
        });

        $this->assertCount(1, ResponseArchive::forStory($story->id));
    }

    // -- Helpers --------------------------------------------------------------

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
