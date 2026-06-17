/**
 * TanStack Query hooks for Purchase Request Management.
 *
 * Hooks:
 *   usePurchaseRequests   — paginated + filterable list
 *   usePurchaseRequest    — single PR with items/history
 *   useCreatePR           — mutation: create draft
 *   useUpdatePR           — mutation: update draft
 *   useSubmitPR           — mutation: submit for approval
 *   useCancelPR           — mutation: cancel
 *   useAttachDocument     — mutation: upload file attachment
 *
 * Validates: Requirements 5.2, 5.5, 5.7, 5.8, 22.5, 22.6
 */

import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query"
import {
  getPurchaseRequests,
  getPurchaseRequest,
  createPurchaseRequest,
  updatePurchaseRequest,
  submitPurchaseRequest,
  cancelPurchaseRequest,
  attachDocument,
} from "@/lib/api/purchaseRequests"
import type { CreatePRData, UpdatePRData, PRFilters, PRStatus } from "@/types/purchaseRequest"

// ─── Query keys ───────────────────────────────────────────────────────────────

export const prQueryKeys = {
  all: ["purchase-requests"] as const,
  lists: () => [...prQueryKeys.all, "list"] as const,
  list: (filters?: PRFilters) => [...prQueryKeys.lists(), filters] as const,
  details: () => [...prQueryKeys.all, "detail"] as const,
  detail: (id: string) => [...prQueryKeys.details(), id] as const,
}

// ─── Queries ──────────────────────────────────────────────────────────────────

/**
 * Paginated + filterable purchase request list.
 */
export function usePurchaseRequests(filters?: PRFilters) {
  return useQuery({
    queryKey: prQueryKeys.list(filters),
    queryFn: () => getPurchaseRequests(filters),
  })
}

/**
 * Single purchase request with items, history, and documents.
 */
export function usePurchaseRequest(id: string) {
  return useQuery({
    queryKey: prQueryKeys.detail(id),
    queryFn: () => getPurchaseRequest(id),
    enabled: Boolean(id),
  })
}

// ─── Mutations ────────────────────────────────────────────────────────────────

/**
 * Create a new draft purchase request.
 * Invalidates the list on success.
 */
export function useCreatePR() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (payload: CreatePRData) => createPurchaseRequest(payload),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: prQueryKeys.lists() })
    },
  })
}

/**
 * Update an existing purchase request.
 * Invalidates both the list and the specific detail on success.
 */
export function useUpdatePR() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({ id, payload }: { id: string; payload: UpdatePRData }) =>
      updatePurchaseRequest(id, payload),
    onSuccess: (_data, { id }) => {
      queryClient.invalidateQueries({ queryKey: prQueryKeys.lists() })
      queryClient.invalidateQueries({ queryKey: prQueryKeys.detail(id) })
    },
  })
}

/**
 * Submit a draft PR for approval.
 * Applies an optimistic status update so the UI reflects immediately.
 */
export function useSubmitPR() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (id: string) => submitPurchaseRequest(id),
    onMutate: async (id) => {
      await queryClient.cancelQueries({ queryKey: prQueryKeys.detail(id) })
      const previous = queryClient.getQueryData(prQueryKeys.detail(id))
      queryClient.setQueryData(
        prQueryKeys.detail(id),
        (old: { data?: { status: PRStatus; status_label: string } } | undefined) =>
          old?.data
            ? {
                ...old,
                data: { ...old.data, status: "pending_approval" as PRStatus, status_label: "Pending Approval" },
              }
            : old,
      )
      return { previous }
    },
    onError: (_err, id, context) => {
      if (context?.previous) {
        queryClient.setQueryData(prQueryKeys.detail(id), context.previous)
      }
    },
    onSettled: (_data, _err, id) => {
      queryClient.invalidateQueries({ queryKey: prQueryKeys.detail(id) })
      queryClient.invalidateQueries({ queryKey: prQueryKeys.lists() })
    },
  })
}

/**
 * Cancel a purchase request.
 * Applies an optimistic status update so the UI reflects immediately.
 */
export function useCancelPR() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({ id, reason }: { id: string; reason?: string }) =>
      cancelPurchaseRequest(id, reason),
    onMutate: async ({ id }) => {
      await queryClient.cancelQueries({ queryKey: prQueryKeys.detail(id) })
      const previous = queryClient.getQueryData(prQueryKeys.detail(id))
      queryClient.setQueryData(
        prQueryKeys.detail(id),
        (old: { data?: { status: PRStatus; status_label: string } } | undefined) =>
          old?.data
            ? {
                ...old,
                data: { ...old.data, status: "cancelled" as PRStatus, status_label: "Cancelled" },
              }
            : old,
      )
      return { previous }
    },
    onError: (_err, { id }, context) => {
      if (context?.previous) {
        queryClient.setQueryData(prQueryKeys.detail(id), context.previous)
      }
    },
    onSettled: (_data, _err, { id }) => {
      queryClient.invalidateQueries({ queryKey: prQueryKeys.detail(id) })
      queryClient.invalidateQueries({ queryKey: prQueryKeys.lists() })
    },
  })
}

/**
 * Upload a document attachment.
 * Invalidates the PR detail on success to refresh the documents list.
 */
export function useAttachDocument() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({ id, file }: { id: string; file: File }) => attachDocument(id, file),
    onSuccess: (_data, { id }) => {
      queryClient.invalidateQueries({ queryKey: prQueryKeys.detail(id) })
    },
  })
}
