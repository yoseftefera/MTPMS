<?php

namespace App\Repositories\Contracts;

use App\Models\Budget;
use Illuminate\Database\Eloquent\Collection;

/**
 * BudgetRepositoryInterface — data access contract for budget operations.
 *
 * All implementations must be tenant-scoped via the HasTenantScope global
 * scope already applied to the Budget model.
 *
 * Requirements: 13.1, 13.10
 */
interface BudgetRepositoryInterface
{
    /**
     * Retrieve a budget by its primary key (UUID).
     * Returns null when not found or outside the active tenant scope.
     */
    public function findById(string $id): ?Budget;

    /**
     * Retrieve the unique budget record for a department and fiscal year
     * within the current tenant scope.
     *
     * Returns null when no budget has been allocated yet.
     */
    public function findByDepartmentAndYear(string $departmentId, int $fiscalYear): ?Budget;

    /**
     * Retrieve all budget records for a given fiscal year, optionally
     * filtered to a single department, within the current tenant scope.
     * Eager-loads the department relationship.
     *
     * @return Collection<int, Budget>
     */
    public function getByFiscalYear(int $fiscalYear, ?string $departmentId = null): Collection;

    /**
     * Retrieve all budget records for all fiscal years within the current
     * tenant scope, with the department relationship eager-loaded.
     * Used for cross-year utilization reporting.
     *
     * @return Collection<int, Budget>
     */
    public function getAllWithDepartments(): Collection;

    /**
     * Persist a new Budget record.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): Budget;

    /**
     * Update an existing Budget record with the given attributes.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function update(Budget $budget, array $attributes): Budget;

    /**
     * Lock a budget row for update within a database transaction.
     * Prevents race conditions when multiple operations modify the same budget.
     */
    public function lockForUpdate(string $id): ?Budget;
}
