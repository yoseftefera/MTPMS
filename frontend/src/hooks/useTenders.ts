/**
 * TanStack Query hooks for Tender & Bidding Management.
 *
 * Hooks:
 *   useTenders            — paginated + filterable tender list (officer view)
 *   useTender             — single tender with documents + bids
 *   useCreateTender       — mutation: create draft tender
 *   useUpdateTender       — mutation: update tender fields
 *   usePublishTender      — mutation: publish draft tender
 *   useCancelTender       — mutation: cancel tender with reason
 *   useExtendDeadline     — mutation: extend submission deadline
 *   useUploadTenderDoc    — mutation: upload tender document
 *   useOpenTenders        — supplier-facing: paginated list of open tenders
 *   useOpenTender         — supplier-facing: single open tender detail
 *   useSubmitBid          — mutation: submit new bid
 *   useUpdateBid          — mutation: revise existing bid before deadline
 *   useUploadBidDoc       — mutation: upload bid supporting document
 *
 * Validates: Requirements 8.1, 8.3, 22.5, 22.6
 */

import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import {
  getTenders,
  getTender,
  createTender,
  updateTender,
  publishTender,
  cancelTender,
  extendTenderDeadline,
  uploadTenderDocument,
  getOpenTenders,
  getOpenTender,
  submitBid,
  updateBid,
  uploadBidDocument,
} from '@/lib/api/tenders';
import type { TenderFilters, CreateTenderData, UpdateTenderData, SubmitBidData, UpdateBidData } from '@/types/tender';

// ─── Query keys ───────────────────────────────────────────────────────────────

export const tenderQueryKeys = {
  all: ['tenders'] as const,
  lists: () => [...tenderQueryKeys.all, 'list'] as const,
  list: (params?: TenderFilters) => [...tenderQueryKeys.lists(), params] as const,
  details: () => [...tenderQueryKeys.all, 'detail'] as const,
  detail: (id: string) => [...tenderQueryKeys.details(), id] as const,
  // Supplier-facing open tenders
  open: ['tenders', 'open'] as const,
  openList: (params?: Record<string, unknown>) => [...tenderQueryKeys.open, 'list', params] as const,
  openDetail: (id: string) => [...tenderQueryKeys.open, 'detail', id] as const,
  // Bids
  bids: (tenderId: string) => [...tenderQueryKeys.detail(tenderId), 'bids'] as const,
};

// ─── Queries — officer view ───────────────────────────────────────────────────

/**
 * Paginated + filterable tender list (Procurement_Officer / Tenant_Admin).
 */
export function useTenders(params?: TenderFilters) {
  return useQuery({
    queryKey: tenderQueryKeys.list(params),
    queryFn: () => getTenders(params),
  });
}

/**
 * Single tender with documents, bids, and creator (officer view).
 */
export function useTender(id: string) {
  return useQuery({
    queryKey: tenderQueryKeys.detail(id),
    queryFn: () => getTender(id),
    enabled: Boolean(id),
  });
}

// ─── Queries — supplier view ──────────────────────────────────────────────────

/**
 * Paginated list of published tenders for the supplier's category.
 */
export function useOpenTenders(params?: { page?: number; per_page?: number; search?: string; category?: string }) {
  return useQuery({
    queryKey: tenderQueryKeys.openList(params),
    queryFn: () => getOpenTenders(params),
  });
}

/**
 * Single open tender detail (supplier view — includes documents + my_bid).
 */
export function useOpenTender(id: string) {
  return useQuery({
    queryKey: tenderQueryKeys.openDetail(id),
    queryFn: () => getOpenTender(id),
    enabled: Boolean(id),
  });
}

// ─── Mutations — officer ──────────────────────────────────────────────────────

/**
 * Create a new draft tender.
 */
export function useCreateTender() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (payload: CreateTenderData) => createTender(payload),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: tenderQueryKeys.lists() });
    },
  });
}

/**
 * Update an existing tender.
 */
export function useUpdateTender() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: ({ id, payload }: { id: string; payload: UpdateTenderData }) =>
      updateTender(id, payload),
    onSuccess: (_data, { id }) => {
      queryClient.invalidateQueries({ queryKey: tenderQueryKeys.lists() });
      queryClient.invalidateQueries({ queryKey: tenderQueryKeys.detail(id) });
    },
  });
}

/**
 * Publish a draft tender (notifies suppliers in category).
 */
export function usePublishTender() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (id: string) => publishTender(id),
    onSuccess: (_data, id) => {
      queryClient.invalidateQueries({ queryKey: tenderQueryKeys.lists() });
      queryClient.invalidateQueries({ queryKey: tenderQueryKeys.detail(id) });
      // Invalidate supplier-facing list too
      queryClient.invalidateQueries({ queryKey: tenderQueryKeys.open });
    },
  });
}

/**
 * Cancel a tender with a documented reason.
 */
export function useCancelTender() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: ({ id, reason }: { id: string; reason: string }) =>
      cancelTender(id, reason),
    onSuccess: (_data, { id }) => {
      queryClient.invalidateQueries({ queryKey: tenderQueryKeys.lists() });
      queryClient.invalidateQueries({ queryKey: tenderQueryKeys.detail(id) });
    },
  });
}

/**
 * Extend a tender's submission deadline.
 */
export function useExtendDeadline() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: ({ id, newDeadline }: { id: string; newDeadline: string }) =>
      extendTenderDeadline(id, newDeadline),
    onSuccess: (_data, { id }) => {
      queryClient.invalidateQueries({ queryKey: tenderQueryKeys.detail(id) });
      queryClient.invalidateQueries({ queryKey: tenderQueryKeys.lists() });
    },
  });
}

/**
 * Upload a specification/supporting document to a tender.
 */
export function useUploadTenderDoc() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: ({ tenderId, file }: { tenderId: string; file: File }) =>
      uploadTenderDocument(tenderId, file),
    onSuccess: (_data, { tenderId }) => {
      queryClient.invalidateQueries({ queryKey: tenderQueryKeys.detail(tenderId) });
    },
  });
}

// ─── Mutations — supplier ─────────────────────────────────────────────────────

/**
 * Submit a new bid for a tender.
 */
export function useSubmitBid() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: ({ tenderId, payload }: { tenderId: string; payload: SubmitBidData }) =>
      submitBid(tenderId, payload),
    onSuccess: (_data, { tenderId }) => {
      queryClient.invalidateQueries({ queryKey: tenderQueryKeys.openDetail(tenderId) });
      queryClient.invalidateQueries({ queryKey: tenderQueryKeys.openList() });
    },
  });
}

/**
 * Revise an existing bid before the deadline.
 */
export function useUpdateBid() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: ({
      tenderId,
      bidId,
      payload,
    }: {
      tenderId: string;
      bidId: string;
      payload: UpdateBidData;
    }) => updateBid(tenderId, bidId, payload),
    onSuccess: (_data, { tenderId }) => {
      queryClient.invalidateQueries({ queryKey: tenderQueryKeys.openDetail(tenderId) });
    },
  });
}

/**
 * Upload a supporting document to a bid.
 */
export function useUploadBidDoc() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: ({
      tenderId,
      bidId,
      file,
    }: {
      tenderId: string;
      bidId: string;
      file: File;
    }) => uploadBidDocument(tenderId, bidId, file),
    onSuccess: (_data, { tenderId }) => {
      queryClient.invalidateQueries({ queryKey: tenderQueryKeys.openDetail(tenderId) });
    },
  });
}
