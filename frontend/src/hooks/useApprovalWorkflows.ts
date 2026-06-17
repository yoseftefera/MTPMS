/**
 * TanStack Query hooks for Approval Workflow management.
 *
 * Hooks:
 *   useApprovalWorkflows     — paginated list
 *   useApprovalWorkflow      — single workflow by ID
 *   useCreateApprovalWorkflow — mutation
 *   useUpdateApprovalWorkflow — mutation
 *   useDeleteApprovalWorkflow — mutation
 *   useAddWorkflowLevel      — mutation
 *   useRemoveWorkflowLevel   — mutation
 *
 * Validates: Requirements 6.8, 22.7
 */

import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import {
  getApprovalWorkflows,
  getApprovalWorkflow,
  createApprovalWorkflow,
  updateApprovalWorkflow,
  deleteApprovalWorkflow,
  addWorkflowLevel,
  removeWorkflowLevel,
  type ApprovalWorkflowsQueryParams,
  type CreateWorkflowPayload,
  type UpdateWorkflowPayload,
  type WorkflowLevelPayload,
} from '@/lib/api/approvalWorkflows';

// ─── Query keys ───────────────────────────────────────────────────────────────

export const workflowQueryKeys = {
  all: ['approval-workflows'] as const,
  lists: () => [...workflowQueryKeys.all, 'list'] as const,
  list: (params?: ApprovalWorkflowsQueryParams) =>
    [...workflowQueryKeys.lists(), params] as const,
  details: () => [...workflowQueryKeys.all, 'detail'] as const,
  detail: (id: string) => [...workflowQueryKeys.details(), id] as const,
};

// ─── Queries ──────────────────────────────────────────────────────────────────

/**
 * Paginated + filterable list of approval workflows.
 */
export function useApprovalWorkflows(params?: ApprovalWorkflowsQueryParams) {
  return useQuery({
    queryKey: workflowQueryKeys.list(params),
    queryFn: () => getApprovalWorkflows(params),
  });
}

/**
 * Single approval workflow by ID (includes levels).
 */
export function useApprovalWorkflow(id: string) {
  return useQuery({
    queryKey: workflowQueryKeys.detail(id),
    queryFn: () => getApprovalWorkflow(id),
    enabled: Boolean(id),
  });
}

// ─── Mutations ────────────────────────────────────────────────────────────────

/**
 * Create a new approval workflow with levels.
 */
export function useCreateApprovalWorkflow() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (payload: CreateWorkflowPayload) => createApprovalWorkflow(payload),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: workflowQueryKeys.lists() });
    },
  });
}

/**
 * Update an approval workflow's metadata or active state.
 */
export function useUpdateApprovalWorkflow(id: string) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (payload: UpdateWorkflowPayload) => updateApprovalWorkflow(id, payload),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: workflowQueryKeys.lists() });
      queryClient.invalidateQueries({ queryKey: workflowQueryKeys.detail(id) });
    },
  });
}

/**
 * Delete an approval workflow.
 */
export function useDeleteApprovalWorkflow() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (id: string) => deleteApprovalWorkflow(id),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: workflowQueryKeys.lists() });
    },
  });
}

/**
 * Add a level to an existing workflow.
 */
export function useAddWorkflowLevel(workflowId: string) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (payload: WorkflowLevelPayload) => addWorkflowLevel(workflowId, payload),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: workflowQueryKeys.detail(workflowId) });
      queryClient.invalidateQueries({ queryKey: workflowQueryKeys.lists() });
    },
  });
}

/**
 * Remove a level from an existing workflow.
 */
export function useRemoveWorkflowLevel(workflowId: string) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (levelId: string) => removeWorkflowLevel(workflowId, levelId),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: workflowQueryKeys.detail(workflowId) });
      queryClient.invalidateQueries({ queryKey: workflowQueryKeys.lists() });
    },
  });
}
