"use client"

/**
 * GRNNavLink — navigation link for the Goods Receipts module.
 *
 * Visible to: Store_Manager, Tenant_Admin, Committee_Member
 *
 * Validates: Requirements 12.1, 22.6
 */

import { useAuthStore } from "@/store/authStore"

const GRN_ROLES = ["Store_Manager", "Tenant_Admin", "Committee_Member"]

export function GRNNavLink() {
  const role = useAuthStore((s) => s.role)

  if (!role || !GRN_ROLES.includes(role)) return null

  return (
    <a
      href="/goods-receipts"
      className="rounded-md px-3 py-1.5 text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
    >
      Goods Receipts
    </a>
  )
}
