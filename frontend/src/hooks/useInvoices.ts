/**
 * TanStack Query hooks for Invoice Processing.
 *
 * Hooks:
 *   useInvoices      — paginated + filterable invoice list
 *   useInvoice       — single invoice with items, approvals, references
 *   useCreateInvoice — mutation: supplier submits invoice
 *   useApproveInvoice — mutation: Finance_Officer approves invoice
 *   useRejectInvoice — mutation: Finance_Officer rejects invoice with reason
 *
 * Validates: Requirements 14.1, 14.4, 22.6
 */

import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query"
import {
  getInvoices,
  getInvoice,
  createInvoice,
  approveInvoice,
  rejectInvoice,
} from "@/lib/api/invoices"
import type { InvoiceFilters, CreateInvoiceData } from "@/types/invoice"

// ─── Query keys ───────────────────────────────────────────────────────────────

export const invoiceQueryKeys = {
  all: ["invoices"] as const,
  lists: () => [...invoiceQueryKeys.all, "list"] as const,
  list: (filters?: InvoiceFilters) =>
    [...invoiceQueryKeys.lists(), filters] as const,
  details: () => [...invoiceQueryKeys.all, "detail"] as const,
  detail: (id: string) => [...invoiceQueryKeys.details(), id] as const,
}

// ─── Queries ──────────────────────────────────────────────────────────────────

/**
 * Paginated + filterable invoice list.
 */
export function useInvoices(filters?: InvoiceFilters) {
  return useQuery({
    queryKey: invoiceQueryKeys.list(filters),
    queryFn: () => getInvoices(filters),
  })
}

/**
 * Single invoice with line items, approval history, PO/contract reference.
 */
export function useInvoice(id: string) {
  return useQuery({
    queryKey: invoiceQueryKeys.detail(id),
    queryFn: () => getInvoice(id),
    enabled: Boolean(id),
  })
}

// ─── Mutations ────────────────────────────────────────────────────────────────

/**
 * Supplier submits a new invoice.
 */
export function useCreateInvoice() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (payload: CreateInvoiceData) => createInvoice(payload),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: invoiceQueryKeys.lists() })
    },
  })
}

/**
 * Finance_Officer approves a pending_approval invoice.
 */
export function useApproveInvoice() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (id: string) => approveInvoice(id),
    onSuccess: (_data, id) => {
      queryClient.invalidateQueries({ queryKey: invoiceQueryKeys.detail(id) })
      queryClient.invalidateQueries({ queryKey: invoiceQueryKeys.lists() })
    },
  })
}

/**
 * Finance_Officer rejects a pending_approval invoice with a mandatory reason.
 */
export function useRejectInvoice() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({ id, reason }: { id: string; reason: string }) =>
      rejectInvoice(id, reason),
    onSuccess: (_data, { id }) => {
      queryClient.invalidateQueries({ queryKey: invoiceQueryKeys.detail(id) })
      queryClient.invalidateQueries({ queryKey: invoiceQueryKeys.lists() })
    },
  })
}
