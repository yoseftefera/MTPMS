<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Define environment setup for tests.
     *
     * This runs during createApplication(), before service providers boot,
     * ensuring Cache::store('redis') is redirected to the array driver before
     * Spatie PermissionRegistrar or RBACService tries to connect to Redis.
     *
     * @param  \Illuminate\Foundation\Application  $app
     */
    protected function defineEnvironment($app): void
    {
        // Redirect the 'redis' cache store to the in-memory array driver.
        // This prevents real TCP connections to Redis (port 6379) in tests.
        $app['config']->set('cache.stores.redis', [
            'driver'    => 'array',
            'serialize' => false,
        ]);

        // Route Spatie's permission cache to the array store.
        $app['config']->set('permission.cache.store', 'array');
    }
}
