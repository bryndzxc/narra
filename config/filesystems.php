<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        /*
         * Hand-made Phase 0 inputs: stills, per-scene audio, timings, acts.
         * Read-only as far as the pipeline is concerned — only fixtures:make
         * writes here. Kept off the `local` disk so a scratch purge can never
         * reach it.
         */
        'fixtures' => [
            'driver' => 'local',
            'root' => storage_path('app/fixtures'),
            'throw' => true,
            'report' => false,
        ],

        /*
         * Render scratch and output. Budget 8-12 GB per in-flight video at
         * 30-40 minutes: 200 scene clips, 2x source stills, per-scene audio.
         * Everything but the final MP4 is purgeable.
         */
        'renders' => [
            'driver' => 'local',
            'root' => storage_path('app/renders'),
            'throw' => true,
            'report' => false,
        ],

        /*
         * Character reference sheets: the candidate faces and the chosen one.
         *
         * Its own disk rather than a folder under `renders`, and that is a
         * durability decision rather than tidiness. Render scratch is purged
         * once a final MP4 exists and decodes; a reference must survive that,
         * because it is cited by every re-render and every still regenerated
         * after a reopened Gate 2. A purge that could reach these would delete
         * the only fixed record of what a character looks like and quietly
         * reintroduce the drift they exist to prevent.
         */
        'characters' => [
            'driver' => 'local',
            'root' => storage_path('app/characters'),
            'throw' => true,
            'report' => false,
        ],

        /*
         * Generated scene assets: the stills and the per-scene narration.
         *
         * Its own disk rather than a folder under `renders`, for exactly the
         * reason `characters` has one: render scratch is purged once a final
         * MP4 exists and decodes, and these must survive that. A still is ~70%
         * of a video's cost and a re-render must never re-bill for one. The
         * purge walks `renderRoot` and cannot reach here.
         *
         * Separate from `fixtures` too, which is hand-made Phase 0 input that
         * nothing in the pipeline may write. This disk is the Phase 2 half of
         * the same idea, and RenderWorkspace::sourcePath() resolves across both.
         */
        'assets' => [
            'driver' => 'local',
            'root' => storage_path('app/assets'),
            'throw' => true,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => rtrim(env('APP_URL', 'http://localhost'), '/').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
