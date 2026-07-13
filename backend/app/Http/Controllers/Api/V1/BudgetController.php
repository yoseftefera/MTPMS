<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Budget\StoreBudgetRequest;
use App\Http\Requests\V1\Budget\TransferBudgetRequest;
use App\Http\Requests\V1\Budget\UpdateBudgetRequest;
use App\Http\Resources\V1\BudgetResource;
use App\Models\Budget;
use App\Services\BudgetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * @OA\Tag(name="Budgets", description="Annual budget allocation, tracking, transfers, and utilization reports.")
 *
 * BudgetController — thin controller for budget management within tenant scope.
 *
 * Endpoints:
 *   GET    /api/v1/budgets                       — paginated list (Finance_Officer | Tenant_Admin)
 *   POST   /api/v1/budgets                       — create annual budget allocation (Finance_Officer)
 *   GET    /api/v1/budgets/utilization-report    — per-department utilization report
 *   GET    /api/v1/budgets/{budget}              — single budget with utilization details
 *   PUT    /api/v1/budgets/{budget}              — update budget (Finance_Officer)
 *   POST   /api/v1/budgets/transfer              — transfer between departments (Finance_Officer)
 *
 * All queries are automatically scoped to the active tenant via HasTenantScope.
 * Route model binding returns HTTP 404 when the budget belongs to a different tenant.
 *
 * Requirements: 13.1, 13.8, 13.10
 */
class BudgetController extends Controller
{
    public function __construct(private readonly BudgetService $budgetService)
    {
    }

    // -------------------------------------------------------------------------
    // GET /api/v1/budgets
    // -------------------------------------------------------------------------

    /**
     * @OA\Get(path="/budgets", operationId="listBudgets", tags={"Budgets"}, summary="List budgets",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(ref="#/components/parameters/XTenantID"), @OA\Parameter(ref="#/components/parameters/XRequestID"),
     *     @OA\Parameter(name="department_id", in="query", required=false, @OA\Schema(type="string", format="uuid")),
     *     @OA\Parameter(name="fiscal_year", in="query", required=false, @OA\Schema(type="integer", example=2025)),
     *     @OA\Parameter(name="per_page", in="query", required=false, @OA\Schema(type="integer", default=20)),
     *     @OA\Response(response=200, description="Budgets list.", @OA\JsonContent(@OA\Property(property="success", type="boolean", example=true), @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/BudgetResource")), @OA\Property(property="message", type="string"), @OA\Property(property="errors", nullable=true, example=null), @OA\Property(property="meta", ref="#/components/schemas/PaginationMeta"))),
     *     @OA\Response(response=401, description="Unauthenticated.", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     *
     * Return a paginated list of budgets for the active tenant.
     *
     * Requirements: 13.1, 13.10
     */
    public function index(Request $request): JsonResponse
    {
        $filters = [
            'department_id' => $request->query('department_id'),
            'fiscal_year'   => $request->query('fiscal_year') ? (int) $request->query('fiscal_year') : null,
            'per_page'      => min((int) $request->query('per_page', 20), 100),
        ];

        $paginator = $this->budgetService->paginatedUtilizationReport($filters);

        // Enrich each budget with computed utilization attributes
        $fiscalYear = $filters['fiscal_year'] ?? now()->year;
        $enriched   = $this->budgetService->getUtilizationReport(
            fiscalYear:   $fiscalYear,
            departmentId: $filters['department_id'],
        );

        // Map enriched data onto paginator items by id
        $enrichedById = $enriched->keyBy('id');
        $items        = collect($paginator->items())->map(function (Budget $budget) use ($enrichedById) {
            $rich = $enrichedById->get($budget->id);
            if ($rich) {
                $budget->setAttribute('available_amount',    $rich->getAttribute('available_amount'));
                $budget->setAttribute('committed_amount',    $rich->getAttribute('committed_amount'));
                $budget->setAttribute('utilization_percent', $rich->getAttribute('utilization_percent'));
            }

            return $budget;
        });

        return $this->paginated(
            paginator: $paginator,
            data:      BudgetResource::collection($items->load('department')),
            message:   'Budgets retrieved successfully.',
        );
    }

    // -------------------------------------------------------------------------
    // POST /api/v1/budgets
    // -------------------------------------------------------------------------

    /**
     * @OA\Post(path="/budgets", operationId="createBudget", tags={"Budgets"}, summary="Create or update budget allocation",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(ref="#/components/parameters/XTenantID"), @OA\Parameter(ref="#/components/parameters/XRequestID"),
     *     @OA\RequestBody(required=true, @OA\JsonContent(required={"department_id","fiscal_year","total_amount"}, @OA\Property(property="department_id", type="string", format="uuid"), @OA\Property(property="fiscal_year", type="integer", example=2025), @OA\Property(property="total_amount", type="string", example="500000.00"), @OA\Property(property="currency", type="string", example="USD"))),
     *     @OA\Response(response=201, description="Budget created.", @OA\JsonContent(@OA\Property(property="success", type="boolean", example=true), @OA\Property(property="data", ref="#/components/schemas/BudgetResource"), @OA\Property(property="message", type="string"), @OA\Property(property="errors", nullable=true, example=null), @OA\Property(property="meta", nullable=true, example=null))),
     *     @OA\Response(response=422, description="Validation error.", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     *
     * Create an annual budget allocation for a department.
     *
     * Requirements: 13.1
     */
    public function store(StoreBudgetRequest $request): JsonResponse
    {
        $actor = Auth::guard('api')->user();

        $result = $this->budgetService->allocateBudget(
            data:      array_merge($request->validated(), [
                'total_amount' => number_format((float) $request->input('total_amount'), 2, '.', ''),
            ]),
            actor:     $actor,
            ipAddress: $request->ip() ?? '0.0.0.0',
            requestId: $request->header('X-Request-ID'),
        );

        if (! $result['success']) {
            return $this->error(
                message: $result['message'],
                status:  $result['code'],
                errors:  $result['errors'] ?? null,
            );
        }

        $budget = $result['data'];
        $status = $result['code'] === 201 ? 201 : 200;

        return response()->json([
            'success' => true,
            'data'    => new BudgetResource($budget),
            'message' => $result['message'],
            'errors'  => null,
            'meta'    => null,
        ], $status);
    }

    // -------------------------------------------------------------------------
    // GET /api/v1/budgets/{budget}
    // -------------------------------------------------------------------------

    /**
     * @OA\Get(path="/budgets/{budget}", operationId="showBudget", tags={"Budgets"}, summary="Get budget",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(ref="#/components/parameters/XTenantID"), @OA\Parameter(ref="#/components/parameters/XRequestID"),
     *     @OA\Parameter(name="budget", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *     @OA\Response(response=200, description="Budget with utilization.", @OA\JsonContent(@OA\Property(property="success", type="boolean", example=true), @OA\Property(property="data", ref="#/components/schemas/BudgetResource"), @OA\Property(property="message", type="string"), @OA\Property(property="errors", nullable=true, example=null), @OA\Property(property="meta", nullable=true, example=null))),
     *     @OA\Response(response=404, description="Not found.", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     *
     * Return a single budget with real-time utilization details.
     *
     * Requirements: 13.1, 13.10
     */
    public function show(Budget $budget): JsonResponse
    {
        $budget->load('department');

        // Compute utilization for this single budget
        $report = $this->budgetService->getUtilizationReport(
            fiscalYear:   (int) $budget->fiscal_year,
            departmentId: $budget->department_id,
        );

        $enriched = $report->firstWhere('id', $budget->id);
        if ($enriched) {
            $budget->setAttribute('available_amount',    $enriched->getAttribute('available_amount'));
            $budget->setAttribute('committed_amount',    $enriched->getAttribute('committed_amount'));
            $budget->setAttribute('utilization_percent', $enriched->getAttribute('utilization_percent'));
        }

        return $this->success(
            data:    new BudgetResource($budget),
            message: 'Budget retrieved successfully.',
        );
    }

    // -------------------------------------------------------------------------
    // PUT /api/v1/budgets/{budget}
    // -------------------------------------------------------------------------

    /**
     * @OA\Put(path="/budgets/{budget}", operationId="updateBudget", tags={"Budgets"}, summary="Update budget allocation",
     *     description="Updates an existing budget's total_amount and/or currency for the active tenant. Roles: Finance_Officer.",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(ref="#/components/parameters/XTenantID"), @OA\Parameter(ref="#/components/parameters/XRequestID"),
     *     @OA\Parameter(name="budget", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *     @OA\RequestBody(required=true, @OA\JsonContent(@OA\Property(property="total_amount", type="string", example="600000.00"), @OA\Property(property="currency", type="string", example="USD"))),
     *     @OA\Response(response=200, description="Budget updated.", @OA\JsonContent(@OA\Property(property="success", type="boolean", example=true), @OA\Property(property="data", ref="#/components/schemas/BudgetResource"), @OA\Property(property="message", type="string"), @OA\Property(property="errors", nullable=true, example=null), @OA\Property(property="meta", nullable=true, example=null))),
     *     @OA\Response(response=422, description="Validation error.", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=404, description="Budget not found.", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     *
     * Update an existing budget (total_amount and/or currency).
     *
     * Roles: Finance_Officer only
     *
     * Requirements: 13.1
     */
    public function update(UpdateBudgetRequest $request, Budget $budget): JsonResponse
    {
        $actor = Auth::guard('api')->user();

        $data = $request->validated();

        if (isset($data['total_amount'])) {
            $data['total_amount'] = number_format((float) $data['total_amount'], 2, '.', '');
        }

        $result = $this->budgetService->allocateBudget(
            data: array_merge([
                'department_id' => $budget->department_id,
                'fiscal_year'   => $budget->fiscal_year,
                'total_amount'  => $data['total_amount'] ?? $budget->total_amount,
                'currency'      => $data['currency']      ?? $budget->currency,
            ]),
            actor:     $actor,
            ipAddress: $request->ip() ?? '0.0.0.0',
            requestId: $request->header('X-Request-ID'),
        );

        if (! $result['success']) {
            return $this->error(
                message: $result['message'],
                status:  $result['code'],
                errors:  $result['errors'] ?? null,
            );
        }

        return $this->success(
            data:    new BudgetResource($result['data']),
            message: $result['message'],
        );
    }

    // -------------------------------------------------------------------------
    // POST /api/v1/budgets/transfer
    // -------------------------------------------------------------------------

    /**
     * @OA\Post(path="/budgets/transfer", operationId="transferBudget", tags={"Budgets"}, summary="Transfer budget between departments",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(ref="#/components/parameters/XTenantID"), @OA\Parameter(ref="#/components/parameters/XRequestID"),
     *     @OA\RequestBody(required=true, @OA\JsonContent(required={"from_budget_id","to_budget_id","amount"}, @OA\Property(property="from_budget_id", type="string", format="uuid"), @OA\Property(property="to_budget_id", type="string", format="uuid"), @OA\Property(property="amount", type="string", example="25000.00"), @OA\Property(property="note", type="string", nullable=true, example="Emergency reallocation."))),
     *     @OA\Response(response=200, description="Transfer completed.", @OA\JsonContent(@OA\Property(property="success", type="boolean", example=true), @OA\Property(property="data", type="object", @OA\Property(property="source_budget", ref="#/components/schemas/BudgetResource"), @OA\Property(property="destination_budget", ref="#/components/schemas/BudgetResource"), @OA\Property(property="transferred_amount", type="string", example="25000.00")), @OA\Property(property="message", type="string"), @OA\Property(property="errors", nullable=true, example=null), @OA\Property(property="meta", nullable=true, example=null))),
     *     @OA\Response(response=422, description="Insufficient balance.", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     *
     * Transfer a monetary amount from one department's budget to another.
     *
     * Requirements: 13.8
     */
    public function transfer(TransferBudgetRequest $request): JsonResponse
    {
        $actor = Auth::guard('api')->user();

        // Resolve source and destination budgets — tenant scope auto-applied
        $sourceBudget      = Budget::find($request->input('from_budget_id'));
        $destinationBudget = Budget::find($request->input('to_budget_id'));

        if (! $sourceBudget) {
            return $this->error('Source budget not found.', 404, [
                'from_budget_id' => ['Source budget not found.'],
            ]);
        }

        if (! $destinationBudget) {
            return $this->error('Destination budget not found.', 404, [
                'to_budget_id' => ['Destination budget not found.'],
            ]);
        }

        $amount = number_format((float) $request->input('amount'), 2, '.', '');

        $result = $this->budgetService->transferBudget(
            sourceBudget:      $sourceBudget,
            destinationBudget: $destinationBudget,
            amount:            $amount,
            actor:             $actor,
            ipAddress:         $request->ip() ?? '0.0.0.0',
            requestId:         $request->header('X-Request-ID'),
        );

        if (! $result['success']) {
            return $this->error(
                message: $result['message'],
                status:  $result['code'],
                errors:  $result['errors'] ?? null,
            );
        }

        $transferData = $result['data'];

        return $this->success(
            data: [
                'source_budget'      => new BudgetResource($transferData['source_budget']),
                'destination_budget' => new BudgetResource($transferData['destination_budget']),
                'transferred_amount' => number_format((float) $transferData['transferred_amount'], 2, '.', ''),
                'reference_id'       => $transferData['reference_id'],
                'note'               => $request->input('note'),
            ],
            message: $result['message'],
        );
    }

    // -------------------------------------------------------------------------
    // GET /api/v1/budgets/utilization-report
    // -------------------------------------------------------------------------

    /**
     * @OA\Get(path="/budgets/utilization-report", operationId="budgetUtilizationReport", tags={"Budgets"}, summary="Budget utilization report",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(ref="#/components/parameters/XTenantID"), @OA\Parameter(ref="#/components/parameters/XRequestID"),
     *     @OA\Parameter(name="fiscal_year", in="query", required=false, @OA\Schema(type="integer")),
     *     @OA\Parameter(name="department_id", in="query", required=false, @OA\Schema(type="string", format="uuid")),
     *     @OA\Response(response=200, description="Utilization report.", @OA\JsonContent(@OA\Property(property="success", type="boolean", example=true), @OA\Property(property="data", type="object", @OA\Property(property="fiscal_year", type="integer"), @OA\Property(property="budgets", type="array", @OA\Items(ref="#/components/schemas/BudgetResource")), @OA\Property(property="summary", type="object")), @OA\Property(property="message", type="string"), @OA\Property(property="errors", nullable=true, example=null), @OA\Property(property="meta", nullable=true, example=null)))
     * )
     *
     * Return a per-department utilization report for the active tenant.
     *
     * Requirements: 13.10
     */
    public function utilizationReport(Request $request): JsonResponse
    {
        $fiscalYear   = $request->query('fiscal_year') ? (int) $request->query('fiscal_year') : (int) now()->year;
        $departmentId = $request->query('department_id');

        $budgets = $this->budgetService->getUtilizationReport(
            fiscalYear:   $fiscalYear,
            departmentId: $departmentId ?: null,
        );

        $budgets->load('department');

        return $this->success(
            data: [
                'fiscal_year' => $fiscalYear,
                'budgets'     => BudgetResource::collection($budgets),
                'summary'     => $this->buildUtilizationSummary($budgets),
            ],
            message: 'Utilization report retrieved successfully.',
        );
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Build a high-level summary from the enriched budget collection.
     *
     * @param  \Illuminate\Support\Collection  $budgets
     * @return array<string, string>
     */
    private function buildUtilizationSummary(\Illuminate\Support\Collection $budgets): array
    {
        $totalBudget    = '0.00';
        $totalSpent     = '0.00';
        $totalEncumbered = '0.00';
        $totalAvailable = '0.00';

        foreach ($budgets as $budget) {
            $totalBudget     = bcadd($totalBudget, (string) $budget->total_amount,     2);
            $totalSpent      = bcadd($totalSpent,      (string) $budget->spent_amount,      2);
            $totalEncumbered = bcadd($totalEncumbered, (string) $budget->encumbered_amount, 2);

            $available       = $budget->getAttribute('available_amount') ?? '0.00';
            $totalAvailable  = bcadd($totalAvailable, (string) $available, 2);
        }

        $overallUtilization = bccomp($totalBudget, '0.00', 2) > 0
            ? bcdiv(
                bcmul(bcadd($totalSpent, $totalEncumbered, 6), '100', 6),
                $totalBudget,
                2,
            )
            : '0.00';

        return [
            'total_budget'          => number_format((float) $totalBudget,    2, '.', ''),
            'total_spent'           => number_format((float) $totalSpent,     2, '.', ''),
            'total_encumbered'      => number_format((float) $totalEncumbered, 2, '.', ''),
            'total_available'       => number_format((float) $totalAvailable,  2, '.', ''),
            'overall_utilization'   => number_format((float) $overallUtilization, 2, '.', ''),
            'department_count'      => (string) $budgets->count(),
        ];
    }
}
