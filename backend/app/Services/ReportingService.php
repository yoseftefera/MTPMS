<?php

namespace App\Services;

use App\Models\Approval;
use App\Models\Budget;
use App\Models\Contract;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\Supplier;
use App\Models\Tender;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * ReportingService — role-specific KPI dashboard data and procurement timeline reports.
 *
 * All data is scoped to the requesting user's tenant and role permissions.
 * System_Admin can request cross-tenant aggregates by omitting tenant scoping.
 * Report data is cached in Redis for 5 minutes (TTL = 300 seconds).
 *
 * KPIs provided:
 *  - Total PRs by status (draft, pending_approval, approved, rejected, revision_required, cancelled)
 *  - Active tenders count (status = 'published')
 *  - PO fulfillment rate (fully_received / (issued + accepted + partially_received + fully_received))
 *  - Budget utilization % ((spent + encumbered) / total × 100)
 *  - Pending approvals count (approvals with action = 'pending' for current user's accessible docs)
 *  - Overdue deliveries count (POs with status = 'overdue')
 *
 * Procurement timeline report:
 *  - Average cycle time from PR creation to PO issuance (in days)
 *  - Filterable by department_id, category, date range (created_from, created_to)
 *
 * Requirements: 16.1, 16.2, 16.9
 */
class ReportingService
{
    /**
     * Redis cache TTL: 5 minutes.
     */
    private const CACHE_TTL = 300;

    /**
     * BCMath scale for percentage calculations.
     */
    private const SCALE = 2;

    // -------------------------------------------------------------------------
    // 16.1 — Dashboard KPIs
    // -------------------------------------------------------------------------

    /**
     * Return role-specific KPI dashboard data for the given user.
     *
     * Cache key is scoped by tenant_id + role to ensure correct data isolation.
     * System_Admin keys are prefixed with 'system_admin' and include cross-tenant
     * aggregates; all other users see only their tenant's data.
     *
     * @param  User  $user
     * @return array{
     *     pr_counts_by_status: array<string,int>,
     *     active_tenders_count: int,
     *     po_fulfillment_rate: float,
     *     budget_utilization_percent: float,
     *     pending_approvals_count: int,
     *     overdue_deliveries_count: int,
     *     generated_at: string,
     * }
     */
    public function getDashboardKPIs(User $user): array
    {
        $cacheKey = $this->buildDashboardCacheKey($user);

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($user) {
            $isSystemAdmin = $user->hasRole('System_Admin');

            return [
                'pr_counts_by_status'       => $this->getPrCountsByStatus($user, $isSystemAdmin),
                'active_tenders_count'       => $this->getActiveTendersCount($user, $isSystemAdmin),
                'po_fulfillment_rate'        => $this->getPoFulfillmentRate($user, $isSystemAdmin),
                'budget_utilization_percent' => $this->getBudgetUtilizationPercent($user, $isSystemAdmin),
                'pending_approvals_count'    => $this->getPendingApprovalsCount($user, $isSystemAdmin),
                'overdue_deliveries_count'   => $this->getOverdueDeliveriesCount($user, $isSystemAdmin),
                'generated_at'               => now()->toIso8601String(),
            ];
        });
    }

    // -------------------------------------------------------------------------
    // 16.2 — Procurement Timeline Report
    // -------------------------------------------------------------------------

    /**
     * Return the average procurement cycle time (PR creation → PO issuance) in days.
     *
     * Filters:
     *  - department_id  (string|null) — filter by department
     *  - category       (string|null) — filter by tender/PR category (matches tenders.category)
     *  - created_from   (string|null) — PR created_at >= this date (Y-m-d)
     *  - created_to     (string|null) — PR created_at <= this date (Y-m-d)
     *
     * @param  User   $user
     * @param  array  $filters
     * @return array{
     *     avg_cycle_time_days: float|null,
     *     total_cycles_measured: int,
     *     min_cycle_time_days: float|null,
     *     max_cycle_time_days: float|null,
     *     filters_applied: array,
     *     generated_at: string,
     * }
     */
    public function getProcurementTimeline(User $user, array $filters): array
    {
        $cacheKey = $this->buildTimelineCacheKey($user, $filters);

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($user, $filters) {
            $isSystemAdmin = $user->hasRole('System_Admin');

            $result = $this->computeTimeline($user, $filters, $isSystemAdmin);

            return array_merge($result, [
                'filters_applied' => $this->describeFilters($filters),
                'generated_at'    => now()->toIso8601String(),
            ]);
        });
    }

    // -------------------------------------------------------------------------
    // KPI computation helpers
    // -------------------------------------------------------------------------

    /**
     * Total PRs grouped by status.
     *
     * Department_Staff sees only their own PRs.
     * Procurement_Officer, Finance_Officer, Tenant_Admin, Store_Manager, Committee_Member
     * see all PRs in their tenant.
     * System_Admin sees cross-tenant totals.
     *
     * @return array<string,int>
     */
    private function getPrCountsByStatus(User $user, bool $isSystemAdmin): array
    {
        $statuses = [
            'draft',
            'pending_approval',
            'approved',
            'rejected',
            'revision_required',
            'cancelled',
        ];

        $query = PurchaseRequest::query()
            ->select('status', DB::raw('COUNT(*) as count'));

        if ($isSystemAdmin) {
            // Cross-tenant: remove global scope for cross-tenant aggregation
            $query->withoutGlobalScopes();
        } elseif ($user->hasRole('Department_Staff')) {
            // Scope to only the user's own PRs
            $query->where('submitted_by', $user->id);
        }
        // All other tenant-scoped roles: HasTenantScope global scope handles it

        $rows = $query->groupBy('status')->pluck('count', 'status');

        // Build a complete map with 0 defaults for missing statuses
        $result = [];
        foreach ($statuses as $status) {
            $result[$status] = (int) ($rows[$status] ?? 0);
        }

        return $result;
    }

    /**
     * Count of active (published) tenders in scope.
     */
    private function getActiveTendersCount(User $user, bool $isSystemAdmin): int
    {
        $query = Tender::query()->where('status', 'published');

        if ($isSystemAdmin) {
            $query->withoutGlobalScopes();
        }

        return (int) $query->count();
    }

    /**
     * PO fulfillment rate: fully_received / (issued + accepted + partially_received + fully_received).
     *
     * Returns a percentage as a float (0–100). Returns 0 if no eligible POs exist.
     */
    private function getPoFulfillmentRate(User $user, bool $isSystemAdmin): float
    {
        $eligibleStatuses = ['issued', 'accepted', 'partially_received', 'fully_received'];

        $query = PurchaseOrder::query()
            ->select('status', DB::raw('COUNT(*) as count'))
            ->whereIn('status', $eligibleStatuses);

        if ($isSystemAdmin) {
            $query->withoutGlobalScopes();
        }

        $rows = $query->groupBy('status')->pluck('count', 'status');

        $fullyReceived = (int) ($rows['fully_received'] ?? 0);
        $denominator   = 0;
        foreach ($eligibleStatuses as $s) {
            $denominator += (int) ($rows[$s] ?? 0);
        }

        if ($denominator === 0) {
            return 0.0;
        }

        return round(($fullyReceived / $denominator) * 100, 2);
    }

    /**
     * Aggregate budget utilization % across all departments in scope.
     *
     * Formula: (SUM(spent_amount) + SUM(encumbered_amount)) / SUM(total_amount) × 100
     * Returns 0 if no budgets exist or total is zero.
     */
    private function getBudgetUtilizationPercent(User $user, bool $isSystemAdmin): float
    {
        $query = Budget::query()
            ->select(
                DB::raw('SUM(total_amount) as total'),
                DB::raw('SUM(spent_amount) as spent'),
                DB::raw('SUM(encumbered_amount) as encumbered'),
            )
            ->where('fiscal_year', now()->year);

        if ($isSystemAdmin) {
            $query->withoutGlobalScopes();
        }

        $row = $query->first();

        $total      = (float) ($row->total ?? 0);
        $spent      = (float) ($row->spent ?? 0);
        $encumbered = (float) ($row->encumbered ?? 0);

        if ($total <= 0) {
            return 0.0;
        }

        return round((($spent + $encumbered) / $total) * 100, 2);
    }

    /**
     * Count of approvals with action = 'pending' that the current user can act on.
     *
     * For System_Admin: total pending approvals across all tenants.
     * For approver roles: only approvals assigned to this user (approver_id = user->id).
     * For Department_Staff / Supplier: pending approvals on documents they submitted.
     */
    private function getPendingApprovalsCount(User $user, bool $isSystemAdmin): int
    {
        $query = Approval::query()->where('action', 'pending');

        if ($isSystemAdmin) {
            $query->withoutGlobalScopes();
        } elseif (
            $user->hasRole('Department_Staff') ||
            $user->hasRole('Supplier')
        ) {
            // Show pending approvals on the user's own submitted documents
            $prIds = PurchaseRequest::query()
                ->where('submitted_by', $user->id)
                ->pluck('id');

            $query->where(function ($q) use ($prIds, $user) {
                $q->where(function ($inner) use ($prIds) {
                    $inner->where('document_type', 'purchase_request')
                        ->whereIn('document_id', $prIds);
                });
            });
        } else {
            // Approver roles: count approvals assigned to this user
            $query->where('approver_id', $user->id);
        }

        return (int) $query->count();
    }

    /**
     * Count of POs flagged as overdue (status = 'overdue') in scope.
     */
    private function getOverdueDeliveriesCount(User $user, bool $isSystemAdmin): int
    {
        $query = PurchaseOrder::query()->where('status', 'overdue');

        if ($isSystemAdmin) {
            $query->withoutGlobalScopes();
        }

        return (int) $query->count();
    }

    // -------------------------------------------------------------------------
    // Timeline computation helper
    // -------------------------------------------------------------------------

    /**
     * Compute average/min/max cycle time from PR creation to PO issuance.
     *
     * We join purchase_requests → purchase_orders on purchase_request_id
     * and compute DATEDIFF(purchase_orders.issued_at, purchase_requests.created_at).
     *
     * Filters applied:
     *  - department_id: matches purchase_requests.department_id
     *  - category:      matches tenders.category via bids join (best-effort; skipped when no bid)
     *  - created_from:  purchase_requests.created_at >= date
     *  - created_to:    purchase_requests.created_at <= date
     *
     * @return array{avg_cycle_time_days: float|null, total_cycles_measured: int, min_cycle_time_days: float|null, max_cycle_time_days: float|null}
     */
    private function computeTimeline(User $user, array $filters, bool $isSystemAdmin): array
    {
        $query = DB::table('purchase_requests as pr')
            ->join('purchase_orders as po', 'po.purchase_request_id', '=', 'pr.id')
            ->whereNotNull('po.issued_at')
            ->whereNull('pr.deleted_at')
            ->whereNull('po.deleted_at')
            ->select(
                DB::raw('AVG(DATEDIFF(po.issued_at, pr.created_at)) as avg_days'),
                DB::raw('MIN(DATEDIFF(po.issued_at, pr.created_at)) as min_days'),
                DB::raw('MAX(DATEDIFF(po.issued_at, pr.created_at)) as max_days'),
                DB::raw('COUNT(*) as total'),
            );

        // Tenant scoping (System_Admin skips this)
        if (! $isSystemAdmin) {
            $query->where('pr.tenant_id', $user->tenant_id);
        }

        // Department filter
        if (! empty($filters['department_id'])) {
            $query->where('pr.department_id', $filters['department_id']);
        }

        // Category filter: join tenders via bids
        if (! empty($filters['category'])) {
            $query->leftJoin('bids', 'bids.id', '=', 'po.bid_id')
                ->leftJoin('tenders', 'tenders.id', '=', 'bids.tender_id')
                ->where('tenders.category', $filters['category']);
        }

        // Date range filter on PR creation date
        if (! empty($filters['created_from'])) {
            $query->whereDate('pr.created_at', '>=', $filters['created_from']);
        }

        if (! empty($filters['created_to'])) {
            $query->whereDate('pr.created_at', '<=', $filters['created_to']);
        }

        $row = $query->first();

        $total = (int) ($row->total ?? 0);

        return [
            'avg_cycle_time_days'  => $total > 0 ? round((float) $row->avg_days, 2) : null,
            'total_cycles_measured'=> $total,
            'min_cycle_time_days'  => $total > 0 ? round((float) $row->min_days, 2) : null,
            'max_cycle_time_days'  => $total > 0 ? round((float) $row->max_days, 2) : null,
        ];
    }

    // -------------------------------------------------------------------------
    // Cache key builders
    // -------------------------------------------------------------------------

    /**
     * Build a tenant + role scoped cache key for the dashboard KPI endpoint.
     *
     * System_Admin: scoped to 'system_admin' (cross-tenant)
     * Others: scoped to tenant_id + primary role name
     */
    private function buildDashboardCacheKey(User $user): string
    {
        if ($user->hasRole('System_Admin')) {
            return 'reports:dashboard:system_admin';
        }

        $role = $user->getRoleNames()->first() ?? 'unknown';

        return sprintf('reports:dashboard:tenant:%s:role:%s', $user->tenant_id, $role);
    }

    /**
     * Build a tenant + role + filters scoped cache key for the timeline endpoint.
     *
     * Filters are hashed so that different filter combinations produce unique keys.
     */
    private function buildTimelineCacheKey(User $user, array $filters): string
    {
        ksort($filters);
        $filterHash = md5(json_encode($filters));

        if ($user->hasRole('System_Admin')) {
            return sprintf('reports:timeline:system_admin:%s', $filterHash);
        }

        return sprintf(
            'reports:timeline:tenant:%s:role:%s:%s',
            $user->tenant_id,
            $user->getRoleNames()->first() ?? 'unknown',
            $filterHash,
        );
    }

    // -------------------------------------------------------------------------
    // 16.3 — Spending Analytics Report
    // -------------------------------------------------------------------------

    /**
     * Return expenditure by department, category, and supplier, plus 12-month trend.
     *
     * Expenditure = SUM(invoice_items.total_price) for invoices with status
     * 'approved' or 'paid', joined through purchase_orders to get department
     * and through bids→tenders to get tender category.
     *
     * Optional filters: department_id, supplier_id, category, date_from, date_to
     *
     * Cache key is scoped by tenant + role + filters (5-minute TTL).
     *
     * Requirements: 16.3, 16.9
     */
    public function getSpendingAnalytics(User $user, array $filters): array
    {
        $cacheKey = $this->buildReportCacheKey($user, 'spending_analytics', $filters);

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($user, $filters) {
            $isSystemAdmin = $user->hasRole('System_Admin');

            return [
                'by_department'       => $this->spendingByDimension('department', $user, $filters, $isSystemAdmin),
                'by_category'         => $this->spendingByDimension('category', $user, $filters, $isSystemAdmin),
                'by_supplier'         => $this->spendingByDimension('supplier', $user, $filters, $isSystemAdmin),
                'monthly_trend'       => $this->spendingMonthlyTrend($user, $filters, $isSystemAdmin),
                'filters_applied'     => array_filter($filters, fn ($v) => $v !== null),
                'generated_at'        => now()->toIso8601String(),
            ];
        });
    }

    /**
     * Build the base invoice-item expenditure query with tenant scoping + filters.
     */
    private function baseSpendingQuery(User $user, array $filters, bool $isSystemAdmin): \Illuminate\Database\Query\Builder
    {
        $query = DB::table('invoice_items as ii')
            ->join('invoices as inv', 'inv.id', '=', 'ii.invoice_id')
            ->join('suppliers as s', 's.id', '=', 'inv.supplier_id')
            ->leftJoin('purchase_orders as po', 'po.id', '=', 'inv.purchase_order_id')
            ->leftJoin('departments as d', 'd.id', '=', 'po.department_id')
            ->leftJoin('bids as b', 'b.id', '=', 'po.bid_id')
            ->leftJoin('tenders as t', 't.id', '=', 'b.tender_id')
            ->whereIn('inv.status', ['approved', 'paid'])
            ->whereNull('inv.deleted_at')
            ->whereNull('ii.deleted_at');

        if (! $isSystemAdmin) {
            $query->where('inv.tenant_id', $user->tenant_id);
        }

        if (! empty($filters['department_id'])) {
            $query->where('po.department_id', $filters['department_id']);
        }
        if (! empty($filters['supplier_id'])) {
            $query->where('inv.supplier_id', $filters['supplier_id']);
        }
        if (! empty($filters['category'])) {
            $query->where('t.category', $filters['category']);
        }
        if (! empty($filters['date_from'])) {
            $query->whereDate('inv.invoice_date', '>=', $filters['date_from']);
        }
        if (! empty($filters['date_to'])) {
            $query->whereDate('inv.invoice_date', '<=', $filters['date_to']);
        }

        return $query;
    }

    /**
     * Aggregate spending by a given dimension: 'department', 'category', or 'supplier'.
     */
    private function spendingByDimension(string $dimension, User $user, array $filters, bool $isSystemAdmin): array
    {
        $query = $this->baseSpendingQuery($user, $filters, $isSystemAdmin);

        switch ($dimension) {
            case 'department':
                $query->select(
                    'd.id as id',
                    'd.name as label',
                    DB::raw('SUM(ii.total_price) as total_expenditure'),
                )->groupBy('d.id', 'd.name');
                break;
            case 'category':
                $query->select(
                    DB::raw('COALESCE(t.category, "Uncategorized") as label'),
                    DB::raw('SUM(ii.total_price) as total_expenditure'),
                )->groupBy(DB::raw('COALESCE(t.category, "Uncategorized")'));
                break;
            case 'supplier':
            default:
                $query->select(
                    's.id as id',
                    's.organization_name as label',
                    DB::raw('SUM(ii.total_price) as total_expenditure'),
                )->groupBy('s.id', 's.organization_name');
                break;
        }

        return $query->orderByDesc('total_expenditure')
            ->get()
            ->map(fn ($row) => [
                'id'                => $row->id ?? null,
                'label'             => $row->label,
                'total_expenditure' => number_format((float) $row->total_expenditure, 2, '.', ''),
            ])
            ->toArray();
    }

    /**
     * Monthly expenditure totals for the last 12 months.
     */
    private function spendingMonthlyTrend(User $user, array $filters, bool $isSystemAdmin): array
    {
        $query = $this->baseSpendingQuery($user, $filters, $isSystemAdmin)
            ->select(
                DB::raw("DATE_FORMAT(inv.invoice_date, '%Y-%m') as month"),
                DB::raw('SUM(ii.total_price) as total_expenditure'),
            )
            ->whereDate('inv.invoice_date', '>=', now()->subMonths(11)->startOfMonth())
            ->groupBy(DB::raw("DATE_FORMAT(inv.invoice_date, '%Y-%m')"))
            ->orderBy('month');

        $rows = $query->get()->keyBy('month');

        // Fill in all 12 months, using 0 for missing periods
        $trend = [];
        for ($i = 11; $i >= 0; $i--) {
            $month = now()->subMonths($i)->format('Y-m');
            $trend[] = [
                'month'             => $month,
                'total_expenditure' => isset($rows[$month])
                    ? number_format((float) $rows[$month]->total_expenditure, 2, '.', '')
                    : '0.00',
            ];
        }

        return $trend;
    }

    // -------------------------------------------------------------------------
    // 16.4 — Supplier Performance Report
    // -------------------------------------------------------------------------

    /**
     * Return per-supplier performance metrics.
     *
     * Metrics per supplier:
     *  - on_time_delivery_rate       (from suppliers table)
     *  - quality_acceptance_rate     (from suppliers table)
     *  - total_pos_count             (count of non-cancelled POs)
     *  - total_contracts_value       (SUM contracts.total_value)
     *  - total_invoiced_amount       (SUM invoices.total_amount for approved/paid)
     *
     * Optional filters: supplier_id, business_category
     *
     * Requirements: 16.4, 16.9
     */
    public function getSupplierPerformance(User $user, array $filters): array
    {
        $cacheKey = $this->buildReportCacheKey($user, 'supplier_performance', $filters);

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($user, $filters) {
            $isSystemAdmin = $user->hasRole('System_Admin');

            $query = DB::table('suppliers as s')
                ->leftJoin(
                    DB::raw('(SELECT supplier_id, COUNT(*) as po_count
                              FROM purchase_orders
                              WHERE status NOT IN ("cancelled","draft")
                              AND deleted_at IS NULL
                              GROUP BY supplier_id) as po_agg'),
                    'po_agg.supplier_id',
                    '=',
                    's.id'
                )
                ->leftJoin(
                    DB::raw('(SELECT supplier_id, SUM(total_value) as contracts_value
                              FROM contracts
                              WHERE deleted_at IS NULL
                              GROUP BY supplier_id) as c_agg'),
                    'c_agg.supplier_id',
                    '=',
                    's.id'
                )
                ->leftJoin(
                    DB::raw('(SELECT supplier_id, SUM(total_amount) as invoiced_amount
                              FROM invoices
                              WHERE status IN ("approved","paid")
                              AND deleted_at IS NULL
                              GROUP BY supplier_id) as inv_agg'),
                    'inv_agg.supplier_id',
                    '=',
                    's.id'
                )
                ->select(
                    's.id',
                    's.organization_name',
                    's.business_category',
                    's.status',
                    's.on_time_delivery_rate',
                    's.quality_acceptance_rate',
                    DB::raw('COALESCE(po_agg.po_count, 0) as total_pos_count'),
                    DB::raw('COALESCE(c_agg.contracts_value, 0) as total_contracts_value'),
                    DB::raw('COALESCE(inv_agg.invoiced_amount, 0) as total_invoiced_amount'),
                )
                ->whereNull('s.deleted_at');

            if (! $isSystemAdmin) {
                $query->where('s.tenant_id', $user->tenant_id);
            }
            if (! empty($filters['supplier_id'])) {
                $query->where('s.id', $filters['supplier_id']);
            }
            if (! empty($filters['business_category'])) {
                $query->where('s.business_category', $filters['business_category']);
            }

            $rows = $query->orderBy('s.organization_name')->get();

            return [
                'suppliers'      => $rows->map(fn ($row) => [
                    'id'                      => $row->id,
                    'organization_name'       => $row->organization_name,
                    'business_category'       => $row->business_category,
                    'status'                  => $row->status,
                    'on_time_delivery_rate'   => number_format((float) $row->on_time_delivery_rate, 2, '.', ''),
                    'quality_acceptance_rate' => number_format((float) $row->quality_acceptance_rate, 2, '.', ''),
                    'total_pos_count'         => (int) $row->total_pos_count,
                    'total_contracts_value'   => number_format((float) $row->total_contracts_value, 2, '.', ''),
                    'total_invoiced_amount'   => number_format((float) $row->total_invoiced_amount, 2, '.', ''),
                ])->toArray(),
                'filters_applied' => array_filter($filters, fn ($v) => $v !== null),
                'generated_at'    => now()->toIso8601String(),
            ];
        });
    }

    // -------------------------------------------------------------------------
    // 16.5 — Tender Statistics Report
    // -------------------------------------------------------------------------

    /**
     * Return tender statistics: totals by status, avg bids per tender, awarded/cancelled ratio.
     *
     * Optional filters: category, date_from, date_to
     *
     * Requirements: 16.5, 16.9
     */
    public function getTenderStatistics(User $user, array $filters): array
    {
        $cacheKey = $this->buildReportCacheKey($user, 'tender_statistics', $filters);

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($user, $filters) {
            $isSystemAdmin = $user->hasRole('System_Admin');

            // Tender counts by status
            $tenderQuery = DB::table('tenders')
                ->select('status', DB::raw('COUNT(*) as count'))
                ->whereNull('deleted_at');

            if (! $isSystemAdmin) {
                $tenderQuery->where('tenant_id', $user->tenant_id);
            }
            if (! empty($filters['category'])) {
                $tenderQuery->where('category', $filters['category']);
            }
            if (! empty($filters['date_from'])) {
                $tenderQuery->whereDate('created_at', '>=', $filters['date_from']);
            }
            if (! empty($filters['date_to'])) {
                $tenderQuery->whereDate('created_at', '<=', $filters['date_to']);
            }

            $tenderStatuses = ['draft', 'published', 'closed', 'awarded', 'cancelled'];
            $statusRows     = $tenderQuery->groupBy('status')->pluck('count', 'status');

            $byStatus = [];
            foreach ($tenderStatuses as $s) {
                $byStatus[$s] = (int) ($statusRows[$s] ?? 0);
            }

            $totalTenders = array_sum($byStatus);

            // Average submitted bids per tender (only tenders that received at least one bid)
            $avgBidsQuery = DB::table('tenders as t')
                ->join('bids as b', 'b.tender_id', '=', 't.id')
                ->whereNull('t.deleted_at')
                ->where('b.status', '!=', 'draft');

            if (! $isSystemAdmin) {
                $avgBidsQuery->where('t.tenant_id', $user->tenant_id);
            }
            if (! empty($filters['category'])) {
                $avgBidsQuery->where('t.category', $filters['category']);
            }
            if (! empty($filters['date_from'])) {
                $avgBidsQuery->whereDate('t.created_at', '>=', $filters['date_from']);
            }
            if (! empty($filters['date_to'])) {
                $avgBidsQuery->whereDate('t.created_at', '<=', $filters['date_to']);
            }

            $avgBidsRow = $avgBidsQuery
                ->select(
                    DB::raw('COUNT(b.id) as total_bids'),
                    DB::raw('COUNT(DISTINCT t.id) as tender_count'),
                )
                ->first();

            $tenderCount = (int) ($avgBidsRow->tender_count ?? 0);
            $avgBids     = $tenderCount > 0
                ? round((int) $avgBidsRow->total_bids / $tenderCount, 2)
                : 0.0;

            // Awarded vs cancelled ratio
            $awarded   = $byStatus['awarded']   ?? 0;
            $cancelled = $byStatus['cancelled'] ?? 0;
            $awardedVsCancelled = ($cancelled > 0)
                ? round($awarded / $cancelled, 4)
                : ($awarded > 0 ? null : 0.0);

            return [
                'total_tenders'           => $totalTenders,
                'by_status'               => $byStatus,
                'avg_bids_per_tender'     => $avgBids,
                'awarded_count'           => $awarded,
                'cancelled_count'         => $cancelled,
                'awarded_vs_cancelled_ratio' => $awardedVsCancelled,
                'filters_applied'         => array_filter($filters, fn ($v) => $v !== null),
                'generated_at'            => now()->toIso8601String(),
            ];
        });
    }

    // -------------------------------------------------------------------------
    // 16.6 — Financial Summary Report
    // -------------------------------------------------------------------------

    /**
     * Return per-department financial summary: invoiced, paid, outstanding, and budget variance.
     *
     * Per department:
     *  - invoiced_amount   = SUM(invoices.total_amount) for approved/paid invoices
     *  - paid_amount       = SUM(payments.amount) for completed payments
     *  - outstanding_amount = invoiced_amount - paid_amount
     *  - budget_allocated  = budgets.total_amount
     *  - budget_spent      = budgets.spent_amount
     *  - budget_variance   = budget_allocated - budget_spent (positive = under budget)
     *
     * Optional filters: department_id, fiscal_year
     *
     * Requirements: 16.6, 16.9
     */
    public function getFinancialSummary(User $user, array $filters): array
    {
        $cacheKey = $this->buildReportCacheKey($user, 'financial_summary', $filters);

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($user, $filters) {
            $isSystemAdmin = $user->hasRole('System_Admin');
            $fiscalYear    = $filters['fiscal_year'] ?? now()->year;

            // Budget rows for the fiscal year
            $budgetQuery = DB::table('budgets as bud')
                ->join('departments as dep', 'dep.id', '=', 'bud.department_id')
                ->select(
                    'bud.department_id',
                    'dep.name as department_name',
                    'bud.total_amount as budget_allocated',
                    'bud.spent_amount as budget_spent',
                )
                ->where('bud.fiscal_year', $fiscalYear)
                ->whereNull('dep.deleted_at');

            if (! $isSystemAdmin) {
                $budgetQuery->where('bud.tenant_id', $user->tenant_id);
            }
            if (! empty($filters['department_id'])) {
                $budgetQuery->where('bud.department_id', $filters['department_id']);
            }

            $budgets = $budgetQuery->get()->keyBy('department_id');

            // Invoiced amount per department (via PO → department)
            $invoicedQuery = DB::table('invoices as inv')
                ->join('purchase_orders as po', 'po.id', '=', 'inv.purchase_order_id')
                ->select(
                    'po.department_id',
                    DB::raw('SUM(inv.total_amount) as invoiced_amount'),
                )
                ->whereIn('inv.status', ['approved', 'paid'])
                ->whereNull('inv.deleted_at')
                ->whereNotNull('inv.purchase_order_id');

            if (! $isSystemAdmin) {
                $invoicedQuery->where('inv.tenant_id', $user->tenant_id);
            }
            if (! empty($filters['department_id'])) {
                $invoicedQuery->where('po.department_id', $filters['department_id']);
            }

            $invoiced = $invoicedQuery->groupBy('po.department_id')
                ->get()
                ->keyBy('department_id');

            // Paid amount per department (via invoices → payments → purchase_orders)
            $paidQuery = DB::table('payments as pay')
                ->join('invoices as inv', 'inv.id', '=', 'pay.invoice_id')
                ->join('purchase_orders as po', 'po.id', '=', 'inv.purchase_order_id')
                ->select(
                    'po.department_id',
                    DB::raw('SUM(pay.amount) as paid_amount'),
                )
                ->where('pay.status', 'completed')
                ->whereNull('inv.deleted_at')
                ->whereNotNull('inv.purchase_order_id');

            if (! $isSystemAdmin) {
                $paidQuery->where('pay.tenant_id', $user->tenant_id);
            }
            if (! empty($filters['department_id'])) {
                $paidQuery->where('po.department_id', $filters['department_id']);
            }

            $paid = $paidQuery->groupBy('po.department_id')
                ->get()
                ->keyBy('department_id');

            // Merge into a unified per-department result set
            $departmentIds = $budgets->keys()
                ->merge($invoiced->keys())
                ->merge($paid->keys())
                ->unique();

            $rows = [];
            foreach ($departmentIds as $deptId) {
                $budgetRow   = $budgets->get($deptId);
                $invoicedAmt = (float) ($invoiced->get($deptId)->invoiced_amount ?? 0);
                $paidAmt     = (float) ($paid->get($deptId)->paid_amount ?? 0);
                $allocated   = (float) ($budgetRow->budget_allocated ?? 0);
                $spent       = (float) ($budgetRow->budget_spent ?? 0);

                $rows[] = [
                    'department_id'     => $deptId,
                    'department_name'   => $budgetRow->department_name ?? null,
                    'fiscal_year'       => (int) $fiscalYear,
                    'invoiced_amount'   => number_format($invoicedAmt, 2, '.', ''),
                    'paid_amount'       => number_format($paidAmt, 2, '.', ''),
                    'outstanding_amount'=> number_format($invoicedAmt - $paidAmt, 2, '.', ''),
                    'budget_allocated'  => number_format($allocated, 2, '.', ''),
                    'budget_spent'      => number_format($spent, 2, '.', ''),
                    'budget_variance'   => number_format($allocated - $spent, 2, '.', ''),
                ];
            }

            return [
                'departments'    => $rows,
                'fiscal_year'    => (int) $fiscalYear,
                'filters_applied'=> array_filter($filters, fn ($v) => $v !== null),
                'generated_at'   => now()->toIso8601String(),
            ];
        });
    }

    // -------------------------------------------------------------------------
    // Shared cache key builder
    // -------------------------------------------------------------------------

    /**
     * Build a tenant + role + report-type + filters scoped cache key.
     */
    private function buildReportCacheKey(User $user, string $reportType, array $filters): string
    {
        ksort($filters);
        $filterHash = md5(json_encode($filters));

        if ($user->hasRole('System_Admin')) {
            return sprintf('reports:%s:system_admin:%s', $reportType, $filterHash);
        }

        return sprintf(
            'reports:%s:tenant:%s:role:%s:%s',
            $reportType,
            $user->tenant_id,
            $user->getRoleNames()->first() ?? 'unknown',
            $filterHash,
        );
    }

    // -------------------------------------------------------------------------
    // Existing private helpers
    // -------------------------------------------------------------------------

    /**
     * Produce a human-readable summary of the filters that were applied.
     *
     * @return array<string,mixed>
     */
    private function describeFilters(array $filters): array
    {
        $applied = [];

        if (! empty($filters['department_id'])) {
            $applied['department_id'] = $filters['department_id'];
        }

        if (! empty($filters['category'])) {
            $applied['category'] = $filters['category'];
        }

        if (! empty($filters['created_from'])) {
            $applied['created_from'] = $filters['created_from'];
        }

        if (! empty($filters['created_to'])) {
            $applied['created_to'] = $filters['created_to'];
        }

        return $applied;
    }
}
