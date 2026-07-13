/**
 * API client functions for Audit Log management.
 *
 * Covers: paginated + filterable audit log list, CSV export.
 *
 * Tenant_Admin: scoped to own tenant only.
 * System_Admin: can access all tenants.
 *
 * Validates: Requirements 17.7, 22.6
 */

import apiClient, { apiGet } from '@/lib/api/client';
import type { PaginatedResponse, ListQueryParams } from '@/types/api.types';
import type { AuditLog } from '@/types/models.types';

// ─── Query params ─────────────────────────────────────────────────────────────

export interface AuditLogQueryParams extends ListQueryParams {
  /** Filter by user name or email (partial match) */
  user?: string;
  /** Filter by action type (e.g. 'create', 'update', 'delete', 'login') */
  action_type?: string;
  /** Filter by entity type (e.g. 'purchase_request', 'tender') */
  entity_type?: string;
  /** Filter by IP address (exact or partial match) */
  ip_address?: string;
  /** ISO 8601 date string — logs created on or after this date */
  date_from?: string;
  /** ISO 8601 date string — logs created on or before this date */
  date_to?: string;
}

// ─── API functions ────────────────────────────────────────────────────────────

/**
 * Fetch a paginated, filterable list of audit logs.
 */
export async function getAuditLogs(
  params?: AuditLogQueryParams,
): Promise<PaginatedResponse<AuditLog>> {
  return apiGet<PaginatedResponse<AuditLog>>('/audit-logs', { params });
}

/**
 * Export audit logs as CSV.
 *
 * Passes the same filter parameters as the list endpoint.
 * Returns a Blob that the caller can trigger a browser download with.
 */
export async function exportAuditLogsCsv(
  params?: Omit<AuditLogQueryParams, 'page' | 'per_page'>,
): Promise<Blob> {
  const response = await apiClient.get('/audit-logs/export', {
    params: { ...params, format: 'csv' },
    responseType: 'blob',
  });
  return response.data as Blob;
}
