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
        | Measured pace, per voice
        |----------------------------------------------------------------------
        |
        | Reading rate is a property of A VOICE AT A SPEED, not of narration in
        | the abstract, and keeping it as one global constant guaranteed that
        | casting a second narrator would reopen the same hole. Brian at 0.9 is
        | not Brian at 1.0 and is not some other voice at either.
        |
        | `measured_at_speed` is part of the record rather than decoration: the
        | figure is only true at that speed, so if ELEVENLABS_SPEED moves and
        | this does not, the number is stale and the pace check will say so.
        |
        | How to add one: run `php artisan narration:bakeoff <story>`, which
        | reads one scene at several speeds and prints the wpm of each. Put the
        | winner here.
        */
        'voices' => [

            // Brian — deep, resonant, comforting. eleven_multilingual_v2.
            //
            // 197 wpm at speed 1.0, and this is now a real measurement rather
            // than an extrapolation. The previous figure came from ONE scene
            // (story 9, scene 125: 68 words in 24.0 s at 0.9) with the 1.0 rate
            // projected from it. This one is the whole of story 9's finished
            // narration: 5,830 words across 186 scenes in 1,775,676 ms of audio.
            //
            //     raw    : 5830 / (1775676/60000)  = 197.0 wpm
            //     padded : 5830 / (53358/30/60)    = 196.7 wpm
            //
            // 197 rather than 196.7 because the pace guard compares against RAW
            // scene durations — `NarrationPace::measure()` divides by
            // `duration_ms`, not by the padded frame count. The 0.2% between them
            // is the per-scene rounding that padding adds and it is nowhere near
            // the tolerance; using the padded figure here would just make the
            // guard compare two subtly different things.
            //
            // The single-scene estimate said 188. It was 5% low, which is the
            // argument for measuring pace over a whole story: a scene's rate
            // swings 23% between neighbours on sentence length alone.
            'nPczCjzI2devNBz1zQrb' => [
                'name' => 'Brian',
                'words_per_minute' => (int) env('NARRATION_WPM_BRIAN', 197),
                'measured_at_speed' => 1.0,
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
        'pace_min_words' => (int) env('NARRATION_PACE_MIN_WORDS', 50),
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

    'queues' => [
        'render' => env('RENDER_QUEUE', 'render'),
        'assets' => env('ASSETS_QUEUE', 'assets'),
        'text' => env('TEXT_QUEUE', 'text'),
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
