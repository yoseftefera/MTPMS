<?php

use App\Http\Middleware\AuthMiddleware;
use App\Jobs\SendPasswordResetEmailJob;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Redis;
use Symfony\Component\HttpFoundation\Response;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * Feature tests for account lockout and session timeout enforcement.
 *
 * Validates Requirements: 2.2, 2.3, 2.4
 *
 * Covers:
 *  - Wrong password increments failed_login_attempts counter
 *  - 5th failure locks account and dispatches SendPasswordResetEmailJob
 *  - Locked account cannot authenticate even with correct password
 *  - Session timeout: JWT older than tenant-configured timeout is rejected with HTTP 401
 */

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Build a POST /api/v1/auth/login request with the tenant header set.
 */
function loginRequest(Tenant $tenant, string $email, string $password): \Illuminate\Testing\TestResponse
{
    return test()->postJson('/api/v1/auth/login', [
        'email'    => $email,
        'password' => $password,
    ], [
        'X-Tenant-ID' => $tenant->id,
    ]);
}

// ---------------------------------------------------------------------------
// Requirement 2.2 — Wrong password increments failed_login_attempts
// ---------------------------------------------------------------------------

it('increments failed_login_attempts by 1 on each wrong password attempt', function () {
    Bus::fake();
    Redis::shouldReceive('exists')->andReturn(0)->byDefault();
    Redis::shouldReceive('setex')->andReturn(true)->byDefault();
    Redis::shouldReceive('get')->andReturn(null)->byDefault();
    Redis::shouldReceive('del')->andReturn(1)->byDefault();

    $tenant = Tenant::factory()->create(['status' => 'active']);

    $user = User::factory()->create([
        'tenant_id'             => $tenant->id,
        'email'                 => 'staff@example.com',
        'password'              => Hash::make('correct-password'),
        'status'                => 'active',
        'failed_login_attempts' => 0,
    ]);

    loginRequest($tenant, 'staff@example.com', 'wrong-password');

    expect($user->fresh()->failed_login_attempts)->toBe(1);
});

it('increments failed_login_attempts cumulatively across multiple wrong attempts', function () {
    Bus::fake();
    Redis::shouldReceive('exists')->andReturn(0)->byDefault();
    Redis::shouldReceive('setex')->andReturn(true)->byDefault();
    Redis::shouldReceive('get')->andReturn(null)->byDefault();
    Redis::shouldReceive('del')->andReturn(1)->byDefault();

    $tenant = Tenant::factory()->create([
        'status'   => 'active',
        'settings' => ['lockout_threshold' => 5],
    ]);

    $user = User::factory()->create([
        'tenant_id'             => $tenant->id,
        'email'                 => 'staff@example.com',
        'password'              => Hash::make('correct-password'),
        'status'                => 'active',
        'failed_login_attempts' => 0,
    ]);

    // Three consecutive wrong-password attempts
    loginRequest($tenant, 'staff@example.com', 'wrong-1');
    loginRequest($tenant, 'staff@example.com', 'wrong-2');
    loginRequest($tenant, 'staff@example.com', 'wrong-3');

    expect($user->fresh()->failed_login_attempts)->toBe(3);
});

it('returns HTTP 401 with invalid credentials message on wrong password', function () {
    Bus::fake();
    Redis::shouldReceive('exists')->andReturn(0)->byDefault();
    Redis::shouldReceive('setex')->andReturn(true)->byDefault();
    Redis::shouldReceive('get')->andReturn(null)->byDefault();
    Redis::shouldReceive('del')->andReturn(1)->byDefault();

    $tenant = Tenant::factory()->create(['status' => 'active']);

    User::factory()->create([
        'tenant_id' => $tenant->id,
        'email'     => 'staff@example.com',
        'password'  => Hash::make('correct-password'),
        'status'    => 'active',
    ]);

    $response = loginRequest($tenant, 'staff@example.com', 'wrong-password');

    $response->assertStatus(401);
    $response->assertJson(['success' => false]);
});

it('resets failed_login_attempts to 0 on successful login', function () {
    Bus::fake();
    Redis::shouldReceive('exists')->andReturn(0)->byDefault();
    Redis::shouldReceive('setex')->andReturn(true)->byDefault();
    Redis::shouldReceive('get')->andReturn(null)->byDefault();
    Redis::shouldReceive('del')->andReturn(1)->byDefault();

    $tenant = Tenant::factory()->create(['status' => 'active']);

    $user = User::factory()->create([
        'tenant_id'             => $tenant->id,
        'email'                 => 'staff@example.com',
        'password'              => Hash::make('correct-password'),
        'status'                => 'active',
        'failed_login_attempts' => 3,
    ]);

    // Mock JWTAuth for the successful login
    JWTAuth::shouldReceive('fromUser')
        ->once()
        ->andReturn('mocked.jwt.token');

    loginRequest($tenant, 'staff@example.com', 'correct-password');

    expect($user->fresh()->failed_login_attempts)->toBe(0);
});

// ---------------------------------------------------------------------------
// Requirement 2.3 — 5th failure locks account and sends password-reset email
// ---------------------------------------------------------------------------

it('locks account when failed_login_attempts reaches the default threshold of 5', function () {
    Bus::fake();
    Redis::shouldReceive('exists')->andReturn(0)->byDefault();
    Redis::shouldReceive('setex')->andReturn(true)->byDefault();
    Redis::shouldReceive('get')->andReturn(null)->byDefault();
    Redis::shouldReceive('del')->andReturn(1)->byDefault();

    $tenant = Tenant::factory()->create([
        'status'   => 'active',
        'settings' => ['lockout_threshold' => 5],
    ]);

    $user = User::factory()->create([
        'tenant_id'             => $tenant->id,
        'email'                 => 'staff@example.com',
        'password'              => Hash::make('correct-password'),
        'status'                => 'active',
        'failed_login_attempts' => 4, // one more will hit threshold
    ]);

    loginRequest($tenant, 'staff@example.com', 'wrong-password');

    $fresh = $user->fresh();
    expect($fresh->status)->toBe('locked');
    expect($fresh->failed_login_attempts)->toBe(5);
});

it('dispatches SendPasswordResetEmailJob when account is locked on 5th failure', function () {
    Bus::fake();
    Redis::shouldReceive('exists')->andReturn(0)->byDefault();
    Redis::shouldReceive('setex')->andReturn(true)->byDefault();
    Redis::shouldReceive('get')->andReturn(null)->byDefault();
    Redis::shouldReceive('del')->andReturn(1)->byDefault();

    $tenant = Tenant::factory()->create([
        'status'   => 'active',
        'settings' => ['lockout_threshold' => 5],
    ]);

    User::factory()->create([
        'tenant_id'             => $tenant->id,
        'email'                 => 'staff@example.com',
        'password'              => Hash::make('correct-password'),
        'status'                => 'active',
        'failed_login_attempts' => 4,
    ]);

    loginRequest($tenant, 'staff@example.com', 'wrong-password');

    Bus::assertDispatched(SendPasswordResetEmailJob::class);
});

it('does not dispatch SendPasswordResetEmailJob before threshold is reached', function () {
    Bus::fake();
    Redis::shouldReceive('exists')->andReturn(0)->byDefault();
    Redis::shouldReceive('setex')->andReturn(true)->byDefault();
    Redis::shouldReceive('get')->andReturn(null)->byDefault();
    Redis::shouldReceive('del')->andReturn(1)->byDefault();

    $tenant = Tenant::factory()->create([
        'status'   => 'active',
        'settings' => ['lockout_threshold' => 5],
    ]);

    User::factory()->create([
        'tenant_id'             => $tenant->id,
        'email'                 => 'staff@example.com',
        'password'              => Hash::make('correct-password'),
        'status'                => 'active',
        'failed_login_attempts' => 3, // 4th attempt — still below threshold
    ]);

    loginRequest($tenant, 'staff@example.com', 'wrong-password');

    Bus::assertNotDispatched(SendPasswordResetEmailJob::class);
});

it('respects tenant-configured lockout_threshold instead of default 5', function () {
    Bus::fake();
    Redis::shouldReceive('exists')->andReturn(0)->byDefault();
    Redis::shouldReceive('setex')->andReturn(true)->byDefault();
    Redis::shouldReceive('get')->andReturn(null)->byDefault();
    Redis::shouldReceive('del')->andReturn(1)->byDefault();

    // Tenant configured with a threshold of 3
    $tenant = Tenant::factory()->create([
        'status'   => 'active',
        'settings' => ['lockout_threshold' => 3],
    ]);

    $user = User::factory()->create([
        'tenant_id'             => $tenant->id,
        'email'                 => 'staff@example.com',
        'password'              => Hash::make('correct-password'),
        'status'                => 'active',
        'failed_login_attempts' => 2, // one more will hit threshold of 3
    ]);

    loginRequest($tenant, 'staff@example.com', 'wrong-password');

    expect($user->fresh()->status)->toBe('locked');
    Bus::assertDispatched(SendPasswordResetEmailJob::class);
});

// ---------------------------------------------------------------------------
// Requirement 2.3 — Locked account cannot authenticate even with correct password
// ---------------------------------------------------------------------------

it('returns HTTP 401 when a locked account attempts login with correct password', function () {
    Bus::fake();
    Redis::shouldReceive('exists')->andReturn(0)->byDefault();
    Redis::shouldReceive('setex')->andReturn(true)->byDefault();
    Redis::shouldReceive('get')->andReturn(null)->byDefault();
    Redis::shouldReceive('del')->andReturn(1)->byDefault();

    $tenant = Tenant::factory()->create(['status' => 'active']);

    User::factory()->create([
        'tenant_id'             => $tenant->id,
        'email'                 => 'locked@example.com',
        'password'              => Hash::make('correct-password'),
        'status'                => 'locked',
        'failed_login_attempts' => 5,
    ]);

    $response = loginRequest($tenant, 'locked@example.com', 'correct-password');

    $response->assertStatus(401);
    $response->assertJson(['success' => false]);
});

it('does not issue a JWT token for a locked account', function () {
    Bus::fake();
    Redis::shouldReceive('exists')->andReturn(0)->byDefault();
    Redis::shouldReceive('setex')->andReturn(true)->byDefault();
    Redis::shouldReceive('get')->andReturn(null)->byDefault();
    Redis::shouldReceive('del')->andReturn(1)->byDefault();

    $tenant = Tenant::factory()->create(['status' => 'active']);

    User::factory()->create([
        'tenant_id'             => $tenant->id,
        'email'                 => 'locked@example.com',
        'password'              => Hash::make('correct-password'),
        'status'                => 'locked',
        'failed_login_attempts' => 5,
    ]);

    $response = loginRequest($tenant, 'locked@example.com', 'correct-password');

    $body = $response->json();
    expect($body)->not->toHaveKey('data.access_token');
    expect($body['success'])->toBeFalse();
});

// ---------------------------------------------------------------------------
// Requirement 2.4 — Session timeout: JWT older than tenant-configured timeout
//                   is rejected with HTTP 401
// ---------------------------------------------------------------------------

it('rejects a JWT whose iat is older than the tenant session_timeout_minutes with HTTP 401', function () {
    $tenant = Tenant::factory()->create([
        'status'   => 'active',
        'settings' => ['session_timeout_minutes' => 30],
    ]);

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
        'status'    => 'active',
    ]);

    // Build a payload where iat is 31 minutes ago (exceeds 30-minute timeout)
    $iatExpired = now()->subMinutes(31)->timestamp;

    $payload = Mockery::mock(\Tymon\JWTAuth\Payload::class);
    $payload->shouldReceive('get')->with('jti')->andReturn('test-jti-expired');
    $payload->shouldReceive('get')->with('iat')->andReturn($iatExpired);
    $payload->shouldReceive('get')->with('tenant_id')->andReturn((string) $tenant->id);

    $tokenParser = Mockery::mock();
    $tokenParser->shouldReceive('getPayload')->andReturn($payload);
    $tokenParser->shouldReceive('authenticate')->andReturn($user);

    JWTAuth::shouldReceive('parseToken')->andReturn($tokenParser);

    Redis::shouldReceive('exists')
        ->with('jwt:blacklist:test-jti-expired')
        ->andReturn(0);

    app()->instance('tenant', $tenant);

    $request = Request::create('/api/v1/auth/me', 'GET');
    $request->headers->set('Authorization', 'Bearer fake.expired.token');

    $middleware = new AuthMiddleware();
    $response   = $middleware->handle($request, fn () => response()->json(['ok' => true]));

    expect($response->getStatusCode())->toBe(Response::HTTP_UNAUTHORIZED);

    $body = json_decode($response->getContent(), true);
    expect($body['success'])->toBeFalse();
    expect($body['message'])->toContain('expired');
});

it('accepts a JWT whose iat is within the tenant session_timeout_minutes', function () {
    $tenant = Tenant::factory()->create([
        'status'   => 'active',
        'settings' => ['session_timeout_minutes' => 30],
    ]);

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
        'status'    => 'active',
    ]);

    // iat is 10 minutes ago — well within the 30-minute window
    $iatValid = now()->subMinutes(10)->timestamp;

    $payload = Mockery::mock(\Tymon\JWTAuth\Payload::class);
    $payload->shouldReceive('get')->with('jti')->andReturn('test-jti-valid');
    $payload->shouldReceive('get')->with('iat')->andReturn($iatValid);
    $payload->shouldReceive('get')->with('tenant_id')->andReturn((string) $tenant->id);

    $tokenParser = Mockery::mock();
    $tokenParser->shouldReceive('getPayload')->andReturn($payload);
    $tokenParser->shouldReceive('authenticate')->andReturn($user);

    JWTAuth::shouldReceive('parseToken')->andReturn($tokenParser);

    Redis::shouldReceive('exists')
        ->with('jwt:blacklist:test-jti-valid')
        ->andReturn(0);

    app()->instance('tenant', $tenant);

    $request = Request::create('/api/v1/auth/me', 'GET');
    $request->headers->set('Authorization', 'Bearer fake.valid.token');

    $middleware = new AuthMiddleware();
    $response   = $middleware->handle($request, fn () => response()->json(['ok' => true]));

    expect($response->getStatusCode())->toBe(Response::HTTP_OK);
});

it('uses default session timeout of 1440 minutes when tenant has no session_timeout_minutes configured', function () {
    $tenant = Tenant::factory()->create([
        'status'   => 'active',
        'settings' => [], // no session_timeout_minutes
    ]);

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
        'status'    => 'active',
    ]);

    // iat is 23 hours ago — within the default 1440-minute (24-hour) window
    $iatValid = now()->subHours(23)->timestamp;

    $payload = Mockery::mock(\Tymon\JWTAuth\Payload::class);
    $payload->shouldReceive('get')->with('jti')->andReturn('test-jti-default-timeout');
    $payload->shouldReceive('get')->with('iat')->andReturn($iatValid);
    $payload->shouldReceive('get')->with('tenant_id')->andReturn((string) $tenant->id);

    $tokenParser = Mockery::mock();
    $tokenParser->shouldReceive('getPayload')->andReturn($payload);
    $tokenParser->shouldReceive('authenticate')->andReturn($user);

    JWTAuth::shouldReceive('parseToken')->andReturn($tokenParser);

    Redis::shouldReceive('exists')
        ->with('jwt:blacklist:test-jti-default-timeout')
        ->andReturn(0);

    app()->instance('tenant', $tenant);

    $request = Request::create('/api/v1/auth/me', 'GET');
    $request->headers->set('Authorization', 'Bearer fake.token');

    $middleware = new AuthMiddleware();
    $response   = $middleware->handle($request, fn () => response()->json(['ok' => true]));

    expect($response->getStatusCode())->toBe(Response::HTTP_OK);
});

it('rejects a JWT that exceeds the default 1440-minute session timeout', function () {
    $tenant = Tenant::factory()->create([
        'status'   => 'active',
        'settings' => [], // no session_timeout_minutes — defaults to 1440
    ]);

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
        'status'    => 'active',
    ]);

    // iat is 25 hours ago — exceeds the default 1440-minute (24-hour) window
    $iatExpired = now()->subHours(25)->timestamp;

    $payload = Mockery::mock(\Tymon\JWTAuth\Payload::class);
    $payload->shouldReceive('get')->with('jti')->andReturn('test-jti-default-expired');
    $payload->shouldReceive('get')->with('iat')->andReturn($iatExpired);
    $payload->shouldReceive('get')->with('tenant_id')->andReturn((string) $tenant->id);

    $tokenParser = Mockery::mock();
    $tokenParser->shouldReceive('getPayload')->andReturn($payload);
    $tokenParser->shouldReceive('authenticate')->andReturn($user);

    JWTAuth::shouldReceive('parseToken')->andReturn($tokenParser);

    Redis::shouldReceive('exists')
        ->with('jwt:blacklist:test-jti-default-expired')
        ->andReturn(0);

    app()->instance('tenant', $tenant);

    $request = Request::create('/api/v1/auth/me', 'GET');
    $request->headers->set('Authorization', 'Bearer fake.token');

    $middleware = new AuthMiddleware();
    $response   = $middleware->handle($request, fn () => response()->json(['ok' => true]));

    expect($response->getStatusCode())->toBe(Response::HTTP_UNAUTHORIZED);

    $body = json_decode($response->getContent(), true);
    expect($body['success'])->toBeFalse();
    expect($body['message'])->toContain('expired');
});

it('session timeout response uses the standard JSON envelope format', function () {
    $tenant = Tenant::factory()->create([
        'status'   => 'active',
        'settings' => ['session_timeout_minutes' => 60],
    ]);

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
        'status'    => 'active',
    ]);

    $iatExpired = now()->subMinutes(61)->timestamp;

    $payload = Mockery::mock(\Tymon\JWTAuth\Payload::class);
    $payload->shouldReceive('get')->with('jti')->andReturn('test-jti-envelope');
    $payload->shouldReceive('get')->with('iat')->andReturn($iatExpired);
    $payload->shouldReceive('get')->with('tenant_id')->andReturn((string) $tenant->id);

    $tokenParser = Mockery::mock();
    $tokenParser->shouldReceive('getPayload')->andReturn($payload);
    $tokenParser->shouldReceive('authenticate')->andReturn($user);

    JWTAuth::shouldReceive('parseToken')->andReturn($tokenParser);

    Redis::shouldReceive('exists')
        ->with('jwt:blacklist:test-jti-envelope')
        ->andReturn(0);

    app()->instance('tenant', $tenant);

    $request = Request::create('/api/v1/auth/me', 'GET');
    $request->headers->set('Authorization', 'Bearer fake.token');

    $middleware = new AuthMiddleware();
    $response   = $middleware->handle($request, fn () => response()->json(['ok' => true]));

    $body = json_decode($response->getContent(), true);

    // Must conform to the standard API response envelope
    expect($body)->toHaveKeys(['success', 'data', 'message', 'errors', 'meta']);
    expect($body['success'])->toBeFalse();
    expect($body['data'])->toBeNull();
    expect($body['errors'])->toBeNull();
    expect($body['meta'])->toBeNull();
    expect($response->getStatusCode())->toBe(Response::HTTP_UNAUTHORIZED);
});
