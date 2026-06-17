/**
 * TanStack Query hooks for Supplier Management.
 *
 * Hooks:
 *   useSuppliers            — paginated + filterable supplier list
 *   useSupplier             — single supplier by ID
 *   useApproveSupplier      — mutation: approve pending supplier
 *   useRejectSupplier       — mutation: reject pending supplier
 *   useBlacklistSupplier    — mutation: blacklist supplier with reason
 *   useReactivateSupplier   — mutation: reactivate inactive supplier
 *   useUploadSupplierDoc    — mutation: upload compliance document
 *   useSupplierPerformance  — paginated performance records
 *
 * Validates: Requirements 7.6, 7.7, 22.5
 */

import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import {
  getSuppliers,
  getSupplier,
  approveSupplier,
  rejectSupplier,
  blacklistSupplier,
  reactivateSupplier,
  uploadSupplierDocument,
  getSupplierPerformance,
  type SuppliersQueryParams,
  type BlacklistSupplierPayload,
} from '@/lib/api/suppliers';
import type { SupplierDocument } from '@/types/models.types';

// ─── Query keys ───────────────────────────────────────────────────────────────

export const supplierQueryKeys = {
  all: ['suppliers'] as const,
  lists: () => [...supplierQueryKeys.all, 'list'] as const,
  list: (params?: SuppliersQueryParams) => [...supplierQueryKeys.lists(), params] as const,
  details: () => [...supplierQueryKeys.all, 'detail'] as const,
  detail: (id: string) => [...supplierQueryKeys.details(), id] as const,
  performance: (id: string) => [...supplierQueryKeys.detail(id), 'performance'] as const,
};

// ─── Queries ──────────────────────────────────────────────────────────────────

/**
 * Paginated + filterable supplier list.
 */
export function useSuppliers(params?: SuppliersQueryParams) {
  return useQuery({
    queryKey: supplierQueryKeys.list(params),
    queryFn: () => getSuppliers(params),
  });
}

/**
 * Single supplier by ID (includes documents, performance, related records).
 */
export function useSupplier(id: string) {
  return useQuery({
    queryKey: supplierQueryKeys.detail(id),
    queryFn: () => getSupplier(id),
    enabled: Boolean(id),
  });
}

/**
 * Paginated performance metric records for a supplier.
 */
export function useSupplierPerformance(
  supplierId: string,
  params?: { page?: number; per_page?: number },
) {
  return useQuery({
    queryKey: [...supplierQueryKeys.performance(supplierId), params],
    queryFn: () => getSupplierPerformance(supplierId, params),
    enabled: Boolean(supplierId),
  });
}

// ─── Mutations ────────────────────────────────────────────────────────────────

/**
 * Approve a pending_verification supplier.
 */
export function useApproveSupplier() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (id: string) => approveSupplier(id),
    onSuccess: (_data, id) => {
      queryClient.invalidateQueries({ queryKey: supplierQueryKeys.lists() });
      queryClient.invalidateQueries({ queryKey: supplierQueryKeys.detail(id) });
    },
  });
}

/**
 * Reject a pending_verification supplier.
 */
export function useRejectSupplier() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: ({ id, reason }: { id: string; reason?: string }) =>
      rejectSupplier(id, reason),
    onSuccess: (_data, { id }) => {
      queryClient.invalidateQueries({ queryKey: supplierQueryKeys.lists() });
      queryClient.invalidateQueries({ queryKey: supplierQueryKeys.detail(id) });
    },
  });
}

/**
 * Blacklist an active supplier with a documented reason.
 */
export function useBlacklistSupplier() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: ({ id, payload }: { id: string; payload: BlacklistSupplierPayload }) =>
      blacklistSupplier(id, payload),
    onSuccess: (_data, { id }) => {
      queryClient.invalidateQueries({ queryKey: supplierQueryKeys.lists() });
      queryClient.invalidateQueries({ queryKey: supplierQueryKeys.detail(id) });
    },
  });
}

/**
 * Reactivate an inactive supplier.
 */
export function useReactivateSupplier() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (id: string) => reactivateSupplier(id),
    onSuccess: (_data, id) => {
      queryClient.invalidateQueries({ queryKey: supplierQueryKeys.lists() });
      queryClient.invalidateQueries({ queryKey: supplierQueryKeys.detail(id) });
    },
  });
}

/**
 * Upload a compliance document for a supplier.
 */
export function useUploadSupplierDoc() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: ({
      supplierId,
      file,
      documentType,
      expiresAt,
    }: {
      supplierId: string;
      file: File;
      documentType: SupplierDocument['document_type'];
      expiresAt?: string | null;
    }) => uploadSupplierDocument(supplierId, file, documentType, expiresAt),
    onSuccess: (_data, { supplierId }) => {
      queryClient.invalidateQueries({ queryKey: supplierQueryKeys.detail(supplierId) });
    },
  });
}
