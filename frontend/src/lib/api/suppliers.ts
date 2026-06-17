/**
 * API client functions for Supplier Management.
 *
 * Covers: list suppliers (paginated/filterable), get single supplier,
 * approve, reject, blacklist, upload document, get performance metrics,
 * and public supplier registration.
 *
 * Validates: Requirements 7.6, 7.7, 22.6
 */

import apiClient, { apiGet, apiPost, apiPatch } from '@/lib/api/client';
import type { ApiResponse, PaginatedResponse, ListQueryParams } from '@/types/api.types';
import type { Supplier, SupplierDocument } from '@/types/models.types';

// ─── Query params specific to suppliers ──────────────────────────────────────

export interface SuppliersQueryParams extends ListQueryParams {
  status?: 'pending_verification' | 'active' | 'inactive' | 'blacklisted';
  business_category?: string;
  search?: string;
}

// ─── Performance metric record ────────────────────────────────────────────────

export interface SupplierPerformanceRecord {
  id: string;
  supplier_id: string;
  on_time_delivery_rate: string;
  quality_acceptance_rate: string;
  period_label: string;
  created_at: string;
}

// ─── Request payloads ─────────────────────────────────────────────────────────

export interface BlacklistSupplierPayload {
  reason: string;
}

export interface SupplierRegistrationPayload {
  organization_name: string;
  contact_name: string;
  contact_email: string;
  contact_phone?: string | null;
  business_category: string;
}

// ─── API functions ────────────────────────────────────────────────────────────

/**
 * Fetch a paginated, filterable list of suppliers.
 */
export async function getSuppliers(
  params?: SuppliersQueryParams,
): Promise<PaginatedResponse<Supplier>> {
  return apiGet<PaginatedResponse<Supplier>>('/suppliers', { params });
}

/**
 * Fetch a single supplier by ID (includes documents, performance).
 */
export async function getSupplier(id: string): Promise<ApiResponse<Supplier>> {
  return apiGet<ApiResponse<Supplier>>(`/suppliers/${id}`, {
    params: { include: 'documents,performance,purchase_orders,contracts' },
  });
}

/**
 * Approve a pending supplier registration.
 */
export async function approveSupplier(id: string): Promise<ApiResponse<Supplier>> {
  return apiPost<ApiResponse<Supplier>>(`/suppliers/${id}/approve`);
}

/**
 * Reject a pending supplier registration.
 */
export async function rejectSupplier(
  id: string,
  reason?: string,
): Promise<ApiResponse<Supplier>> {
  return apiPost<ApiResponse<Supplier>>(`/suppliers/${id}/reject`, { reason });
}

/**
 * Blacklist an active supplier with a documented reason.
 */
export async function blacklistSupplier(
  id: string,
  payload: BlacklistSupplierPayload,
): Promise<ApiResponse<Supplier>> {
  return apiPost<ApiResponse<Supplier>>(`/suppliers/${id}/blacklist`, payload);
}

/**
 * Upload a compliance document for a supplier (multipart/form-data).
 */
export async function uploadSupplierDocument(
  supplierId: string,
  file: File,
  documentType: SupplierDocument['document_type'],
  expiresAt?: string | null,
): Promise<ApiResponse<SupplierDocument>> {
  const formData = new FormData();
  formData.append('file', file);
  formData.append('document_type', documentType);
  if (expiresAt) formData.append('expires_at', expiresAt);

  const response = await apiClient.post<ApiResponse<SupplierDocument>>(
    `/suppliers/${supplierId}/documents`,
    formData,
    { headers: { 'Content-Type': 'multipart/form-data' } },
  );
  return response.data;
}

/**
 * Fetch paginated performance metric records for a supplier.
 */
export async function getSupplierPerformance(
  supplierId: string,
  params?: { page?: number; per_page?: number },
): Promise<PaginatedResponse<SupplierPerformanceRecord>> {
  return apiGet<PaginatedResponse<SupplierPerformanceRecord>>(
    `/suppliers/${supplierId}/performance`,
    { params },
  );
}

/**
 * PUBLIC endpoint — register a new supplier (no authentication required).
 * Submits to POST /api/v1/suppliers/register.
 */
export async function registerSupplier(
  payload: SupplierRegistrationPayload,
): Promise<ApiResponse<{ message: string }>> {
  return apiPost<ApiResponse<{ message: string }>>('/suppliers/register', payload);
}

/**
 * Reactivate an inactive supplier.
 */
export async function reactivateSupplier(id: string): Promise<ApiResponse<Supplier>> {
  return apiPatch<ApiResponse<Supplier>>(`/suppliers/${id}/reactivate`, {});
}
