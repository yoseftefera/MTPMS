<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\GenerateReportJob;
use App\Services\ExportService;
use App\Services\ReportingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * @OA\Tag(name="Reports", description="KPI dashboard, procurement timeline, spending analytics, and export.")
 *
 * ReportController — thin controller for reporting and analytics endpoints.
 *
 * Endpoints implemented here (task 16.1):
 *   GET /api/v1/reports/dashboard             — role-specific KPI dashboard data
 *   GET /api/v1/reports/procurement-timeline  — avg cycle time PR→PO with filters
 *
 * Additional endpoints (task 16.2 — implemented):
 *   GET /api/v1/reports/spending-analytics
 *   GET /api/v1/reports/supplier-performance
 *   GET /api/v1/reports/tender-statistics
 *   GET /api/v1/reports/financial-summary
 *   POST /api/v1/reports/export
 *
 * All responses use the standard { success, data, message, errors, meta } envelope.
 * All report data is scoped to the requesting user's tenant and role permissions.
 *
 * Requirements: 16.1, 16.2, 16.3, 16.4, 16.5, 16.6, 16.9
 */
class ReportController extends Controller
{
    /** Row threshold: datasets above this limit are processed asynchronously. */
    private const ASYNC_ROW_THRESHOLD = 10_000;

    public function __construct(
        private readonly ReportingService $reportingService,
        private readonly ExportService    $exportService,
    ) {
    }

    // -------------------------------------------------------------------------
    // GET /api/v1/reports/dashboard
    // -------------------------------------------------------------------------

    /**
     * @OA\Get(path="/reports/dashboard", operationId="reportDashboard", tags={"Reports"}, summary="KPI dashboard data",
     *     description="Returns role-specific KPI data: PR counts by status, active tenders, PO fulfillment rate, budget utilization, pending approvals, overdue deliveries. Cached 5 min.",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(ref="#/components/parameters/XTenantID"), @OA\Parameter(ref="#/components/parameters/XRequestID"),
     *     @OA\Response(response=200, description="Dashboard KPIs.", @OA\JsonContent(@OA\Property(property="success", type="boolean", example=true), @OA\Property(property="data", type="object", @OA\Property(property="pr_counts_by_status", type="object"), @OA\Property(property="active_tenders_count", type="integer"), @OA\Property(property="po_fulfillment_rate", type="string"), @OA\Property(property="budget_utilization_percent", type="string"), @OA\Property(property="pending_approvals_count", type="integer"), @OA\Property(property="overdue_deliveries_count", type="integer")), @OA\Property(property="message", type="string"), @OA\Property(property="errors", nullable=true, example=null), @OA\Property(property="meta", nullable=true, example=null))),
     *     @OA\Response(response=401, description="Unauthenticated.", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     *
     * Return role-specific KPI dashboard data for the authenticated user.
     *
     * KPIs returned:
     *  - pr_counts_by_status       — PR totals per status
     *  - active_tenders_count      — tenders in 'published' status
     *  - po_fulfillment_rate       — % of eligible POs fully received
     *  - budget_utilization_percent— (spent + encumbered) / total × 100 for current fiscal year
     *  - pending_approvals_count   — approvals awaiting action from/for this user
     *  - overdue_deliveries_count  — POs flagged as overdue
     *
     * Data is cached in Redis for 5 minutes, scoped by tenant + role.
     *
     * Roles: all authenticated users (data scoped per role)
     * Requirements: 16.1, 16.9
     */
    public function dashboard(Request $request): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = Auth::user();

        $kpis = $this->reportingService->getDashboardKPIs($user);

        return $this->success(
            data:    $kpis,
            message: 'Dashboard KPIs retrieved successfully.',
        );
    }

    // -------------------------------------------------------------------------
    // GET /api/v1/reports/procurement-timeline
    // -------------------------------------------------------------------------

    /**
     * @OA\Get(path="/reports/procurement-timeline", operationId="reportProcurementTimeline", tags={"Reports"}, summary="Procurement timeline report",
     *     description="Average PR-to-PO cycle time in days, with optional filters. Cached 5 min.",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(ref="#/components/parameters/XTenantID"), @OA\Parameter(ref="#/components/parameters/XRequestID"),
     *     @OA\Parameter(name="department_id", in="query", required=false, @OA\Schema(type="string", format="uuid")),
     *     @OA\Parameter(name="category", in="query", required=false, @OA\Schema(type="string")),
     *     @OA\Parameter(name="created_from", in="query", required=false, @OA\Schema(type="string", format="date")),
     *     @OA\Parameter(name="created_to", in="query", required=false, @OA\Schema(type="string", format="date")),
     *     @OA\Response(response=200, description="Timeline report.", @OA\JsonContent(@OA\Property(property="success", type="boolean", example=true), @OA\Property(property="data", type="object", @OA\Property(property="avg_cycle_days", type="string", example="12.50"), @OA\Property(property="min_cycle_days", type="string"), @OA\Property(property="max_cycle_days", type="string"), @OA\Property(property="cycle_count", type="integer")), @OA\Property(property="message", type="string"), @OA\Property(property="errors", nullable=true, example=null), @OA\Property(property="meta", nullable=true, example=null))),
     *     @OA\Response(response=401, description="Unauthenticated.", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     *
     * Return average procurement cycle time from PR creation to PO issuance.
     *
     * Query parameters (all optional):
     *   department_id  — filter by department UUID
     *   category       — filter by tender/PR category string
     *   created_from   — PR created_at >= date (Y-m-d)
     *   created_to     — PR created_at <= date (Y-m-d)
     *
     * Response includes avg/min/max cycle times in days plus count of measured cycles.
     *
     * Data is cached in Redis for 5 minutes, scoped by tenant + role + filters.
     *
     * Roles: all authenticated users with reports.view permission
     * Requirements: 16.2, 16.9
     */
    public function procurementTimeline(Request $request): JsonResponse
    {
        $request->validate([
            'department_id' => ['nullable', 'uuid'],
            'category'      => ['nullable', 'string', 'max:100'],
            'created_from'  => ['nullable', 'date_format:Y-m-d'],
            'created_to'    => ['nullable', 'date_format:Y-m-d', 'after_or_equal:created_from'],
        ]);

        /** @var \App\Models\User $user */
        $user = Auth::user();

        $filters = [
            'department_id' => $request->query('department_id'),
            'category'      => $request->query('category'),
            'created_from'  => $request->query('created_from'),
            'created_to'    => $request->query('created_to'),
        ];

        // Remove null values so cache keys are stable
        $filters = array_filter($filters, fn ($v) => $v !== null);

        $timeline = $this->reportingService->getProcurementTimeline($user, $filters);

        return $this->success(
            data:    $timeline,
            message: 'Procurement timeline report retrieved successfully.',
        );
    }

    // -------------------------------------------------------------------------
    // Task 16.2 report endpoints
    // -------------------------------------------------------------------------

    // -------------------------------------------------------------------------
    // GET /api/v1/reports/spending-analytics
    // -------------------------------------------------------------------------

    /**
     * @OA\Get(path="/reports/spending-analytics", operationId="reportSpendingAnalytics", tags={"Reports"}, summary="Spending analytics report",
     *     description="Expenditure by department, category, and supplier with month-over-month trends. Cached 5 min.",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(ref="#/components/parameters/XTenantID"), @OA\Parameter(ref="#/components/parameters/XRequestID"),
     *     @OA\Parameter(name="department_id", in="query", required=false, @OA\Schema(type="string", format="uuid")),
     *     @OA\Parameter(name="supplier_id", in="query", required=false, @OA\Schema(type="string", format="uuid")),
     *     @OA\Parameter(name="category", in="query", required=false, @OA\Schema(type="string")),
     *     @OA\Parameter(name="date_from", in="query", required=false, @OA\Schema(type="string", format="date")),
     *     @OA\Parameter(name="date_to", in="query", required=false, @OA\Schema(type="string", format="date")),
     *     @OA\Response(response=200, description="Spending analytics.", @OA\JsonContent(@OA\Property(property="success", type="boolean", example=true), @OA\Property(property="data", type="object"), @OA\Property(property="message", type="string"), @OA\Property(property="errors", nullable=true, example=null), @OA\Property(property="meta", nullable=true, example=null))),
     *     @OA\Response(response=401, description="Unauthenticated.", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     *
     * Return actual expenditure grouped by department, category, and supplier.
     *
     * Query parameters (all optional):
     *   department_id  — filter by department UUID
     *   supplier_id    — filter by supplier UUID
     *   category       — filter by tender category string
     *   date_from      — invoice_date >= date (Y-m-d)
     *   date_to        — invoice_date <= date (Y-m-d)
     *
     * Expenditure = SUM(invoice_items.total_price) for approved/paid invoices.
     * Data is cached in Redis for 5 minutes, scoped by tenant + role + filters.
     *
     * Roles: Finance_Officer, Procurement_Officer, Tenant_Admin, System_Admin
     * Requirements: 16.3, 16.9
     */
    public function spendingAnalytics(Request $request): JsonResponse
    {
        $request->validate([
            'department_id' => ['nullable', 'uuid'],
            'supplier_id'   => ['nullable', 'uuid'],
            'category'      => ['nullable', 'string', 'max:100'],
            'date_from'     => ['nullable', 'date_format:Y-m-d'],
            'date_to'       => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
        ]);

        /** @var \App\Models\User $user */
        $user = Auth::user();

        $filters = array_filter([
            'department_id' => $request->query('department_id'),
            'supplier_id'   => $request->query('supplier_id'),
            'category'      => $request->query('category'),
            'date_from'     => $request->query('date_from'),
            'date_to'       => $request->query('date_to'),
        ], fn ($v) => $v !== null);

        $data = $this->reportingService->getSpendingAnalytics($user, $filters);

        return $this->success(
            data:    $data,
            message: 'Spending analytics report retrieved successfully.',
        );
    }

    // -------------------------------------------------------------------------
    // GET /api/v1/reports/supplier-performance
    // -------------------------------------------------------------------------

    /**
     * Return per-supplier performance metrics.
     *
     * Metrics returned per supplier:
     *   on_time_delivery_rate, quality_acceptance_rate,
     *   total_pos_count, total_contracts_value, total_invoiced_amount
     *
     * Query parameters (all optional):
     *   supplier_id       — filter to a single supplier UUID
     *   business_category — filter by supplier business category string
     *
     * Data is cached in Redis for 5 minutes, scoped by tenant + role + filters.
     *
     * Roles: Finance_Officer, Procurement_Officer, Tenant_Admin, System_Admin
     * Requirements: 16.4, 16.9
     */
    public function supplierPerformance(Request $request): JsonResponse
    {
        $request->validate([
            'supplier_id'       => ['nullable', 'uuid'],
            'business_category' => ['nullable', 'string', 'max:100'],
        ]);

        /** @var \App\Models\User $user */
        $user = Auth::user();

        $filters = array_filter([
            'supplier_id'       => $request->query('supplier_id'),
            'business_category' => $request->query('business_category'),
        ], fn ($v) => $v !== null);

        $data = $this->reportingService->getSupplierPerformance($user, $filters);

        return $this->success(
            data:    $data,
            message: 'Supplier performance report retrieved successfully.',
        );
    }

    // -------------------------------------------------------------------------
    // GET /api/v1/reports/tender-statistics
    // -------------------------------------------------------------------------

    /**
     * Return aggregate tender statistics.
     *
     * Metrics:
     *   total_tenders, by_status (per-status counts),
     *   avg_bids_per_tender, awarded_count, cancelled_count,
     *   awarded_vs_cancelled_ratio
     *
     * Query parameters (all optional):
     *   category  — filter by tender category string
     *   date_from — tender created_at >= date (Y-m-d)
     *   date_to   — tender created_at <= date (Y-m-d)
     *
     * Data is cached in Redis for 5 minutes, scoped by tenant + role + filters.
     *
     * Roles: Procurement_Officer, Tenant_Admin, System_Admin
     * Requirements: 16.5, 16.9
     */
    public function tenderStatistics(Request $request): JsonResponse
    {
        $request->validate([
            'category'  => ['nullable', 'string', 'max:100'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to'   => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
        ]);

        /** @var \App\Models\User $user */
        $user = Auth::user();

        $filters = array_filter([
            'category'  => $request->query('category'),
            'date_from' => $request->query('date_from'),
            'date_to'   => $request->query('date_to'),
        ], fn ($v) => $v !== null);

        $data = $this->reportingService->getTenderStatistics($user, $filters);

        return $this->success(
            data:    $data,
            message: 'Tender statistics report retrieved successfully.',
        );
    }

    // -------------------------------------------------------------------------
    // GET /api/v1/reports/financial-summary
    // -------------------------------------------------------------------------

    /**
     * Return financial summary per department for a given fiscal year.
     *
     * Per-department fields:
     *   invoiced_amount, paid_amount, outstanding_amount (invoiced − paid),
     *   budget_allocated, budget_spent, budget_variance (allocated − spent)
     *
     * Query parameters (all optional):
     *   department_id — filter by department UUID
     *   fiscal_year   — four-digit year (defaults to current year)
     *
     * Data is cached in Redis for 5 minutes, scoped by tenant + role + filters.
     *
     * Roles: Finance_Officer, Tenant_Admin, System_Admin
     * Requirements: 16.6, 16.9
     */
    public function financialSummary(Request $request): JsonResponse
    {
        $request->validate([
            'department_id' => ['nullable', 'uuid'],
            'fiscal_year'   => ['nullable', 'integer', 'digits:4', 'min:2000', 'max:2100'],
        ]);

        /** @var \App\Models\User $user */
        $user = Auth::user();

        $filters = array_filter([
            'department_id' => $request->query('department_id'),
            'fiscal_year'   => $request->query('fiscal_year'),
        ], fn ($v) => $v !== null);

        $data = $this->reportingService->getFinancialSummary($user, $filters);

        return $this->success(
            data:    $data,
            message: 'Financial summary report retrieved successfully.',
        );
    }

    /**
     * POST /api/v1/reports/export
     *
     * Generate and download a report in PDF or Excel format.
     *
     * Request body:
     *   report_type  string (required) — one of: dashboard, procurement_timeline,
     *                                    spending_analytics, supplier_performance,
     *                                    tender_statistics, financial_summary
     *   format       string (required) — "pdf" | "excel"
     *   filters      object (optional) — arbitrary filter map (department_id, date_from, etc.)
     *
     * Behaviour:
     *   - If estimated row count ≤ 10,000: generate synchronously and stream as file download.
     *   - If estimated row count > 10,000: dispatch GenerateReportJob to the `reports` queue
     *     and return HTTP 202 with a job_id.
     *
     * Requirements: 16.7, 16.8
     */
    public function export(Request $request): \Symfony\Component\HttpFoundation\Response
    {
        $validated = $request->validate([
            'report_type' => ['required', 'string', 'in:' . implode(',', ExportService::SUPPORTED_REPORT_TYPES)],
            'format'      => ['required', 'string', 'in:pdf,excel'],
            'filters'     => ['sometimes', 'nullable', 'array'],
        ]);

        $reportType = $validated['report_type'];
        $format     = $validated['format'];
        $filters    = $validated['filters'] ?? [];

        /** @var \App\Models\User $user */
        $user = Auth::user();

        // Estimate the number of rows to decide sync vs async
        $rowCount = $this->exportService->estimateRowCount($reportType, $filters, $user);

        // ── Async path (large dataset) ────────────────────────────────────────
        if ($rowCount > self::ASYNC_ROW_THRESHOLD) {
            $jobId = (string) Str::uuid();

            GenerateReportJob::dispatch(
                reportType: $reportType,
                format:     $format,
                filters:    $filters,
                userId:     $user->id,
                tenantId:   $user->tenant_id,
            );

            return response()->json([
                'success' => true,
                'data'    => [
                    'job_id'  => $jobId,
                    'message' => 'Report generation in progress. You will be notified when ready.',
                ],
                'message' => 'Report generation in progress. You will be notified when ready.',
                'errors'  => null,
                'meta'    => null,
            ], 202);
        }

        // ── Synchronous path (small dataset) ─────────────────────────────────
        try {
            [$fileContent, $mimeType, $extension] = $this->exportService->generate(
                reportType: $reportType,
                format:     $format,
                filters:    $filters,
                user:       $user,
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'data'    => null,
                'message' => $e->getMessage(),
                'errors'  => ['report_type' => [$e->getMessage()]],
                'meta'    => null,
            ], 422);
        }

        $timestamp = now()->format('Ymd_His');
        $fileName  = "{$reportType}_{$timestamp}.{$extension}";

        return response($fileContent, 200, [
            'Content-Type'        => $mimeType,
            'Content-Disposition' => "attachment; filename=\"{$fileName}\"",
            'Content-Length'      => strlen($fileContent),
            'Cache-Control'       => 'no-store, no-cache',
            'Pragma'              => 'no-cache',
        ]);
    }
}
