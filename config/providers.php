<?php

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

    /*
    | The publish sheet. Same vendor, same rate cards, same category as the
    | script writer, and defaulted the same way for the same reason: this is
    | text an operator reads at a gate, it costs cents, and leaving it faked
    | would mean Gate 4 quietly showing placeholder copy that looks generated.
    */
    'metadata_writer' => env('PROVIDER_METADATA_WRITER', 'anthropic'),

    'image_generator' => env('PROVIDER_IMAGE_GENERATOR', 'fake'),
    'reference_image_generator' => env('PROVIDER_REFERENCE_IMAGE_GENERATOR', 'fake'),
    'speech_synthesizer' => env('PROVIDER_SPEECH_SYNTHESIZER', 'fake'),
    'transcriber' => env('PROVIDER_TRANSCRIBER', 'fake'),

    /*
    | The narrator a new story starts with, or null to force a deliberate pick.
    |
    | Null is the default and it is a correction rather than an omission. This
    | used to be the literal string 'narrator-us-01' hard-coded into StoryWrite
    | — a voice id FakeSpeechSynthesizer invented so it had something to record.
    | It is not a voice on any vendor. Every story in the database carries it,
    | and nothing ever noticed, because `SpeechSynthesizer::voices()` was
    | declared on the contract and called from nowhere: there was no picker to
    | disagree with the placeholder.
    |
    | Set it once a channel's narrator is locked, which is the point of storing
    | a voice per story at all. Until then, GenerateSceneNarration refuses to
    | synthesize without one and `voices:list --set` assigns a real id.
    */
    'default_voice_id' => env('NARRATION_VOICE_ID') ?: null,

    'anthropic' => [

        'api_key' => env('ANTHROPIC_API_KEY'),

        /*
        |----------------------------------------------------------------------
        | Model per call type
        |----------------------------------------------------------------------
        |
        | Not one model for the whole pipeline. The four text calls are not the
        | same kind of work and were never worth the same rate:
        |
        |   generate_outline    ONE call per video, and the only one making
        |                       decisions the rest of the pipeline cannot
        |                       revisit — the grievance, the antagonist's
        |                       justification, what is withheld and when it is
        |                       exposed. Every act is written against it. Stays
        |                       on Opus: ~1% of the token spend, 100% of the
        |                       structure.
        |
        |   generate_act_script 5-8 calls, and the bulk of the tokens. Tried on
        |                       Sonnet and moved back: the structure being
        |                       fixed does not make the prose mechanical, and
        |                       what the cheaper model dropped — dialogue and
        |                       paragraph breaks — is the narration itself and
        |                       the thing the scene splitter cuts on. Measured;
        |                       see the entry below.
        |
        |   extract_characters  ONE call. Mechanical on its face — read the
        |                       acts, list the people — but its output is pasted
        |                       VERBATIM into 150-250 image prompts and is the
        |                       fixed text the whole consistency mechanism rests
        |                       on. Sonnet rather than Haiku for that reason: it
        |                       is one call, and a sloppy physical description
        |                       is not a text problem, it is 250 stills.
        |
        |   draft_scenes        5-8 calls returning sentence ranges, a frame
        |                       description and a motion preset. Genuinely
        |                       mechanical, and structurally checkable — the
        |                       ranges must tile the act exactly. Haiku, with an
        |                       automatic fallback when they do not.
        |
        | `effort` is per-operation and NULLABLE, and the null is load-bearing:
        | `output_config.effort` is rejected by Haiku 4.5. Sending it is not a
        | degraded result, it is a 400 on every scene call.
        |
        | `fallback` is a model retried once when a response comes back
        | structurally invalid. Both attempts are billed and both write a cost
        | row — see ClaudeScriptWriter::scenes().
        */
        'operations' => [

            'generate_outline' => [
                'model' => env('ANTHROPIC_MODEL_OUTLINE', 'claude-opus-5'),
                'effort' => env('ANTHROPIC_EFFORT_OUTLINE', 'high'),
                'max_tokens' => (int) env('ANTHROPIC_MAX_TOKENS_OUTLINE', 16000),
            ],

            'generate_act_script' => [
                // Opus, and this was MEASURED rather than assumed. The same
                // outline was written twice, once on each model, and Sonnet's
                // acts came back 40% lighter on paragraph breaks (92 -> 55),
                // 20% lighter on quoted dialogue (35 -> 28), and 16% longer per
                // sentence (15.9 -> 18.5 words). It summarises where Opus
                // dramatises: "Paul confirmed that he had the canceled checks"
                // instead of `Paul said, "That's correct. I have them."`
                //
                // Every one of those is worse specifically for THIS product.
                // The words are read aloud over a still at 160 wpm, so long
                // compound sentences are where a synthetic narrator sounds most
                // synthetic — and paragraph breaks are what the scene splitter
                // cuts on, so losing 40% of them degrades scene drafting
                // directly, downstream of the saving.
                //
                // It also ran the story to 5,429 words, under the 5,500 floor,
                // and lost most of that in the LATER acts where the running
                // summary is longest — the acts that most need to hold.
                //
                // The saving was $0.38 a video against ~$7 of images. It did
                // not move the budget and what it bought was the narration.
                'model' => env('ANTHROPIC_MODEL_ACT_SCRIPT', 'claude-opus-5'),
                // Prose over a 7,000-word arc is a reasoning task even with the
                // spine fixed. `low` produces acts that hit the beats in the
                // outline without connecting them.
                'effort' => env('ANTHROPIC_EFFORT_ACT_SCRIPT', 'high'),
                'max_tokens' => (int) env('ANTHROPIC_MAX_TOKENS_ACT_SCRIPT', 16000),
            ],

            'extract_characters' => [
                'model' => env('ANTHROPIC_MODEL_CHARACTERS', 'claude-sonnet-5'),
                'effort' => env('ANTHROPIC_EFFORT_CHARACTERS', 'medium'),
                'max_tokens' => (int) env('ANTHROPIC_MAX_TOKENS_CHARACTERS', 8000),
            ],

            'draft_scenes' => [
                'model' => env('ANTHROPIC_MODEL_SCENES', 'claude-haiku-4-5'),
                // NULL, and it must stay null while this is a 4.5-generation
                // model. `output_config.effort` errors on Haiku 4.5 — it is not
                // accepted-and-ignored, it is a hard 400 on every call.
                'effort' => env('ANTHROPIC_EFFORT_SCENES') ?: null,
                'max_tokens' => (int) env('ANTHROPIC_MAX_TOKENS_SCENES', 16000),
                'fallback' => env('ANTHROPIC_MODEL_SCENES_FALLBACK', 'claude-sonnet-5'),
                'fallback_effort' => env('ANTHROPIC_EFFORT_SCENES_FALLBACK', 'medium'),
            ],

            /*
            |------------------------------------------------------------------
            | The publish sheet — three more calls, split on the same reasoning
            |------------------------------------------------------------------
            |
            | These run once, after the render, and together they cost about
            | what one act script costs. The split is not about saving money at
            | that scale; it is that the three are not the same kind of work,
            | and the roster is what an operator reads before authorising.
            |
            |   generate_titles   ONE call, and the highest-leverage text in the
            |                     entire product. Thirty-eight minutes of
            |                     finished video, 186 paid stills and 12 dollars
            |                     of generation are all downstream of whether
            |                     anybody clicks the title — and in this genre
            |                     the title has to STATE the ending rather than
            |                     tease it, front-load the hook into the first
            |                     forty characters that survive truncation, and
            |                     do it five times from five genuinely different
            |                     angles rather than five rewordings of one.
            |                     That is the same trade as generate_outline:
            |                     ~1% of the story's token spend, and the part
            |                     nothing downstream can revisit. Opus, high.
            |
            |                     The description's opening two or three
            |                     sentences are in this call, not a later one.
            |                     They are the search snippet and they make the
            |                     same promise the title makes; written by a
            |                     different model against a title it did not
            |                     choose, they pitch a different video.
            |
            |   generate_copy     Thumbnail overlay phrases and the pinned
            |                     comment. Short copy written against a promise
            |                     that already exists rather than inventing one,
            |                     which is exactly the shape extract_characters
            |                     has: one call, read by humans, load-bearing
            |                     but not deciding. Sonnet, medium. Not Haiku,
            |                     because both of these are read as writing and
            |                     the cheap version reads like a template.
            |
            |   generate_tags     A keyword list inside a hard 500-character
            |                     budget. Mechanical, and structurally checkable
            |                     the way scene ranges are: the budget fits or it
            |                     does not, and that is arithmetic the caller
            |                     does. Haiku, and `effort` must stay null — it
            |                     is a hard 400 on Haiku 4.5, not a degraded
            |                     result.
            |
            | No fallback on any of them. The scene fallback exists because a
            | miscounted range corrupts the video silently; there is no
            | equivalent failure here, and every one of these is cheap enough to
            | simply re-run by hand.
            */

            'generate_titles' => [
                'model' => env('ANTHROPIC_MODEL_TITLES', 'claude-opus-5'),
                'effort' => env('ANTHROPIC_EFFORT_TITLES', 'high'),
                'max_tokens' => (int) env('ANTHROPIC_MAX_TOKENS_TITLES', 8000),
            ],

            'generate_copy' => [
                'model' => env('ANTHROPIC_MODEL_METADATA_COPY', 'claude-sonnet-5'),
                'effort' => env('ANTHROPIC_EFFORT_METADATA_COPY', 'medium'),
                'max_tokens' => (int) env('ANTHROPIC_MAX_TOKENS_METADATA_COPY', 4000),
            ],

            'generate_tags' => [
                'model' => env('ANTHROPIC_MODEL_TAGS', 'claude-haiku-4-5'),
                // NULL, and load-bearing. See draft_scenes above.
                'effort' => env('ANTHROPIC_EFFORT_TAGS') ?: null,
                'max_tokens' => (int) env('ANTHROPIC_MAX_TOKENS_TAGS', 4000),
            ],
        ],

        /*
        | Rate cards, USD per million tokens, keyed by model. The provider
        | prices its own calls from this; no call site computes money.
        |
        | Keyed by MODEL rather than sitting flat on the provider, because the
        | split above means one story bills against three rate cards and a
        | single card would silently price Haiku scene calls at Opus rates.
        | Cache write is 1.25x input and cache read 0.1x input on every model;
        | those multipliers are why the numbers look the way they do.
        |
        | Thinking tokens are billed as output, so a model that thinks harder
        | costs more per call — which is why `effort` is a cost lever and not
        | just a quality one.
        */
        'pricing' => [

            'claude-opus-5' => [
                'input_per_mtok' => (float) env('ANTHROPIC_OPUS_INPUT_PER_MTOK', 5.00),
                'output_per_mtok' => (float) env('ANTHROPIC_OPUS_OUTPUT_PER_MTOK', 25.00),
                'cache_write_per_mtok' => (float) env('ANTHROPIC_OPUS_CACHE_WRITE_PER_MTOK', 6.25),
                'cache_read_per_mtok' => (float) env('ANTHROPIC_OPUS_CACHE_READ_PER_MTOK', 0.50),
            ],

            'claude-sonnet-5' => [
                'input_per_mtok' => (float) env('ANTHROPIC_SONNET_INPUT_PER_MTOK', 2.00),
                'output_per_mtok' => (float) env('ANTHROPIC_SONNET_OUTPUT_PER_MTOK', 10.00),
                'cache_write_per_mtok' => (float) env('ANTHROPIC_SONNET_CACHE_WRITE_PER_MTOK', 2.50),
                'cache_read_per_mtok' => (float) env('ANTHROPIC_SONNET_CACHE_READ_PER_MTOK', 0.20),
            ],

            'claude-haiku-4-5' => [
                'input_per_mtok' => (float) env('ANTHROPIC_HAIKU_INPUT_PER_MTOK', 1.00),
                'output_per_mtok' => (float) env('ANTHROPIC_HAIKU_OUTPUT_PER_MTOK', 5.00),
                'cache_write_per_mtok' => (float) env('ANTHROPIC_HAIKU_CACHE_WRITE_PER_MTOK', 1.25),
                'cache_read_per_mtok' => (float) env('ANTHROPIC_HAIKU_CACHE_READ_PER_MTOK', 0.10),
            ],
        ],

        /*
        | Streaming on every call, because a long act on a thinking model can
        | outrun a non-streaming HTTP timeout. An act is 1,100-1,600 words and
        | the 16k default is roughly double what that needs, deliberately:
        | hitting max_tokens truncates an act mid-sentence and the retry costs
        | the same again.
        */

        /*
        | Symfony Process timeouts do not apply to HTTP, and on this platform
        | `queue:work --timeout` is silently ineffective — no pcntl in Windows
        | PHP. An explicit client timeout is the only thing that stops a stalled
        | request occupying a worker forever.
        */
        'timeout_seconds' => (int) env('ANTHROPIC_TIMEOUT', 900),

        'max_retries' => (int) env('ANTHROPIC_MAX_RETRIES', 3),
    ],

    'elevenlabs' => [

        'api_key' => env('ELEVENLABS_API_KEY'),

        'base_url' => env('ELEVENLABS_BASE_URL', 'https://api.elevenlabs.io/v1'),

        /*
        |----------------------------------------------------------------------
        | Image model
        |----------------------------------------------------------------------
        |
        | ElevenLabs does not train image models; it fronts other people's
        | (Seedream, Gemini/"Nano Banana", GPT-Image) behind one API. It was
        | chosen over Leonardo for one reason, and the reason is not price.
        |
        | Leonardo's Character Reference is a ControlNet with ONE preprocessor
        | slot per generation (id 133 on SDXL, 397 on Phoenix). Multiple
        | `controlnets[]` entries are different KINDS of guidance — style, edge,
        | depth — not multiple people, so a two-hander scene can pin one face
        | and must invent the other from text. Leonardo's own documentation also
        | declines to promise a likeness for the one it does pin: "not intended
        | as a face swap feature and does not guarantee a perfect replica."
        |
        | This API takes an `images[]` array of up to 14 references. Our scenes
        | routinely have two or three named characters in frame, so
        | refs-per-call is the constraint that decides the PROVIDER.
        |
        | It does NOT decide the MODEL, and an earlier version of this comment
        | claimed it did. Seedream 5.0 Lite also takes 14 references; every
        | candidate here is far past the three a frame ever needs, so ref count
        | separates ElevenLabs from Leonardo and separates nothing after that.
        |
        | `gemini-3.1-flash-image` is a PLACEHOLDER DEFAULT, not a finding. The
        | honest state of the comparison against `bytedance-seedream-5-lite`:
        |
        |   - Seedream is roughly half the price per image at every resolution.
        |   - Published head-to-head comparisons contradict each other on face
        |     consistency, which is the only axis that would justify paying the
        |     difference. One measures Nano Banana 2 as strongest; another
        |     measures Seedream 5.0 Lite as stronger specifically for the same
        |     person recurring across many scenes, which is our exact case.
        |   - Nothing here has been tested. Two candidates of one character on
        |     each model settles it for about $0.20, and that is the only thing
        |     that should change this line.
        |
        | The ByteDance models are additionally disabled by default on an
        | ElevenLabs account and need explicit approval before a call succeeds.
        | That is an access hurdle on THIS reseller, not a quality argument, and
        | it does not exist when calling Seedream direct.
        */
        'image_model' => env('ELEVENLABS_IMAGE_MODEL', 'gemini-3.1-flash-image'),

        /*
        | How many reference images the chosen model will accept in one call.
        |
        | Enforced before the request rather than discovered from a 4xx, because
        | the interesting failure is not the error — it is silently dropping the
        | eleventh character's face and generating it from text, which is the
        | exact thing this feature exists to prevent. See ResolveSceneReferences.
        */
        'max_references' => (int) env('ELEVENLABS_MAX_REFERENCES', 14),

        /*
        |----------------------------------------------------------------------
        | Rate card
        |----------------------------------------------------------------------
        |
        | DECLARED, NOT DISCOVERED — and that is a statement about the vendor,
        | not a shortcut here.
        |
        | Neither candidate publishes a per-image price you can verify without
        | an account. Leonardo bills in dollars but puts the rate behind a
        | logged-in calculator. ElevenLabs bills in credits, publishes credit
        | costs for only a few models (Seedream 5.0 Lite 4K = 50 credits, Nano
        | Banana Pro 4K = 1,818), and does not return a cost or credits-used
        | field in the generation response — the documented completed payload is
        | id, status, content_url, content_mime_type and nothing else.
        |
        | So the provider prices its own calls from these numbers, as every
        | provider in this app does, and the sheet shows the figure on screen
        | before anything is generated. That makes the number checkable against
        | the real bill rather than assumed. It is the operator's job to check
        | it once against their own usage page and correct it here; it is this
        | file's job to make sure there is exactly one place to correct.
        |
        | PLAN GATE, stated here because it is the largest number on this page
        | and it is not per-image: **image generation through this API requires
        | a Pro plan or above** ($99/mo). It is not available on Free, Starter
        | or Creator. Choosing this provider is therefore a $1,188/year
        | commitment before a single image is generated, and at low volume that
        | floor dominates the per-image rate completely.
        |
        | Defaults below: 240 credits/image at the Pro-tier rate of $99 per
        | 600,000 credits. Both are stated separately so a plan change is one
        | number and a model change is the other.
        |
        | TREAT 240 AS A GUESS. It was reverse-engineered from the one credit
        | figure ElevenLabs publishes for a comparable model (Nano Banana Pro
        | 4K = 1,818 credits, against Google's direct 4K price of $0.24 — a
        | markup near 1.25x). Applying that markup to Google's direct
        | gemini-3.1-flash-image rates suggests 400-760 credits at 1K-2K, so
        | this default probably UNDERSTATES the bill by 2-3x. A separately
        | circulated figure of 50 credits for Seedream 5.0 Lite is almost
        | certainly wrong: it would have ElevenLabs reselling at a quarter of
        | that model's own direct price.
        |
        | Correct both numbers from the account's usage page before relying on
        | any projection this app prints.
        */
        'pricing' => [
            'usd_per_credit' => (float) env('ELEVENLABS_USD_PER_CREDIT', 99 / 600000),
            'credits_per_image' => (float) env('ELEVENLABS_CREDITS_PER_IMAGE', 240),
        ],

        /*
        | Generation is asynchronous: POST returns `pending`, then the id is
        | polled. The docs ask for at least two seconds between polls for an
        | image.
        |
        | The ceiling matters more than it looks on this platform. Symfony
        | Process timeouts do not apply to HTTP and `queue:work --timeout` is
        | silently ineffective without pcntl, so a poll loop with no bound is a
        | worker occupied forever with no error and no recovery.
        */

        /*
        |----------------------------------------------------------------------
        | Text to speech
        |----------------------------------------------------------------------
        |
        | The narrator. One voice per story, stored on `stories.voice_id`, so a
        | channel keeps one consistent narrator across every video.
        |
        | MODEL. `eleven_multilingual_v2` is the default because ElevenLabs' own
        | documentation calls it "most stable on long-form generations", and a
        | 36-minute narration cut into 186 separately-billed calls is the exact
        | case that word describes. `eleven_flash_v2_5` is HALF the credit cost
        | per character and is the reason this is a config key rather than a
        | constant: on a plan with no overage headroom, halving the credit cost
        | is sometimes the difference between a video finishing and a video
        | stopping at scene 170. It is a real quality trade and it belongs to
        | the operator, not to this file.
        |
        | OUTPUT FORMAT. `pcm_24000`, and both halves of that matter.
        |
        |   PCM, not MP3, because the render pipeline's exactness guarantee is
        |   built on decoded sample counts. GenerateSceneNarration probes the
        |   written file for the duration the frame count is ceil()'d from, and
        |   PadSceneAudio then DECODES that same file and hard-fails if the
        |   audio does not fit inside those frames. For a WAV those two numbers
        |   are the same number. For an MP3 they are not — the decoder emits
        |   encoder delay and padding the container never declared — so an MP3
        |   here puts a few samples of slop between the probe and the decode on
        |   every one of 186 scenes, and the failure it eventually produces
        |   ("padding would become a trim") reads as a frame-count bug.
        |
        |   24000 rather than 44100 because 24 kHz is what the model actually
        |   generates. `pcm_44100` is an upsample of this same signal, it is
        |   gated behind the Pro plan, and PadSceneAudio resamples to
        |   render.audio.sample_rate regardless. Paying a plan tier for an
        |   upsample the pipeline would redo is buying nothing.
        |
        | The API returns pcm_* as HEADERLESS s16le. The provider wraps it in a
        | WAV container itself — ffprobe cannot read raw PCM without being told
        | the rate and layout, and every consumer downstream reaches for ffprobe.
        */
        'tts' => [

            'model' => env('ELEVENLABS_TTS_MODEL', 'eleven_multilingual_v2'),

            'output_format' => env('ELEVENLABS_TTS_OUTPUT_FORMAT', 'pcm_24000'),

            /*
            | Voice settings, applied to every call so 186 scenes are one
            | performance rather than 186 auditions.
            |
            | `stability` is the one that matters at this length. Low values
            | give a more expressive read per scene and a narrator who audibly
            | changes character across an act; 0.5 is the balanced default, and
            | consistency is worth more than expressiveness to a listener who is
            | thirty minutes in.
            */
            'voice_settings' => [
                'stability' => (float) env('ELEVENLABS_STABILITY', 0.5),
                'similarity_boost' => (float) env('ELEVENLABS_SIMILARITY', 0.75),
                'style' => (float) env('ELEVENLABS_STYLE', 0.0),
                'use_speaker_boost' => (bool) env('ELEVENLABS_SPEAKER_BOOST', true),
                'speed' => (float) env('ELEVENLABS_SPEED', 1.0),
            ],

            /*
            | Request stitching: hand the model the neighbouring scenes' text as
            | unspoken context, so prosody carries across a scene boundary
            | instead of resetting 186 times.
            |
            | DEFAULT OFF, and the reason is billing rather than quality. It is
            | the best-value knob here for a narration cut this finely — and
            | ElevenLabs does not document whether `previous_text` and
            | `next_text` are charged as characters. On a plan that has no
            | overage and simply STOPS when credits run out, an undocumented
            | multiplier on every call is not a thing to take on trust.
            |
            | It is measurable rather than guessable: the provider records the
            | `character-cost` response header on every call, so one scene
            | generated each way settles it against the real bill. Turn it on
            | after that, not before.
            */
            'stitch_context' => (bool) env('ELEVENLABS_STITCH_CONTEXT', false),

            /*
            | Best-effort determinism. A scene regenerated after a failed batch
            | should come back as the take its neighbours were generated
            | alongside, not as a fresh audition — so the seed is derived from
            | the scene id rather than being random or fixed. ElevenLabs
            | documents this as best-effort and does not promise sample-exact
            | reproduction.
            */
            'deterministic_seed' => (bool) env('ELEVENLABS_DETERMINISTIC_SEED', true),

            /*
            | 'auto' | 'on' | 'off'. Spells out numbers, dates and abbreviations
            | before synthesis. 'on' is unsupported on the flash and turbo
            | models and is a 400 there rather than a downgrade, which is why
            | this is a key and not a constant.
            */
            'text_normalization' => env('ELEVENLABS_TEXT_NORMALIZATION', 'auto'),

            /*
            |------------------------------------------------------------------
            | Rate card
            |------------------------------------------------------------------
            |
            | Declared, like every other rate card here, and reconciled against
            | the account's usage page. TTS is billed in CREDITS, and a credit
            | is not a character: the v2 multilingual models charge 1 credit per
            | character and the flash/turbo models charge 0.5. That multiplier
            | is per MODEL, so it lives keyed by model rather than flat — one
            | number would price a flash run at double.
            |
            | `usd_per_credit` is the PLAN rate, which is a different kind of
            | number from a per-image price and worth being honest about: it is
            | a subscription divided by an allowance, so it is the true marginal
            | cost only if the allowance is actually consumed. Starter is $6 for
            | 30,000 credits — $0.0002 a credit — and a video that used 15,000
            | of them did not cost $3.00 in any sense a bookkeeper would accept.
            | It is the right number for "did this fit inside the plan" and the
            | wrong number for "what would this cost at scale", where the API
            | list rate is roughly half it.
            |
            | Defaults are the Starter plan. Correct both when the plan changes.
            */
            'pricing' => [
                'usd_per_credit' => (float) env('ELEVENLABS_USD_PER_CREDIT_TTS', 6 / 30000),
                'credits_per_character' => [
                    'eleven_v3' => (float) env('ELEVENLABS_CREDITS_V3', 1.0),
                    'eleven_multilingual_v2' => (float) env('ELEVENLABS_CREDITS_MULTILINGUAL_V2', 1.0),
                    'eleven_turbo_v2_5' => (float) env('ELEVENLABS_CREDITS_TURBO_V2_5', 0.5),
                    'eleven_flash_v2_5' => (float) env('ELEVENLABS_CREDITS_FLASH_V2_5', 0.5),
                ],
                /*
                | What an unlisted model is assumed to cost. 1.0 rather than
                | 0.5, deliberately: a new model priced at the cheaper rate
                | would under-quote a run, and this app's money discipline is
                | that the number on the confirmation screen is never lower than
                | the bill.
                */
                'default_credits_per_character' => (float) env('ELEVENLABS_CREDITS_DEFAULT', 1.0),
            ],

            /*
            | A scene is one to three sentences and comes back in seconds. This
            | bound is the only real one on this platform — `queue:work
            | --timeout` needs pcntl, which does not exist in Windows PHP, so
            | without it a stalled request holds an assets worker forever.
            */
            'timeout_seconds' => (int) env('ELEVENLABS_TTS_TIMEOUT', 180),

            /*
            | Zero, and that is not an oversight.
            |
            | Every other retry in this app is on a call that either succeeded
            | or did not. A TTS call that times out on OUR side may well have
            | been generated and BILLED on theirs, so an automatic retry is an
            | automatic second charge against an allowance that stops the video
            | when it runs out. The batch flags the scene and the operator
            | re-presses the button, which is the same policy `$tries = 1`
            | already sets on the job.
            */
            'max_retries' => (int) env('ELEVENLABS_TTS_MAX_RETRIES', 0),
        ],

        'poll_interval_seconds' => (float) env('ELEVENLABS_POLL_INTERVAL', 2.0),
        'poll_timeout_seconds' => (int) env('ELEVENLABS_POLL_TIMEOUT', 300),
        'timeout_seconds' => (int) env('ELEVENLABS_TIMEOUT', 120),
        'max_retries' => (int) env('ELEVENLABS_MAX_RETRIES', 2),
    ],

    /*
    |--------------------------------------------------------------------------
    | fal.ai — Seedream 5.0 Lite
    |--------------------------------------------------------------------------
    |
    | Direct, pay-as-you-go, no subscription floor. That is the whole reason it
    | is here: ElevenLabs resells these same third-party models but gates image
    | generation behind a Pro plan at $99/mo, which at a few videos a month
    | dominates the per-image rate completely.
    |
    | Two endpoints, because they take different inputs:
    |   text-to-image  a reference sheet — there is no input image yet
    |   edit           a scene still — conditioned on the approved faces
    |
    | Seedream takes up to 10 reference images, which is well past the two or
    | three named characters a frame ever carries.
    |
    */
    'fal' => [

        'api_key' => env('FAL_API_KEY'),

        // The synchronous host. fal also exposes queue.fal.run for submit-then-
        // poll; a single still finishes inside an HTTP timeout, so the simpler
        // path is the right one until a batch needs otherwise.
        'base_url' => env('FAL_BASE_URL', 'https://fal.run'),

        'text_to_image_model' => env('FAL_T2I_MODEL', 'fal-ai/bytedance/seedream/v5/lite/text-to-image'),
        'edit_model' => env('FAL_EDIT_MODEL', 'fal-ai/bytedance/seedream/v5/lite/edit'),

        'max_references' => (int) env('FAL_MAX_REFERENCES', 10),

        /*
        | $0.035 per image, flat, at every resolution — the one published,
        | verifiable per-image price among the candidates. Still declared here
        | rather than read off the response, because fal returns no cost field
        | either; but unlike the reseller's credit rate this one can be checked
        | against a public page.
        */
        'usd_per_image' => (float) env('FAL_USD_PER_IMAGE', 0.035),

        'timeout_seconds' => (int) env('FAL_TIMEOUT', 180),
        'max_retries' => (int) env('FAL_MAX_RETRIES', 2),
    ],

    /*
    |--------------------------------------------------------------------------
    | WhisperX — local forced alignment
    |--------------------------------------------------------------------------
    |
    | FORCED ALIGNMENT, not transcription, and the distinction is the entire
    | reason this provider exists in the shape it does.
    |
    | We already know every word. The narration was written at Gate 1, reviewed
    | and edited at Gate 2, and a paid TTS call has just spoken it verbatim. The
    | question is not "what was said" — it is "when was each known word said".
    |
    | Transcribing would re-derive the text from the audio and would sometimes
    | get it wrong: a proper noun misheard, a contraction expanded, a homophone
    | swapped. Those errors do not stay small. The `.ass` karaoke line is built
    | word by word from this output, so one substituted word desynchronises the
    | highlight for the rest of the scene and puts a word on screen that is not
    | the word the operator approved. Alignment cannot do that — the word list
    | is an INPUT, and the model only decides where the boundaries fall.
    |
    | It also means the Whisper ASR model is never loaded at all. Only the
    | wav2vec2 alignment model is, which is ~360 MB rather than ~3 GB and runs
    | comfortably on CPU.
    |
    | Free, and local. There is no rate card here because there is no bill —
    | but this is NOT a simulated provider. It produces real timings from real
    | audio, so it reports $0.00 with `simulated: false`, and the ledger's
    | "WHERE simulated = 0" query still means what it says.
    |
    */
    'whisperx' => [

        /*
        | The Python that has whisperx installed. Absolute path on Windows,
        | where `python` on PATH is routinely the App Execution Alias stub that
        | opens the Microsoft Store instead of running anything.
        */
        'python' => env('WHISPERX_PYTHON', 'python'),

        /*
        | The alignment script this provider shells out to. Version-controlled
        | in the repo rather than generated at runtime, so the thing that runs
        | is the thing that was reviewed.
        */
        'script' => env('WHISPERX_SCRIPT', resource_path('whisperx/align.py')),

        /*
        | 'cpu' or 'cuda'. CPU is the default because it is the configuration
        | that needs nothing installed beyond the package itself, and the
        | alignment model is small enough that CPU is workable — a scene is
        | seconds of audio, not the 40 minutes the ASR path would face.
        */
        'device' => env('WHISPERX_DEVICE', 'cpu'),

        /*
        | 'int8' on CPU, 'float16' on a modern CUDA card. Only consulted by the
        | ASR path in whisperx proper; carried here because the script passes it
        | through and a future change of mind about ASR should not need a new
        | key.
        */
        'compute_type' => env('WHISPERX_COMPUTE_TYPE', 'int8'),

        /*
        | The language the alignment model is loaded for. Hard-defaulted to the
        | audience's language rather than detected: detection on a three-second
        | clip is unreliable, and a US-audience channel narrated in American
        | English has nothing to detect.
        */
        'language' => env('WHISPERX_LANGUAGE', 'en'),

        /*
        | An explicit wav2vec2 checkpoint, or null for whisperx's default for
        | the language. Null is right until there is a measured reason.
        */
        'align_model' => env('WHISPERX_ALIGN_MODEL') ?: null,

        /*
        | Where HuggingFace caches the alignment model. Null leaves it at the
        | user default (~/.cache/huggingface). Worth setting on Windows, where
        | the home directory can sit on a small system drive.
        */
        'cache_dir' => env('WHISPERX_CACHE_DIR') ?: null,

        /*
        | Symfony Process enforces this in userland and does not need pcntl,
        | which is the only reason there is a real bound on this platform at
        | all. It has to cover a cold start, not a warm one: every call is a
        | fresh interpreter, so it pays the torch import and the model load
        | before it aligns a word. A few seconds of audio, tens of seconds of
        | startup.
        */
        'timeout_seconds' => (int) env('WHISPERX_TIMEOUT', 600),

        /*
        | How far a word boundary may fall outside the audio before the result
        | is refused, in milliseconds.
        |
        | Alignment can emit an end past the end of the file by a rounding
        | margin. A few milliseconds is arithmetic; anything larger means the
        | model has aligned against something other than this audio, and the
        | subtitle timeline would be built on it.
        */
        'boundary_tolerance_ms' => (int) env('WHISPERX_BOUNDARY_TOLERANCE_MS', 50),
    ],

    /*
    | There is no rate card for the fakes, deliberately, and this comment is
    | here so nobody adds one back.
    |
    | They used to have one — $0.04 an image, $0.015 per 1k characters — so that
    | a fixture-driven run produced a "realistic" cost breakdown to sanity-check
    | the per-video estimate against. It cost nothing to run and looked like it
    | had cost something, which is precisely the problem: a run that never
    | touched the network wrote $8.12 into `cost_entries`, and the table that
    | exists to answer "what did this video cost" answered with money nobody
    | had spent.
    |
    | Simulated providers now report $0.00 by construction, via
    | ProviderUsage::simulated(), and RecordProviderCost refuses a non-zero cost
    | from one. To find out what a run WOULD cost on a real vendor, point the
    | binding at that vendor and read the estimate — which now prices itself
    | from the bound instance rather than from this file.
    */

];
