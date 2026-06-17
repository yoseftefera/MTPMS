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
     * Return a paginated list of invoices with optional filters.
     *
     * Query parameters:
     *   status      — filter by invoice status
     *   supplier_id — filter by supplier UUID
     *   date_from   — filter invoice_date >= date (Y-m-d)
     *   date_to     — filter invoice_date <= date (Y-m-d)
     *   per_page    — results per page (default 20, max 100)
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
     * Submit a new invoice for approval.
     *
     * Roles: Supplier (via invoices.submit permission)
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
     * Approve an invoice and create the corresponding payment record.
     *
     * Roles: Finance_Officer (via invoices.approve permission)
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
     * Reject an invoice with a documented reason.
     *
     * Roles: Finance_Officer (via invoices.approve permission)
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
