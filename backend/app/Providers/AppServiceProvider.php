<?php

namespace App\Providers;

use App\Repositories\Contracts\ApprovalWorkflowRepositoryInterface;
use App\Repositories\ApprovalWorkflowRepository;
use App\Repositories\Contracts\BudgetRepositoryInterface;
use App\Repositories\BudgetRepository;
use App\Repositories\Contracts\PurchaseRequestRepositoryInterface;
use App\Repositories\PurchaseRequestRepository;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(
            ApprovalWorkflowRepositoryInterface::class,
            ApprovalWorkflowRepository::class,
        );

        $this->app->bind(
            BudgetRepositoryInterface::class,
            BudgetRepository::class,
        );

        $this->app->bind(
            PurchaseRequestRepositoryInterface::class,
            PurchaseRequestRepository::class,
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Prevent lazy loading in non-production environments
        Model::preventLazyLoading(! app()->isProduction());

        // Prevent silently discarding attributes not in fillable
        Model::preventSilentlyDiscardingAttributes(! app()->isProduction());

        // Log slow queries (> 1 second) as per design requirement
        DB::whenQueryingForLongerThan(1000, function () {
            Log::warning('Slow query detected', [
                'query'    => DB::getQueryLog(),
                'duration' => '> 1000ms',
            ]);
        });

        // ── Rate Limiters ────────────────────────────────────────────────────

        /**
         * Auth rate limiter: 60 requests/minute per IP address.
         *
         * Applied to all /api/v1/auth/* endpoints.
         * Returns HTTP 429 with standard JSON envelope on exceed.
         *
         * Requirements: 2.8
         */
        RateLimiter::for('auth', function (Request $request) {
            $maxAttempts = (int) config('app.rate_limit_auth', 60);

            return Limit::perMinute($maxAttempts)
                ->by($request->ip())
                ->response(function (Request $req, array $headers) use ($maxAttempts) {
                    return response()->json([
                        'success' => false,
                        'data'    => null,
                        'message' => 'Too many requests. Please try again later.',
                        'errors'  => null,
                        'meta'    => [
                            'retry_after' => $headers['Retry-After'] ?? null,
                        ],
                    ], 429, [
                        'X-RateLimit-Limit'     => $maxAttempts,
                        'X-RateLimit-Remaining' => 0,
                        'Retry-After'           => $headers['Retry-After'] ?? 60,
                    ]);
                });
        });

        /**
         * API rate limiter: 300 requests/minute per authenticated user ID or IP.
         *
         * Applied to all protected /api/v1/* endpoints.
         * Returns HTTP 429 with standard JSON envelope on exceed.
         *
         * Requirements: 2.8
         */
        RateLimiter::for('api', function (Request $request) {
            $maxAttempts = (int) config('app.rate_limit_api', 300);

            return Limit::perMinute($maxAttempts)
                ->by($request->user()?->id ?: $request->ip())
                ->response(function (Request $req, array $headers) use ($maxAttempts) {
                    return response()->json([
                        'success' => false,
                        'data'    => null,
                        'message' => 'Too many requests. Please try again later.',
                        'errors'  => null,
                        'meta'    => [
                            'retry_after' => $headers['Retry-After'] ?? null,
                        ],
                    ], 429, [
                        'X-RateLimit-Limit'     => $maxAttempts,
                        'X-RateLimit-Remaining' => 0,
                        'Retry-After'           => $headers['Retry-After'] ?? 60,
                    ]);
                });
        });
    }
}
