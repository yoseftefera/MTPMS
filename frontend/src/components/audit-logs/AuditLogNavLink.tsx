"use client"

/**
 * AuditLogNavLink — navigation link for the Audit Log Viewer.
 *
 * Visible to: Tenant_Admin (own tenant logs), System_Admin (all tenants).
 *
 * Validates: Requirements 17.7, 22.6
 */

import { useAuthStore } from "@/store/authStore"

const AUDIT_LOG_ROLES = ["Tenant_Admin", "System_Admin"]

export function AuditLogNavLink() {
  const role = useAuthStore((s) => s.role)

  if (!role || !AUDIT_LOG_ROLES.includes(role)) return null

  return (
    <a
      href="/audit-logs"
      className="rounded-md px-3 py-1.5 text-muted-foreground transition-colors hover:bg-muted hover:text-foreground whitespace-nowrap"
    >
      Audit Logs
    </a>
  )
}
