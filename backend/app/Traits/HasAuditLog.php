<?php

namespace App\Traits;

use App\Jobs\WriteAuditLogJob;
use Illuminate\Database\Eloquent\Model;

/**
 * Automatically dispatches audit log entries on model create, update, and delete events.
 *
 * Applied to all models that require audit trail tracking.
 * Audit writes are async via the 'default' Redis queue (max 5-second latency).
 */
trait HasAuditLog
{
    protected static function bootHasAuditLog(): void
    {
        static::created(function (Model $model) {
            static::dispatchAuditLog('created', $model, null, $model->toArray());
        });

        static::updated(function (Model $model) {
            static::dispatchAuditLog('updated', $model, $model->getOriginal(), $model->toArray());
        });

        static::deleted(function (Model $model) {
            static::dispatchAuditLog('deleted', $model, $model->toArray(), null);
        });
    }

    private static function dispatchAuditLog(
        string $action,
        Model $model,
        ?array $before,
        ?array $after,
    ): void {
        // Dispatch when running in a web context or during unit tests.
        // Skip during artisan commands (seeding, migrations, etc.) unless
        // we are explicitly running the test suite.
        if (! app()->runningInConsole() || app()->runningUnitTests()) {
            WriteAuditLogJob::dispatch(
                tenantId:   app()->has('tenant') ? app('tenant')->id : null,
                userId:     auth()->id(),
                userRole:   auth()->user()?->getRoleNames()->first(),
                actionType: $action,
                entityType: class_basename($model),
                entityId:   (string) $model->getKey(),
                before:     $before,
                after:      $after,
                ipAddress:  request()->ip() ?? '0.0.0.0',
                requestId:  request()->header('X-Request-ID'),
            )->onQueue('default');
        }
    }
}
