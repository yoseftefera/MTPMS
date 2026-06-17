/**
 * API client functions for Approval Workflow management.
 *
 * Covers: list workflows, create/update/delete workflows,
 * add/remove workflow levels.
 *
 * All functions consume the standard ApiResponse<T> envelope.
 *
 * Validates: Requirements 6.8, 22.6
 */

import { apiGet, apiPost, apiPut, apiDelete } from '@/lib/api/client';
import type { ApiResponse, PaginatedResponse, ListQueryParams } from '@/types/api.types';
import type { ApprovalWorkflow, ApprovalWorkflowLevel } from '@/types/models.types';

// ─── Query params ─────────────────────────────────────────────────────────────

export interface ApprovalWorkflowsQueryParams extends ListQueryParams {
  document_type?: string;
  is_active?: boolean;
}

// ─── Request payloads ─────────────────────────────────────────────────────────

export interface WorkflowLevelPayload {
  level_order: number;
  approver_type: 'role' | 'user';
  approver_role?: string | null;
  approver_user_id?: string | null;
  is_parallel: boolean;
  escalation_hours: number;
}

export interface CreateWorkflowPayload {
  name: string;
  document_type: 'purchase_request' | 'tender' | 'purchase_order' | 'contract' | 'invoice';
  department_id?: string | null;
  levels: WorkflowLevelPayload[];
}

export interface UpdateWorkflowPayload {
  name?: string;
  document_type?: 'purchase_request' | 'tender' | 'purchase_order' | 'contract' | 'invoice';
  department_id?: string | null;
  is_active?: boolean;
}

// ─── API functions ────────────────────────────────────────────────────────────

/**
 * Fetch a paginated list of approval workflows.
 */
export async function getApprovalWorkflows(
  params?: ApprovalWorkflowsQueryParams,
): Promise<PaginatedResponse<ApprovalWorkflow>> {
  return apiGet<PaginatedResponse<ApprovalWorkflow>>('/approval-workflows', { params });
}

/**
 * Fetch a single approval workflow by ID (includes levels).
 */
export async function getApprovalWorkflow(id: string): Promise<ApiResponse<ApprovalWorkflow>> {
  return apiGet<ApiResponse<ApprovalWorkflow>>(`/approval-workflows/${id}`);
}

/**
 * Create a new approval workflow with its levels.
 */
export async function createApprovalWorkflow(
  payload: CreateWorkflowPayload,
): Promise<ApiResponse<ApprovalWorkflow>> {
  return apiPost<ApiResponse<ApprovalWorkflow>>('/approval-workflows', payload);
}

/**
 * Update an existing approval workflow's metadata.
 */
export async function updateApprovalWorkflow(
  id: string,
  payload: UpdateWorkflowPayload,
): Promise<ApiResponse<ApprovalWorkflow>> {
  return apiPut<ApiResponse<ApprovalWorkflow>>(`/approval-workflows/${id}`, payload);
}

/**
 * Delete an approval workflow.
 */
export async function deleteApprovalWorkflow(id: string): Promise<ApiResponse<null>> {
  return apiDelete<ApiResponse<null>>(`/approval-workflows/${id}`);
}

/**
 * Add a new level to an existing workflow.
 */
export async function addWorkflowLevel(
  workflowId: string,
  payload: WorkflowLevelPayload,
): Promise<ApiResponse<ApprovalWorkflowLevel>> {
  return apiPost<ApiResponse<ApprovalWorkflowLevel>>(
    `/approval-workflows/${workflowId}/levels`,
    payload,
  );
}

/**
 * Remove a level from an existing workflow.
 */
export async function removeWorkflowLevel(
  workflowId: string,
  levelId: string,
): Promise<ApiResponse<null>> {
  return apiDelete<ApiResponse<null>>(
    `/approval-workflows/${workflowId}/levels/${levelId}`,
  );
}
