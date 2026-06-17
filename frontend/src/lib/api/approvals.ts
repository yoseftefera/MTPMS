/**
 * API client functions for the Approvals / Pending Approvals workflow.
 *
 * Covers: list pending approvals, approval history,
 * approve / reject / return-for-revision actions.
 *
 * All functions consume the standard ApiResponse<T> envelope.
 *
 * Validates: Requirements 22.5, 22.6
 */

import { apiGet, apiPost } from '@/lib/api/client';
import type { ApiResponse, PaginatedResponse, ListQueryParams } from '@/types/api.types';
import type { Approval } from '@/types/models.types';

// ─── Query params ─────────────────────────────────────────────────────────────

export interface PendingApprovalsQueryParams extends ListQueryParams {
  document_type?: string;
}

// ─── Request payloads ─────────────────────────────────────────────────────────

export interface ApprovePayload {
  comment?: string;
}

export interface RejectPayload {
  reason: string;
}

export interface ReturnPayload {
  comments: string;
}

// ─── API functions ────────────────────────────────────────────────────────────

/**
 * Fetch pending approvals for the current user.
 */
export async function getPendingApprovals(
  params?: PendingApprovalsQueryParams,
): Promise<PaginatedResponse<Approval>> {
  return apiGet<PaginatedResponse<Approval>>('/approvals/pending', { params });
}

/**
 * Fetch the approval history for a specific document.
 */
export async function getApprovalHistory(
  documentType: string,
  documentId: string,
): Promise<ApiResponse<Approval[]>> {
  return apiGet<ApiResponse<Approval[]>>(
    `/approvals/history/${documentType}/${documentId}`,
  );
}

/**
 * Approve a document (marks approval action as approved).
 */
export async function approveDocument(
  approvalId: string,
  payload: ApprovePayload,
): Promise<ApiResponse<Approval>> {
  return apiPost<ApiResponse<Approval>>(`/approvals/${approvalId}/approve`, payload);
}

/**
 * Reject a document (marks approval action as rejected).
 */
export async function rejectDocument(
  approvalId: string,
  payload: RejectPayload,
): Promise<ApiResponse<Approval>> {
  return apiPost<ApiResponse<Approval>>(`/approvals/${approvalId}/reject`, payload);
}

/**
 * Return a document for revision.
 */
export async function returnForRevision(
  approvalId: string,
  payload: ReturnPayload,
): Promise<ApiResponse<Approval>> {
  return apiPost<ApiResponse<Approval>>(`/approvals/${approvalId}/return`, payload);
}
