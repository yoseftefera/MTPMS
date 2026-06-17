<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Queue Connection Name
    |--------------------------------------------------------------------------
    |
    | Laravel's queue supports a variety of backends via a unified API,
    | giving you convenient access to each backend using identical syntax.
    | The default connection is used when no explicit connection is specified.
    |
    */

    'default' => env('QUEUE_CONNECTION', 'redis'),

    /*
    |--------------------------------------------------------------------------
    | Queue Connections
    |--------------------------------------------------------------------------
    |
    | Here you may configure the connection information for each server that
    | is used by your application. A default configuration has been added
    | for each back-end shipped with Laravel. You are free to add more.
    |
    | Drivers: "sync", "database", "beanstalkd", "sqs", "redis", "null"
    |
    */

    'connections' => [

        'sync' => [
            'driver' => 'sync',
        ],

        'database' => [
            'driver'      => 'database',
            'connection'  => env('DB_QUEUE_CONNECTION'),
            'table'       => env('DB_QUEUE_TABLE', 'jobs'),
            'queue'       => env('DB_QUEUE', 'default'),
            'retry_after' => (int) env('DB_QUEUE_RETRY_AFTER', 90),
            'after_commit' => false,
        ],

        'redis' => [
            'driver'      => 'redis',
            'connection'  => env('REDIS_QUEUE_CONNECTION', 'default'),
            'retry_after' => (int) env('REDIS_QUEUE_RETRY_AFTER', 90),
            'block_for'   => null,
            'after_commit' => false,

            /*
            |------------------------------------------------------------------
            | Named Queues — Priority Order
            |------------------------------------------------------------------
            |
            | Three named queues are defined in priority order (highest first):
            |
            |   notifications  — High priority
            |       Jobs: SendNotificationEmailJob, WebSocket broadcasts
            |       Processed first on every queue:work cycle.
            |
            |   default        — Medium priority
            |       Jobs: WriteAuditLogJob, ProcessSupplierDocumentScanJob
            |       Processed after notifications queue is empty.
            |
            |   reports        — Low priority
            |       Jobs: GenerateReportJob
            |       Processed only when both higher-priority queues are empty.
            |
            | The queue worker is started with:
            |   php artisan queue:work redis \
            |       --queue=notifications,default,reports \
            |       --tries=3 \
            |       --backoff=60
            |
            */
            'queue' => env('REDIS_QUEUE', 'default'),
        ],

        'sqs' => [
            'driver'      => 'sqs',
            'key'         => env('AWS_ACCESS_KEY_ID'),
            'secret'      => env('AWS_SECRET_ACCESS_KEY'),
            'prefix'      => env('SQS_PREFIX', 'https://sqs.us-east-1.amazonaws.com/your-account-id'),
            'queue'       => env('SQS_QUEUE', 'default'),
            'suffix'      => env('SQS_SUFFIX'),
            'region'      => env('AWS_DEFAULT_REGION', 'us-east-1'),
            'after_commit' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Queue Worker Defaults
    |--------------------------------------------------------------------------
    |
    | These options configure the behavior of the queue worker process.
    | The queue worker is invoked via the queue:work Artisan command.
    |
    | Priority queue order: notifications → default → reports
    |
    */

    'worker' => [
        'queue'   => 'notifications,default,reports',
        'tries'   => 3,
        'backoff' => 60,
        'sleep'   => 3,
        'timeout' => 60,
    ],

    /*
    |--------------------------------------------------------------------------
    | Job Batching
    |--------------------------------------------------------------------------
    |
    | The following options configure the database and table that store job
    | batching information. These options can be updated to any database
    | connection and table which has been defined by your application.
    |
    */

    'batching' => [
        'database' => env('DB_CONNECTION', 'mysql'),
        'table'    => 'job_batches',
    ],

    /*
    |--------------------------------------------------------------------------
    | Failed Queue Jobs
    |--------------------------------------------------------------------------
    |
    | These options configure the behavior of failed queue job logging so you
    | can control which database and table are used to store the information.
    | You may change them to any database / table you wish.
    |
    */

    'failed' => [
        'driver'   => env('QUEUE_FAILED_DRIVER', 'database-uuids'),
        'database' => env('DB_CONNECTION', 'mysql'),
        'table'    => 'failed_jobs',
    ],

];
