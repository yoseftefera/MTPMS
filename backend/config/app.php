<?php

use Illuminate\Support\Facades\Facade;

return [

    /*
    |--------------------------------------------------------------------------
    | Application Name
    |--------------------------------------------------------------------------
    */
    'name' => env('APP_NAME', 'Procurement Management Platform'),

    /*
    |--------------------------------------------------------------------------
    | Application Environment
    |--------------------------------------------------------------------------
    */
    'env' => env('APP_ENV', 'production'),

    /*
    |--------------------------------------------------------------------------
    | Application Debug Mode
    |--------------------------------------------------------------------------
    */
    'debug' => (bool) env('APP_DEBUG', false),

    /*
    |--------------------------------------------------------------------------
    | Application URL
    |--------------------------------------------------------------------------
    */
    'url' => env('APP_URL', 'http://localhost'),

    /*
    |--------------------------------------------------------------------------
    | Application Timezone
    |--------------------------------------------------------------------------
    */
    'timezone' => env('APP_TIMEZONE', 'UTC'),

    /*
    |--------------------------------------------------------------------------
    | Application Locale Configuration
    |--------------------------------------------------------------------------
    */
    'locale'          => env('APP_LOCALE', 'en'),
    'fallback_locale' => env('APP_FALLBACK_LOCALE', 'en'),
    'faker_locale'    => env('APP_FAKER_LOCALE', 'en_US'),

    /*
    |--------------------------------------------------------------------------
    | Encryption Key
    |--------------------------------------------------------------------------
    */
    'key'    => env('APP_KEY'),
    'cipher' => 'AES-256-CBC',

    /*
    |--------------------------------------------------------------------------
    | Maintenance Mode Driver
    |--------------------------------------------------------------------------
    */
    'maintenance' => [
        'driver' => env('APP_MAINTENANCE_DRIVER', 'file'),
        'store'  => env('APP_MAINTENANCE_STORE', 'database'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Custom Application Configuration
    |--------------------------------------------------------------------------
    */

    // Multi-tenancy
    'tenant_cache_ttl' => (int) env('TENANT_CACHE_TTL', 60),

    // Rate limiting
    'rate_limit_auth' => (int) env('RATE_LIMIT_AUTH', 60),
    'rate_limit_api'  => (int) env('RATE_LIMIT_API', 300),

    // Account security
    'max_login_attempts'    => (int) env('MAX_LOGIN_ATTEMPTS', 5),
    'password_reset_expiry' => (int) env('PASSWORD_RESET_EXPIRY', 60),

    // Notifications
    'notification_queue'       => env('NOTIFICATION_QUEUE', 'notifications'),
    'notification_max_retries' => (int) env('NOTIFICATION_MAX_RETRIES', 3),

    // Reports
    'report_cache_ttl'    => (int) env('REPORT_CACHE_TTL', 300),
    'report_sync_max_rows' => (int) env('REPORT_SYNC_MAX_ROWS', 10000),

];
