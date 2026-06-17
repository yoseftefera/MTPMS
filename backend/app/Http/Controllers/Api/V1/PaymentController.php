<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Payment\RecordPaymentRequest;
use App\Http\Resources\V1\PaymentResource;
use App\Models\Payment;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;

/**
 * PaymentController — thin controller for the payment lifecycle.
 *
 * Endpoints:
 *   GET  /api/v1/payments                       — paginated list of payments
 *   GET  /api/v1/payments/schedule              — payment schedule for approved/partially_paid invoices
 *   GET  /api/v1/payments/{payment}             — single payment with invoice details
 *   POST /api/v1/payments/{payment}/record      — record a payment (full or partial)
 *
 * All queries are automatically scoped to the active tenant via HasTenantScope.
 *
 * Requirements: 14.5, 14.6, 14.7, 14.8, 14.9
 */
class PaymentController extends Controller
{
    public function __construct(private readonly PaymentService $service) {}

    // -------------------------------------------------------------------------
    // GET /api/v1/payments
    // -------------------------------------------------------------------------

    /**
     * Return a paginated list of payments.
     *
     * Query parameters:
     *   invoice_id — filter by invoice UUID
     *   status     — filter by payment status
     *   per_page   — results per page (default 20, max 100)
     *
     * Requirements: 14.5
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = min((int) $request->query('per_page', 20), 100);

        $query = Payment::with(['invoice', 'processedBy']);

        if ($invoiceId = $request->query('invoice_id')) {
            $query->where('invoice_id', $invoiceId);
        }

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        $paginator = $query->orderBy('created_at', 'desc')->paginate($perPage);

        return $this->paginated(
            paginator: $paginator,
            data:      PaymentResource::collection($paginator->items()),
            message:   'Payments retrieved successfully.',
        );
    }

    // -------------------------------------------------------------------------
    // GET /api/v1/payments/schedule
    // -------------------------------------------------------------------------

    /**
     * Return the payment schedule: approved and partially_paid invoices with
     * amount_due and due_date.
     *
     * Query parameters:
     *   supplier_id — filter by supplier UUID
     *   date_from   — filter due_date >= date (Y-m-d)
     *   date_to     — filter due_date <= date (Y-m-d)
     *
     * Requirements: 14.7
     */
    public function schedule(Request $request): JsonResponse
    {
        $filters = array_filter([
            'supplier_id' => $request->query('supplier_id'),
            'date_from'   => $request->query('date_from'),
            'date_to'     => $request->query('date_to'),
        ], fn ($v) => $v !== null && $v !== '');

        $schedule = $this->service->getPaymentSchedule($filters);

        return $this->success(
            data:    $schedule,
            message: 'Payment schedule retrieved successfully.',
        );
    }

    // -------------------------------------------------------------------------
    // GET /api/v1/payments/{payment}
    // -------------------------------------------------------------------------

    /**
     * Return a single payment with invoice and processor details.
     *
     * Requirements: 14.5
     */
    public function show(Payment $payment): JsonResponse
    {
        $payment->load(['invoice.supplier', 'processedBy']);

        return $this->success(
            data:    new PaymentResource($payment),
            message: 'Payment retrieved successfully.',
        );
    }

    // -------------------------------------------------------------------------
    // POST /api/v1/payments/{payment}/record
    // -------------------------------------------------------------------------

    /**
     * Record a payment (full or partial) against the invoice.
     *
     * Updates invoice paid_amount and transitions invoice/payment status.
     *
     * Roles: Finance_Officer (via payments.process permission)
     *
     * Requirements: 14.6, 14.8, 14.9
     */
    public function record(RecordPaymentRequest $request, Payment $payment): JsonResponse
    {
        $user      = Auth::guard('api')->user();
        $validated = $request->validated();

        try {
            $payment = $this->service->recordPayment(
                payment:          $payment,
                amountPaid:       (string) $validated['amount'],
                paymentMethod:    $validated['payment_method'],
                paymentReference: $validated['payment_reference'] ?? null,
                actor:            $user,
            );
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422, ['general' => [$e->getMessage()]]);
        }

        return $this->success(
            data:    new PaymentResource($payment->load(['invoice.supplier', 'processedBy'])),
            message: 'Payment recorded successfully.',
        );
    }
}
