"use client"

/**
 * PONavLink — navigation link for the Purchase Orders module.
 *
 * Visible to: Procurement_Officer, Tenant_Admin, Supplier
 *
 * Validates: Requirements 10.2, 22.6
 */

import { useAuthStore } from "@/store/authStore"

const PO_ROLES = ["Procurement_Officer", "Tenant_Admin", "Supplier"]

export function PONavLink() {
  const role = useAuthStore((s) => s.role)

  if (!role || !PO_ROLES.includes(role)) return null

  return (
    <a
      href="/purchase-orders"
      className="rounded-md px-3 py-1.5 text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
    >
      Purchase Orders
    </a>
  )
}
