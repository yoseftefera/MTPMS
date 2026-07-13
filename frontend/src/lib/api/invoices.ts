/**
 * API client functions for Invoice and Payment Processing.
 *
 * Covers:
 *   - Supplier: create invoice
 *   - Finance_Officer: list, get detail, approve, reject
 *   - All: paginated list with filters
 *
 * Validates: Requirements 14.1, 14.10, 22.6
 */

import { apiGet, apiPost } from "@/lib/api/client"
import type { ApiResponse, PaginatedResponse } from "@/types/api.types"
import type {
  InvoiceDetail,
  InvoiceFilters,
  CreateInvoiceData,
} from "@/types/invoice"

// ─── List ─────────────────────────────────────────────────────────────────────

/**
 * Paginated + filterable list of invoices.
 */
export async function getInvoices(
  params?: InvoiceFilters,
): Promise<PaginatedResponse<InvoiceDetail>> {
  return apiGet<PaginatedResponse<InvoiceDetail>>("/invoices", { params })
}

// ─── Detail ───────────────────────────────────────────────────────────────────

/**
 * Single invoice with line items, PO/contract reference, approval history.
 */
export async function getInvoice(
  id: string,
): Promise<ApiResponse<InvoiceDetail>> {
  return apiGet<ApiResponse<InvoiceDetail>>(`/invoices/${id}`)
}

// ─── Create ───────────────────────────────────────────────────────────────────

/**
 * Supplier submits a new invoice.
 */
export async function createInvoice(
  payload: CreateInvoiceData,
): Promise<ApiResponse<InvoiceDetail>> {
  return apiPost<ApiResponse<InvoiceDetail>>("/invoices", payload)
}

// ─── Approve ──────────────────────────────────────────────────────────────────

/**
 * Finance_Officer approves a pending_approval invoice.
 */
export async function approveInvoice(
  id: string,
): Promise<ApiResponse<InvoiceDetail>> {
  return apiPost<ApiResponse<InvoiceDetail>>(`/invoices/${id}/approve`)
}

// ─── Reject ───────────────────────────────────────────────────────────────────

/**
 * Finance_Officer rejects a pending_approval invoice with a mandatory reason.
 */
export async function rejectInvoice(
  id: string,
  reason: string,
): Promise<ApiResponse<InvoiceDetail>> {
  return apiPost<ApiResponse<InvoiceDetail>>(`/invoices/${id}/reject`, {
    reason,
  })
}
