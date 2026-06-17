<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Throwable;

class HealthController extends Controller
{
    /**
     * GET /api/health
     *
     * Returns the health status of all critical services.
     * HTTP 200 when all healthy, HTTP 503 when any service is degraded.
     */
    public function health(): JsonResponse
    {
        $checks = [
            'database' => $this->checkDatabase(),
            'redis'    => $this->checkRedis(),
            'queue'    => $this->checkQueueWorker(),
        ];

        $allHealthy = collect($checks)->every(fn ($check) => $check['status'] === 'ok');

        return response()->json([
            'status'    => $allHealthy ? 'healthy' : 'degraded',
            'timestamp' => now()->toIso8601String(),
            'checks'    => $checks,
        ], $allHealthy ? 200 : 503);
    }

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

    private function checkRedis(): array
    {
        try {
            Cache::store('redis')->put('health_check', true, 10);
            $value = Cache::store('redis')->get('health_check');

            if ($value !== true) {
                return ['status' => 'error', 'message' => 'Redis read/write check failed.'];
            }

            return ['status' => 'ok', 'message' => 'Redis connection is healthy.'];
        } catch (Throwable $e) {
            return ['status' => 'error', 'message' => 'Redis connection failed: '.$e->getMessage()];
        }
    }

    private function checkQueueWorker(): array
    {
        try {
            // Check if there are any failed jobs in the last 5 minutes as a proxy
            // for queue worker health. A more robust check would use a heartbeat job.
            $failedCount = DB::table('failed_jobs')
                ->where('failed_at', '>=', now()->subMinutes(5))
                ->count();

            if ($failedCount > 10) {
                return [
                    'status'  => 'degraded',
                    'message' => "High failed job count in last 5 minutes: {$failedCount}",
                ];
            }

            return ['status' => 'ok', 'message' => 'Queue worker appears healthy.'];
        } catch (Throwable $e) {
            return ['status' => 'error', 'message' => 'Queue check failed: '.$e->getMessage()];
        }
    }
}
