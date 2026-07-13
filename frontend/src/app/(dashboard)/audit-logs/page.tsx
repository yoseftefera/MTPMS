/**
 * Audit Log Viewer page.
 *
 * Accessible at /audit-logs (within the dashboard route group).
 * Tenant_Admin: sees only their own tenant's logs.
 * System_Admin: can see all tenants' logs.
 *
 * Validates: Requirements 17.7, 22.6, 22.7
 */

import { AuditLogsDataTable } from "@/components/audit-logs/AuditLogsDataTable"
import { SectionErrorBoundary } from "@/components/ui/SectionErrorBoundary"

export const metadata = {
  title: "Audit Logs — PMP",
  description:
    "Browse the immutable audit trail of all platform actions, with advanced filters and CSV export.",
}

export default function AuditLogsPage() {
  return (
    <div className="space-y-6">
      {/* Page header */}
      <div>
        <h1 className="text-2xl font-semibold tracking-tight">Audit Logs</h1>
        <p className="mt-1 text-sm text-muted-foreground">
          Immutable record of all platform actions. Use the filters below to
          search by user, action type, entity, date range, or IP address.
        </p>
      </div>

      {/* Data table (client component) — wrapped with error boundary */}
      <SectionErrorBoundary title="Audit logs table">
        <AuditLogsDataTable />
      </SectionErrorBoundary>
    </div>
  )
}
