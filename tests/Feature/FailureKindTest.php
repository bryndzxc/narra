<?php

namespace Tests\Feature;

use App\Enums\FailureKind;
use App\Enums\RenderStage;
use App\Exceptions\PipelineFailure;
use App\Models\RenderJob;
use App\Models\Story;
use App\Services\WhisperX\WhisperXTranscriber;
use App\Support\Providers\ScriptWriterException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;

/**
 * What kind of failure a row records, read from the exception that caused it.
 *
 * The kind is the stored FACT the repair is built from at display time, so a
 * wrong kind is a wrong repair on every page load for as long as the row
 * exists. Each case is a RED/GREEN pair where the difference is one detail.
 */
class FailureKindTest extends TestCase
{
    use RefreshDatabase;

    /**
     * RED/GREEN: a classified cause under an unclassified wrapper is found.
     *
     * TalksToClaude wraps a transport failure in a ScriptWriterException that
     * carries no kind. Stopping at the first ClassifiedFailure in the chain
     * would report every wrapped cause as Unclassified.
     */
    public function test_a_classified_cause_under_an_unclassified_wrapper_is_found(): void
    {
        $cause = new PipelineFailure('scene 4 has no narration audio', FailureKind::NarrationMissing, ['scene' => 4]);
        $wrapped = new ScriptWriterException('draft_scenes failed', previous: $cause);

        $this->assertSame([FailureKind::NarrationMissing, ['scene' => 4]], FailureKind::of($wrapped));
        $this->assertSame([FailureKind::Unclassified, []], FailureKind::of(new ScriptWriterException('draft_scenes failed')));
    }

    public function test_red_green_a_truncated_column_is_schema_drift_and_another_query_error_is_not(): void
    {
        $drift = new QueryException('mysql', 'insert into cost_entries', [], new \PDOException(
            "SQLSTATE[01000]: Warning: 1265 Data truncated for column 'unit' at row 1"
        ));
        $other = new QueryException('mysql', 'insert into cost_entries', [], new \PDOException(
            "SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry '12-30001'"
        ));

        $this->assertSame([FailureKind::SchemaDrift, ['column' => 'unit']], FailureKind::of($drift));
        $this->assertSame(FailureKind::Unclassified, FailureKind::of($other)[0]);
    }

    public function test_red_green_a_curl_timeout_is_a_vendor_timeout_and_a_refused_connection_is_not(): void
    {
        $this->assertSame(FailureKind::VendorTimeout, FailureKind::of(new ConnectionException(
            'cURL error 28: Operation timed out after 180001 milliseconds with 0 bytes received'
        ))[0]);

        $this->assertSame(FailureKind::Unclassified, FailureKind::of(new ConnectionException(
            'cURL error 7: Failed to connect to fal.run port 443'
        ))[0]);

        // And the same words on an exception that is not a connection failure
        // are not a timeout: the class is part of the evidence.
        $this->assertSame(FailureKind::Unclassified, FailureKind::of(new RuntimeException('cURL error 28'))[0]);
    }

    /**
     * RED/GREEN on the WhisperX detector, fed the exact error the 328 failed
     * alignments in failed_jobs carried, and a different missing module.
     */
    public function test_red_green_whisperx_missing_is_the_aligner_and_another_missing_module_is_not(): void
    {
        $detect = new ReflectionMethod(WhisperXTranscriber::class, 'missingModule');

        $this->assertTrue($detect->invoke(null, "ModuleNotFoundError: No module named 'whisperx'"));
        $this->assertFalse($detect->invoke(null, "ModuleNotFoundError: No module named 'torchaudio'"));
    }

    /** The row keeps the kind and facts, and a reopen clears them. */
    public function test_the_row_records_the_kind_and_a_reopen_clears_it(): void
    {
        $story = Story::factory()->create();

        RenderJob::open($story->id, RenderStage::Concat)->fail(
            new PipelineFailure('Missing clip for scene 9.', FailureKind::ClipMissing, ['scene' => 9])
        );

        $row = RenderJob::query()->where('story_id', $story->id)->firstOrFail();
        $this->assertSame(FailureKind::ClipMissing, $row->failure_kind);
        $this->assertSame(['scene' => 9], $row->failure_facts);
        // The stored message carries the fact and no procedure.
        $this->assertSame('Missing clip for scene 9.', $row->error);

        RenderJob::open($story->id, RenderStage::Concat);

        $row->refresh();
        $this->assertNull($row->failure_kind);
        $this->assertNull($row->failure_facts);
    }
}
