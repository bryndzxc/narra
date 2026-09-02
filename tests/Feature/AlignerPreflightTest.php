<?php

namespace Tests\Feature;

use App\Exceptions\DispatchRefusedException;
use App\Support\AlignerProbe;
use Tests\TestCase;

/**
 * The check that would have stopped 181 failed alignments.
 *
 * The stage costs nothing, which is exactly why its failure was expensive: it
 * runs SECOND, after the paid narration it aligns, so "the free thing is broken"
 * is only discovered once the money is gone. Importing the module needs neither
 * audio nor a model and answers the only question that actually failed.
 */
class AlignerPreflightTest extends TestCase
{
    /**
     * The configuration that caused the incident: the key absent, so it fell
     * through to a bare `python` resolved from PATH.
     *
     * PATH belongs to whoever started the process. It resolved one way in the
     * operator's shell and another in the queue worker, which is why alignment
     * "worked" every time it was tested by hand.
     */
    public function test_a_path_relative_interpreter_is_refused_without_spawning_anything(): void
    {
        config(['providers.whisperx.python' => 'python']);

        $probe = AlignerProbe::check();

        $this->assertFalse($probe['ok']);
        $this->assertStringContainsString('resolved from PATH', (string) $probe['error']);
    }

    public function test_python3_is_refused_for_the_same_reason(): void
    {
        config(['providers.whisperx.python' => 'python3']);

        $this->assertFalse(AlignerProbe::check()['ok']);
    }

    public function test_an_empty_interpreter_is_refused(): void
    {
        config(['providers.whisperx.python' => '']);

        $probe = AlignerProbe::check();

        $this->assertFalse($probe['ok']);
        $this->assertStringContainsString('empty', (string) $probe['error']);
    }

    /**
     * Absolute paths are accepted as a SHAPE even when nothing is there.
     *
     * Existence is deliberately not asserted by stat. A Windows App Execution
     * Alias — how a Store-installed Python is reached, and what this machine's
     * aligner actually runs on — is a reparse point that PHP's is_file() reports
     * as absent for a path that executes perfectly. Refusing on that would block
     * the one working interpreter. So a missing path is caught by failing to
     * run, not by failing to stat.
     */
    public function test_an_absolute_path_gets_as_far_as_being_executed(): void
    {
        config(['providers.whisperx.python' => 'C:/definitely/not/here/python.exe']);

        $probe = AlignerProbe::check();

        $this->assertFalse($probe['ok']);
        $this->assertStringNotContainsString('resolved from PATH', (string) $probe['error']);
    }

    /** The refusal names the stage, the interpreter and the cost of ignoring it. */
    public function test_the_refusal_explains_that_the_free_stage_runs_after_the_paid_one(): void
    {
        $message = DispatchRefusedException::alignerUnavailable(
            ['interpreter' => 'python', 'error' => 'no module named whisperx'],
            181,
        )->getMessage();

        $this->assertStringContainsString('181 scene(s)', $message);
        $this->assertStringContainsString('AFTER the narration', $message);
    }

    /** Nothing is probed when whisperx is not the bound transcriber. */
    public function test_the_probe_is_inactive_for_other_transcribers(): void
    {
        config(['providers.transcriber' => 'fake']);

        $this->assertFalse(AlignerProbe::isActive());
    }

    /**
     * The interpreter this machine is configured with really does import
     * whisperx.
     *
     * Skipped rather than failed where it is not configured, so the suite still
     * passes on CI and on the Linux production box — but on the dev machine this
     * is the one test that touches the actual thing that broke.
     */
    public function test_the_configured_interpreter_imports_whisperx(): void
    {
        if (! AlignerProbe::isActive()) {
            $this->markTestSkipped('whisperx is not the bound transcriber here.');
        }

        $probe = AlignerProbe::check();

        $this->assertTrue(
            $probe['ok'],
            "The configured WHISPERX_PYTHON cannot import whisperx:\n".(string) $probe['error'],
        );
    }
}
