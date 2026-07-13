<?php

use App\Http\Controllers\Api\V1\HealthController;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

/**
 * Feature tests for GET /api/health — Requirement 20.10
 *
 * The health check endpoint must:
 *   - Be accessible without authentication or a tenant header
 *   - Always return { status, services: { database, redis, queue }, timestamp }
 *   - Return HTTP 200 when all services are healthy
 *   - Return HTTP 503 when any service is degraded
 */
describe('GET /api/health (HTTP integration)', function () {

    it('is reachable without authentication or tenant header', function () {
        // The endpoint MUST respond without 401 / 403, regardless of service health.
        $response = $this->withHeaders([])->getJson('/api/health');

        expect($response->status())->not->toBe(401);
        expect($response->status())->not->toBe(403);
    });

    it('always returns the required JSON envelope shape', function () {
        $response = $this->getJson('/api/health');

        // Whatever the actual service health is, the shape must be correct.
        $response->assertJsonStructure([
            'status',
            'services' => ['database', 'redis', 'queue'],
            'timestamp',
        ]);
    });

    it('status is either "healthy" or "degraded"', function () {
        $response = $this->getJson('/api/health');

        expect($response->json('status'))->toBeIn(['healthy', 'degraded']);
    });

    it('HTTP status code matches logical status', function () {
        $response = $this->getJson('/api/health');

        $status = $response->json('status');
        if ($status === 'healthy') {
            $response->assertStatus(200);
        } else {
            $response->assertStatus(503);
        }
    });

    it('each service value is "ok", "error", or "degraded"', function () {
        $response = $this->getJson('/api/health');

        $allowed  = ['ok', 'error', 'degraded'];
        $services = $response->json('services');

        expect($services)->toBeArray();
        foreach ($services as $name => $value) {
            expect($value)->toBeIn($allowed, "Service '{$name}' has unexpected value '{$value}'");
        }
    });

    it('timestamp is an ISO 8601 string', function () {
        $response = $this->getJson('/api/health');

        $timestamp = $response->json('timestamp');
        expect($timestamp)->toBeString();
        // ISO 8601 date-time guard
        expect($timestamp)->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/');
    });
});

/**
 * Unit tests for HealthController internal logic.
 * These bypass HTTP routing so we can safely mock DB and Cache
 * without the ThrottleRequests/JWT middleware touching them.
 */
describe('HealthController (unit)', function () {

    it('returns 200 and healthy status when database and Redis respond correctly', function () {
        DB::shouldReceive('connection')->once()->andReturnSelf();
        DB::shouldReceive('getPdo')->once()->andReturn(new \stdClass());
        DB::shouldReceive('select')->once()->with('SELECT 1')->andReturn([]);
        DB::shouldReceive('table')->with('failed_jobs')->once()->andReturnSelf();
        DB::shouldReceive('where')->once()->andReturnSelf();
        DB::shouldReceive('count')->once()->andReturn(0);

        $cacheStore = Mockery::mock();
        $cacheStore->shouldReceive('put')->once()->andReturn(true);
        $cacheStore->shouldReceive('get')->once()->andReturn(true);
        Cache::shouldReceive('store')->with('redis')->twice()->andReturn($cacheStore);

        Redis::shouldReceive('connection')->times(3)->andReturnSelf();
        Redis::shouldReceive('llen')->times(3)->andReturn(0);

        $controller = new HealthController();
        /** @var JsonResponse $response */
        $response = $controller->health();
        $data     = $response->getData(true);

        expect($data['status'])->toBe('healthy');
        expect($data['services']['database'])->toBe('ok');
        expect($data['services']['redis'])->toBe('ok');
        expect($data['services']['queue'])->toBe('ok');
        expect($response->getStatusCode())->toBe(200);
    });

    it('returns 503 and degraded when the database throws', function () {
        DB::shouldReceive('connection')->once()->andThrow(new \RuntimeException('DB down'));

        $cacheStore = Mockery::mock();
        $cacheStore->shouldReceive('put')->once()->andReturn(true);
        $cacheStore->shouldReceive('get')->once()->andReturn(true);
        Cache::shouldReceive('store')->with('redis')->twice()->andReturn($cacheStore);

        DB::shouldReceive('table')->with('failed_jobs')->once()->andReturnSelf();
        DB::shouldReceive('where')->once()->andReturnSelf();
        DB::shouldReceive('count')->once()->andReturn(0);

        Redis::shouldReceive('connection')->times(3)->andReturnSelf();
        Redis::shouldReceive('llen')->times(3)->andReturn(0);

        $controller = new HealthController();
        $response   = $controller->health();
        $data       = $response->getData(true);

        expect($data['status'])->toBe('degraded');
        expect($data['services']['database'])->toBe('error');
        expect($response->getStatusCode())->toBe(503);
    });

    it('returns 503 and degraded when Redis throws on put', function () {
        DB::shouldReceive('connection')->once()->andReturnSelf();
        DB::shouldReceive('getPdo')->once()->andReturn(new \stdClass());
        DB::shouldReceive('select')->once()->with('SELECT 1')->andReturn([]);
        DB::shouldReceive('table')->with('failed_jobs')->once()->andReturnSelf();
        DB::shouldReceive('where')->once()->andReturnSelf();
        DB::shouldReceive('count')->once()->andReturn(0);

        $cacheStore = Mockery::mock();
        $cacheStore->shouldReceive('put')->once()->andThrow(new \RuntimeException('Redis down'));
        Cache::shouldReceive('store')->with('redis')->once()->andReturn($cacheStore);

        Redis::shouldReceive('connection')->times(3)->andReturnSelf();
        Redis::shouldReceive('llen')->times(3)->andReturn(0);

        $controller = new HealthController();
        $response   = $controller->health();
        $data       = $response->getData(true);

        expect($data['status'])->toBe('degraded');
        expect($data['services']['redis'])->toBe('error');
        expect($response->getStatusCode())->toBe(503);
    });

    it('returns 503 with error queue status when failed job count exceeds threshold', function () {
        DB::shouldReceive('connection')->once()->andReturnSelf();
        DB::shouldReceive('getPdo')->once()->andReturn(new \stdClass());
        DB::shouldReceive('select')->once()->with('SELECT 1')->andReturn([]);

        $cacheStore = Mockery::mock();
        $cacheStore->shouldReceive('put')->once()->andReturn(true);
        $cacheStore->shouldReceive('get')->once()->andReturn(true);
        Cache::shouldReceive('store')->with('redis')->twice()->andReturn($cacheStore);

        // 11 failures in 5 minutes — exceeds the threshold of 10
        DB::shouldReceive('table')->with('failed_jobs')->once()->andReturnSelf();
        DB::shouldReceive('where')->once()->andReturnSelf();
        DB::shouldReceive('count')->once()->andReturn(11);

        $controller = new HealthController();
        $response   = $controller->health();
        $data       = $response->getData(true);

        expect($data['status'])->toBe('degraded');
        expect($data['services']['queue'])->toBe('error');
        expect($response->getStatusCode())->toBe(503);
    });

    it('returns degraded with "degraded" queue status when queue backlog is too large', function () {
        DB::shouldReceive('connection')->once()->andReturnSelf();
        DB::shouldReceive('getPdo')->once()->andReturn(new \stdClass());
        DB::shouldReceive('select')->once()->with('SELECT 1')->andReturn([]);

        $cacheStore = Mockery::mock();
        $cacheStore->shouldReceive('put')->once()->andReturn(true);
        $cacheStore->shouldReceive('get')->once()->andReturn(true);
        Cache::shouldReceive('store')->with('redis')->twice()->andReturn($cacheStore);

        DB::shouldReceive('table')->with('failed_jobs')->once()->andReturnSelf();
        DB::shouldReceive('where')->once()->andReturnSelf();
        DB::shouldReceive('count')->once()->andReturn(0);

        // Combined queue depth of 1001 exceeds the 1000-item threshold
        Redis::shouldReceive('connection')->times(3)->andReturnSelf();
        Redis::shouldReceive('llen')->times(3)->andReturn(334);  // 334 × 3 = 1002

        $controller = new HealthController();
        $response   = $controller->health();
        $data       = $response->getData(true);

        expect($data['status'])->toBe('degraded');
        expect($data['services']['queue'])->toBe('degraded');
        expect($response->getStatusCode())->toBe(503);
    });
});
