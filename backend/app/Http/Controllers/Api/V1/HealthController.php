<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * @OA\Tag(name="Health", description="Platform health check endpoint.")
 */
class HealthController extends Controller
{
    /**
     * @OA\Get(
     *     path="/health",
     *     operationId="healthCheck",
     *     tags={"Health"},
     *     summary="Platform health check",
     *     description="Returns the health status of database, Redis, and queue worker. No authentication or tenant context required.",
     *     @OA\Parameter(ref="#/components/parameters/XRequestID"),
     *     @OA\Response(
     *         response=200,
     *         description="All services healthy.",
     *         @OA\JsonContent(
     *             @OA\Property(property="status", type="string", example="healthy"),
     *             @OA\Property(property="timestamp", type="string", format="date-time", example="2025-01-15T10:30:00+00:00"),
     *             @OA\Property(property="services", type="object",
     *                 @OA\Property(property="database", type="string", example="ok"),
     *                 @OA\Property(property="redis",    type="string", example="ok"),
     *                 @OA\Property(property="queue",    type="string", example="ok")
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=503,
     *         description="One or more services degraded.",
     *         @OA\JsonContent(
     *             @OA\Property(property="status", type="string", example="degraded"),
     *             @OA\Property(property="timestamp", type="string", format="date-time"),
     *             @OA\Property(property="services", type="object",
     *                 @OA\Property(property="database", type="string", example="ok"),
     *                 @OA\Property(property="redis",    type="string", example="error"),
     *                 @OA\Property(property="queue",    type="string", example="ok")
     *             )
     *         )
     *     )
     * )
     *
     * GET /api/health
     *
     * Returns the health status of all critical services.
     * HTTP 200 when all healthy, HTTP 503 when any service is degraded.
     *
     * Response format:
     * {
     *   "status":    "healthy" | "degraded",
     *   "services":  { "database": "ok"|"error", "redis": "ok"|"error", "queue": "ok"|"error" },
     *   "timestamp": "<ISO 8601>"
     * }
     */
    public function health(): JsonResponse
    {
        $database = $this->checkDatabase();
        $redis    = $this->checkRedis();
        $queue    = $this->checkQueueWorker();

        $services = [
            'database' => $database['status'],
            'redis'    => $redis['status'],
            'queue'    => $queue['status'],
        ];

        $allHealthy = ! in_array('error', $services, true)
                   && ! in_array('degraded', $services, true);

        return response()->json([
            'status'    => $allHealthy ? 'healthy' : 'degraded',
            'services'  => $services,
            'timestamp' => now()->toIso8601String(),
        ], $allHealthy ? 200 : 503);
    }

    /**
     * Verify the database is reachable by obtaining a PDO handle and running a
     * trivial query.
     *
     * @return array{status: string, message: string}
     */
    private function checkDatabase(): array
    {
        try {
            DB::connection()->getPdo();
            DB::select('SELECT 1');

            return ['status' => 'ok', 'message' => 'Database connection is healthy.'];
        } catch (Throwable $e) {
            return ['status' => 'error', 'message' => 'Database connection failed: '.$e->getMessage()];
        }
    }

    /**
     * Verify Redis is reachable by performing a round-trip write/read with a
     * short TTL key.
     *
     * @return array{status: string, message: string}
     */
    private function checkRedis(): array
    {
        try {
            Cache::store('redis')->put('_health_check', true, 10);
            $value = Cache::store('redis')->get('_health_check');

            if ($value !== true) {
                return ['status' => 'error', 'message' => 'Redis read/write verification failed.'];
            }

            return ['status' => 'ok', 'message' => 'Redis connection is healthy.'];
        } catch (Throwable $e) {
            return ['status' => 'error', 'message' => 'Redis connection failed: '.$e->getMessage()];
        }
    }

    /**
     * Assess queue worker health by inspecting the Redis queue lengths for the
     * three named queues and checking for a recent spike in failed jobs.
     *
     * We cannot directly detect whether a worker process is alive without a
     * heartbeat mechanism, so we use two proxy signals:
     *   1. No more than 10 failed jobs in the last 5 minutes.
     *   2. The combined pending depth across all named queues is ≤ 1 000 items
     *      (an abnormally large backlog suggests workers are not draining).
     *
     * @return array{status: string, message: string}
     */
    private function checkQueueWorker(): array
    {
        try {
            // --- Signal 1: recent failed-job rate ---
            $failedCount = DB::table('failed_jobs')
                ->where('failed_at', '>=', now()->subMinutes(5))
                ->count();

            if ($failedCount > 10) {
                return [
                    'status'  => 'error',
                    'message' => "High failed-job rate in the last 5 minutes: {$failedCount} failures.",
                ];
            }

            // --- Signal 2: queue depth via Redis LLEN ---
            $queues   = ['notifications', 'default', 'reports'];
            $totalLen = 0;

            foreach ($queues as $queueName) {
                try {
                    // Laravel's Redis queue stores jobs in a list keyed as
                    // "queues:{name}" and delayed jobs in "queues:{name}:delayed".
                    $connection = Redis::connection('default');
                    $totalLen  += (int) $connection->llen("queues:{$queueName}");
                } catch (Throwable) {
                    // If we cannot inspect Redis queue depth, skip this signal
                    // to avoid a false-positive degraded status.
                }
            }

            if ($totalLen > 1000) {
                return [
                    'status'  => 'degraded',
                    'message' => "Queue backlog is unusually high: {$totalLen} pending jobs across all queues.",
                ];
            }

            return ['status' => 'ok', 'message' => 'Queue worker appears healthy.'];
        } catch (Throwable $e) {
            return ['status' => 'error', 'message' => 'Queue health check failed: '.$e->getMessage()];
        }
    }
}
