<?php

namespace App\Repositories;

use App\Models\PurchaseRequest;
use App\Repositories\Contracts\PurchaseRequestRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * PurchaseRequestRepository — Eloquent implementation of PurchaseRequestRepositoryInterface.
 *
 * All queries are automatically tenant-scoped by the HasTenantScope global scope
 * applied to the PurchaseRequest model. No manual tenant_id filtering is needed
 * here; it is enforced at the model layer.
 *
 * Requirements: 5.1, 5.7, 5.8
 */
class PurchaseRequestRepository implements PurchaseRequestRepositoryInterface
{
    /**
     * Retrieve a purchase request by its primary key (UUID).
     * Returns null when not found or outside the active tenant scope.
     */
    public function findById(string $id): ?PurchaseRequest
    {
        return PurchaseRequest::find($id);
    }

    /**
     * Retrieve a purchase request by its PR number within the active tenant scope.
     */
    public function findByPRNumber(string $prNumber): ?PurchaseRequest
    {
        return PurchaseRequest::where('pr_number', $prNumber)->first();
    }

    /**
     * Return a paginated list of purchase requests with optional filters.
     *
     * Supported filters:
     *  - pr_number      partial LIKE match
     *  - department_id  exact UUID match
     *  - status         exact string match or array of strings (WHERE IN)
     *  - date_from      created_at >= value
     *  - date_to        created_at <= value (end of day)
     *  - submitted_by   exact UUID match
     *
     * @param  array<string, mixed>  $filters
     */
    public function paginate(array $filters, int $perPage = 20): LengthAwarePaginator
    {
        $query = PurchaseRequest::with(['department', 'submittedBy', 'items'])
            ->orderBy('created_at', 'desc');

        if (! empty($filters['pr_number'])) {
            $query->where('pr_number', 'like', '%' . $filters['pr_number'] . '%');
        }

        if (! empty($filters['department_id'])) {
            $query->where('department_id', $filters['department_id']);
        }

        if (! empty($filters['status'])) {
            if (is_array($filters['status'])) {
                $query->whereIn('status', $filters['status']);
            } else {
                $query->where('status', $filters['status']);
            }
        }

        if (! empty($filters['date_from'])) {
            $query->whereDate('created_at', '>=', $filters['date_from']);
        }

        if (! empty($filters['date_to'])) {
            $query->whereDate('created_at', '<=', $filters['date_to']);
        }

        if (! empty($filters['submitted_by'])) {
            $query->where('submitted_by', $filters['submitted_by']);
        }

        return $query->paginate($perPage);
    }

    /**
     * Persist a new PurchaseRequest record and return the hydrated model.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): PurchaseRequest
    {
        return PurchaseRequest::create($data);
    }

    /**
     * Update an existing PurchaseRequest record and return the refreshed model.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(PurchaseRequest $pr, array $data): PurchaseRequest
    {
        $pr->update($data);

        return $pr->fresh();
    }
}
