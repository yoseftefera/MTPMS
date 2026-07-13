/**
 * TanStack Query hooks for Audit Logs.
 *
 * Hooks:
 *   useAuditLogs     — paginated + filterable audit log list
 *   useExportAuditLogs — mutation: export filtered results as CSV
 *
 * Validates: Requirements 17.7, 22.6
 */

import { useQuery, useMutation } from '@tanstack/react-query';
import {
  getAuditLogs,
  exportAuditLogsCsv,
  type AuditLogQueryParams,
} from '@/lib/api/auditLogs';

// ─── Query keys ───────────────────────────────────────────────────────────────

export const auditLogQueryKeys = {
  all: ['audit-logs'] as const,
  lists: () => [...auditLogQueryKeys.all, 'list'] as const,
  list: (params?: AuditLogQueryParams) =>
    [...auditLogQueryKeys.lists(), params] as const,
};

// ─── Queries ──────────────────────────────────────────────────────────────────

/**
 * Paginated, filterable audit log list.
 * Results are never stale (audit logs are immutable/append-only).
 */
export function useAuditLogs(params?: AuditLogQueryParams) {
  return useQuery({
    queryKey: auditLogQueryKeys.list(params),
    queryFn: () => getAuditLogs(params),
    staleTime: 30_000, // 30 s — logs are append-only so short stale window is fine
  });
}

// ─── Mutations ────────────────────────────────────────────────────────────────

/**
 * Export audit logs as CSV.
 * Triggers a browser file download on success.
 */
export function useExportAuditLogs() {
  return useMutation({
    mutationFn: (
      params?: Omit<AuditLogQueryParams, 'page' | 'per_page'>,
    ) => exportAuditLogsCsv(params),
    onSuccess: (blob) => {
      const timestamp = new Date().toISOString().slice(0, 10);
      const filename = `audit-logs-${timestamp}.csv`;
      const url = URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = url;
      a.download = filename;
      document.body.appendChild(a);
      a.click();
      document.body.removeChild(a);
      URL.revokeObjectURL(url);
    },
  });
}
