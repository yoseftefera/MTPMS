"use client"

/**
 * ApprovalWorkflowNavLink.
 *
 * Renders the "Approval Workflows" nav link only for Tenant_Admin role,
 * since only Tenant_Admin can configure approval workflows (Requirement 6.8).
 *
 * Validates: Requirements 6.8, 22.5
 */

import { useAuthStore } from "@/store/authStore"

export function ApprovalWorkflowNavLink() {
  const role = useAuthStore((s) => s.role)

  if (role !== "Tenant_Admin") {
    return null
  }

  return (
    <a
      href="/approval-workflows"
      className="rounded-md px-3 py-1.5 text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
    >
      Workflows
    </a>
  )
}
