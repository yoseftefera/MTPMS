<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | The FILESYSTEM_DISK environment variable controls which disk is used at
    | runtime.  Set it to 'local' for development/testing and 's3' for
    | production deployments on AWS or any S3-compatible object store.
    |
    | Requirements: 23.1, 23.5
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    */

    'disks' => [

        /*
         * Local disk — stores files under storage/app.
         * Used during local development and automated tests.
         */
        'local' => [
            'driver' => 'local',
            'root'   => storage_path('app'),
            'throw'  => false,
        ],

        /*
         * Public disk — files accessible via the /storage URL.
         * Run `php artisan storage:link` to create the symbolic link.
         */
        'public' => [
            'driver'     => 'local',
            'root'       => storage_path('app/public'),
            'url'        => env('APP_URL') . '/storage',
            'visibility' => 'public',
            'throw'      => false,
        ],

        /*
         * S3-compatible disk — used in production and staging.
         *
         * Required environment variables:
         *   AWS_ACCESS_KEY_ID       — IAM access key
         *   AWS_SECRET_ACCESS_KEY   — IAM secret key
         *   AWS_DEFAULT_REGION      — e.g. us-east-1
         *   AWS_BUCKET              — S3 bucket name
         *   AWS_URL                 — optional public CDN / pre-signed URL base
         *   AWS_ENDPOINT            — optional for S3-compatible stores (MinIO, etc.)
         *   AWS_USE_PATH_STYLE_ENDPOINT — set to true for MinIO / localstack
         *
         * Requirements: 23.1, 23.5
         */
        's3' => [
            'driver'                  => 's3',
            'key'                     => env('AWS_ACCESS_KEY_ID'),
            'secret'                  => env('AWS_SECRET_ACCESS_KEY'),
            'region'                  => env('AWS_DEFAULT_REGION', 'us-east-1'),
            'bucket'                  => env('AWS_BUCKET'),
            'url'                     => env('AWS_URL'),
            'endpoint'                => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw'                   => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | When you run the `storage:link` Artisan command, the following symbolic
    | links will be created.  The array keys are the link locations and the
    | values are their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
