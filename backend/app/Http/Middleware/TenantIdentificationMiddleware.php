<?php

namespace App\Http\Middleware;

use App\Jobs\WriteAuditLogJob;
use App\Models\Tenant;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Tymon\JWTAuth\Facades\JWTAuth;

class TenantIdentificationMiddleware
{
    /**
     * Routes that should bypass tenant identification entirely.
     * Used as a safety net in addition to route-level ->withoutMiddleware().
     */
    private const EXCLUDED_PATHS = [
        'api/health',
        'api/health-check',
    ];

    /**
     * Resolve the active tenant from:
     *   1. X-Tenant-ID header
     *   2. Subdomain (tenant.platform.com)
     *   3. JWT claim (tenant_id)
     *
     * Sets app('tenant') context for all downstream code.
     * Rejects unresolvable requests with HTTP 401 and dispatches an audit log entry.
     *
     * Requirements: 1.1, 1.3, 1.5
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Skip tenant resolution for explicitly excluded paths
        foreach (self::EXCLUDED_PATHS as $excludedPath) {
            if ($request->is($excludedPath)) {
                return $next($request);
            }
        }

        $tenantId = $this->resolveTenantIdentifier($request);

        if (! $tenantId) {
            Log::warning('Tenant resolution failed — no identifier found', [
                'ip'   => $request->ip(),
                'path' => $request->path(),
            ]);

            $this->dispatchAuditLog(
                tenantId: null,
                actionType: 'TENANT_RESOLUTION_FAILED',
                entityType: 'tenant',
                entityId: null,
                ipAddress: $request->ip(),
                requestId: $request->header('X-Request-ID'),
                after: [
                    'reason' => 'No tenant identifier found in request',
                    'path'   => $request->path(),
                    'method' => $request->method(),
                ],
            );

            return $this->unauthorizedResponse('Tenant identifier is required.');
        }

        $tenant = $this->resolveTenant($tenantId);

        if (! $tenant) {
            Log::warning('Tenant not found', [
                'tenant_identifier' => $tenantId,
                'ip'                => $request->ip(),
                'path'              => $request->path(),
            ]);

            $this->dispatchAuditLog(
                tenantId: null,
                actionType: 'TENANT_NOT_FOUND',
                entityType: 'tenant',
                entityId: $tenantId,
                ipAddress: $request->ip(),
                requestId: $request->header('X-Request-ID'),
                after: [
                    'reason'            => 'Tenant not found for identifier',
                    'tenant_identifier' => $tenantId,
                    'path'              => $request->path(),
                    'method'            => $request->method(),
                ],
            );

            return $this->unauthorizedResponse('Tenant not found.');
        }

        if ($tenant->status === Tenant::STATUS_SUSPENDED) {
            Log::warning('Request from suspended tenant', [
                'tenant_id' => $tenant->id,
                'ip'        => $request->ip(),
                'path'      => $request->path(),
            ]);

            $this->dispatchAuditLog(
                tenantId: $tenant->id,
                actionType: 'TENANT_ACCESS_DENIED_SUSPENDED',
                entityType: 'tenant',
                entityId: $tenant->id,
                ipAddress: $request->ip(),
                requestId: $request->header('X-Request-ID'),
                after: [
                    'reason' => 'Tenant is suspended',
                    'path'   => $request->path(),
                    'method' => $request->method(),
                ],
            );

            return $this->unauthorizedResponse('Tenant account is suspended.');
        }

        if ($tenant->status === Tenant::STATUS_DEACTIVATED) {
            Log::warning('Request from deactivated tenant', [
                'tenant_id' => $tenant->id,
                'ip'        => $request->ip(),
                'path'      => $request->path(),
            ]);

            $this->dispatchAuditLog(
                tenantId: $tenant->id,
                actionType: 'TENANT_ACCESS_DENIED_DEACTIVATED',
                entityType: 'tenant',
                entityId: $tenant->id,
                ipAddress: $request->ip(),
                requestId: $request->header('X-Request-ID'),
                after: [
                    'reason' => 'Tenant is deactivated',
                    'path'   => $request->path(),
                    'method' => $request->method(),
                ],
            );

            return $this->unauthorizedResponse('Tenant account has been deactivated.');
        }

        // Set tenant in application container for downstream use
        app()->instance('tenant', $tenant);

        return $next($request);
    }

    /**
     * Extract the tenant identifier from the request.
     * Priority: X-Tenant-ID header → subdomain → JWT claim
     */
    private function resolveTenantIdentifier(Request $request): ?string
    {
        // 1. X-Tenant-ID header (UUID or subdomain slug)
        if ($header = $request->header('X-Tenant-ID')) {
            return trim($header);
        }

        // 2. Subdomain extraction (tenant.platform.com)
        $host = $request->getHost();
        $parts = explode('.', $host);
        if (count($parts) >= 3) {
            // First segment is the subdomain — skip 'www'
            $subdomain = $parts[0];
            if ($subdomain !== 'www') {
                return $subdomain;
            }
        }

        // 3. JWT claim — attempt to decode without full auth validation
        try {
            $token = JWTAuth::parseToken();
            $payload = $token->getPayload();
            if ($tenantId = $payload->get('tenant_id')) {
                return (string) $tenantId;
            }
        } catch (\Throwable) {
            // Token not present or invalid — fall through
        }

        return null;
    }

    /**
     * Resolve the Tenant model from the identifier.
     *
     * Uses two dedicated Redis cache keys with 300s TTL (Requirement 24.3):
     *   - `tenant:{uuid}:config`        — keyed by tenant UUID (canonical config key)
     *   - `tenant:subdomain:{slug}`     — keyed by subdomain slug (maps slug → UUID)
     *
     * A subdomain lookup is first resolved to a UUID via the subdomain key,
     * then the UUID config key is used to store/retrieve the full Tenant model.
     * This allows targeted invalidation when a tenant record changes.
     *
     * Key pattern for tenant config: `tenant:{tenantId}:config`
     * Requirement 24.3
     */
    private function resolveTenant(string $identifier): ?Tenant
    {
        $ttl = (int) config('cache.tenant_config_ttl', 300);

        // Determine if the identifier looks like a UUID
        $isUuid = preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
            $identifier,
        );

        if ($isUuid) {
            // Direct UUID lookup — store full tenant under `tenant:{uuid}:config`
            return Cache::store('redis')->remember(
                "tenant:{$identifier}:config",
                $ttl,
                fn () => Tenant::where('id', $identifier)->first(),
            );
        }

        // Subdomain lookup — first resolve subdomain → UUID, then UUID → tenant config
        $subdomainKey = "tenant:subdomain:{$identifier}";
        $tenantId = Cache::store('redis')->remember(
            $subdomainKey,
            $ttl,
            function () use ($identifier) {
                $tenant = Tenant::where('subdomain', $identifier)->first();
                return $tenant?->id;
            },
        );

        if (! $tenantId) {
            return null;
        }

        return Cache::store('redis')->remember(
            "tenant:{$tenantId}:config",
            $ttl,
            fn () => Tenant::where('id', $tenantId)->first(),
        );
    }

    /**
     * Invalidate all Redis cache keys for a given tenant.
     *
     * Should be called after any tenant update or status change.
     * Clears both the canonical config key and the subdomain lookup key.
     *
     * Requirement 24.3
     *
     * @param  Tenant  $tenant
     */
    public static function invalidateTenantCache(Tenant $tenant): void
    {
        Cache::store('redis')->forget("tenant:{$tenant->id}:config");
        Cache::store('redis')->forget("tenant:subdomain:{$tenant->subdomain}");
    }

    /**
     * Dispatch an async audit log entry for failed tenant resolution attempts.
     */
    private function dispatchAuditLog(
        ?string $tenantId,
        string $actionType,
        string $entityType,
        ?string $entityId,
        string $ipAddress,
        ?string $requestId,
        ?array $after = null,
    ): void {
        try {
            WriteAuditLogJob::dispatch(
                tenantId: $tenantId,
                userId: null,
                userRole: null,
                actionType: $actionType,
                entityType: $entityType,
                entityId: $entityId,
                before: null,
                after: $after,
                ipAddress: $ipAddress,
                requestId: $requestId,
            );
        } catch (\Throwable $e) {
            // Never let audit log failure break the request pipeline
            Log::error('Failed to dispatch audit log from TenantIdentificationMiddleware', [
                'error'       => $e->getMessage(),
                'action_type' => $actionType,
                'ip'          => $ipAddress,
            ]);
        }
    }

    /**
     * Return a standardised HTTP 401 JSON response.
     */
    private function unauthorizedResponse(string $message): Response
    {
        return response()->json([
            'success' => false,
            'data'    => null,
            'message' => $message,
            'errors'  => null,
            'meta'    => null,
        ], Response::HTTP_UNAUTHORIZED);
    }
}
