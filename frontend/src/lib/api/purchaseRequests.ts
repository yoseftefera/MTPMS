/**
 * API client functions for Purchase Request Management.
 *
 * Covers: list, single, create, update, submit, cancel, attach document.
 *
 * Validates: Requirements 5.2, 5.5, 5.7, 5.8, 22.6
 */

import apiClient, { apiGet, apiPost, apiPut } from "@/lib/api/client"
import type { ApiResponse, PaginatedResponse } from "@/types/api.types"
import type {
  PurchaseRequest,
  CreatePRData,
  UpdatePRData,
  PRFilters,
} from "@/types/purchaseRequest"

// ─── Read ─────────────────────────────────────────────────────────────────────

/**
 * Fetch a paginated, filterable list of purchase requests.
 */
export async function getPurchaseRequests(
  filters?: PRFilters,
): Promise<PaginatedResponse<PurchaseRequest>> {
  return apiGet<PaginatedResponse<PurchaseRequest>>("/purchase-requests", {
    params: filters,
  })
}

/**
 * Fetch a single purchase request (includes items + history).
 */
export async function getPurchaseRequest(
  id: string,
): Promise<ApiResponse<PurchaseRequest>> {
  return apiGet<ApiResponse<PurchaseRequest>>(`/purchase-requests/${id}`, {
    params: { include: "items,history,documents,department,submitter" },
  })
}

// ─── Mutations ────────────────────────────────────────────────────────────────

/**
 * Create a new draft purchase request.
 */
export async function createPurchaseRequest(
  payload: CreatePRData,
): Promise<ApiResponse<PurchaseRequest>> {
  return apiPost<ApiResponse<PurchaseRequest>>("/purchase-requests", payload)
}

/**
 * Update an existing purchase request (must be in draft or revision_required).
 */
export async function updatePurchaseRequest(
  id: string,
  payload: UpdatePRData,
): Promise<ApiResponse<PurchaseRequest>> {
  return apiPut<ApiResponse<PurchaseRequest>>(`/purchase-requests/${id}`, payload)
}

/**
 * Submit a draft PR for approval.
 */
export async function submitPurchaseRequest(
  id: string,
): Promise<ApiResponse<PurchaseRequest>> {
  return apiPost<ApiResponse<PurchaseRequest>>(`/purchase-requests/${id}/submit`)
}

/**
 * Cancel a purchase request.
 */
export async function cancelPurchaseRequest(
  id: string,
  reason?: string,
): Promise<ApiResponse<PurchaseRequest>> {
  return apiPost<ApiResponse<PurchaseRequest>>(`/purchase-requests/${id}/cancel`, {
    reason,
  })
}

/**
 * Upload a document attachment to a purchase request (multipart/form-data).
 */
export async function attachDocument(
  id: string,
  file: File,
): Promise<ApiResponse<{ id: string; file_name: string; file_path: string }>> {
  const formData = new FormData()
  formData.append("file", file)

  const response = await apiClient.post<
    ApiResponse<{ id: string; file_name: string; file_path: string }>
  >(`/purchase-requests/${id}/documents`, formData, {
    headers: { "Content-Type": "multipart/form-data" },
  })
  return response.data
}
