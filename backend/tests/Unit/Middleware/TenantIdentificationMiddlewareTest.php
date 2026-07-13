<?php

use App\Http\Middleware\TenantIdentificationMiddleware;
use App\Jobs\WriteAuditLogJob;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Unit tests for TenantIdentificationMiddleware.
 *
 * Validates Requirements: 1.1, 1.3, 1.5
 */

beforeEach(function () {
    Bus::fake();
    Cache::flush();
});

// ---------------------------------------------------------------------------
// Resolution via X-Tenant-ID header
// ---------------------------------------------------------------------------

it('resolves tenant from X-Tenant-ID header and sets app context', function () {
    $tenant = Tenant::factory()->create(['status' => Tenant::STATUS_ACTIVE]);

    $request = Request::create('/api/v1/test', 'GET');
    $request->headers->set('X-Tenant-ID', $tenant->id);

    $middleware = new TenantIdentificationMiddleware();
    $response = $middleware->handle($request, fn () => response()->json(['ok' => true]));

    expect($response->getStatusCode())->toBe(Response::HTTP_OK);
    expect(app('tenant'))->not->toBeNull();
    expect(app('tenant')->id)->toBe($tenant->id);
});

it('resolves tenant from X-Tenant-ID header using subdomain slug', function () {
    $tenant = Tenant::factory()->create([
        'subdomain' => 'acme',
        'status'    => Tenant::STATUS_ACTIVE,
    ]);

    $request = Request::create('/api/v1/test', 'GET');
    $request->headers->set('X-Tenant-ID', 'acme');

    $middleware = new TenantIdentificationMiddleware();
    $response = $middleware->handle($request, fn () => response()->json(['ok' => true]));

    expect($response->getStatusCode())->toBe(Response::HTTP_OK);
    expect(app('tenant')->subdomain)->toBe('acme');
});

// ---------------------------------------------------------------------------
// Resolution via subdomain
// ---------------------------------------------------------------------------

it('resolves tenant from subdomain', function () {
    $tenant = Tenant::factory()->create([
        'subdomain' => 'globex',
        'status'    => Tenant::STATUS_ACTIVE,
    ]);

    // Simulate request from globex.platform.com
    $request = Request::create('http://globex.platform.com/api/v1/test', 'GET');

    $middleware = new TenantIdentificationMiddleware();
    $response = $middleware->handle($request, fn () => response()->json(['ok' => true]));

    expect($response->getStatusCode())->toBe(Response::HTTP_OK);
    expect(app('tenant')->subdomain)->toBe('globex');
});

it('does not resolve tenant from www subdomain', function () {
    // www.platform.com should not be treated as a tenant subdomain
    $request = Request::create('http://www.platform.com/api/v1/test', 'GET');

    $middleware = new TenantIdentificationMiddleware();
    $response = $middleware->handle($request, fn () => response()->json(['ok' => true]));

    expect($response->getStatusCode())->toBe(Response::HTTP_UNAUTHORIZED);
});

// ---------------------------------------------------------------------------
// Resolution via JWT claim
// ---------------------------------------------------------------------------

it('resolves tenant from JWT tenant_id claim', function () {
    $tenant = Tenant::factory()->create(['status' => Tenant::STATUS_ACTIVE]);

    // Mock JWTAuth to return the tenant ID from the payload
    $payload = Mockery::mock(\Tymon\JWTAuth\Payload::class);
    $payload->shouldReceive('get')->with('tenant_id')->andReturn($tenant->id);

    $token = Mockery::mock(\Tymon\JWTAuth\JWT::class);
    $token->shouldReceive('getPayload')->andReturn($payload);

    \Tymon\JWTAuth\Facades\JWTAuth::shouldReceive('parseToken')->andReturn($token);

    $request = Request::create('/api/v1/test', 'GET');
    $request->headers->set('Authorization', 'Bearer fake.jwt.token');

    $middleware = new TenantIdentificationMiddleware();
    $response = $middleware->handle($request, fn () => response()->json(['ok' => true]));

    expect($response->getStatusCode())->toBe(Response::HTTP_OK);
    expect(app('tenant')->id)->toBe($tenant->id);
});

// ---------------------------------------------------------------------------
// Rejection cases — HTTP 401 + audit log dispatch
// ---------------------------------------------------------------------------

it('returns HTTP 401 when no tenant identifier is present', function () {
    // Plain request with no header, no subdomain, no JWT
    $request = Request::create('http://platform.com/api/v1/test', 'GET');

    $middleware = new TenantIdentificationMiddleware();
    $response = $middleware->handle($request, fn () => response()->json(['ok' => true]));

    expect($response->getStatusCode())->toBe(Response::HTTP_UNAUTHORIZED);

    $body = json_decode($response->getContent(), true);
    expect($body['success'])->toBeFalse();
    expect($body['message'])->toBe('Tenant identifier is required.');
});

it('dispatches audit log when no tenant identifier is present', function () {
    $request = Request::create('http://platform.com/api/v1/test', 'GET');

    $middleware = new TenantIdentificationMiddleware();
    $middleware->handle($request, fn () => response()->json(['ok' => true]));

    Bus::assertDispatched(WriteAuditLogJob::class, function ($job) {
        // Inspect via reflection since properties are private readonly
        $ref = new ReflectionClass($job);
        $actionProp = $ref->getProperty('actionType');
        $actionProp->setAccessible(true);

        return $actionProp->getValue($job) === 'TENANT_RESOLUTION_FAILED';
    });
});

it('returns HTTP 401 when tenant is not found in database', function () {
    $request = Request::create('/api/v1/test', 'GET');
    $request->headers->set('X-Tenant-ID', 'non-existent-tenant-id');

    $middleware = new TenantIdentificationMiddleware();
    $response = $middleware->handle($request, fn () => response()->json(['ok' => true]));

    expect($response->getStatusCode())->toBe(Response::HTTP_UNAUTHORIZED);

    $body = json_decode($response->getContent(), true);
    expect($body['success'])->toBeFalse();
    expect($body['message'])->toBe('Tenant not found.');
});

it('dispatches audit log when tenant is not found', function () {
    $request = Request::create('/api/v1/test', 'GET');
    $request->headers->set('X-Tenant-ID', 'ghost-tenant');

    $middleware = new TenantIdentificationMiddleware();
    $middleware->handle($request, fn () => response()->json(['ok' => true]));

    Bus::assertDispatched(WriteAuditLogJob::class, function ($job) {
        $ref = new ReflectionClass($job);
        $actionProp = $ref->getProperty('actionType');
        $actionProp->setAccessible(true);

        return $actionProp->getValue($job) === 'TENANT_NOT_FOUND';
    });
});

it('returns HTTP 401 when tenant is suspended', function () {
    $tenant = Tenant::factory()->create(['status' => Tenant::STATUS_SUSPENDED]);

    $request = Request::create('/api/v1/test', 'GET');
    $request->headers->set('X-Tenant-ID', $tenant->id);

    $middleware = new TenantIdentificationMiddleware();
    $response = $middleware->handle($request, fn () => response()->json(['ok' => true]));

    expect($response->getStatusCode())->toBe(Response::HTTP_UNAUTHORIZED);

    $body = json_decode($response->getContent(), true);
    expect($body['success'])->toBeFalse();
    expect($body['message'])->toBe('Tenant account is suspended.');
});

it('dispatches audit log when tenant is suspended', function () {
    $tenant = Tenant::factory()->create(['status' => Tenant::STATUS_SUSPENDED]);

    $request = Request::create('/api/v1/test', 'GET');
    $request->headers->set('X-Tenant-ID', $tenant->id);

    $middleware = new TenantIdentificationMiddleware();
    $middleware->handle($request, fn () => response()->json(['ok' => true]));

    Bus::assertDispatched(WriteAuditLogJob::class, function ($job) {
        $ref = new ReflectionClass($job);
        $actionProp = $ref->getProperty('actionType');
        $actionProp->setAccessible(true);

        return $actionProp->getValue($job) === 'TENANT_ACCESS_DENIED_SUSPENDED';
    });
});

it('returns HTTP 401 when tenant is deactivated', function () {
    $tenant = Tenant::factory()->create(['status' => Tenant::STATUS_DEACTIVATED]);

    $request = Request::create('/api/v1/test', 'GET');
    $request->headers->set('X-Tenant-ID', $tenant->id);

    $middleware = new TenantIdentificationMiddleware();
    $response = $middleware->handle($request, fn () => response()->json(['ok' => true]));

    expect($response->getStatusCode())->toBe(Response::HTTP_UNAUTHORIZED);

    $body = json_decode($response->getContent(), true);
    expect($body['success'])->toBeFalse();
    expect($body['message'])->toBe('Tenant account has been deactivated.');
});

it('dispatches audit log when tenant is deactivated', function () {
    $tenant = Tenant::factory()->create(['status' => Tenant::STATUS_DEACTIVATED]);

    $request = Request::create('/api/v1/test', 'GET');
    $request->headers->set('X-Tenant-ID', $tenant->id);

    $middleware = new TenantIdentificationMiddleware();
    $middleware->handle($request, fn () => response()->json(['ok' => true]));

    Bus::assertDispatched(WriteAuditLogJob::class, function ($job) {
        $ref = new ReflectionClass($job);
        $actionProp = $ref->getProperty('actionType');
        $actionProp->setAccessible(true);

        return $actionProp->getValue($job) === 'TENANT_ACCESS_DENIED_DEACTIVATED';
    });
});

// ---------------------------------------------------------------------------
// Redis caching
// ---------------------------------------------------------------------------

it('caches resolved tenant in Redis for 60 seconds', function () {
    $tenant = Tenant::factory()->create(['status' => Tenant::STATUS_ACTIVE]);

    $request = Request::create('/api/v1/test', 'GET');
    $request->headers->set('X-Tenant-ID', $tenant->id);

    $middleware = new TenantIdentificationMiddleware();

    // First call — populates cache
    $middleware->handle($request, fn () => response()->json(['ok' => true]));

    $cacheKey = "tenant:{$tenant->id}:config";
    expect(Cache::has($cacheKey))->toBeTrue();

    $cached = Cache::get($cacheKey);
    expect($cached)->not->toBeNull();
    expect($cached->id)->toBe($tenant->id);
});

it('serves tenant from cache on subsequent requests without hitting the database', function () {
    $tenant = Tenant::factory()->create(['status' => Tenant::STATUS_ACTIVE]);
    $cacheKey = "tenant:{$tenant->id}:config";

    // Pre-populate cache
    Cache::put($cacheKey, $tenant, 60);

    // Delete the tenant from DB to prove cache is used
    $tenant->forceDelete();

    $request = Request::create('/api/v1/test', 'GET');
    $request->headers->set('X-Tenant-ID', $tenant->id);

    $middleware = new TenantIdentificationMiddleware();
    $response = $middleware->handle($request, fn () => response()->json(['ok' => true]));

    // Should succeed because tenant was served from cache
    expect($response->getStatusCode())->toBe(Response::HTTP_OK);
});

// ---------------------------------------------------------------------------
// Response envelope format
// ---------------------------------------------------------------------------

it('returns standard JSON envelope on 401 responses', function () {
    $request = Request::create('http://platform.com/api/v1/test', 'GET');

    $middleware = new TenantIdentificationMiddleware();
    $response = $middleware->handle($request, fn () => response()->json(['ok' => true]));

    $body = json_decode($response->getContent(), true);

    expect($body)->toHaveKeys(['success', 'data', 'message', 'errors', 'meta']);
    expect($body['success'])->toBeFalse();
    expect($body['data'])->toBeNull();
    expect($body['errors'])->toBeNull();
    expect($body['meta'])->toBeNull();
});

// ---------------------------------------------------------------------------
// Resolution priority order
// ---------------------------------------------------------------------------

it('prefers X-Tenant-ID header over subdomain', function () {
    $tenantA = Tenant::factory()->create(['subdomain' => 'tenant-a', 'status' => Tenant::STATUS_ACTIVE]);
    $tenantB = Tenant::factory()->create(['subdomain' => 'tenant-b', 'status' => Tenant::STATUS_ACTIVE]);

    // Request comes from tenant-a subdomain but header says tenant-b
    $request = Request::create('http://tenant-a.platform.com/api/v1/test', 'GET');
    $request->headers->set('X-Tenant-ID', $tenantB->id);

    $middleware = new TenantIdentificationMiddleware();
    $middleware->handle($request, fn () => response()->json(['ok' => true]));

    // Header takes priority — should resolve to tenant-b
    expect(app('tenant')->id)->toBe($tenantB->id);
});
