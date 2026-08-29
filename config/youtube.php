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
        ],
        'not_made_for_kids' => [
            'label' => '"Not made for kids" audience setting confirmed',
            'required' => true,
        ],
        'category_set' => [
            'label' => 'Category set',
            'required' => false,
        ],
        'languages_set' => [
            'label' => 'Video language and caption language set',
            'required' => false,
        ],
        'scheduled_time_confirmed_et' => [
            'label' => 'Scheduled publish time confirmed in Eastern time',
            'required' => false,
        ],
        'pinned_comment_drafted' => [
            'label' => 'Pinned comment drafted',
            'required' => false,
        ],
    ],

];
