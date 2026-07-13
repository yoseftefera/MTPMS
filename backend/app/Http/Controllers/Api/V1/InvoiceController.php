<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Invoice\RejectInvoiceRequest;
use App\Http\Requests\V1\Invoice\StoreInvoiceRequest;
use App\Http\Resources\V1\InvoiceResource;
use App\Models\Invoice;
use App\Services\InvoiceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;

/**
 * @OA\Tag(name="Invoices", description="Supplier invoice submission, validation, approval, and rejection.")
 *
 * InvoiceController — thin controller for the invoice lifecycle.
 *
 * Endpoints:
 *   GET    /api/v1/invoices                        — paginated list with filters
 *   POST   /api/v1/invoices                        — submit new invoice
 *   GET    /api/v1/invoices/{invoice}              — single invoice with details
 *   POST   /api/v1/invoices/{invoice}/approve      — approve invoice
 *   POST   /api/v1/invoices/{invoice}/reject       — reject invoice with reason
 *
 * All queries are automatically scoped to the active tenant via HasTenantScope.
 *
 * Requirements: 14.1, 14.2, 14.3, 14.4
 */
class InvoiceController extends Controller
{
    public function __construct(private readonly InvoiceService $service) {}

    // -------------------------------------------------------------------------
    // GET /api/v1/invoices
    // -------------------------------------------------------------------------

    /**
     * @OA\Get(path="/invoices", operationId="listInvoices", tags={"Invoices"}, summary="List invoices",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(ref="#/components/parameters/XTenantID"), @OA\Parameter(ref="#/components/parameters/XRequestID"),
     *     @OA\Parameter(name="status", in="query", required=false, @OA\Schema(type="string", enum={"submitted","under_review","approved","rejected","partially_paid","paid"})),
     *     @OA\Parameter(name="supplier_id", in="query", required=false, @OA\Schema(type="string", format="uuid")),
     *     @OA\Parameter(name="date_from", in="query", required=false, @OA\Schema(type="string", format="date")),
     *     @OA\Parameter(name="date_to", in="query", required=false, @OA\Schema(type="string", format="date")),
     *     @OA\Parameter(name="per_page", in="query", required=false, @OA\Schema(type="integer", default=20)),
     *     @OA\Response(response=200, description="Invoices list.", @OA\JsonContent(@OA\Property(property="success", type="boolean", example=true), @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/InvoiceResource")), @OA\Property(property="message", type="string"), @OA\Property(property="errors", nullable=true, example=null), @OA\Property(property="meta", ref="#/components/schemas/PaginationMeta"))),
     *     @OA\Response(response=401, description="Unauthenticated.", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     *
     * Return a paginated list of invoices with optional filters.
     *
     * Requirements: 14.1
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = min((int) $request->query('per_page', 20), 100);

        $filters = array_filter([
            'status'      => $request->query('status'),
            'supplier_id' => $request->query('supplier_id'),
            'date_from'   => $request->query('date_from'),
            'date_to'     => $request->query('date_to'),
        ], fn ($v) => $v !== null && $v !== '');

        $paginator = $this->service->search($filters, $perPage);

        return $this->paginated(
            paginator: $paginator,
            data:      InvoiceResource::collection($paginator->items()),
            message:   'Invoices retrieved successfully.',
        );
    }

    // -------------------------------------------------------------------------
    // POST /api/v1/invoices
    // -------------------------------------------------------------------------

    /**
     * @OA\Post(path="/invoices", operationId="submitInvoice", tags={"Invoices"}, summary="Submit invoice",
     *     description="Supplier submits invoice referencing a PO or contract. Validates amount does not exceed PO/contract value and goods have been received.",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(ref="#/components/parameters/XTenantID"), @OA\Parameter(ref="#/components/parameters/XRequestID"),
     *     @OA\RequestBody(required=true, @OA\JsonContent(required={"invoice_number","supplier_id","total_amount","items"}, @OA\Property(property="invoice_number", type="string", example="INV-2025-001"), @OA\Property(property="supplier_id", type="string", format="uuid"), @OA\Property(property="purchase_order_id", type="string", format="uuid", nullable=true), @OA\Property(property="contract_id", type="string", format="uuid", nullable=true), @OA\Property(property="total_amount", type="string", example="48750.00"), @OA\Property(property="currency", type="string", example="USD"), @OA\Property(property="due_date", type="string", format="date", nullable=true), @OA\Property(property="items", type="array", @OA\Items(@OA\Property(property="description", type="string"), @OA\Property(property="quantity", type="number"), @OA\Property(property="unit_price", type="number"), @OA\Property(property="total_price", type="number"))))),
     *     @OA\Response(response=201, description="Invoice submitted.", @OA\JsonContent(@OA\Property(property="success", type="boolean", example=true), @OA\Property(property="data", ref="#/components/schemas/InvoiceResource"), @OA\Property(property="message", type="string"), @OA\Property(property="errors", nullable=true, example=null), @OA\Property(property="meta", nullable=true, example=null))),
     *     @OA\Response(response=422, description="Amount exceeds PO/contract value or goods not received.", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     *
     * Submit a new invoice for approval.
     *
     * Requirements: 14.1, 14.2, 14.3
     */
    public function store(StoreInvoiceRequest $request): JsonResponse
    {
        $user = Auth::guard('api')->user();

        try {
            $invoice = $this->service->submit($request->validated(), $user);
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422, ['general' => [$e->getMessage()]]);
        }

        return $this->created(
            data:    new InvoiceResource($invoice),
            message: 'Invoice submitted successfully.',
        );
    }

    // -------------------------------------------------------------------------
    // GET /api/v1/invoices/{invoice}
    // -------------------------------------------------------------------------

    /**
     * @OA\Get(path="/invoices/{invoice}", operationId="showInvoice", tags={"Invoices"}, summary="Get invoice",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(ref="#/components/parameters/XTenantID"), @OA\Parameter(ref="#/components/parameters/XRequestID"),
     *     @OA\Parameter(name="invoice", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *     @OA\Response(response=200, description="Invoice with related data.", @OA\JsonContent(@OA\Property(property="success", type="boolean", example=true), @OA\Property(property="data", ref="#/components/schemas/InvoiceResource"), @OA\Property(property="message", type="string"), @OA\Property(property="errors", nullable=true, example=null), @OA\Property(property="meta", nullable=true, example=null))),
     *     @OA\Response(response=404, description="Not found.", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     *
     * Return a single invoice with all related data.
     *
     * Requirements: 14.1
     */
    public function show(Invoice $invoice): JsonResponse
    {
        $invoice->load(['supplier', 'purchaseOrder', 'contract', 'payments']);

        return $this->success(
            data:    new InvoiceResource($invoice),
            message: 'Invoice retrieved successfully.',
        );
    }

    // -------------------------------------------------------------------------
    // POST /api/v1/invoices/{invoice}/approve
    // -------------------------------------------------------------------------

    /**
     * @OA\Post(path="/invoices/{invoice}/approve", operationId="approveInvoice", tags={"Invoices"}, summary="Approve invoice",
     *     description="Finance_Officer approves invoice. Creates corresponding payment record.",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(ref="#/components/parameters/XTenantID"), @OA\Parameter(ref="#/components/parameters/XRequestID"),
     *     @OA\Parameter(name="invoice", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *     @OA\Response(response=200, description="Invoice approved.", @OA\JsonContent(@OA\Property(property="success", type="boolean", example=true), @OA\Property(property="data", ref="#/components/schemas/InvoiceResource"), @OA\Property(property="message", type="string"), @OA\Property(property="errors", nullable=true, example=null), @OA\Property(property="meta", nullable=true, example=null))),
     *     @OA\Response(response=422, description="Invoice not in correct state.", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     *
     * Approve an invoice and create the corresponding payment record.
     *
     * Requirements: 14.4, 14.5
     */
    public function approve(Invoice $invoice): JsonResponse
    {
        $user = Auth::guard('api')->user();

        try {
            $this->service->approve($invoice, $user);
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422, ['general' => [$e->getMessage()]]);
        }

        return $this->success(
            data:    new InvoiceResource(
                $invoice->fresh(['supplier', 'purchaseOrder', 'contract', 'payments'])
            ),
            message: 'Invoice approved successfully.',
        );
    }

    // -------------------------------------------------------------------------
    // POST /api/v1/invoices/{invoice}/reject
    // -------------------------------------------------------------------------

    /**
     * @OA\Post(path="/invoices/{invoice}/reject", operationId="rejectInvoice", tags={"Invoices"}, summary="Reject invoice",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(ref="#/components/parameters/XTenantID"), @OA\Parameter(ref="#/components/parameters/XRequestID"),
     *     @OA\Parameter(name="invoice", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *     @OA\RequestBody(required=true, @OA\JsonContent(required={"reason"}, @OA\Property(property="reason", type="string", example="Invoice amount does not match PO value."))),
     *     @OA\Response(response=200, description="Invoice rejected.", @OA\JsonContent(@OA\Property(property="success", type="boolean", example=true), @OA\Property(property="data", ref="#/components/schemas/InvoiceResource"), @OA\Property(property="message", type="string"), @OA\Property(property="errors", nullable=true, example=null), @OA\Property(property="meta", nullable=true, example=null))),
     *     @OA\Response(response=422, description="Invoice not in correct state.", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     *
     * Reject an invoice with a documented reason.
     *
     * Requirements: 14.4
     */
    public function reject(RejectInvoiceRequest $request, Invoice $invoice): JsonResponse
    {
        $user   = Auth::guard('api')->user();
        $reason = $request->validated()['reason'];

        try {
            $this->service->reject($invoice, $reason, $user);
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422, ['general' => [$e->getMessage()]]);
        }

        return $this->success(
            data:    new InvoiceResource(
                $invoice->fresh(['supplier', 'purchaseOrder', 'contract'])
            ),
            message: 'Invoice rejected successfully.',
        );
    }

    // -------------------------------------------------------------------------
    // Unsupported standard resource methods
    // -------------------------------------------------------------------------

    public function update(Request $request, Invoice $invoice): JsonResponse
    {
        return $this->error(
            'Direct update is not supported. Use the approve or reject endpoints.',
            405,
        );
    }

    public function destroy(Invoice $invoice): JsonResponse
    {
        return $this->error('Invoices cannot be deleted.', 405);
    }
}
