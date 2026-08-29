<?php

use App\Enums\CostUnit;

/*
|--------------------------------------------------------------------------
| External providers
|--------------------------------------------------------------------------
|
| Every provider sits behind an interface in app/Contracts/ with a Fake in
| app/Services/Fake/. This file decides which implementation is bound, and
| carries the rate cards the providers price their own calls against.
|
| The default for everything except the script writer is `fake`, because
| Phase 2a is script generation only — no TTS, no images, no transcription.
| Binding a real implementation for those is a later phase's decision, and
| leaving them faked means nothing can accidentally start billing for them.
|
*/

return [

    'script_writer' => env('PROVIDER_SCRIPT_WRITER', 'anthropic'),
    'image_generator' => env('PROVIDER_IMAGE_GENERATOR', 'fake'),
    'speech_synthesizer' => env('PROVIDER_SPEECH_SYNTHESIZER', 'fake'),
    'transcriber' => env('PROVIDER_TRANSCRIBER', 'fake'),

    'anthropic' => [

        'api_key' => env('ANTHROPIC_API_KEY'),

        /*
        | Claude Opus 5. 1M context, which matters here: by the last act the
        | request carries the full outline plus five running summaries.
        */
        'model' => env('ANTHROPIC_MODEL', 'claude-opus-5'),

        /*
        | Rate card, USD per million tokens. The provider prices its own calls
        | from this; no call site computes money.
        |
        | Thinking tokens are billed as output, so a model that thinks harder
        | costs more per act — which is why `effort` below is a cost lever and
        | not just a quality one.
        */
        'pricing' => [
            'input_per_mtok' => (float) env('ANTHROPIC_INPUT_PER_MTOK', 5.00),
            'output_per_mtok' => (float) env('ANTHROPIC_OUTPUT_PER_MTOK', 25.00),
            'cache_write_per_mtok' => (float) env('ANTHROPIC_CACHE_WRITE_PER_MTOK', 6.25),
            'cache_read_per_mtok' => (float) env('ANTHROPIC_CACHE_READ_PER_MTOK', 0.50),
        ],

        /*
        | An act is 1,100-1,600 words. 16k output tokens is roughly double the
        | ceiling that needs, which is deliberate: hitting max_tokens truncates
        | an act mid-sentence and the retry costs more than the headroom does.
        | Streaming, because a long act on a thinking model can outrun a
        | non-streaming HTTP timeout.
        */
        'max_tokens' => (int) env('ANTHROPIC_MAX_TOKENS', 16000),

        /*
        | Narrative prose over a 7,000-word arc is a reasoning task, not a
        | lookup. `high` is the default; `low` produces acts that hit the beats
        | in the outline without connecting them.
        */
        'effort' => env('ANTHROPIC_EFFORT', 'high'),

        /*
        | Symfony Process timeouts do not apply to HTTP, and on this platform
        | `queue:work --timeout` is silently ineffective — no pcntl in Windows
        | PHP. An explicit client timeout is the only thing that stops a stalled
        | request occupying a worker forever.
        */
        'timeout_seconds' => (int) env('ANTHROPIC_TIMEOUT', 900),

        'max_retries' => (int) env('ANTHROPIC_MAX_RETRIES', 3),
    ],

    /*
    | Rate cards for the faked providers, so a fixture-driven run still produces
    | a realistic cost breakdown. These are the numbers the ~$1-per-video
    | estimate was built on, kept here so the estimate can be checked against
    | reality rather than remembered.
    */
    'fake' => [
        'image_usd' => 0.0400,
        'image_unit' => CostUnit::Images,
        'speech_usd_per_1k_chars' => 0.0150,
        'transcribe_usd_per_minute' => 0.0060,
    ],

];
