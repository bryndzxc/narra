<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Binaries
    |--------------------------------------------------------------------------
    |
    | Resolved through PATH by default. Override when the box has several
    | FFmpeg builds and the wrong one wins.
    |
    */

    'ffmpeg' => env('FFMPEG_PATH', 'ffmpeg'),
    'ffprobe' => env('FFPROBE_PATH', 'ffprobe'),

    /*
    |--------------------------------------------------------------------------
    | Process timeouts (seconds)
    |--------------------------------------------------------------------------
    |
    | These are enforced by Symfony Process in userland. On Windows there is no
    | pcntl, so `queue:work --timeout` is silently ineffective — these values
    | are the only thing standing between a hung FFmpeg and a worker occupied
    | forever. Never set one to null.
    |
    */

    'timeouts' => [
        'probe' => (int) env('RENDER_TIMEOUT_PROBE', 600),
        'scene_clip' => (int) env('RENDER_TIMEOUT_SCENE_CLIP', 900),
        'audio_pad' => (int) env('RENDER_TIMEOUT_AUDIO_PAD', 300),
        'concat' => (int) env('RENDER_TIMEOUT_CONCAT', 1800),
        'encode_audio' => (int) env('RENDER_TIMEOUT_ENCODE_AUDIO', 1800),
        'mux' => (int) env('RENDER_TIMEOUT_MUX', 21600),
    ],

    /*
    |--------------------------------------------------------------------------
    | Video
    |--------------------------------------------------------------------------
    */

    'video' => [
        'fps' => 30,
        'width' => 1920,
        'height' => 1080,

        // zoompan is jittery applied straight at output resolution. Upscale the
        // still, zoom on the large version, downscale to output. This is the
        // single most important detail in the scene-clip step.
        'upscale_width' => 3840,

        'crf' => 20,

        // `slow` doubles render time for marginal gain across 200 clips.
        'preset' => 'medium',
    ],

    /*
    |--------------------------------------------------------------------------
    | Audio
    |--------------------------------------------------------------------------
    |
    | sample_rate must divide evenly by video.fps, or a video frame is not a
    | whole number of samples and the exact-duration guarantee degrades into a
    | rounding argument. 44100 / 30 = 1470 samples per frame, exactly.
    |
    | Per-scene padded audio is PCM, not MP3, and so is the concat. MP3 carries
    | per-file encoder delay and padding, so concatenating 12 padded MP3s with
    | -c copy gains ~1685 samples per file — measured at +458 ms over these 12
    | scenes, and roughly +7.6 s over 200. narration.mp3 is encoded once from
    | the finished PCM instead.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Narration rate
    |--------------------------------------------------------------------------
    |
    | Words per minute of unhurried US narration over stills. One constant, in
    | one place, because three separate things are derived from it and they must
    | agree: the per-act word target the script writer is given, the runtime
    | estimate reported back, and the duration the fake TTS returns.
    |
    | 160 is chosen to reconcile the two targets in the spec, which do not
    | quite agree with each other. A 30-40 minute runtime and a 5,500-8,000
    | word band imply ~185 wpm; typical narration over stills is 150-160. At
    | 160, a 35-minute midpoint asks for 5,600 words — inside the word band AND
    | inside the runtime window. At 150 it would ask for 5,250, which lands
    | under the word floor; at 185 it would ask for 6,475 words that then run
    | 43 minutes at a realistic reading pace, over the runtime ceiling.
    |
    | Runtime is the product here, not word count, so if these ever have to
    | diverge the runtime window wins and the word band moves.
    |
    */

    'narration' => [

        /*
        | The FALLBACK rate, and no longer the answer.
        |
        | It stayed 160 through an entire real narration run and nothing ever
        | compared it to what a vendor actually did. It could not: the only
        | synthesizer that existed was the fake, which DERIVES its duration from
        | this constant, so the two agreed by construction and the agreement
        | proved nothing. The first real voice read at 195 wpm — 22% faster —
        | and the only reason it surfaced was somebody dividing words by minutes
        | by hand, sixty-nine scenes in.
        |
        | So this is now what is used when the narrator is unknown: the fake, a
        | story with no voice set, a voice with no measured profile yet. A voice
        | that HAS been measured uses its own figure below.
        */
        'words_per_minute' => (int) env('NARRATION_WPM', 160),

        /*
        |----------------------------------------------------------------------
        | Measured pace, per voice AND per locale profile
        |----------------------------------------------------------------------
        |
        | Reading rate is a property of A VOICE, AT A SPEED, READING A PARTICULAR
        | KIND OF PROSE. Keeping it as one global constant guaranteed that
        | casting a second narrator would reopen the hole; keeping it per-voice
        | guaranteed the same thing for a second SETTING, and that is exactly
        | what happened.
        |
        | Story 21 is the instance. Same narrator, same speed, different locale
        | profile: Brian read story 9's en-US script at 197.0 wpm across 186
        | scenes, and story 21's en-CN script at ~225 wpm. The pace guard fired
        | on scene 2 and cancelled a 270-scene batch, comparing en-CN audio
        | against a number measured on en-US prose.
        |
        | The guard was not wrong to notice. It was wrong to be certain: the
        | docblock under `measured_at_speed` already says a stale expectation is
        | worse than no expectation, because a check built on one can PASS while
        | being meaningless. That argument was written about speed and it is
        | exactly as true of prose, so the key gained a second dimension rather
        | than the tolerance gaining slack.
        |
        | `measured_at_speed` is part of each record rather than decoration: the
        | figure is only true at that speed, so if ELEVENLABS_SPEED moves and
        | this does not, the number is stale and the pace check says so.
        |
        | AN ABSENT PAIR IS NOT AN ERROR AND IS NOT A ZERO. It means this
        | narrator has never been measured on this kind of script, so the guard
        | cannot judge and says so at dispatch instead of pretending. The run
        | that establishes the number is the one that fills the gap: when it
        | finishes, `php artisan narration:measure <story>` prints the block to
        | paste here. Nothing writes it automatically — a measured figure that
        | appeared on its own is a number nobody checked.
        */
        'voices' => [

            // Brian - deep, resonant, comforting. eleven_multilingual_v2.
            'nPczCjzI2devNBz1zQrb' => [
                'name' => 'Brian',

                'locales' => [

                    // 197 wpm at speed 1.0, and a real measurement rather than
                    // an extrapolation: the whole of story 9's finished
                    // narration, 5,830 words across 186 scenes in 1,775,676 ms.
                    //
                    //     raw    : 5830 / (1775676/60000)  = 197.0 wpm
                    //     padded : 5830 / (53358/30/60)    = 196.7 wpm
                    //
                    // 197 rather than 196.7 because the guard compares against
                    // RAW scene durations - `measure()` divides by duration_ms,
                    // not by the padded frame count. The 0.2% between them is
                    // per-scene padding rounding, nowhere near the tolerance,
                    // and using the padded figure would make the guard compare
                    // two subtly different things.
                    //
                    // An earlier single-scene estimate said 188 - 5% low, which
                    // is the argument for measuring across a whole story: a
                    // scene's rate swings 23% between neighbours on sentence
                    // length alone.
                    'en-US' => [
                        'words_per_minute' => (int) env('NARRATION_WPM_BRIAN_EN_US', 197),
                        'measured_at_speed' => 1.0,
                        'measured_on' => 'story 9 - 5,830 words across 186 scenes',
                    ],

                    // Measured, now that story 21's narration is finished:
                    // 8,085 words across 270 scenes in 2,431,745 ms = 199.49.
                    //
                    //     en-US : 197.00 wpm  (186 scenes)
                    //     en-CN : 199.49 wpm  (270 scenes)
                    //     difference: 1.26%
                    //
                    // Worth reading twice, because it retires the reason this
                    // key was added. The locale dimension was introduced when
                    // two en-CN scenes read 219 and 230 and the guard cancelled
                    // a batch; the full measurement says the two settings are
                    // 1.26% apart, which is a fifth of the pace tolerance and
                    // an order of magnitude inside the sampling noise the
                    // threshold above documents. **The key was not what was
                    // wrong. `pace_min_words` was.**
                    //
                    // The key stays anyway, and the reasoning is worth keeping
                    // with it: it is not costing anything to be finer than the
                    // effect, because an unmeasured pair still DETECTS and only
                    // declines to ENFORCE, which self-heals after one story.
                    // Collapsing it back to per-voice would make a third
                    // setting enforceable on day one against a figure measured
                    // on prose it has never seen — which is the mistake this
                    // key was created to stop, and one data point of agreement
                    // is not evidence that prose kind never matters.
                    //
                    // IF A THIRD LOCALE ALSO LANDS WITHIN ~2%, collapse it.
                    // Two agreeing measurements is a coincidence; three is a
                    // finding, and at that point the key is carrying cost for
                    // nothing.
                    'en-CN' => [
                        'words_per_minute' => (int) env('NARRATION_WPM_BRIAN_EN_CN', 199),
                        'measured_at_speed' => 1.0,
                        'measured_on' => 'story 21 - 8,085 words across 270 scenes',
                    ],

                ],
            ],
        ],

        /*
        |----------------------------------------------------------------------
        | Pace check
        |----------------------------------------------------------------------
        |
        | How far the real narration may drift from the figure the script was
        | SIZED against before the run is stopped.
        |
        | This is the guard that did not exist, and its absence is why a 22%
        | error survived sixty-nine paid scenes. Named for the failure it
        | catches: the script writer is given a word target derived from a wpm
        | figure, and nothing downstream ever checked that the voice honoured
        | it. A 5,781-word story sized for 36:08 came back reading 29:41.
        |
        | Measured CUMULATIVELY across the story, never on one scene, and that
        | is what makes it usable. Four consecutive real scenes from story 9
        | read 189, 201, 233 and 206 wpm — a 23% spread between neighbours,
        | caused by nothing but sentence length and how much short dialogue a
        | scene carries. A per-scene threshold tight enough to catch a 22%
        | systematic drift would have cancelled a healthy batch at scene four.
        |
        | The running average settles within a couple of scenes and still fires
        | early: story 9's first scene alone carries 56 words, past the
        | threshold on its own, so the drift that cost sixty-nine scenes would
        | have stopped the run on the first one.
        */
        'pace_tolerance' => (float) env('NARRATION_PACE_TOLERANCE', 0.12),

        /*
        | The sample the running average needs before it means anything, and
        | 50 was wrong by a factor of twenty.
        |
        | THIS is what cancelled story 21's batch, not the locale key. Measured
        | over both finished stories, the running average's own worst deviation
        | from that story's FINAL rate — its noise, on a perfectly healthy run:
        |
        |     cumulative words     story 9        story 21
        |     ----------------     --------       ---------
        |         50               +9.9%          +12.6%   <- fired at scene 2
        |        400               +8.8%           +6.9%
        |        800               +3.9%           +3.5%
        |       1000               +2.3%           +1.9%
        |       1500               +1.0%           +1.8%
        |
        | At 50 words the instrument's own noise is +12.6% against a 12%
        | tolerance. The guard was not measuring the narrator; it was measuring
        | where the sentence breaks happened to fall in the first two scenes.
        | Correcting the locale key would not have helped — with en-CN recorded
        | at its true 199 wpm, scene 2's running average of ~225 is still +12.6%
        | and the batch is still cancelled.
        |
        | 1,000 is the knee of the curve: noise drops to ~2%, a fifth of the
        | tolerance, and 800 -> 1000 halves it while 1000 -> 1500 does almost
        | nothing. It is reached at scene 30 of story 9 and scene 34 of story
        | 21, so a systematic drift is still caught with 85% of a 270-scene run
        | unspent — and story 9's real defect, a script sized at 160 read at
        | 197, is +23% and nowhere near the noise floor at that point.
        |
        | The trade is stated plainly because it is a real one: the guard now
        | costs ~30 scenes of narration before it can fire, where it used to
        | cost two. Two was not early, it was wrong — it fired on healthy runs,
        | which is the failure mode that gets a guard switched off.
        */
        'pace_min_words' => (int) env('NARRATION_PACE_MIN_WORDS', 1000),
    ],

    /*
    |--------------------------------------------------------------------------
    | What the act writer actually produces, as opposed to what it is asked for
    |--------------------------------------------------------------------------
    |
    | THE TARGET IS ADVISORY. `ScriptSizing` computes a word target from the
    | runtime window and the narrator's measured rate, the prompt states it
    | plainly — "Target 985 words" — and the writer largely ignores it. Five
    | observations, targets from 800 to 1,120:
    |
    |   story  8   asked 1,120   wrote 1,195/act    +6.7%
    |   story  9   asked   933   wrote   964/act    +3.3%
    |   story 12   asked   933   wrote   893/act    -4.3%
    |   probe      asked   985   wrote 1,123/act   +14.0%
    |   story 21   asked   800   wrote 1,152/act   +44.0%
    |
    | Least squares gives a slope of +0.30: a hundred more words asked buys
    | about thirty. An act comes back at roughly this length whatever the prompt
    | says, which is why the ACT COUNT is the lever that moves runtime and the
    | word target is not.
    |
    | 1,123 IS ONE ACT. It is the only unconfounded observation in the set —
    | en-US, current code, current target — and every other story differs from
    | it on the code version, the locale or both. So this is a projection, not a
    | prediction, and every surface that shows it says so beside the number. It
    | is here rather than as a constant in ScriptSizing for the reason the
    | per-voice wpm figures are here: a measurement belongs where the next
    | measurement will be written, next to the run that produced it.
    |
    | Re-measure it when the act prompt, the model or the effort setting change.
    | Do NOT tune it to make a runtime estimate come out nicer — that is the
    | false-success pattern this file names at story 9, and the figure would
    | stop being a measurement the moment it happened.
    */
    'script' => [
        'measured_act_words' => (int) env('SCRIPT_MEASURED_ACT_WORDS', 1123),
        'measured_act_words_on' => 'one act, en-US, 6-act plan, asked 985 (2026-09-05)',
        'target_response_slope' => 0.30,
    ],

    'audio' => [
        'sample_rate' => 44100,
        'channels' => 1,
        'pcm_codec' => 'pcm_s16le',
        'mp3_bitrate' => '192k',

        /*
         * Final delivery codec. AAC is the shipping default and PCM exists as
         * an escape hatch, not as an aspiration.
         *
         * The case for AAC: 192k mono is transparent for narration, YouTube
         * re-encodes everything on ingest so a lossless master buys no audible
         * quality, and PCM roughly doubles the upload (~570 MB against ~275 MB
         * for a 40-minute video). The known cost is a sub-millisecond sample
         * delta at the very end of the file — measured 0, 15 or 29 samples,
         * 0.00 to 0.66 ms — which is inaudible, sits at a terminal boundary,
         * and does not accumulate, because per-scene sync was already fixed
         * exactly in PCM at concat.
         *
         * Switching to 'pcm' makes the mux assertion sample-exact at the cost
         * of file size. MuxFinalVideo reads this; nothing else needs to.
         */
        'master_codec' => env('RENDER_MASTER_CODEC', 'aac'),

        'aac_bitrate' => env('RENDER_AAC_BITRATE', '192k'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Verification depth
    |--------------------------------------------------------------------------
    |
    | Two ways to ask a file how long it is:
    |
    |   declared  ffprobe reads the container's own numbers — nb_frames, and
    |             the audio stream duration after the edit list. One process,
    |             milliseconds, no decoding.
    |   deep      FFmpeg walks every packet: -count_frames for video, astats
    |             for audio.
    |
    | Deep is the honest answer to "what is actually in this file", and it cost
    | 404 s on a finished 58-minute render — a third of the whole mux stage,
    | on every render, in a stage that is already the longest in the pipeline.
    | The declared numbers agreed with the decoded ones in every measured case,
    | which is what the container is for.
    |
    | So: declared by default, deep on request (`--deep` on the render commands,
    | or this flag). Deep is the tool for the day a render looks wrong, not a
    | tax paid on every render that looks right.
    |
    */

    'verify' => [
        'deep' => env('RENDER_VERIFY_DEEP', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Queues
    |--------------------------------------------------------------------------
    |
    | Three separate `queue:work` processes, because a 40-minute mux must never
    | block a script draft:
    |
    |   render  1-2 workers.  CPU-bound FFmpeg. Long, and jealous of cores.
    |   assets  more workers. Image and TTS calls - waiting on somebody else.
    |   text    more workers. Script and metadata generation.
    |
    | No Horizon: it hard-requires pcntl and posix, which do not exist in
    | Windows PHP. See docs/queue-workers.md for the exact commands and the
    | NSSM service registration that replaces Supervisor.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Delivery
    |--------------------------------------------------------------------------
    |
    | Where the finished MP4 is put so a human can find it.
    |
    | The render workspace is not that place and was never meant to be. It sits
    | under `storage/app/renders/<slug>/` next to the scratch, it is named
    | `final.mp4` for every story so twenty of them are twenty identically-named
    | files in twenty directories, and neither of those is a property you want
    | in the one artifact you actually open, upload and keep.
    |
    | So on a successful render the file is COPIED here as `<slug>.mp4`.
    |
    | Copied, not moved, and that is not timidity — a move breaks three things
    | that all read the workspace copy:
    |
    |   1. Gate 3's player. `stories.video` streams
    |      `RenderWorkspace::path('final.mp4')`, so a moved file is a 404 on the
    |      gate whose entire job is watching the render.
    |   2. The purge guard, which refuses to delete scratch unless `final.mp4`
    |      exists AND its tail decodes. Move the file and the guard fires
    |      correctly on a render that actually succeeded, and ~700 MB of scratch
    |      survives every time.
    |   3. Re-render idempotency, which verifies an existing encode rather than
    |      redoing it.
    |
    | The delivered copy is therefore yours: rename it, move it, delete it. The
    | workspace copy is the app's record and stays where the app expects it.
    |
    | Empty means no delivery, which is the default. Nothing about the render
    | changes when it is unset; the stage reports that it is off and succeeds.
    |
    */

    'delivery' => [

        /*
        | An absolute path OUTSIDE the project. A relative path is refused
        | rather than resolved, because "relative to what" has three plausible
        | answers here — the project root, the storage root, and the worker's
        | working directory — and picking one silently would put the file
        | somewhere the operator has to hunt for.
        */
        'path' => env('RENDER_DELIVERY_PATH'),

        /*
        | Create the directory if it is not there. On by default: an operator
        | pointing at a folder they have not made yet is the ordinary case, and
        | failing the last stage of a forty-minute render over `mkdir` is not a
        | useful safety property.
        |
        | The typo case it does NOT protect against — a wrong drive letter — is
        | caught separately, by refusing when the parent does not exist.
        */
        'create' => (bool) env('RENDER_DELIVERY_CREATE', true),
    ],

    'queues' => [
        'render' => env('RENDER_QUEUE', 'render'),
        'assets' => env('ASSETS_QUEUE', 'assets'),
        'text' => env('TEXT_QUEUE', 'text'),
    ],

    'workers' => [

        /*
        |----------------------------------------------------------------------
        | Self-restart when the code moves under a worker
        |----------------------------------------------------------------------
        |
        | A worker that finds itself running superseded code exits between jobs,
        | and NSSM's `AppExit Default Restart` brings it back on current code.
        |
        | This does not weaken the dispatch-time refusal. `AssertWorkersCurrent`
        | still refuses a spend into stale workers, in the dispatching process,
        | as loudly as before. What it removes is the manual chore: every code
        | edit used to turn the worker panel red until somebody restarted three
        | services by hand, and a red that means "somebody saved a file" is
        | indistinguishable from a red that means "your pipeline has stopped".
        | An alarm that fires for something the reader cannot act on is the
        | cheapest way to teach them to ignore it.
        |
        | It is bounded three ways, and `StaleWorkerRestart` explains each: the
        | check is skipped whenever ANY queue on the machine holds work, so a
        | batch in flight is never interrupted and cannot be split across two
        | code versions; the exit goes through the same cache flag
        | `queue:restart` sets, so no job is ever killed; and the recomputed
        | marker is used ONLY to decide to die, never to announce freshness.
        |
        | That first bound used to read "whenever the queue holds work", meaning
        | the worker's own — and it was false. The stop is a machine-wide
        | broadcast, so an idle worker on an empty queue stood a busy sibling
        | down and split its batch across two code versions. `workers:drill busy`
        | found it on the first run. The bound is now as wide as the action.
        |
        | Rehearse it rather than believing it:
        |
        |     php artisan workers:drill idle
        |     php artisan workers:drill busy
        |
        | Turn it OFF in production. There, a deploy restarts the workers as
        | part of shipping, and a worker that restarts itself part way through a
        | file copy is booting on a half-deployed tree - it would correct itself
        | on the next pass, but there is no reason to invite it.
        */
        'restart_when_stale' => (bool) env('WORKER_RESTART_WHEN_STALE', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Generated scene assets
    |--------------------------------------------------------------------------
    |
    | Where the paid stills and per-scene narration are filed.
    |
    | The disk is deliberately NOT `renders`. Render scratch is purged once a
    | final MP4 exists and decodes; a still is ~70% of a video's cost and a
    | re-render must never re-bill for one, so paid assets live on a disk the
    | purge cannot reach - the same argument that gave character sheets theirs.
    |
    | How many run at once is decided by how many `assets` workers are started,
    | not by a setting here - Bus::batch queues the whole set and the workers
    | draw from it. A knob for it would be a second answer to the same question.
    |
    */

    'assets' => [
        'disk' => env('ASSETS_DISK', 'assets'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Heartbeat
    |--------------------------------------------------------------------------
    |
    | How often a running job touches its render_jobs row while FFmpeg works.
    | This is the ONLY thing that distinguishes a working worker from a hung one
    | on this platform - `queue:work --timeout` needs pcntl and is silently
    | ineffective. Must stay comfortably below RenderJob::STALE_AFTER_MINUTES.
    |
    */

    'heartbeat_seconds' => (int) env('RENDER_HEARTBEAT_SECONDS', 30),

    /*
     * How long a `running` job may go without a heartbeat before the operator
     * page calls it hung. Must be several multiples of heartbeat_seconds: a
     * worker busy inside one long FFmpeg call still beats on schedule, and a
     * threshold close to the interval would flag healthy work.
     *
     * Tunable because it is also the drill: set it low, kill a worker mid-job,
     * and check the alarm actually fires. An alarm nobody has ever seen go off
     * is not an alarm.
     */
    'stale_after_minutes' => (int) env('RENDER_STALE_AFTER_MINUTES', 15),

    /*
    |--------------------------------------------------------------------------
    | Subtitle style presets
    |--------------------------------------------------------------------------
    |
    | The animated word-by-word highlight is the format's visual signature, so
    | this is the channel's visual identity and will be tuned often. Colours are
    | plain RGB hex here and are converted to ASS's &HAABBGGRR at write time.
    |
    | Note the karaoke colour roles, which are the reverse of what the names
    | suggest: ASS sweeps text FROM SecondaryColour TO PrimaryColour. So
    | `primary_colour` below (the resting text colour) is written into ASS's
    | SecondaryColour field, and `highlight_colour` into ASS's PrimaryColour.
    | Getting these backwards inverts the whole effect.
    |
    */

    'subtitles' => [

        'preset' => env('SUBTITLE_PRESET', 'default'),

        /*
         * Line breaking. The reference format shows one short line at a time;
         * a whole scene on screen at once is the wrong look. Chunks are chosen
         * to land near ideal_words while preferring clause boundaries, and
         * max_words is a hard cap no chunk may exceed.
         */
        'chunk' => [
            'ideal_words' => 6.5,
            'max_words' => 10,
        ],

        'presets' => [

            'default' => [
                'font' => 'Arial',
                'font_size' => 64,
                'bold' => true,

                'primary_colour' => '#FFFFFF',    // resting text
                'highlight_colour' => '#FFD666',  // swept text
                'outline_colour' => '#000000',
                'back_colour' => '#000000',

                'outline' => 4,
                'shadow' => 2,

                // 2 = bottom centre.
                'alignment' => 2,
                'margin_l' => 120,
                'margin_r' => 120,
                'margin_v' => 90,
            ],

        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Ken Burns motion
    |--------------------------------------------------------------------------
    |
    | zoom_rate is per frame, so the distance travelled scales with scene
    | length and zoom_max caps it. pan_zoom is the constant zoom held during a
    | pan — the crop has to be smaller than the source for there to be anywhere
    | to pan to.
    |
    */

    'motion' => [
        'zoom_rate' => 0.0004,
        'zoom_max' => 1.20,
        'pan_zoom' => 1.15,
    ],

];
