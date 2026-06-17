<?php

namespace App\Repositories\Contracts;

use App\Models\PurchaseRequest;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * PurchaseRequestRepositoryInterface — data access contract for purchase request operations.
 *
 * All implementations are automatically tenant-scoped via the HasTenantScope
 * global scope applied to the PurchaseRequest model.
 *
 * Requirements: 5.1, 5.7, 5.8
 */
interface PurchaseRequestRepositoryInterface
{
    /**
     * Retrieve a purchase request by its primary key (UUID).
     * Returns null when not found or outside the active tenant scope.
     */
    public function findById(string $id): ?PurchaseRequest;

    /**
     * Retrieve a purchase request by its PR number within the active tenant scope.
     * Returns null when not found.
     */
    public function findByPRNumber(string $prNumber): ?PurchaseRequest;

    /**
     * Return a paginated list of purchase requests, applying optional filters.
     *
     * Supported filter keys:
     *  - pr_number      (string, partial match)
     *  - department_id  (UUID)
     *  - status         (string or array of strings)
     *  - date_from      (date string, inclusive, matches created_at)
     *  - date_to        (date string, inclusive, matches created_at)
     *  - submitted_by   (UUID)
     *
     * @param  array<string, mixed>  $filters
     */
    public function paginate(array $filters, int $perPage = 20): LengthAwarePaginator;

    /**
     * Persist a new PurchaseRequest record and return the hydrated model.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): PurchaseRequest;

    /**
     * Update an existing PurchaseRequest record with the given attributes
     * and return the refreshed model.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(PurchaseRequest $pr, array $data): PurchaseRequest;
}
