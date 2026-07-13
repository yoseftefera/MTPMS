/**
 * TanStack Query hooks for Payment Management.
 *
 * Hooks:
 *   usePayments         — paginated + filterable payments list
 *   usePayment          — single payment detail
 *   usePaymentSchedule  — upcoming payment schedule
 *   useRecordPayment    — mutation: Finance_Officer records a payment
 *
 * Validates: Requirements 14.5, 14.6, 14.8, 22.6
 */

import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query"
import {
  getPayments,
  getPayment,
  getPaymentSchedule,
  recordPayment,
} from "@/lib/api/payments"
import { invoiceQueryKeys } from "@/hooks/useInvoices"
import type { PaymentFilters, RecordPaymentData } from "@/types/invoice"

// ─── Query keys ───────────────────────────────────────────────────────────────

export const paymentQueryKeys = {
  all: ["payments"] as const,
  lists: () => [...paymentQueryKeys.all, "list"] as const,
  list: (filters?: PaymentFilters) =>
    [...paymentQueryKeys.lists(), filters] as const,
  details: () => [...paymentQueryKeys.all, "detail"] as const,
  detail: (id: string) => [...paymentQueryKeys.details(), id] as const,
  schedule: () => [...paymentQueryKeys.all, "schedule"] as const,
}

// ─── Queries ──────────────────────────────────────────────────────────────────

/**
 * Paginated + filterable payments list.
 */
export function usePayments(filters?: PaymentFilters) {
  return useQuery({
    queryKey: paymentQueryKeys.list(filters),
    queryFn: () => getPayments(filters),
  })
}

/**
 * Single payment detail with invoice info.
 */
export function usePayment(id: string) {
  return useQuery({
    queryKey: paymentQueryKeys.detail(id),
    queryFn: () => getPayment(id),
    enabled: Boolean(id),
  })
}

/**
 * Payment schedule — upcoming invoices due for payment.
 */
export function usePaymentSchedule() {
  return useQuery({
    queryKey: paymentQueryKeys.schedule(),
    queryFn: () => getPaymentSchedule(),
  })
}

// ─── Mutations ────────────────────────────────────────────────────────────────

/**
 * Finance_Officer records a payment against a payment record.
 * Invalidates payments list and the related invoice detail.
 */
export function useRecordPayment() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({ id, payload }: { id: string; payload: RecordPaymentData }) =>
      recordPayment(id, payload),
    onSuccess: (_data, { id }) => {
      queryClient.invalidateQueries({ queryKey: paymentQueryKeys.lists() })
      queryClient.invalidateQueries({ queryKey: paymentQueryKeys.detail(id) })
      queryClient.invalidateQueries({ queryKey: paymentQueryKeys.schedule() })
      // Also refresh invoice list since payment changes invoice status
      queryClient.invalidateQueries({ queryKey: invoiceQueryKeys.lists() })
    },
  })
}
