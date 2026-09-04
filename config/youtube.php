<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Publish sheet limits
    |--------------------------------------------------------------------------
    |
    | YouTube's, not ours. Enforced in code before the operator can approve
    | Gate 4, because the failure mode otherwise is a title that truncates in
    | search or a tag list silently cut in half at upload time.
    |
    | Titles: 100 is the hard limit; 70 is the point past which the tail stops
    | being visible on mobile and in search results. The hook goes on the left
    | either way.
    |
    */

    'limits' => [
        'title_hard' => 100,
        'title_target' => 70,
        'description' => 5000,
        'tags_chars' => 500,
        'min_chapters' => 3,
        'min_chapter_ms' => 10_000,
        'thumbnail_text_words' => 5,
    ],

    /*
    |--------------------------------------------------------------------------
    | Description footer
    |--------------------------------------------------------------------------
    |
    | Appended below the chapter list. Per channel, hence config rather than a
    | string in a Blade file.
    |
    | The disclosure line is not decoration: the operator also has to toggle
    | YouTube's "altered or synthetic content" setting at upload, and the Gate 4
    | checklist asks for it. Saying it in the description as well is the honest
    | version of the same statement.
    |
    */

    'footer' => env('YOUTUBE_DESCRIPTION_FOOTER', <<<'TXT'
        ---
        This story is fiction. Narration and illustrations are AI-assisted;
        the story, the edit and every editorial decision are human.
        TXT),

    /*
    |--------------------------------------------------------------------------
    | Channel defaults
    |--------------------------------------------------------------------------
    |
    | The upload settings that are the same on every video of this channel.
    |
    | They lived in the operator's memory, which is not a place a setting can
    | live: the Gate 4 checklist asked "Category set" and a category was very
    | nearly shipped as Gaming, because a tick box that asks whether you did a
    | thing does not tell you WHICH thing to do. A checklist item the sheet
    | cannot answer is unfalsifiable — the same defect as an item about
    | something that cannot exist, which is why `target_publish_at` got a
    | column rather than the question being dropped.
    |
    | So the sheet states the value and the tick confirms you entered it. One
    | place to change, visible on every render, and wrong in a way somebody can
    | SEE rather than wrong in a way somebody has to remember.
    |
    | Two of these are also asserted in the description footer and in the
    | metadata prompt; they are stated here because YouTube's upload form is
    | where they actually take effect.
    |
    */

    'channel' => [

        // People & Blogs: the category long-form narrated story channels in
        // this niche run under. Change here, not per upload.
        'category' => env('YOUTUBE_CATEGORY', 'People & Blogs'),

        // Both, every time. The audience is American and the narrator is
        // American in every locale profile — the SETTING of a story moves,
        // the language of the narration does not. See CLAUDE.md.
        'video_language' => env('YOUTUBE_VIDEO_LANGUAGE', 'en-US'),
        'caption_language' => env('YOUTUBE_CAPTION_LANGUAGE', 'en-US'),

        // "Not made for kids". Getting this wrong silently disables comments
        // and personalised ads, which is why its checklist item blocks.
        'made_for_kids' => env('YOUTUBE_MADE_FOR_KIDS', false),

        // Yes, always: narration and illustrations are AI-assisted. Stated in
        // the description footer as well, because the toggle and the sentence
        // are two halves of one honest claim.
        'synthetic_content' => env('YOUTUBE_SYNTHETIC_CONTENT', true),

        // Others clipping these stills and this narration into their own
        // Shorts is not a trade this channel wants.
        'shorts_remixing' => env('YOUTUBE_SHORTS_REMIXING', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Thumbnail composition
    |--------------------------------------------------------------------------
    |
    | The channel's format: two stills the story already owns, side by side,
    | faces prominent, no text overlay — the title carries the hook, so the
    | image does not have to.
    |
    | Composed from existing stills and NEVER generated. That is the constraint
    | this feature is built around rather than a saving: a 270-scene story has
    | already paid for every frame it could possibly want, and buying another
    | one to crop in half would be spending money to avoid choosing.
    |
    | 1280x720 and 2 MB are YouTube's, not ours. The width and the divider have
    | to divide exactly or the composition comes out a pixel short and YouTube
    | rescales it, so the panel width is derived rather than configured.
    |
    */

    'thumbnail' => [

        'width' => 1280,
        'height' => 720,

        // A hairline between the panels, so the composition reads as a
        // deliberate split rather than as two images that failed to blend.
        // Zero gives a hard butt-join.
        'divider_px' => 4,
        'divider_color' => '#0b0b0f',

        /*
        | MJPEG quality: 2 is best, 31 worst. Not the H.264 CRF scale.
        |
        | A ladder rather than a number, because the size cap is YouTube's and
        | has to be MET, not hoped for. A 1280x720 panel at q=3 measures a few
        | hundred KB and will never reach 2 MB — but "will never" is the
        | sentence this project has been wrong about before, so the composer
        | steps down the ladder and fails loudly if even the last rung is over.
        */
        'quality_ladder' => [3, 6, 10, 16],

        'max_bytes' => 2_000_000,

        // How many compositions the operator gets to choose between.
        'candidates' => 4,

        // Seconds. Two JPEG crops and an hstack is sub-second work; this is
        // here because every Process call in this app carries a timeout, since
        // `queue:work --timeout` does nothing on Windows.
        'timeout' => 60,
    ],

    /*
    |--------------------------------------------------------------------------
    | Gate 4 publish checklist
    |--------------------------------------------------------------------------
    |
    | Rendered as a checklist rather than prose, because that is what gets
    | followed at 1am. Keys are stored in youtube_metadata.checklist_state.
    |
    | `required` items block approval. The two that block are the ones with
    | consequences outside this app: the synthetic-content disclosure is a
    | platform policy obligation, and the kids setting silently disables
    | comments and personalised ads if it is wrong.
    |
    */

    'checklist' => [
        'synthetic_content_disclosed' => [
            'label' => 'Altered or synthetic content disclosure toggled',
            'required' => true,
            'set_to' => 'channel.synthetic_content',
        ],
        'not_made_for_kids' => [
            'label' => 'Audience setting confirmed',
            'required' => true,
            'set_to' => 'channel.made_for_kids',
        ],
        'category_set' => [
            'label' => 'Category set',
            'required' => false,
            'set_to' => 'channel.category',
        ],
        'languages_set' => [
            'label' => 'Video language and caption language set',
            'required' => false,
            'set_to' => 'channel.languages',
        ],
        'shorts_remixing_set' => [
            'label' => 'Shorts remixing set',
            'required' => false,
            'set_to' => 'channel.shorts_remixing',
        ],
        'scheduled_time_confirmed_et' => [
            'label' => 'Scheduled publish time confirmed in Eastern time',
            'required' => false,
            // Answered from the story, not from channel config: this one is
            // per-video and already has a column behind it.
            'set_to' => 'story.target_publish_at',
        ],
        'pinned_comment_drafted' => [
            'label' => 'Pinned comment drafted',
            'required' => false,
            'set_to' => 'metadata.pinned_comment',
        ],
    ],

];
