<?php

use App\Jobs\SendPasswordResetEmailJob;
use App\Jobs\WriteAuditLogJob;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AuthService;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Redis;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * Unit tests for AuthService.
 *
 * Validates Requirements: 2.1, 2.5, 2.6, 2.9
 */

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    Bus::fake();
    // Mock Redis to avoid needing a real Redis connection in unit tests
    Redis::shouldReceive('exists')->andReturn(0)->byDefault();
    Redis::shouldReceive('setex')->andReturn(true)->byDefault();
    Redis::shouldReceive('get')->andReturn(null)->byDefault();
    Redis::shouldReceive('del')->andReturn(1)->byDefault();
    Redis::shouldReceive('ttl')->andReturn(1800)->byDefault();
    Redis::shouldReceive('keys')->andReturn([])->byDefault();
    Redis::shouldReceive('flushall')->andReturn(true)->byDefault();
});

// ---------------------------------------------------------------------------
// login() — successful authentication
// ---------------------------------------------------------------------------

it('returns token and user on successful login', function () {
    $tenant = Tenant::factory()->create(['status' => 'active']);
    app()->instance('tenant', $tenant);

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
        'email'     => 'staff@example.com',
        'password'  => Hash::make('secret123'),
        'status'    => 'active',
    ]);

    JWTAuth::shouldReceive('fromUser')
        ->once()
        ->with(Mockery::type(User::class))
        ->andReturn('mocked.jwt.token');

    $service = new AuthService();
    $result  = $service->login(
        email: 'staff@example.com',
        password: 'secret123',
        tenantId: $tenant->id,
        ipAddress: '127.0.0.1',
    );

    expect($result)->not->toBeNull();
    expect($result['token'])->toBe('mocked.jwt.token');
    expect($result['user']->id)->toBe($user->id);
});

it('dispatches LOGIN_SUCCESS audit log on successful login', function () {
    $tenant = Tenant::factory()->create(['status' => 'active']);
    app()->instance('tenant', $tenant);

    User::factory()->create([
        'tenant_id' => $tenant->id,
        'email'     => 'staff@example.com',
        'password'  => Hash::make('secret123'),
        'status'    => 'active',
    ]);

    JWTAuth::shouldReceive('fromUser')->andReturn('mocked.jwt.token');

    $service = new AuthService();
    $service->login(
        email: 'staff@example.com',
        password: 'secret123',
        tenantId: $tenant->id,
        ipAddress: '127.0.0.1',
    );

    Bus::assertDispatched(WriteAuditLogJob::class, function ($job) {
        $ref        = new ReflectionClass($job);
        $actionProp = $ref->getProperty('actionType');
        $actionProp->setAccessible(true);

        return $actionProp->getValue($job) === 'LOGIN_SUCCESS';
    });
});

it('resets failed_login_attempts to 0 on successful login', function () {
    $tenant = Tenant::factory()->create(['status' => 'active']);
    app()->instance('tenant', $tenant);

    $user = User::factory()->create([
        'tenant_id'             => $tenant->id,
        'email'                 => 'staff@example.com',
        'password'              => Hash::make('secret123'),
        'status'                => 'active',
        'failed_login_attempts' => 3,
    ]);

    JWTAuth::shouldReceive('fromUser')->andReturn('mocked.jwt.token');

    $service = new AuthService();
    $service->login(
        email: 'staff@example.com',
        password: 'secret123',
        tenantId: $tenant->id,
        ipAddress: '127.0.0.1',
    );

    expect($user->fresh()->failed_login_attempts)->toBe(0);
});

// ---------------------------------------------------------------------------
// login() — failure cases
// ---------------------------------------------------------------------------

it('returns null when user is not found', function () {
    $tenant = Tenant::factory()->create(['status' => 'active']);
    app()->instance('tenant', $tenant);

    $service = new AuthService();
    $result  = $service->login(
        email: 'nobody@example.com',
        password: 'secret123',
        tenantId: $tenant->id,
        ipAddress: '127.0.0.1',
    );

    expect($result)->toBeNull();
});

it('returns null when password is incorrect', function () {
    $tenant = Tenant::factory()->create(['status' => 'active']);
    app()->instance('tenant', $tenant);

    User::factory()->create([
        'tenant_id' => $tenant->id,
        'email'     => 'staff@example.com',
        'password'  => Hash::make('correct-password'),
        'status'    => 'active',
    ]);

    $service = new AuthService();
    $result  = $service->login(
        email: 'staff@example.com',
        password: 'wrong-password',
        tenantId: $tenant->id,
        ipAddress: '127.0.0.1',
    );

    expect($result)->toBeNull();
});

it('returns null when user account is locked', function () {
    $tenant = Tenant::factory()->create(['status' => 'active']);
    app()->instance('tenant', $tenant);

    User::factory()->create([
        'tenant_id' => $tenant->id,
        'email'     => 'locked@example.com',
        'password'  => Hash::make('secret123'),
        'status'    => 'locked',
    ]);

    $service = new AuthService();
    $result  = $service->login(
        email: 'locked@example.com',
        password: 'secret123',
        tenantId: $tenant->id,
        ipAddress: '127.0.0.1',
    );

    expect($result)->toBeNull();
});

it('returns null when user account is inactive', function () {
    $tenant = Tenant::factory()->create(['status' => 'active']);
    app()->instance('tenant', $tenant);

    User::factory()->create([
        'tenant_id' => $tenant->id,
        'email'     => 'inactive@example.com',
        'password'  => Hash::make('secret123'),
        'status'    => 'inactive',
    ]);

    $service = new AuthService();
    $result  = $service->login(
        email: 'inactive@example.com',
        password: 'secret123',
        tenantId: $tenant->id,
        ipAddress: '127.0.0.1',
    );

    expect($result)->toBeNull();
});

it('increments failed_login_attempts on wrong password', function () {
    $tenant = Tenant::factory()->create(['status' => 'active']);
    app()->instance('tenant', $tenant);

    $user = User::factory()->create([
        'tenant_id'             => $tenant->id,
        'email'                 => 'staff@example.com',
        'password'              => Hash::make('correct-password'),
        'status'                => 'active',
        'failed_login_attempts' => 0,
    ]);

    $service = new AuthService();
    $service->login(
        email: 'staff@example.com',
        password: 'wrong-password',
        tenantId: $tenant->id,
        ipAddress: '127.0.0.1',
    );

    expect($user->fresh()->failed_login_attempts)->toBe(1);
});

it('dispatches LOGIN_FAILED_INVALID_PASSWORD audit log on wrong password', function () {
    $tenant = Tenant::factory()->create(['status' => 'active']);
    app()->instance('tenant', $tenant);

    User::factory()->create([
        'tenant_id' => $tenant->id,
        'email'     => 'staff@example.com',
        'password'  => Hash::make('correct-password'),
        'status'    => 'active',
    ]);

    $service = new AuthService();
    $service->login(
        email: 'staff@example.com',
        password: 'wrong-password',
        tenantId: $tenant->id,
        ipAddress: '127.0.0.1',
    );

    Bus::assertDispatched(WriteAuditLogJob::class, function ($job) {
        $ref        = new ReflectionClass($job);
        $actionProp = $ref->getProperty('actionType');
        $actionProp->setAccessible(true);

        return $actionProp->getValue($job) === 'LOGIN_FAILED_INVALID_PASSWORD';
    });
});

it('locks account and dispatches password reset email when threshold is reached', function () {
    $tenant = Tenant::factory()->create([
        'status'   => 'active',
        'settings' => ['lockout_threshold' => 5],
    ]);
    app()->instance('tenant', $tenant);

    $user = User::factory()->create([
        'tenant_id'             => $tenant->id,
        'email'                 => 'staff@example.com',
        'password'              => Hash::make('correct-password'),
        'status'                => 'active',
        'failed_login_attempts' => 4, // one more will hit threshold
    ]);

    // Allow setex for the reset token storage
    Redis::shouldReceive('setex')->once()->andReturn(true);

    $service = new AuthService();
    $service->login(
        email: 'staff@example.com',
        password: 'wrong-password',
        tenantId: $tenant->id,
        ipAddress: '127.0.0.1',
    );

    expect($user->fresh()->status)->toBe('locked');
    expect($user->fresh()->failed_login_attempts)->toBe(5);

    Bus::assertDispatched(SendPasswordResetEmailJob::class);
    Bus::assertDispatched(WriteAuditLogJob::class, function ($job) {
        $ref        = new ReflectionClass($job);
        $actionProp = $ref->getProperty('actionType');
        $actionProp->setAccessible(true);

        return $actionProp->getValue($job) === 'ACCOUNT_LOCKED';
    });
});

it('does not issue token for user belonging to a different tenant', function () {
    $tenantA = Tenant::factory()->create(['status' => 'active']);
    $tenantB = Tenant::factory()->create(['status' => 'active']);
    app()->instance('tenant', $tenantA);

    // User belongs to tenant B
    User::factory()->create([
        'tenant_id' => $tenantB->id,
        'email'     => 'staff@example.com',
        'password'  => Hash::make('secret123'),
        'status'    => 'active',
    ]);

    $service = new AuthService();
    $result  = $service->login(
        email: 'staff@example.com',
        password: 'secret123',
        tenantId: $tenantA->id, // querying in tenant A's scope
        ipAddress: '127.0.0.1',
    );

    expect($result)->toBeNull();
});

// ---------------------------------------------------------------------------
// logout() — Redis blacklist via jti (Requirements: 2.9)
// ---------------------------------------------------------------------------

it('stores jti in Redis blacklist on logout with correct TTL', function () {
    $jti    = 'test-jti-' . uniqid();
    $exp    = now()->addMinutes(30)->timestamp;
    $userId = (string) \Illuminate\Support\Str::uuid();

    $payload = Mockery::mock(\Tymon\JWTAuth\Payload::class);
    $payload->shouldReceive('get')->with('jti')->andReturn($jti);
    $payload->shouldReceive('get')->with('exp')->andReturn($exp);
    $payload->shouldReceive('get')->with('user_id')->andReturn($userId);
    $payload->shouldReceive('get')->with('tenant_id')->andReturn(null);
    $payload->shouldReceive('get')->with('role')->andReturn('Department_Staff');

    $tokenParser = Mockery::mock();
    $tokenParser->shouldReceive('getPayload')->andReturn($payload);
    $tokenParser->shouldReceive('invalidate')->andReturn(true);

    JWTAuth::shouldReceive('parseToken')->andReturn($tokenParser);

    // Expect Redis::setex to be called with the blacklist key and a positive TTL
    Redis::shouldReceive('setex')
        ->once()
        ->withArgs(function ($key, $ttl, $value) use ($jti) {
            return $key === 'jwt:blacklist:' . $jti
                && $ttl > 0
                && $ttl <= 30 * 60
                && $value === '1';
        })
        ->andReturn(true);

    $service = new AuthService();
    $service->logout(ipAddress: '127.0.0.1');
});

it('dispatches LOGOUT audit log on logout', function () {
    $jti    = 'test-jti-' . uniqid();
    $exp    = now()->addMinutes(30)->timestamp;
    $userId = (string) \Illuminate\Support\Str::uuid();

    $payload = Mockery::mock(\Tymon\JWTAuth\Payload::class);
    $payload->shouldReceive('get')->with('jti')->andReturn($jti);
    $payload->shouldReceive('get')->with('exp')->andReturn($exp);
    $payload->shouldReceive('get')->with('user_id')->andReturn($userId);
    $payload->shouldReceive('get')->with('tenant_id')->andReturn(null);
    $payload->shouldReceive('get')->with('role')->andReturn('Department_Staff');

    $tokenParser = Mockery::mock();
    $tokenParser->shouldReceive('getPayload')->andReturn($payload);
    $tokenParser->shouldReceive('invalidate')->andReturn(true);

    JWTAuth::shouldReceive('parseToken')->andReturn($tokenParser);

    $service = new AuthService();
    $service->logout(ipAddress: '127.0.0.1');

    Bus::assertDispatched(WriteAuditLogJob::class, function ($job) {
        $ref        = new ReflectionClass($job);
        $actionProp = $ref->getProperty('actionType');
        $actionProp->setAccessible(true);

        return $actionProp->getValue($job) === 'LOGOUT';
    });
});

it('does not store expired jti in Redis blacklist', function () {
    $jti = 'expired-jti-' . uniqid();
    $exp = now()->subMinutes(5)->timestamp; // already expired

    $payload = Mockery::mock(\Tymon\JWTAuth\Payload::class);
    $payload->shouldReceive('get')->with('jti')->andReturn($jti);
    $payload->shouldReceive('get')->with('exp')->andReturn($exp);
    $payload->shouldReceive('get')->with('user_id')->andReturn(null);
    $payload->shouldReceive('get')->with('tenant_id')->andReturn(null);
    $payload->shouldReceive('get')->with('role')->andReturn(null);

    $tokenParser = Mockery::mock();
    $tokenParser->shouldReceive('getPayload')->andReturn($payload);
    $tokenParser->shouldReceive('invalidate')->andReturn(true);

    JWTAuth::shouldReceive('parseToken')->andReturn($tokenParser);

    // setex should NOT be called for an already-expired token
    Redis::shouldReceive('setex')->never();

    $service = new AuthService();
    $service->logout(ipAddress: '127.0.0.1');
});

// ---------------------------------------------------------------------------
// isBlacklisted()
// ---------------------------------------------------------------------------

it('returns true when jti is in Redis blacklist', function () {
    $jti = 'blacklisted-jti-' . uniqid();

    Redis::shouldReceive('exists')
        ->once()
        ->with('jwt:blacklist:' . $jti)
        ->andReturn(1);

    $service = new AuthService();
    expect($service->isBlacklisted($jti))->toBeTrue();
});

it('returns false when jti is not in Redis blacklist', function () {
    Redis::shouldReceive('exists')
        ->once()
        ->with('jwt:blacklist:non-existent-jti')
        ->andReturn(0);

    $service = new AuthService();
    expect($service->isBlacklisted('non-existent-jti'))->toBeFalse();
});

// ---------------------------------------------------------------------------
// requestPasswordReset() — Requirements: 2.5
// ---------------------------------------------------------------------------

it('stores reset token in Redis with 60-minute TTL and dispatches email job', function () {
    $tenant = Tenant::factory()->create(['status' => 'active']);
    app()->instance('tenant', $tenant);

    User::factory()->create([
        'tenant_id' => $tenant->id,
        'email'     => 'staff@example.com',
        'status'    => 'active',
    ]);

    // Expect Redis::setex to be called with a 3600-second TTL
    Redis::shouldReceive('setex')
        ->once()
        ->withArgs(function ($key, $ttl, $value) {
            return str_starts_with($key, 'pwd:reset:')
                && $ttl === 3600
                && is_string($value);
        })
        ->andReturn(true);

    $service = new AuthService();
    $result  = $service->requestPasswordReset(
        email: 'staff@example.com',
        tenantId: $tenant->id,
        ipAddress: '127.0.0.1',
    );

    expect($result)->toBeTrue();
    Bus::assertDispatched(SendPasswordResetEmailJob::class);
});

it('returns true without dispatching email when user is not found (prevents enumeration)', function () {
    $tenant = Tenant::factory()->create(['status' => 'active']);
    app()->instance('tenant', $tenant);

    // setex should NOT be called when user doesn't exist
    Redis::shouldReceive('setex')->never();

    $service = new AuthService();
    $result  = $service->requestPasswordReset(
        email: 'nobody@example.com',
        tenantId: $tenant->id,
        ipAddress: '127.0.0.1',
    );

    expect($result)->toBeTrue();
    Bus::assertNotDispatched(SendPasswordResetEmailJob::class);
});

it('dispatches PASSWORD_RESET_REQUESTED audit log', function () {
    $tenant = Tenant::factory()->create(['status' => 'active']);
    app()->instance('tenant', $tenant);

    User::factory()->create([
        'tenant_id' => $tenant->id,
        'email'     => 'staff@example.com',
        'status'    => 'active',
    ]);

    $service = new AuthService();
    $service->requestPasswordReset(
        email: 'staff@example.com',
        tenantId: $tenant->id,
        ipAddress: '127.0.0.1',
    );

    Bus::assertDispatched(WriteAuditLogJob::class, function ($job) {
        $ref        = new ReflectionClass($job);
        $actionProp = $ref->getProperty('actionType');
        $actionProp->setAccessible(true);

        return $actionProp->getValue($job) === 'PASSWORD_RESET_REQUESTED';
    });
});

// ---------------------------------------------------------------------------
// resetPassword() — Requirements: 2.5, 2.6
// ---------------------------------------------------------------------------

it('resets password successfully with valid token', function () {
    $tenant = Tenant::factory()->create(['status' => 'active']);
    app()->instance('tenant', $tenant);

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
        'email'     => 'staff@example.com',
        'password'  => Hash::make('old-password'),
        'status'    => 'active',
    ]);

    $token    = \Illuminate\Support\Str::random(64);
    $redisKey = 'pwd:reset:' . $token;

    Redis::shouldReceive('get')
        ->once()
        ->with($redisKey)
        ->andReturn(json_encode([
            'user_id'   => $user->id,
            'tenant_id' => $tenant->id,
            'email'     => $user->email,
        ]));

    Redis::shouldReceive('del')->once()->with($redisKey)->andReturn(1);

    $service = new AuthService();
    $result  = $service->resetPassword(
        token: $token,
        newPassword: 'new-secure-password',
        ipAddress: '127.0.0.1',
    );

    expect($result)->toBeTrue();
    expect(Hash::check('new-secure-password', $user->fresh()->password))->toBeTrue();
});

it('returns false when reset token is invalid or expired', function () {
    $token    = \Illuminate\Support\Str::random(64);
    $redisKey = 'pwd:reset:' . $token;

    Redis::shouldReceive('get')
        ->once()
        ->with($redisKey)
        ->andReturn(null); // token not found

    $service = new AuthService();
    $result  = $service->resetPassword(
        token: $token,
        newPassword: 'new-password',
        ipAddress: '127.0.0.1',
    );

    expect($result)->toBeFalse();
});

it('unlocks a locked account when password is reset', function () {
    $tenant = Tenant::factory()->create(['status' => 'active']);
    app()->instance('tenant', $tenant);

    $user = User::factory()->create([
        'tenant_id'             => $tenant->id,
        'email'                 => 'locked@example.com',
        'password'              => Hash::make('old-password'),
        'status'                => 'locked',
        'failed_login_attempts' => 5,
    ]);

    $token    = \Illuminate\Support\Str::random(64);
    $redisKey = 'pwd:reset:' . $token;

    Redis::shouldReceive('get')
        ->once()
        ->with($redisKey)
        ->andReturn(json_encode([
            'user_id'   => $user->id,
            'tenant_id' => $tenant->id,
            'email'     => $user->email,
        ]));

    Redis::shouldReceive('del')->once()->with($redisKey)->andReturn(1);

    $service = new AuthService();
    $service->resetPassword(token: $token, newPassword: 'new-password', ipAddress: '127.0.0.1');

    $fresh = $user->fresh();
    expect($fresh->status)->toBe('active');
    expect($fresh->failed_login_attempts)->toBe(0);
});

it('dispatches PASSWORD_RESET_COMPLETED audit log on successful reset', function () {
    $tenant = Tenant::factory()->create(['status' => 'active']);
    app()->instance('tenant', $tenant);

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
        'email'     => 'staff@example.com',
        'password'  => Hash::make('old-password'),
        'status'    => 'active',
    ]);

    $token    = \Illuminate\Support\Str::random(64);
    $redisKey = 'pwd:reset:' . $token;

    Redis::shouldReceive('get')
        ->once()
        ->with($redisKey)
        ->andReturn(json_encode([
            'user_id'   => $user->id,
            'tenant_id' => $tenant->id,
            'email'     => $user->email,
        ]));

    Redis::shouldReceive('del')->once()->with($redisKey)->andReturn(1);

    $service = new AuthService();
    $service->resetPassword(token: $token, newPassword: 'new-password', ipAddress: '127.0.0.1');

    Bus::assertDispatched(WriteAuditLogJob::class, function ($job) {
        $ref        = new ReflectionClass($job);
        $actionProp = $ref->getProperty('actionType');
        $actionProp->setAccessible(true);

        return $actionProp->getValue($job) === 'PASSWORD_RESET_COMPLETED';
    });
});

// ---------------------------------------------------------------------------
// JWT payload claims — Requirements: 2.1
// ---------------------------------------------------------------------------

it('JWT custom claims include user_id, tenant_id, role, and permissions', function () {
    $tenant = Tenant::factory()->create(['status' => 'active']);
    app()->instance('tenant', $tenant);

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
        'email'     => 'staff@example.com',
        'password'  => Hash::make('secret123'),
        'status'    => 'active',
    ]);

    $claims = $user->getJWTCustomClaims();

    expect($claims)->toHaveKey('user_id');
    expect($claims)->toHaveKey('tenant_id');
    expect($claims)->toHaveKey('role');
    expect($claims)->toHaveKey('permissions');
    expect($claims['user_id'])->toBe($user->id);
    expect($claims['tenant_id'])->toBe($tenant->id);
    expect($claims['permissions'])->toBeArray();
});
