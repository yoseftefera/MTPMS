/**
 * API client functions for Contract Lifecycle Management.
 *
 * Covers:
 *   - Procurement_Officer / Tenant_Admin: list, create, get detail,
 *     activate, amend, terminate, upload document
 *
 * Validates: Requirements 11.1, 11.5, 22.6
 */

import { apiGet, apiPost } from '@/lib/api/client';
import apiClient from '@/lib/api/client';
import type { ApiResponse, PaginatedResponse } from '@/types/api.types';
import type {
  ContractDetail,
  ContractFilters,
  CreateContractData,
  AmendContractData,
  TerminateContractData,
} from '@/types/contract';

// ─── List ─────────────────────────────────────────────────────────────────────

/**
 * Paginated + filterable list of contracts.
 */
export async function getContracts(
  params?: ContractFilters,
): Promise<PaginatedResponse<ContractDetail>> {
  return apiGet<PaginatedResponse<ContractDetail>>('/contracts', { params });
}

// ─── Detail ───────────────────────────────────────────────────────────────────

/**
 * Single contract with supplier, amendments, documents, linked PO/tender.
 */
export async function getContract(
  id: string,
): Promise<ApiResponse<ContractDetail>> {
  return apiGet<ApiResponse<ContractDetail>>(`/contracts/${id}`);
}

// ─── Create ───────────────────────────────────────────────────────────────────

/**
 * Create a new draft contract.
 */
export async function createContract(
  payload: CreateContractData,
): Promise<ApiResponse<ContractDetail>> {
  return apiPost<ApiResponse<ContractDetail>>('/contracts', payload);
}

// ─── Activate ─────────────────────────────────────────────────────────────────

/**
 * Transition a draft contract to active.
 */
export async function activateContract(
  id: string,
): Promise<ApiResponse<ContractDetail>> {
  return apiPost<ApiResponse<ContractDetail>>(`/contracts/${id}/activate`);
}

// ─── Amend ────────────────────────────────────────────────────────────────────

/**
 * Amend a contract with a documented reason and updated fields.
 */
export async function amendContract(
  id: string,
  payload: AmendContractData,
): Promise<ApiResponse<ContractDetail>> {
  return apiPost<ApiResponse<ContractDetail>>(`/contracts/${id}/amend`, payload);
}

// ─── Terminate ────────────────────────────────────────────────────────────────

/**
 * Terminate an active contract with a mandatory reason.
 */
export async function terminateContract(
  id: string,
  payload: TerminateContractData,
): Promise<ApiResponse<ContractDetail>> {
  return apiPost<ApiResponse<ContractDetail>>(`/contracts/${id}/terminate`, payload);
}

// ─── Upload document ──────────────────────────────────────────────────────────

/**
 * Upload a document (PDF) and associate it with the contract.
 * Uses multipart/form-data.
 */
export async function uploadContractDocument(
  id: string,
  file: File,
  documentType: string,
): Promise<ApiResponse<ContractDetail>> {
  const formData = new FormData();
  formData.append('file', file);
  formData.append('document_type', documentType);

  const response = await apiClient.post<ApiResponse<ContractDetail>>(
    `/contracts/${id}/documents`,
    formData,
    { headers: { 'Content-Type': 'multipart/form-data' } },
  );
  return response.data;
}
