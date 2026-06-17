/**
 * API client functions for Purchase Order Management.
 *
 * Covers:
 *   - Procurement_Officer / Tenant_Admin: list POs, create, get detail,
 *     issue, amend, cancel
 *   - Supplier: accept, reject (with reason)
 *
 * Validates: Requirements 10.2, 10.9, 22.6
 */

import { apiGet, apiPost, apiPut } from "@/lib/api/client"
import type { ApiResponse, PaginatedResponse } from "@/types/api.types"
import type {
  PurchaseOrderDetail,
  POFilters,
  CreatePOData,
  AmendPOData,
} from "@/types/purchaseOrder"

// ─── List ─────────────────────────────────────────────────────────────────────

/**
 * Paginated + filterable list of purchase orders.
 */
export async function getPurchaseOrders(
  params?: POFilters,
): Promise<PaginatedResponse<PurchaseOrderDetail>> {
  return apiGet<PaginatedResponse<PurchaseOrderDetail>>("/purchase-orders", {
    params,
  })
}

// ─── Detail ───────────────────────────────────────────────────────────────────

/**
 * Single PO with line items, supplier, department, history.
 */
export async function getPurchaseOrder(
  id: string,
): Promise<ApiResponse<PurchaseOrderDetail>> {
  return apiGet<ApiResponse<PurchaseOrderDetail>>(`/purchase-orders/${id}`, {
    params: { include: "items,supplier,department,history,creator" },
  })
}

// ─── Create ───────────────────────────────────────────────────────────────────

/**
 * Create a new draft purchase order.
 */
export async function createPurchaseOrder(
  payload: CreatePOData,
): Promise<ApiResponse<PurchaseOrderDetail>> {
  return apiPost<ApiResponse<PurchaseOrderDetail>>("/purchase-orders", payload)
}

// ─── Amend ────────────────────────────────────────────────────────────────────

/**
 * Amend an existing PO (delivery address, date, notes, line items).
 * Post-acceptance amendments require supplier acknowledgment.
 */
export async function amendPurchaseOrder(
  id: string,
  payload: AmendPOData,
): Promise<ApiResponse<PurchaseOrderDetail>> {
  return apiPut<ApiResponse<PurchaseOrderDetail>>(`/purchase-orders/${id}`, payload)
}

// ─── Status transitions ───────────────────────────────────────────────────────

/**
 * Issue a draft PO to the supplier.
 */
export async function issuePurchaseOrder(
  id: string,
): Promise<ApiResponse<PurchaseOrderDetail>> {
  return apiPost<ApiResponse<PurchaseOrderDetail>>(`/purchase-orders/${id}/issue`)
}

/**
 * Supplier accepts the PO.
 */
export async function acceptPurchaseOrder(
  id: string,
): Promise<ApiResponse<PurchaseOrderDetail>> {
  return apiPost<ApiResponse<PurchaseOrderDetail>>(`/purchase-orders/${id}/accept`)
}

/**
 * Supplier rejects the PO with a mandatory reason.
 */
export async function rejectPurchaseOrder(
  id: string,
  reason: string,
): Promise<ApiResponse<PurchaseOrderDetail>> {
  return apiPost<ApiResponse<PurchaseOrderDetail>>(`/purchase-orders/${id}/reject`, {
    reason,
  })
}

/**
 * Procurement_Officer cancels the PO with a mandatory reason.
 */
export async function cancelPurchaseOrder(
  id: string,
  reason: string,
): Promise<ApiResponse<PurchaseOrderDetail>> {
  return apiPost<ApiResponse<PurchaseOrderDetail>>(`/purchase-orders/${id}/cancel`, {
    reason,
  })
}
