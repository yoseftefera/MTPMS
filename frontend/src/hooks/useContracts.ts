/**
 * TanStack Query hooks for Contract Lifecycle Management.
 *
 * Hooks:
 *   useContracts          — paginated + filterable contract list
 *   useContract           — single contract with full relations
 *   useCreateContract     — mutation: create draft contract
 *   useActivateContract   — mutation: activate a draft contract
 *   useAmendContract      — mutation: amend contract with reason
 *   useTerminateContract  — mutation: terminate active contract
 *   useUploadContractDoc  — mutation: upload document to contract
 *
 * Validates: Requirements 11.1, 11.5, 22.5, 22.6
 */

import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import {
  getContracts,
  getContract,
  createContract,
  activateContract,
  amendContract,
  terminateContract,
  uploadContractDocument,
} from '@/lib/api/contracts';
import type {
  ContractFilters,
  CreateContractData,
  AmendContractData,
  TerminateContractData,
} from '@/types/contract';

// ─── Query keys ───────────────────────────────────────────────────────────────

export const contractQueryKeys = {
  all: ['contracts'] as const,
  lists: () => [...contractQueryKeys.all, 'list'] as const,
  list: (filters?: ContractFilters) => [...contractQueryKeys.lists(), filters] as const,
  details: () => [...contractQueryKeys.all, 'detail'] as const,
  detail: (id: string) => [...contractQueryKeys.details(), id] as const,
};

// ─── Queries ──────────────────────────────────────────────────────────────────

/**
 * Paginated + filterable contract list.
 */
export function useContracts(filters?: ContractFilters) {
  return useQuery({
    queryKey: contractQueryKeys.list(filters),
    queryFn: () => getContracts(filters),
  });
}

/**
 * Single contract with full relations (amendments, documents, supplier, etc.).
 */
export function useContract(id: string) {
  return useQuery({
    queryKey: contractQueryKeys.detail(id),
    queryFn: () => getContract(id),
    enabled: Boolean(id),
  });
}

// ─── Mutations ────────────────────────────────────────────────────────────────

/**
 * Create a new draft contract.
 */
export function useCreateContract() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (payload: CreateContractData) => createContract(payload),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: contractQueryKeys.lists() });
    },
  });
}

/**
 * Activate a draft contract.
 */
export function useActivateContract() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (id: string) => activateContract(id),
    onSuccess: (_data, id) => {
      queryClient.invalidateQueries({ queryKey: contractQueryKeys.detail(id) });
      queryClient.invalidateQueries({ queryKey: contractQueryKeys.lists() });
    },
  });
}

/**
 * Amend a contract with a documented reason.
 */
export function useAmendContract() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: ({ id, payload }: { id: string; payload: AmendContractData }) =>
      amendContract(id, payload),
    onSuccess: (_data, { id }) => {
      queryClient.invalidateQueries({ queryKey: contractQueryKeys.detail(id) });
      queryClient.invalidateQueries({ queryKey: contractQueryKeys.lists() });
    },
  });
}

/**
 * Terminate an active contract.
 */
export function useTerminateContract() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: ({ id, payload }: { id: string; payload: TerminateContractData }) =>
      terminateContract(id, payload),
    onSuccess: (_data, { id }) => {
      queryClient.invalidateQueries({ queryKey: contractQueryKeys.detail(id) });
      queryClient.invalidateQueries({ queryKey: contractQueryKeys.lists() });
    },
  });
}

/**
 * Upload a document to a contract.
 */
export function useUploadContractDoc() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: ({
      contractId,
      file,
      documentType,
    }: {
      contractId: string;
      file: File;
      documentType: string;
    }) => uploadContractDocument(contractId, file, documentType),
    onSuccess: (_data, { contractId }) => {
      queryClient.invalidateQueries({ queryKey: contractQueryKeys.detail(contractId) });
    },
  });
}
