/**
 * API client functions for Tender & Bidding Management.
 *
 * Covers:
 *   - Procurement_Officer: CRUD tenders, attach documents, publish, cancel,
 *     extend deadline, list bids per tender
 *   - Supplier: list open tenders, get tender detail, submit/revise bid,
 *     attach bid documents
 *
 * Validates: Requirements 8.1, 8.3, 22.6
 */

import apiClient, { apiGet, apiPost, apiPatch, apiPut } from '@/lib/api/client';
import type { ApiResponse, PaginatedResponse } from '@/types/api.types';
import type {
  TenderDetail,
  OpenTender,
  BidSummary,
  TenderFilters,
  CreateTenderData,
  UpdateTenderData,
  SubmitBidData,
  UpdateBidData,
  TenderDocument,
  BidDocument,
} from '@/types/tender';

// ─── Tenders ─────────────────────────────────────────────────────────────────

/**
 * Paginated + filterable list of tenders (Procurement_Officer / Tenant_Admin view).
 */
export async function getTenders(
  params?: TenderFilters,
): Promise<PaginatedResponse<TenderDetail>> {
  return apiGet<PaginatedResponse<TenderDetail>>('/tenders', { params });
}

/**
 * Single tender detail including documents and bids (officer view).
 * The backend only returns bid amounts/scores to Procurement_Officer / Tenant_Admin / Committee_Member.
 */
export async function getTender(id: string): Promise<ApiResponse<TenderDetail>> {
  return apiGet<ApiResponse<TenderDetail>>(`/tenders/${id}`, {
    params: { include: 'documents,bids,bids.supplier,creator' },
  });
}

/**
 * Create a new tender (draft).
 */
export async function createTender(
  payload: CreateTenderData,
): Promise<ApiResponse<TenderDetail>> {
  return apiPost<ApiResponse<TenderDetail>>('/tenders', payload);
}

/**
 * Update a draft or published tender.
 */
export async function updateTender(
  id: string,
  payload: UpdateTenderData,
): Promise<ApiResponse<TenderDetail>> {
  return apiPut<ApiResponse<TenderDetail>>(`/tenders/${id}`, payload);
}

/**
 * Publish a draft tender (notifies suppliers in category).
 */
export async function publishTender(id: string): Promise<ApiResponse<TenderDetail>> {
  return apiPost<ApiResponse<TenderDetail>>(`/tenders/${id}/publish`);
}

/**
 * Cancel a tender with a documented reason.
 */
export async function cancelTender(
  id: string,
  reason: string,
): Promise<ApiResponse<TenderDetail>> {
  return apiPost<ApiResponse<TenderDetail>>(`/tenders/${id}/cancel`, { reason });
}

/**
 * Extend tender submission deadline (before original deadline).
 */
export async function extendTenderDeadline(
  id: string,
  newDeadline: string,
): Promise<ApiResponse<TenderDetail>> {
  return apiPatch<ApiResponse<TenderDetail>>(`/tenders/${id}/extend-deadline`, {
    submission_deadline: newDeadline,
  });
}

/**
 * Upload a specification / supporting document to a tender.
 */
export async function uploadTenderDocument(
  tenderId: string,
  file: File,
): Promise<ApiResponse<TenderDocument>> {
  const formData = new FormData();
  formData.append('file', file);

  const response = await apiClient.post<ApiResponse<TenderDocument>>(
    `/tenders/${tenderId}/documents`,
    formData,
    { headers: { 'Content-Type': 'multipart/form-data' } },
  );
  return response.data;
}

// ─── Open tenders (supplier-facing) ──────────────────────────────────────────

/**
 * List published tenders open to the current supplier's category.
 * Returns only status=published tenders.
 */
export async function getOpenTenders(params?: {
  page?: number;
  per_page?: number;
  search?: string;
  category?: string;
}): Promise<PaginatedResponse<OpenTender>> {
  return apiGet<PaginatedResponse<OpenTender>>('/tenders/open', { params });
}

/**
 * Single open tender detail for the supplier view.
 * Includes tender documents but NOT other suppliers' bids.
 */
export async function getOpenTender(id: string): Promise<ApiResponse<OpenTender>> {
  return apiGet<ApiResponse<OpenTender>>(`/tenders/${id}/open`, {
    params: { include: 'documents,my_bid' },
  });
}

// ─── Bids ─────────────────────────────────────────────────────────────────────

/**
 * List bids for a specific tender (Procurement_Officer / Tenant_Admin / Committee_Member).
 */
export async function getTenderBids(
  tenderId: string,
  params?: { page?: number; per_page?: number },
): Promise<PaginatedResponse<BidSummary>> {
  return apiGet<PaginatedResponse<BidSummary>>(`/tenders/${tenderId}/bids`, { params });
}

/**
 * Submit a new bid for a tender (Supplier).
 */
export async function submitBid(
  tenderId: string,
  payload: SubmitBidData,
): Promise<ApiResponse<BidSummary>> {
  return apiPost<ApiResponse<BidSummary>>(`/tenders/${tenderId}/bids`, payload);
}

/**
 * Revise an existing bid before deadline (Supplier).
 */
export async function updateBid(
  tenderId: string,
  bidId: string,
  payload: UpdateBidData,
): Promise<ApiResponse<BidSummary>> {
  return apiPut<ApiResponse<BidSummary>>(`/tenders/${tenderId}/bids/${bidId}`, payload);
}

/**
 * Upload a supporting document to an existing bid (Supplier).
 */
export async function uploadBidDocument(
  tenderId: string,
  bidId: string,
  file: File,
): Promise<ApiResponse<BidDocument>> {
  const formData = new FormData();
  formData.append('file', file);

  const response = await apiClient.post<ApiResponse<BidDocument>>(
    `/tenders/${tenderId}/bids/${bidId}/documents`,
    formData,
    { headers: { 'Content-Type': 'multipart/form-data' } },
  );
  return response.data;
}
