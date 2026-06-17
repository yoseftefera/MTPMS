<?php

namespace App\Repositories;

use App\Models\Budget;
use App\Repositories\Contracts\BudgetRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;

/**
 * BudgetRepository — Eloquent implementation of BudgetRepositoryInterface.
 *
 * All queries are automatically tenant-scoped by the HasTenantScope global
 * scope applied to the Budget model. No manual tenant_id filtering is needed
 * here; it is enforced at the model layer.
 *
 * Requirements: 13.1, 13.10
 */
class BudgetRepository implements BudgetRepositoryInterface
{
    /**
     * Retrieve a budget by its primary key (UUID).
     * Returns null when not found or outside the active tenant scope.
     */
    public function findById(string $id): ?Budget
    {
        return Budget::find($id);
    }

    /**
     * Retrieve the unique budget record for a department and fiscal year
     * within the current tenant scope.
     */
    public function findByDepartmentAndYear(string $departmentId, int $fiscalYear): ?Budget
    {
        return Budget::where('department_id', $departmentId)
            ->where('fiscal_year', $fiscalYear)
            ->first();
    }

    /**
     * Retrieve all budget records for a given fiscal year, optionally
     * filtered to a single department, within the current tenant scope.
     * Eager-loads the department relationship for reporting use.
     *
     * @return Collection<int, Budget>
     */
    public function getByFiscalYear(int $fiscalYear, ?string $departmentId = null): Collection
    {
        $query = Budget::with(['department'])
            ->where('fiscal_year', $fiscalYear);

        if ($departmentId !== null) {
            $query->where('department_id', $departmentId);
        }

        return $query->get();
    }

    /**
     * Retrieve all budgets across all fiscal years within the current tenant
     * scope, with the department relationship eager-loaded.
     *
     * @return Collection<int, Budget>
     */
    public function getAllWithDepartments(): Collection
    {
        return Budget::with(['department'])->get();
    }

    /**
     * Persist a new Budget record and return the hydrated model.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): Budget
    {
        return Budget::create($attributes);
    }

    /**
     * Update an existing Budget record and return the refreshed model.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function update(Budget $budget, array $attributes): Budget
    {
        $budget->update($attributes);

        return $budget->fresh();
    }

    /**
     * Lock a budget row for update within a running database transaction.
     * Prevents race conditions when multiple concurrent operations attempt
     * to modify the same budget record simultaneously.
     */
    public function lockForUpdate(string $id): ?Budget
    {
        return Budget::lockForUpdate()->find($id);
    }
}
