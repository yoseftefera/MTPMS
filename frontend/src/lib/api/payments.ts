/**
 * API client functions for Payment Management.
 *
 * Covers:
 *   - Finance_Officer: list payments, get detail, record payment
 *   - All: payment schedule
 *
 * Validates: Requirements 14.5, 14.6, 14.7, 14.8, 22.6
 */

import { apiGet, apiPost } from "@/lib/api/client"
import type { ApiResponse, PaginatedResponse } from "@/types/api.types"
import type {
  PaymentDetail,
  PaymentFilters,
  PaymentScheduleEntry,
  RecordPaymentData,
} from "@/types/invoice"

// ─── List ─────────────────────────────────────────────────────────────────────

/**
 * Paginated + filterable list of payments.
 */
export async function getPayments(
  params?: PaymentFilters,
): Promise<PaginatedResponse<PaymentDetail>> {
  return apiGet<PaginatedResponse<PaymentDetail>>("/payments", { params })
}

// ─── Detail ───────────────────────────────────────────────────────────────────

/**
 * Single payment detail with invoice information.
 */
export async function getPayment(
  id: string,
): Promise<ApiResponse<PaymentDetail>> {
  return apiGet<ApiResponse<PaymentDetail>>(`/payments/${id}`)
}

// ─── Schedule ─────────────────────────────────────────────────────────────────

/**
 * Payment schedule — upcoming invoices due for payment.
 */
export async function getPaymentSchedule(): Promise<
  ApiResponse<PaymentScheduleEntry[]>
> {
  return apiGet<ApiResponse<PaymentScheduleEntry[]>>("/payments/schedule")
}

// ─── Record payment ───────────────────────────────────────────────────────────

/**
 * Finance_Officer records a payment against a payment record.
 */
export async function recordPayment(
  id: string,
  payload: RecordPaymentData,
): Promise<ApiResponse<PaymentDetail>> {
  return apiPost<ApiResponse<PaymentDetail>>(`/payments/${id}/record`, payload)
}
