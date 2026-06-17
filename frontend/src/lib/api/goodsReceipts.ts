/**
 * API client functions for Goods Receipt and Inventory Management.
 *
 * Covers:
 *   - Store_Manager: list GRNs, create, get detail, assign committee
 *   - Committee_Member: submit inspection result
 *   - All: inventory list + detail
 *
 * Validates: Requirements 12.1, 12.8, 22.6
 */

import { apiGet, apiPost } from "@/lib/api/client"
import type { ApiResponse, PaginatedResponse } from "@/types/api.types"
import type {
  GoodsReceiptDetail,
  GRNFilters,
  CreateGRNData,
  AssignCommitteeData,
  InspectionResultData,
  InventoryItem,
  InventoryFilters,
  POLookupResult,
} from "@/types/goodsReceipt"
import type { PaginatedResponse as PR2 } from "@/types/api.types"

// ─── Goods Receipts ───────────────────────────────────────────────────────────

/**
 * Paginated + filterable list of goods receipts.
 */
export async function getGoodsReceipts(
  params?: GRNFilters,
): Promise<PaginatedResponse<GoodsReceiptDetail>> {
  return apiGet<PaginatedResponse<GoodsReceiptDetail>>("/goods-receipts", {
    params,
  })
}

/**
 * Single goods receipt with items, PO, committee members.
 */
export async function getGoodsReceipt(
  id: string,
): Promise<ApiResponse<GoodsReceiptDetail>> {
  return apiGet<ApiResponse<GoodsReceiptDetail>>(`/goods-receipts/${id}`)
}

/**
 * Create a new goods receipt.
 */
export async function createGoodsReceipt(
  payload: CreateGRNData,
): Promise<ApiResponse<GoodsReceiptDetail>> {
  return apiPost<ApiResponse<GoodsReceiptDetail>>("/goods-receipts", payload)
}

/**
 * Assign committee members to a goods receipt (Store_Manager, pending_inspection).
 */
export async function assignGRNCommittee(
  id: string,
  payload: AssignCommitteeData,
): Promise<ApiResponse<GoodsReceiptDetail>> {
  return apiPost<ApiResponse<GoodsReceiptDetail>>(
    `/goods-receipts/${id}/assign-committee`,
    payload,
  )
}

/**
 * Submit inspection results (Committee_Member, under_inspection).
 */
export async function submitInspectionResult(
  id: string,
  payload: InspectionResultData,
): Promise<ApiResponse<GoodsReceiptDetail>> {
  return apiPost<ApiResponse<GoodsReceiptDetail>>(
    `/goods-receipts/${id}/inspection-result`,
    payload,
  )
}

// ─── PO lookup (for create GRN form) ─────────────────────────────────────────

/**
 * Search purchase orders by PO number to populate the create GRN form.
 */
export async function lookupPurchaseOrderByNumber(
  poNumber: string,
): Promise<PaginatedResponse<POLookupResult>> {
  return apiGet<PaginatedResponse<POLookupResult>>("/purchase-orders", {
    params: { po_number: poNumber, per_page: 10 },
  })
}

// ─── Inventory ────────────────────────────────────────────────────────────────

/**
 * Paginated + filterable inventory list.
 */
export async function getInventory(
  params?: InventoryFilters,
): Promise<PaginatedResponse<InventoryItem>> {
  return apiGet<PaginatedResponse<InventoryItem>>("/inventory", { params })
}

/**
 * Single inventory item detail.
 */
export async function getInventoryItem(
  id: string,
): Promise<ApiResponse<InventoryItem>> {
  return apiGet<ApiResponse<InventoryItem>>(`/inventory/${id}`)
}
