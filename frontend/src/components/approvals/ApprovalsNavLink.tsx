"use client"

/**
 * ApprovalsNavLink.
 *
 * Renders the "Approvals" nav link for roles that can act as approvers
 * (Tenant_Admin, Procurement_Officer, Finance_Officer, Store_Manager,
 * Committee_Member).
 *
 * Validates: Requirements 6.6, 22.5
 */

import { useAuthStore } from "@/store/authStore"

const APPROVER_ROLES = new Set([
  "Tenant_Admin",
  "Procurement_Officer",
  "Finance_Officer",
  "Store_Manager",
  "Committee_Member",
])

export function ApprovalsNavLink() {
  const role = useAuthStore((s) => s.role)

  if (!role || !APPROVER_ROLES.has(role)) {
    return null
  }

  return (
    <a
      href="/approvals"
      className="rounded-md px-3 py-1.5 text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
    >
      Approvals
    </a>
  )
}
