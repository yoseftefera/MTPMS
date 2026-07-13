/**
 * TanStack Query hooks for Pending Approvals workflow.
 *
 * Hooks:
 *   usePendingApprovals      — list with 30s auto-refetch
 *   useApprovalHistory       — history for a specific document
 *   useApproveDocument       — mutation with optimistic update (removes from pending list)
 *   useRejectDocument        — mutation with optimistic update (removes from pending list)
 *   useReturnForRevision     — mutation with optimistic update (removes from pending list)
 *
 * All three action mutations apply the same optimistic update pattern:
 *   1. Cancel in-flight pending queries
 *   2. Snapshot previous data for rollback
 *   3. Remove the acted-upon item from the pending list immediately
 *   4. Rollback on error, then re-validate on settle
 *
 * Validates: Requirements 6.3, 6.4, 6.5, 22.5, 22.7
 */

import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import {
  getPendingApprovals,
  getApprovalHistory,
  approveDocument,
  rejectDocument,
  returnForRevision,
  type PendingApprovalsQueryParams,
  type ApprovePayload,
  type RejectPayload,
  type ReturnPayload,
} from '@/lib/api/approvals';
import type { PaginatedResponse } from '@/types/api.types';
import type { Approval } from '@/types/models.types';

// ─── Query keys ───────────────────────────────────────────────────────────────

export const approvalQueryKeys = {
  all: ['approvals'] as const,
  pending: () => [...approvalQueryKeys.all, 'pending'] as const,
  pendingList: (params?: PendingApprovalsQueryParams) =>
    [...approvalQueryKeys.pending(), params] as const,
  history: (documentType: string, documentId: string) =>
    [...approvalQueryKeys.all, 'history', documentType, documentId] as const,
};

// ─── Queries ──────────────────────────────────────────────────────────────────

/**
 * Paginated pending approvals with 30-second auto-refetch for live updates.
 */
export function usePendingApprovals(params?: PendingApprovalsQueryParams) {
  return useQuery({
    queryKey: approvalQueryKeys.pendingList(params),
    queryFn: () => getPendingApprovals(params),
    refetchInterval: 30_000,
  });
}

/**
 * Approval history for a specific document.
 */
export function useApprovalHistory(documentType: string, documentId: string) {
  return useQuery({
    queryKey: approvalQueryKeys.history(documentType, documentId),
    queryFn: () => getApprovalHistory(documentType, documentId),
    enabled: Boolean(documentType) && Boolean(documentId),
  });
}

// ─── Mutations ────────────────────────────────────────────────────────────────

/**
 * Approve a document.
 * Optimistically removes the item from the pending list immediately.
 */
export function useApproveDocument() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: ({ approvalId, payload }: { approvalId: string; payload: ApprovePayload }) =>
      approveDocument(approvalId, payload),
    onMutate: async ({ approvalId }) => {
      await queryClient.cancelQueries({ queryKey: approvalQueryKeys.pending() });

      const previousData = queryClient.getQueriesData<PaginatedResponse<Approval>>({
        queryKey: approvalQueryKeys.pending(),
      });

      // Optimistically remove the item from every cached pending list
      queryClient.setQueriesData<PaginatedResponse<Approval>>(
        { queryKey: approvalQueryKeys.pending() },
        (old) => {
          if (!old?.data) return old;
          return {
            ...old,
            data: old.data.filter((a) => a.id !== approvalId),
          };
        },
      );

      return { previousData };
    },
    onError: (_err, _vars, context) => {
      // Rollback optimistic update on error
      if (context?.previousData) {
        for (const [queryKey, data] of context.previousData) {
          queryClient.setQueryData(queryKey, data);
        }
      }
    },
    onSettled: () => {
      queryClient.invalidateQueries({ queryKey: approvalQueryKeys.pending() });
    },
  });
}

/**
 * Reject a document.
 * Optimistically removes the item from the pending list (same as approve).
 */
export function useRejectDocument() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: ({ approvalId, payload }: { approvalId: string; payload: RejectPayload }) =>
      rejectDocument(approvalId, payload),
    onMutate: async ({ approvalId }) => {
      await queryClient.cancelQueries({ queryKey: approvalQueryKeys.pending() });

      const previousData = queryClient.getQueriesData<PaginatedResponse<Approval>>({
        queryKey: approvalQueryKeys.pending(),
      });

      // Optimistically remove the rejected item from every cached pending list
      queryClient.setQueriesData<PaginatedResponse<Approval>>(
        { queryKey: approvalQueryKeys.pending() },
        (old) => {
          if (!old?.data) return old;
          return {
            ...old,
            data: old.data.filter((a) => a.id !== approvalId),
          };
        },
      );

      return { previousData };
    },
    onError: (_err, _vars, context) => {
      // Rollback optimistic update on error
      if (context?.previousData) {
        for (const [queryKey, data] of context.previousData) {
          queryClient.setQueryData(queryKey, data);
        }
      }
    },
    onSettled: () => {
      queryClient.invalidateQueries({ queryKey: approvalQueryKeys.pending() });
    },
  });
}

/**
 * Return a document for revision.
 * Optimistically removes the item from the pending list.
 */
export function useReturnForRevision() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: ({ approvalId, payload }: { approvalId: string; payload: ReturnPayload }) =>
      returnForRevision(approvalId, payload),
    onMutate: async ({ approvalId }) => {
      await queryClient.cancelQueries({ queryKey: approvalQueryKeys.pending() });

      const previousData = queryClient.getQueriesData<PaginatedResponse<Approval>>({
        queryKey: approvalQueryKeys.pending(),
      });

      // Optimistically remove the item returned for revision from every cached pending list
      queryClient.setQueriesData<PaginatedResponse<Approval>>(
        { queryKey: approvalQueryKeys.pending() },
        (old) => {
          if (!old?.data) return old;
          return {
            ...old,
            data: old.data.filter((a) => a.id !== approvalId),
          };
        },
      );

      return { previousData };
    },
    onError: (_err, _vars, context) => {
      // Rollback optimistic update on error
      if (context?.previousData) {
        for (const [queryKey, data] of context.previousData) {
          queryClient.setQueryData(queryKey, data);
        }
      }
    },
    onSettled: () => {
      queryClient.invalidateQueries({ queryKey: approvalQueryKeys.pending() });
    },
  });
}
