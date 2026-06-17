/**
 * TanStack Query hooks for Goods Receipt and Inventory Management.
 *
 * Hooks:
 *   useGoodsReceipts          — paginated + filterable GRN list
 *   useGoodsReceipt           — single GRN with items / committee / PO
 *   useCreateGoodsReceipt     — mutation: create GRN (Store_Manager)
 *   useAssignGRNCommittee     — mutation: assign committee members
 *   useSubmitInspectionResult — mutation: Committee_Member submits inspection
 *   useLookupPO               — query: look up PO by number for GRN form
 *   useInventory              — paginated + filterable inventory list
 *   useInventoryItem          — single inventory item detail
 *
 * Validates: Requirements 12.1, 12.8, 22.6
 */

import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query"
import {
  getGoodsReceipts,
  getGoodsReceipt,
  createGoodsReceipt,
  assignGRNCommittee,
  submitInspectionResult,
  lookupPurchaseOrderByNumber,
  getInventory,
  getInventoryItem,
} from "@/lib/api/goodsReceipts"
import type {
  GRNFilters,
  CreateGRNData,
  AssignCommitteeData,
  InspectionResultData,
  InventoryFilters,
} from "@/types/goodsReceipt"

// ─── Query keys ───────────────────────────────────────────────────────────────

export const grnQueryKeys = {
  all: ["goods-receipts"] as const,
  lists: () => [...grnQueryKeys.all, "list"] as const,
  list: (filters?: GRNFilters) => [...grnQueryKeys.lists(), filters] as const,
  details: () => [...grnQueryKeys.all, "detail"] as const,
  detail: (id: string) => [...grnQueryKeys.details(), id] as const,
}

export const inventoryQueryKeys = {
  all: ["inventory"] as const,
  lists: () => [...inventoryQueryKeys.all, "list"] as const,
  list: (filters?: InventoryFilters) => [...inventoryQueryKeys.lists(), filters] as const,
  details: () => [...inventoryQueryKeys.all, "detail"] as const,
  detail: (id: string) => [...inventoryQueryKeys.details(), id] as const,
}

// ─── GRN Queries ──────────────────────────────────────────────────────────────

/**
 * Paginated + filterable goods receipt list.
 */
export function useGoodsReceipts(filters?: GRNFilters) {
  return useQuery({
    queryKey: grnQueryKeys.list(filters),
    queryFn: () => getGoodsReceipts(filters),
  })
}

/**
 * Single goods receipt with items, PO, committee members.
 */
export function useGoodsReceipt(id: string) {
  return useQuery({
    queryKey: grnQueryKeys.detail(id),
    queryFn: () => getGoodsReceipt(id),
    enabled: Boolean(id),
  })
}

/**
 * Look up a purchase order by PO number for use in the create GRN form.
 */
export function useLookupPO(poNumber: string) {
  return useQuery({
    queryKey: ["po-lookup", poNumber],
    queryFn: () => lookupPurchaseOrderByNumber(poNumber),
    enabled: poNumber.length >= 3,
    staleTime: 30_000,
  })
}

// ─── GRN Mutations ────────────────────────────────────────────────────────────

/**
 * Create a new goods receipt (Store_Manager).
 */
export function useCreateGoodsReceipt() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (payload: CreateGRNData) => createGoodsReceipt(payload),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: grnQueryKeys.lists() })
    },
  })
}

/**
 * Assign committee members to a GRN (Store_Manager, pending_inspection).
 */
export function useAssignGRNCommittee() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({ id, payload }: { id: string; payload: AssignCommitteeData }) =>
      assignGRNCommittee(id, payload),
    onSuccess: (_data, { id }) => {
      queryClient.invalidateQueries({ queryKey: grnQueryKeys.detail(id) })
      queryClient.invalidateQueries({ queryKey: grnQueryKeys.lists() })
    },
  })
}

/**
 * Submit inspection results (Committee_Member, under_inspection).
 */
export function useSubmitInspectionResult() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({ id, payload }: { id: string; payload: InspectionResultData }) =>
      submitInspectionResult(id, payload),
    onSuccess: (_data, { id }) => {
      queryClient.invalidateQueries({ queryKey: grnQueryKeys.detail(id) })
      queryClient.invalidateQueries({ queryKey: grnQueryKeys.lists() })
      // Inventory may change after inspection acceptance
      queryClient.invalidateQueries({ queryKey: inventoryQueryKeys.lists() })
    },
  })
}

// ─── Inventory Queries ────────────────────────────────────────────────────────

/**
 * Paginated + filterable inventory list.
 */
export function useInventory(filters?: InventoryFilters) {
  return useQuery({
    queryKey: inventoryQueryKeys.list(filters),
    queryFn: () => getInventory(filters),
  })
}

/**
 * Single inventory item detail.
 */
export function useInventoryItem(id: string) {
  return useQuery({
    queryKey: inventoryQueryKeys.detail(id),
    queryFn: () => getInventoryItem(id),
    enabled: Boolean(id),
  })
}
