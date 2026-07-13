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
 * @OA\Tag(name="Payments", description="Payment tracking, schedule, and recording against invoices.")
 *
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
     * @OA\Get(path="/payments", operationId="listPayments", tags={"Payments"}, summary="List payments",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(ref="#/components/parameters/XTenantID"), @OA\Parameter(ref="#/components/parameters/XRequestID"),
     *     @OA\Parameter(name="invoice_id", in="query", required=false, @OA\Schema(type="string", format="uuid")),
     *     @OA\Parameter(name="status", in="query", required=false, @OA\Schema(type="string", enum={"pending","processed","failed"})),
     *     @OA\Parameter(name="per_page", in="query", required=false, @OA\Schema(type="integer", default=20)),
     *     @OA\Response(response=200, description="Payments list.", @OA\JsonContent(@OA\Property(property="success", type="boolean", example=true), @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/PaymentResource")), @OA\Property(property="message", type="string"), @OA\Property(property="errors", nullable=true, example=null), @OA\Property(property="meta", ref="#/components/schemas/PaginationMeta"))),
     *     @OA\Response(response=401, description="Unauthenticated.", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     *
     * Return a paginated list of payments.
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
     * @OA\Get(path="/payments/schedule", operationId="paymentSchedule", tags={"Payments"}, summary="Payment schedule",
     *     description="Returns approved and partially-paid invoices with amount_due and due_date. Roles: Finance_Officer.",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(ref="#/components/parameters/XTenantID"), @OA\Parameter(ref="#/components/parameters/XRequestID"),
     *     @OA\Parameter(name="supplier_id", in="query", required=false, @OA\Schema(type="string", format="uuid")),
     *     @OA\Parameter(name="date_from", in="query", required=false, @OA\Schema(type="string", format="date", example="2025-01-01")),
     *     @OA\Parameter(name="date_to", in="query", required=false, @OA\Schema(type="string", format="date", example="2025-12-31")),
     *     @OA\Response(response=200, description="Payment schedule.", @OA\JsonContent(@OA\Property(property="success", type="boolean", example=true), @OA\Property(property="data", type="array", @OA\Items(type="object")), @OA\Property(property="message", type="string"), @OA\Property(property="errors", nullable=true, example=null), @OA\Property(property="meta", nullable=true, example=null))),
     *     @OA\Response(response=401, description="Unauthenticated.", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     *
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
     * @OA\Get(path="/payments/{payment}", operationId="showPayment", tags={"Payments"}, summary="Get payment",
     *     description="Returns a single payment record with invoice and processor details.",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(ref="#/components/parameters/XTenantID"), @OA\Parameter(ref="#/components/parameters/XRequestID"),
     *     @OA\Parameter(name="payment", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *     @OA\Response(response=200, description="Payment details.", @OA\JsonContent(@OA\Property(property="success", type="boolean", example=true), @OA\Property(property="data", ref="#/components/schemas/PaymentResource"), @OA\Property(property="message", type="string"), @OA\Property(property="errors", nullable=true, example=null), @OA\Property(property="meta", nullable=true, example=null))),
     *     @OA\Response(response=404, description="Not found.", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     *
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
     * @OA\Post(path="/payments/{payment}/record", operationId="recordPayment", tags={"Payments"}, summary="Record payment against invoice",
     *     description="Records a full or partial payment. Updates invoice paid_amount and transitions payment/invoice status. Roles: Finance_Officer.",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(ref="#/components/parameters/XTenantID"), @OA\Parameter(ref="#/components/parameters/XRequestID"),
     *     @OA\Parameter(name="payment", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *     @OA\RequestBody(required=true, @OA\JsonContent(required={"amount","payment_method"}, @OA\Property(property="amount", type="string", example="48750.00"), @OA\Property(property="payment_method", type="string", example="bank_transfer"), @OA\Property(property="payment_reference", type="string", nullable=true, example="TXN-20250101-001"))),
     *     @OA\Response(response=200, description="Payment recorded.", @OA\JsonContent(@OA\Property(property="success", type="boolean", example=true), @OA\Property(property="data", ref="#/components/schemas/PaymentResource"), @OA\Property(property="message", type="string"), @OA\Property(property="errors", nullable=true, example=null), @OA\Property(property="meta", nullable=true, example=null))),
     *     @OA\Response(response=422, description="Payment exceeds outstanding amount.", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     *
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
