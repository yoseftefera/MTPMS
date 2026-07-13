/**
 * API client functions for Tenant Management (System_Admin).
 *
 * Covers: list tenants (paginated/searchable/filterable), register tenant,
 * fetch single tenant, suspend tenant, reactivate tenant, and analytics.
 *
 * All functions consume the standard ApiResponse<T> envelope.
 *
 * Validates: Requirements 1.6, 1.8
 */

import { apiGet, apiPost, apiPatch } from '@/lib/api/client';
import type { ApiResponse, PaginatedResponse, ListQueryParams } from '@/types/api.types';
import type { Tenant } from '@/types/models.types';

// ─── Query params specific to tenants ────────────────────────────────────────

export interface TenantsQueryParams extends ListQueryParams {
  status?: 'active' | 'suspended' | 'deactivated';
}

// ─── Request payloads ─────────────────────────────────────────────────────────

export interface RegisterTenantPayload {
  name: string;
  subdomain: string;
  admin_email: string;
  tenant_code: string;
}

// ─── Analytics types ──────────────────────────────────────────────────────────

export interface TenantAnalytics {
  total_tenants: number;
  active_tenants: number;
  suspended_tenants: number;
  deactivated_tenants: number;
  new_tenants_this_month: number;
  registrations_by_month: Array<{
    month: string; // e.g. "2025-01"
    count: number;
  }>;
  status_distribution: Array<{
    status: string;
    count: number;
  }>;
  top_tenants_by_activity: Array<{
    tenant_id: string;
    tenant_name: string;
    tenant_code: string;
    pr_count: number;
    po_count: number;
    total_spend: string;
  }>;
}

// ─── API functions ────────────────────────────────────────────────────────────

/**
 * Fetch a paginated, searchable list of all tenants (System_Admin only).
 */
export async function getTenants(
  params?: TenantsQueryParams,
): Promise<PaginatedResponse<Tenant>> {
  return apiGet<PaginatedResponse<Tenant>>('/tenants', { params });
}

/**
 * Fetch a single tenant by ID (System_Admin only).
 */
export async function getTenant(id: string): Promise<ApiResponse<Tenant>> {
  return apiGet<ApiResponse<Tenant>>(`/tenants/${id}`);
}

/**
 * Register a new tenant on the platform.
 * The backend provisions default roles, permissions, and configuration.
 */
export async function registerTenant(
  payload: RegisterTenantPayload,
): Promise<ApiResponse<Tenant>> {
  return apiPost<ApiResponse<Tenant>>('/tenants', payload);
}

/**
 * Suspend an active tenant.
 * All authentication and API requests for that tenant's users will be denied
 * while preserving all data.
 */
export async function suspendTenant(id: string): Promise<ApiResponse<Tenant>> {
  return apiPatch<ApiResponse<Tenant>>(`/tenants/${id}/suspend`, {});
}

/**
 * Reactivate a previously suspended tenant.
 */
export async function reactivateTenant(id: string): Promise<ApiResponse<Tenant>> {
  return apiPatch<ApiResponse<Tenant>>(`/tenants/${id}/reactivate`, {});
}

/**
 * Deactivate a tenant permanently.
 */
export async function deactivateTenant(id: string): Promise<ApiResponse<Tenant>> {
  return apiPatch<ApiResponse<Tenant>>(`/tenants/${id}/deactivate`, {});
}

/**
 * Fetch cross-tenant aggregated analytics (System_Admin only).
 * Does not expose individual tenant data to other tenants.
 */
export async function getTenantAnalytics(): Promise<ApiResponse<TenantAnalytics>> {
  return apiGet<ApiResponse<TenantAnalytics>>('/admin/analytics');
}
