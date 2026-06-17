<?php

namespace App\Traits;

use Illuminate\Database\Eloquent\Builder;

/**
 * Applies a global tenant scope to all Eloquent queries on the model.
 *
 * - Automatically appends WHERE tenant_id = ? to every query when a tenant
 *   context is set in the application container.
 * - Automatically sets tenant_id on the model when creating a new record.
 *
 * Applied to all tenant-scoped models (users, departments, budgets, etc.).
 */
trait HasTenantScope
{
    protected static function booted(): void
    {
        // Apply global scope to all queries
        static::addGlobalScope('tenant', function (Builder $builder) {
            if (app()->has('tenant')) {
                $builder->where(
                    $builder->getModel()->getTable().'.tenant_id',
                    app('tenant')->id
                );
            }
        });

        // Auto-set tenant_id when creating a new record
        static::creating(function ($model) {
            if (app()->has('tenant') && empty($model->tenant_id)) {
                $model->tenant_id = app('tenant')->id;
            }
        });
    }

    /**
     * Temporarily bypass the tenant scope for a query.
     * Use with caution — only for cross-tenant system operations.
     */
    public static function withoutTenantScope(): Builder
    {
        return static::withoutGlobalScope('tenant');
    }
}
