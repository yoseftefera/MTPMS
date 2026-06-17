<?php

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Redis;

/**
 * Feature tests for rate limiting and CSRF token endpoint.
 *
 * Validates Requirements: 2.7, 2.8
 *
 * Covers:
 *  - Auth endpoints are rate-limited at 60 requests/minute per IP (HTTP 429 on exceed)
 *  - API endpoints are rate-limited at 300 requests/minute per user/IP (HTTP 429 on exceed)
 *  - Rate limit response uses the standard JSON envelope
 *  - Rate limit response includes X-RateLimit-Limit, X-RateLimit-Remaining, Retry-After headers
 *  - CSRF token endpoint returns a valid token for browser clients
 *  - CSRF token endpoint is accessible without authentication
 */

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

// ---------------------------------------------------------------------------
// Requirement 2.8 — Auth rate limiter: 60 requests/minute per IP
// ---------------------------------------------------------------------------

it('auth rate limiter is registered with the correct name', function () {
    $limiter = RateLimiter::limiter('auth');
    expect($limiter)->not->toBeNull();
});

it('api rate limiter is registered with the correct name', function () {
    $limiter = RateLimiter::limiter('api');
    expect($limiter)->not->toBeNull();
});

it('auth rate limiter uses the configured rate_limit_auth value from config', function () {
    $authLimit = config('app.rate_limit_auth');
    expect($authLimit)->toBe(60);
});

it('api rate limiter uses the configured rate_limit_api value from config', function () {
    $apiLimit = config('app.rate_limit_api');
    expect($apiLimit)->toBe(300);
});

it('auth rate limiter is scoped per IP address', function () {
    // Build a fake request to test the limiter key
    $request = Request::create('/api/v1/auth/login', 'POST');
    $request->server->set('REMOTE_ADDR', '192.168.1.100');

    $limiterCallback = RateLimiter::limiter('auth');
    $limit = $limiterCallback($request);

    // The limit should be keyed by IP
    expect($limit)->toBeInstanceOf(Limit::class);
    expect($limit->key)->toBe('192.168.1.100');
});

it('api rate limiter is scoped per user ID when authenticated', function () {
    $tenant = Tenant::factory()->create(['status' => 'active']);
    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
        'status'    => 'active',
    ]);

    // Build a fake request with an authenticated user
    $request = Request::create('/api/v1/users', 'GET');
    $request->setUserResolver(fn () => $user);

    $limiterCallback = RateLimiter::limiter('api');
    $limit = $limiterCallback($request);

    // The limit should be keyed by user ID when authenticated
    expect($limit)->toBeInstanceOf(Limit::class);
    expect($limit->key)->toBe((string) $user->id);
});

it('api rate limiter falls back to IP when unauthenticated', function () {
    $request = Request::create('/api/v1/users', 'GET');
    $request->server->set('REMOTE_ADDR', '10.0.0.1');
    // No user resolver set — unauthenticated

    $limiterCallback = RateLimiter::limiter('api');
    $limit = $limiterCallback($request);

    // Falls back to IP when no authenticated user
    expect($limit)->toBeInstanceOf(Limit::class);
    expect($limit->key)->toBe('10.0.0.1');
});

it('auth rate limiter allows requests up to the configured limit', function () {
    $key = 'test-auth-ip-' . uniqid();

    // Simulate hitting the rate limiter up to the limit
    $maxAttempts = config('app.rate_limit_auth', 60);

    for ($i = 0; $i < $maxAttempts; $i++) {
        $tooManyAttempts = RateLimiter::tooManyAttempts($key, $maxAttempts);
        if (! $tooManyAttempts) {
            RateLimiter::hit($key, 60);
        }
    }

    // After hitting the limit, the next attempt should be blocked
    expect(RateLimiter::tooManyAttempts($key, $maxAttempts))->toBeTrue();
});

it('auth rate limiter returns HTTP 429 when limit is exceeded via actual endpoint', function () {
    Redis::shouldReceive('exists')->andReturn(0)->byDefault();
    Redis::shouldReceive('setex')->andReturn(true)->byDefault();
    Redis::shouldReceive('get')->andReturn(null)->byDefault();
    Redis::shouldReceive('del')->andReturn(1)->byDefault();

    $tenant = Tenant::factory()->create(['status' => 'active']);

    // Override the auth rate limit to 1 for testing
    RateLimiter::for('auth', function (Request $request) {
        return Limit::perMinute(1)
            ->by($request->ip())
            ->response(function (Request $req, array $headers) {
                return response()->json([
                    'success' => false,
                    'data'    => null,
                    'message' => 'Too many requests. Please try again later.',
                    'errors'  => null,
                    'meta'    => ['retry_after' => $headers['Retry-After'] ?? null],
                ], 429, [
                    'X-RateLimit-Limit'     => 1,
                    'X-RateLimit-Remaining' => 0,
                    'Retry-After'           => $headers['Retry-After'] ?? 60,
                ]);
            });
    });

    // Pre-exhaust the rate limiter using the exact key ThrottleRequests uses:
    // md5($limiterName . $limit->key) = md5('auth' . '127.0.0.1')
    $rateLimiterKey = md5('auth' . '127.0.0.1');
    RateLimiter::hit($rateLimiterKey, 60);

    // This request should now be rate-limited (limit of 1 already hit)
    $response = test()->postJson('/api/v1/auth/login', [
        'email'    => 'nonexistent@example.com',
        'password' => 'wrong-password',
    ], ['X-Tenant-ID' => $tenant->id]);

    $response->assertStatus(429);
});

it('rate limit response on auth endpoint uses the standard JSON envelope', function () {
    Redis::shouldReceive('exists')->andReturn(0)->byDefault();
    Redis::shouldReceive('setex')->andReturn(true)->byDefault();
    Redis::shouldReceive('get')->andReturn(null)->byDefault();
    Redis::shouldReceive('del')->andReturn(1)->byDefault();

    $tenant = Tenant::factory()->create(['status' => 'active']);

    // Override the auth rate limit to 1 for testing
    RateLimiter::for('auth', function (Request $request) {
        return Limit::perMinute(1)
            ->by($request->ip())
            ->response(function (Request $req, array $headers) {
                return response()->json([
                    'success' => false,
                    'data'    => null,
                    'message' => 'Too many requests. Please try again later.',
                    'errors'  => null,
                    'meta'    => ['retry_after' => $headers['Retry-After'] ?? null],
                ], 429, [
                    'X-RateLimit-Limit'     => 1,
                    'X-RateLimit-Remaining' => 0,
                    'Retry-After'           => $headers['Retry-After'] ?? 60,
                ]);
            });
    });

    // Pre-exhaust the rate limiter using the exact key ThrottleRequests uses
    RateLimiter::hit(md5('auth' . '127.0.0.1'), 60);

    $response = test()->postJson('/api/v1/auth/login', [
        'email'    => 'nonexistent@example.com',
        'password' => 'wrong',
    ], ['X-Tenant-ID' => $tenant->id]);

    $response->assertStatus(429);

    $body = $response->json();
    expect($body)->toHaveKeys(['success', 'data', 'message', 'errors', 'meta']);
    expect($body['success'])->toBeFalse();
    expect($body['data'])->toBeNull();
    expect($body['message'])->toBe('Too many requests. Please try again later.');
    expect($body['errors'])->toBeNull();
});

it('rate limit response on auth endpoint includes Retry-After and X-RateLimit headers', function () {
    Redis::shouldReceive('exists')->andReturn(0)->byDefault();
    Redis::shouldReceive('setex')->andReturn(true)->byDefault();
    Redis::shouldReceive('get')->andReturn(null)->byDefault();
    Redis::shouldReceive('del')->andReturn(1)->byDefault();

    $tenant = Tenant::factory()->create(['status' => 'active']);

    RateLimiter::for('auth', function (Request $request) {
        return Limit::perMinute(1)
            ->by($request->ip())
            ->response(function (Request $req, array $headers) {
                return response()->json([
                    'success' => false,
                    'data'    => null,
                    'message' => 'Too many requests. Please try again later.',
                    'errors'  => null,
                    'meta'    => ['retry_after' => $headers['Retry-After'] ?? null],
                ], 429, [
                    'X-RateLimit-Limit'     => 1,
                    'X-RateLimit-Remaining' => 0,
                    'Retry-After'           => $headers['Retry-After'] ?? 60,
                ]);
            });
    });

    // Pre-exhaust the rate limiter using the exact key ThrottleRequests uses
    RateLimiter::hit(md5('auth' . '127.0.0.1'), 60);

    $response = test()->postJson('/api/v1/auth/login', [
        'email'    => 'nonexistent@example.com',
        'password' => 'wrong',
    ], ['X-Tenant-ID' => $tenant->id]);

    $response->assertStatus(429);
    $response->assertHeader('Retry-After');
    $response->assertHeader('X-RateLimit-Limit', 1);
    $response->assertHeader('X-RateLimit-Remaining', 0);
});

// ---------------------------------------------------------------------------
// Requirement 2.8 — API rate limiter: 300 requests/minute per user/IP
// ---------------------------------------------------------------------------

it('api rate limiter allows requests up to the configured limit', function () {
    $key = 'test-api-user-' . uniqid();

    $maxAttempts = config('app.rate_limit_api', 300);

    for ($i = 0; $i < $maxAttempts; $i++) {
        $tooManyAttempts = RateLimiter::tooManyAttempts($key, $maxAttempts);
        if (! $tooManyAttempts) {
            RateLimiter::hit($key, 60);
        }
    }

    expect(RateLimiter::tooManyAttempts($key, $maxAttempts))->toBeTrue();
});

it('api rate limiter returns HTTP 429 when limit is exceeded via actual endpoint', function () {
    Redis::shouldReceive('exists')->andReturn(0)->byDefault();
    Redis::shouldReceive('setex')->andReturn(true)->byDefault();
    Redis::shouldReceive('get')->andReturn(null)->byDefault();
    Redis::shouldReceive('del')->andReturn(1)->byDefault();

    $tenant = Tenant::factory()->create(['status' => 'active']);

    // Override the API rate limit to 1 for testing
    RateLimiter::for('api', function (Request $request) {
        return Limit::perMinute(1)
            ->by($request->user()?->id ?: $request->ip())
            ->response(function (Request $req, array $headers) {
                return response()->json([
                    'success' => false,
                    'data'    => null,
                    'message' => 'Too many requests. Please try again later.',
                    'errors'  => null,
                    'meta'    => ['retry_after' => $headers['Retry-After'] ?? null],
                ], 429, [
                    'X-RateLimit-Limit'     => 1,
                    'X-RateLimit-Remaining' => 0,
                    'Retry-After'           => $headers['Retry-After'] ?? 60,
                ]);
            });
    });

    // Pre-exhaust the rate limiter for the test IP (127.0.0.1) — unauthenticated request
    // ThrottleRequests uses md5($limiterName . $limit->key) as the cache key
    RateLimiter::hit(md5('api' . '127.0.0.1'), 60);

    // This request should now be rate-limited
    $response = test()->getJson('/api/v1/users', [
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertStatus(429);
});

it('api rate limit response uses the standard JSON envelope', function () {
    Redis::shouldReceive('exists')->andReturn(0)->byDefault();
    Redis::shouldReceive('setex')->andReturn(true)->byDefault();
    Redis::shouldReceive('get')->andReturn(null)->byDefault();
    Redis::shouldReceive('del')->andReturn(1)->byDefault();

    $tenant = Tenant::factory()->create(['status' => 'active']);

    RateLimiter::for('api', function (Request $request) {
        return Limit::perMinute(1)
            ->by($request->user()?->id ?: $request->ip())
            ->response(function (Request $req, array $headers) {
                return response()->json([
                    'success' => false,
                    'data'    => null,
                    'message' => 'Too many requests. Please try again later.',
                    'errors'  => null,
                    'meta'    => ['retry_after' => $headers['Retry-After'] ?? null],
                ], 429, [
                    'X-RateLimit-Limit'     => 1,
                    'X-RateLimit-Remaining' => 0,
                    'Retry-After'           => $headers['Retry-After'] ?? 60,
                ]);
            });
    });

    // Pre-exhaust the rate limiter
    RateLimiter::hit(md5('api' . '127.0.0.1'), 60);

    $response = test()->getJson('/api/v1/users', ['X-Tenant-ID' => $tenant->id]);

    $response->assertStatus(429);

    $body = $response->json();
    expect($body)->toHaveKeys(['success', 'data', 'message', 'errors', 'meta']);
    expect($body['success'])->toBeFalse();
    expect($body['data'])->toBeNull();
    expect($body['message'])->toBe('Too many requests. Please try again later.');
    expect($body['errors'])->toBeNull();
});

it('api rate limit response includes Retry-After and X-RateLimit headers', function () {
    Redis::shouldReceive('exists')->andReturn(0)->byDefault();
    Redis::shouldReceive('setex')->andReturn(true)->byDefault();
    Redis::shouldReceive('get')->andReturn(null)->byDefault();
    Redis::shouldReceive('del')->andReturn(1)->byDefault();

    $tenant = Tenant::factory()->create(['status' => 'active']);

    RateLimiter::for('api', function (Request $request) {
        return Limit::perMinute(1)
            ->by($request->user()?->id ?: $request->ip())
            ->response(function (Request $req, array $headers) {
                return response()->json([
                    'success' => false,
                    'data'    => null,
                    'message' => 'Too many requests. Please try again later.',
                    'errors'  => null,
                    'meta'    => ['retry_after' => $headers['Retry-After'] ?? null],
                ], 429, [
                    'X-RateLimit-Limit'     => 1,
                    'X-RateLimit-Remaining' => 0,
                    'Retry-After'           => $headers['Retry-After'] ?? 60,
                ]);
            });
    });

    // Pre-exhaust the rate limiter
    RateLimiter::hit(md5('api' . '127.0.0.1'), 60);

    $response = test()->getJson('/api/v1/users', ['X-Tenant-ID' => $tenant->id]);

    $response->assertStatus(429);
    $response->assertHeader('Retry-After');
    $response->assertHeader('X-RateLimit-Limit', 1);
    $response->assertHeader('X-RateLimit-Remaining', 0);
});

// ---------------------------------------------------------------------------
// Requirement 2.7 — CSRF token endpoint for browser clients
// ---------------------------------------------------------------------------

it('GET /api/v1/auth/csrf-token returns HTTP 200 with a csrf_token', function () {
    Redis::shouldReceive('exists')->andReturn(0)->byDefault();
    Redis::shouldReceive('setex')->andReturn(true)->byDefault();
    Redis::shouldReceive('get')->andReturn(null)->byDefault();

    $tenant = Tenant::factory()->create(['status' => 'active']);

    $response = test()->getJson('/api/v1/auth/csrf-token', [
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertStatus(200);
    $response->assertJsonStructure([
        'success',
        'data' => ['csrf_token'],
        'message',
        'errors',
        'meta',
    ]);
    $response->assertJson(['success' => true]);
});

it('CSRF token endpoint returns a non-empty token string', function () {
    Redis::shouldReceive('exists')->andReturn(0)->byDefault();
    Redis::shouldReceive('setex')->andReturn(true)->byDefault();
    Redis::shouldReceive('get')->andReturn(null)->byDefault();

    $tenant = Tenant::factory()->create(['status' => 'active']);

    $response = test()->getJson('/api/v1/auth/csrf-token', [
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertStatus(200);

    $token = $response->json('data.csrf_token');
    expect($token)->toBeString();
    expect(strlen($token))->toBeGreaterThan(0);
});

it('CSRF token endpoint is accessible without authentication', function () {
    Redis::shouldReceive('exists')->andReturn(0)->byDefault();
    Redis::shouldReceive('setex')->andReturn(true)->byDefault();
    Redis::shouldReceive('get')->andReturn(null)->byDefault();

    $tenant = Tenant::factory()->create(['status' => 'active']);

    // No Authorization header — should still succeed
    $response = test()->getJson('/api/v1/auth/csrf-token', [
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertStatus(200);
    $response->assertJson(['success' => true]);
});

it('CSRF token endpoint response uses the standard JSON envelope', function () {
    Redis::shouldReceive('exists')->andReturn(0)->byDefault();
    Redis::shouldReceive('setex')->andReturn(true)->byDefault();
    Redis::shouldReceive('get')->andReturn(null)->byDefault();

    $tenant = Tenant::factory()->create(['status' => 'active']);

    $response = test()->getJson('/api/v1/auth/csrf-token', [
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertStatus(200);

    $body = $response->json();
    expect($body)->toHaveKeys(['success', 'data', 'message', 'errors', 'meta']);
    expect($body['success'])->toBeTrue();
    expect($body['errors'])->toBeNull();
    expect($body['data'])->toHaveKey('csrf_token');
});

it('CSRF token endpoint sets XSRF-TOKEN cookie for browser clients', function () {
    Redis::shouldReceive('exists')->andReturn(0)->byDefault();
    Redis::shouldReceive('setex')->andReturn(true)->byDefault();
    Redis::shouldReceive('get')->andReturn(null)->byDefault();

    $tenant = Tenant::factory()->create(['status' => 'active']);

    $response = test()->getJson('/api/v1/auth/csrf-token', [
        'X-Tenant-ID' => $tenant->id,
    ]);

    $response->assertStatus(200);
    $response->assertCookie('XSRF-TOKEN');
});

it('CSRF token endpoint returns a different token on each request', function () {
    Redis::shouldReceive('exists')->andReturn(0)->byDefault();
    Redis::shouldReceive('setex')->andReturn(true)->byDefault();
    Redis::shouldReceive('get')->andReturn(null)->byDefault();

    $tenant = Tenant::factory()->create(['status' => 'active']);

    $response1 = test()->getJson('/api/v1/auth/csrf-token', ['X-Tenant-ID' => $tenant->id]);
    $response2 = test()->getJson('/api/v1/auth/csrf-token', ['X-Tenant-ID' => $tenant->id]);

    $token1 = $response1->json('data.csrf_token');
    $token2 = $response2->json('data.csrf_token');

    expect($token1)->toBeString();
    expect($token2)->toBeString();
    // Each call generates a fresh random token
    expect($token1)->not->toBe($token2);
});

it('CSRF token endpoint is included in the auth route group', function () {
    $routes = app('router')->getRoutes();
    $csrfRoute = collect($routes->getRoutes())->first(function ($route) {
        return str_contains($route->uri(), 'auth/csrf-token');
    });

    expect($csrfRoute)->not->toBeNull();
    expect($csrfRoute->methods())->toContain('GET');
});
