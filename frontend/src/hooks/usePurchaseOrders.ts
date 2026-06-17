/**
 * TanStack Query hooks for Purchase Order Management.
 *
 * Hooks:
 *   usePurchaseOrders     — paginated + filterable PO list
 *   usePurchaseOrder      — single PO with items / history
 *   useCreatePO           — mutation: create draft PO
 *   useAmendPO            — mutation: amend PO fields + line items
 *   useIssuePO            — mutation: issue PO to supplier
 *   useAcceptPO           — mutation: supplier accepts PO
 *   useRejectPO           — mutation: supplier rejects PO with reason
 *   useCancelPO           — mutation: cancel PO with reason
 *
 * Validates: Requirements 10.2, 10.9, 22.5, 22.6
 */

import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query"
import {
  getPurchaseOrders,
  getPurchaseOrder,
  createPurchaseOrder,
  amendPurchaseOrder,
  issuePurchaseOrder,
  acceptPurchaseOrder,
  rejectPurchaseOrder,
  cancelPurchaseOrder,
} from "@/lib/api/purchaseOrders"
import type { POFilters, CreatePOData, AmendPOData } from "@/types/purchaseOrder"

// ─── Query keys ───────────────────────────────────────────────────────────────

export const poQueryKeys = {
  all: ["purchase-orders"] as const,
  lists: () => [...poQueryKeys.all, "list"] as const,
  list: (filters?: POFilters) => [...poQueryKeys.lists(), filters] as const,
  details: () => [...poQueryKeys.all, "detail"] as const,
  detail: (id: string) => [...poQueryKeys.details(), id] as const,
}

// ─── Queries ──────────────────────────────────────────────────────────────────

/**
 * Paginated + filterable purchase order list.
 */
export function usePurchaseOrders(filters?: POFilters) {
  return useQuery({
    queryKey: poQueryKeys.list(filters),
    queryFn: () => getPurchaseOrders(filters),
  })
}

/**
 * Single purchase order with items, history, supplier, department.
 */
export function usePurchaseOrder(id: string) {
  return useQuery({
    queryKey: poQueryKeys.detail(id),
    queryFn: () => getPurchaseOrder(id),
    enabled: Boolean(id),
  })
}

// ─── Mutations ────────────────────────────────────────────────────────────────

/**
 * Create a new draft purchase order.
 */
export function useCreatePO() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (payload: CreatePOData) => createPurchaseOrder(payload),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: poQueryKeys.lists() })
    },
  })
}

/**
 * Amend an existing PO (delivery address, date, notes, line items).
 */
export function useAmendPO() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({ id, payload }: { id: string; payload: AmendPOData }) =>
      amendPurchaseOrder(id, payload),
    onSuccess: (_data, { id }) => {
      queryClient.invalidateQueries({ queryKey: poQueryKeys.detail(id) })
      queryClient.invalidateQueries({ queryKey: poQueryKeys.lists() })
    },
  })
}

/**
 * Issue a draft PO to the supplier.
 */
export function useIssuePO() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (id: string) => issuePurchaseOrder(id),
    onSuccess: (_data, id) => {
      queryClient.invalidateQueries({ queryKey: poQueryKeys.detail(id) })
      queryClient.invalidateQueries({ queryKey: poQueryKeys.lists() })
    },
  })
}

/**
 * Supplier accepts the PO.
 */
export function useAcceptPO() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (id: string) => acceptPurchaseOrder(id),
    onSuccess: (_data, id) => {
      queryClient.invalidateQueries({ queryKey: poQueryKeys.detail(id) })
      queryClient.invalidateQueries({ queryKey: poQueryKeys.lists() })
    },
  })
}

/**
 * Supplier rejects the PO with a mandatory reason.
 */
export function useRejectPO() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({ id, reason }: { id: string; reason: string }) =>
      rejectPurchaseOrder(id, reason),
    onSuccess: (_data, { id }) => {
      queryClient.invalidateQueries({ queryKey: poQueryKeys.detail(id) })
      queryClient.invalidateQueries({ queryKey: poQueryKeys.lists() })
    },
  })
}

/**
 * Cancel a PO with a mandatory reason.
 */
export function useCancelPO() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({ id, reason }: { id: string; reason: string }) =>
      cancelPurchaseOrder(id, reason),
    onSuccess: (_data, { id }) => {
      queryClient.invalidateQueries({ queryKey: poQueryKeys.detail(id) })
      queryClient.invalidateQueries({ queryKey: poQueryKeys.lists() })
    },
  })
}
