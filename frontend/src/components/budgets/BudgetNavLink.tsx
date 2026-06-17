"use client"

/**
 * BudgetNavLink.
 *
 * Renders the "Budgets" top-nav link only for Finance_Officer and
 * Tenant_Admin roles. Reads the role from the Zustand auth store.
 *
 * Validates: Requirements 13.1, 22.5
 */

import { useAuthStore } from "@/store/authStore"

export function BudgetNavLink() {
  const role = useAuthStore((s) => s.role)

  if (role !== "Finance_Officer" && role !== "Tenant_Admin") {
    return null
  }

  return (
    <>
      <a
        href="/budgets"
        className="rounded-md px-3 py-1.5 text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
      >
        Budgets
      </a>
      <a
        href="/budgets/utilization"
        className="rounded-md px-3 py-1.5 text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
      >
        Utilization
      </a>
    </>
  )
}
