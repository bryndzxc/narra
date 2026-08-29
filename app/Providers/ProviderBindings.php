<?php

namespace App\Providers;

use Anthropic\Client;
use Anthropic\RequestOptions;
use App\Contracts\ImageGenerator;
use App\Contracts\ScriptWriter;
use App\Contracts\SpeechSynthesizer;
use App\Contracts\Transcriber;
use App\Services\Claude\ClaudeScriptWriter;
use App\Services\Fake\FakeImageGenerator;
use App\Services\Fake\FakeScriptWriter;
use App\Services\Fake\FakeSpeechSynthesizer;
use App\Services\Fake\FakeTranscriber;
use App\Support\LocaleGuard;
use GuzzleHttp\Client as Guzzle;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

/**
 * Which implementation each provider interface resolves to.
 *
 * Two rules this class exists to make structural rather than remembered:
 *
 *  1. **Tests never hit the network.** In the `testing` environment every
 *     interface binds to its Fake, unconditionally, before config is consulted.
 *     A test that misconfigures itself gets a fake, not a bill.
 *
 *  2. **Nothing bills by accident.** Phase 2a is script generation only. The
 *     other three default to `fake` in config, so images, TTS and transcription
 *     cannot start spending because someone wired up a job early.
 *
 * Fakes are singletons so a test can resolve one, run a stage, and read the
 * calls it recorded.
 */
class ProviderBindings extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(LocaleGuard::class);

        $this->bind(ScriptWriter::class, 'script_writer', [
            'fake' => fn (): FakeScriptWriter => new FakeScriptWriter,
            'anthropic' => fn (): ClaudeScriptWriter => new ClaudeScriptWriter(
                $this->anthropic(),
                $this->app->make(LocaleGuard::class),
            ),
        ]);

        $this->bind(ImageGenerator::class, 'image_generator', [
            'fake' => fn (): FakeImageGenerator => new FakeImageGenerator,
        ]);

        $this->bind(SpeechSynthesizer::class, 'speech_synthesizer', [
            'fake' => fn (): FakeSpeechSynthesizer => new FakeSpeechSynthesizer,
        ]);

        $this->bind(Transcriber::class, 'transcriber', [
            'fake' => fn (): FakeTranscriber => new FakeTranscriber,
        ]);
    }

    /**
     * @param  array<string, callable>  $implementations
     */
    private function bind(string $contract, string $configKey, array $implementations): void
    {
        $this->app->singleton($contract, function () use ($contract, $configKey, $implementations) {
            // The environment wins over config, not the other way round. A test
            // that set PROVIDER_SCRIPT_WRITER=anthropic in its own .env would
            // otherwise spend real money proving a fake works.
            $choice = $this->app->environment('testing')
                ? 'fake'
                : (string) config("providers.{$configKey}", 'fake');

            if (! isset($implementations[$choice])) {
                throw new RuntimeException(sprintf(
                    "No implementation '%s' for %s. Available: %s. Set providers.%s in config, or "
                    .'PROVIDER_%s in the environment.',
                    $choice,
                    class_basename($contract),
                    implode(', ', array_keys($implementations)),
                    $configKey,
                    strtoupper($configKey),
                ));
            }

            return $implementations[$choice]();
        });
    }

    private function anthropic(): Client
    {
        $key = (string) config('providers.anthropic.api_key');

        if (trim($key) === '') {
            throw new RuntimeException(
                'ANTHROPIC_API_KEY is not set, so the Claude script writer cannot be built. Set it in '
                .'.env, or set PROVIDER_SCRIPT_WRITER=fake to run the pipeline on fixtures.'
            );
        }

        $seconds = (float) config('providers.anthropic.timeout_seconds');

        return new Client(
            apiKey: $key,
            requestOptions: RequestOptions::with(
                timeout: $seconds,
                maxRetries: (int) config('providers.anthropic.max_retries'),
                // A Guzzle client built HERE with an explicit timeout, rather
                // than the PSR-18 instance the SDK would otherwise discover.
                //
                // This is not belt-and-braces. RequestOptions::$timeout is
                // documented in the SDK as advisory — "the timeout is enforced
                // by the caller-supplied transport, not by this SDK, so nothing
                // in src reads it" — and a discovered Guzzle client defaults to
                // no timeout at all. Setting the field alone would look correct
                // and enforce nothing.
                //
                // On this platform that is the difference between a recoverable
                // stall and an unrecoverable one: `queue:work --timeout` is
                // enforced with a pcntl alarm, pcntl does not exist in Windows
                // PHP, and so the queue-level timeout is silently ineffective
                // too. If a request hangs and neither of these is real, the
                // worker is occupied forever with no error and no recovery.
                transporter: $this->guzzle($seconds),
            ),
        );
    }

    /**
     * A PSR-18 client that actually enforces the timeout it is given.
     *
     * `connect_timeout` is separate and much shorter on purpose: failing to
     * open a socket is a network problem that will not resolve by waiting
     * fifteen minutes, whereas a long-running act legitimately takes minutes to
     * come back.
     */
    private function guzzle(float $seconds): Guzzle
    {
        return new Guzzle([
            'timeout' => $seconds,
            'connect_timeout' => 15.0,
            // Streaming responses must not be buffered into memory before this
            // layer sees them, or the SDK's stream helper has nothing to read
            // incrementally and a long act round-trips as one blocking read.
            'stream' => false,
            'http_errors' => false,
        ]);
    }
}
